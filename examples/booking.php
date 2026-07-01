<?php
declare(strict_types=1);

/**
 * The full CQDA driving-school booking flow, rebuilt on meraki/schema + schema-html.
 *
 *     php -S localhost:8000 examples/booking.php   (then open http://localhost:8000/)
 *
 * It models, no JavaScript, the stepped flow:
 *
 *   1. Account or guest            — choose to sign in or continue as a guest.
 *   2. Service + who-for           — pick a service (radio) and Myself / Someone else.
 *   3. Vehicle (own/school)        — only shown when the service permits an own vehicle.
 *   4. Transmission                — only shown for a school vehicle that offers >1.
 *   5. Identity                    — the payer (always) + participant + who manages.
 *   6. Lessons (collection, >=1)   — each = { when, pick-up location, notes }; its own line.
 *   7. Terms                       — must be accepted.
 *   8. Review & confirm            — dialog; "Continue to payment" emits the payload below.
 *   ( Pay — omitted: a redirect to a third-party form. The JSON shown on submit IS the
 *     payload that would be handed to the payment service. )
 *
 * Trigger fields sit in EARLIER groups than the fields they reveal, because each reveal
 * is a server round-trip (no JS): service -> vehicle, vehicle -> transmission,
 * who_for -> participant fields. Groups whose every field is currently hidden are skipped
 * automatically (e.g. Vehicle/Transmission for a hire) — FormOptions::showAllSteps() opts out.
 *
 * New lessons inherit the first lesson's pick-up address + notes for convenience
 * (FieldOptions::inheritInNewItems) — still fully editable.
 *
 * ───────────────────────────────────────────────────────────────────────────────────────
 * WHAT STAYS APP-LEVEL (correctly outside the form — the form captures/validates fields only):
 *   - login / "return_to" redirects, and "skip this step if already logged in".
 *   - per-service ACCESS GATES (login-gated service for a guest -> block; a course -> redirect
 *     to /courses; a phone-only service -> "call to book"). The catalogue is filtered by the
 *     app *before* the form; a form cannot block or redirect.
 *   - the per-service POLICY VALUES ("truck is manual-only", "hire is school-only"): the form
 *     HIDES the control; the fixed value itself is applied by the app.
 *   - the payment hand-off.
 */

require __DIR__ . '/../vendor/autoload.php';

use Meraki\Schema\Facade;
use Meraki\Schema\Field;
use Meraki\Schema\Property\Name;
use Meraki\Schema\Rule\FieldBuilder;
use Meraki\Schema\Html\FormOptions;
use Meraki\Schema\Html\Wizard\Form;
use Meraki\Schema\Html\Wizard\HiddenFieldStore;

function buildSchema(): Facade
{
	$schema = new Facade('booking');

	// 1) Account — a real app redirects to login (with return_to) here and skips this step
	//    when already signed in; the form only records the choice. [APP-LEVEL]
	$schema->addEnumField('account', ['user', 'guest']);

	// 2) Service. [APP-LEVEL: login-gated / course / phone-only services are filtered out by
	//    access rules before this catalogue is shown.]
	//
	//    The own-vehicle choice is added by, and gated on, the service: own is only offered for
	//    an ordinary driving lesson. "Fixed to school" is expressed as a default that survives
	//    being ignored, so a downstream rule still sees vehicle === 'school'.
	$vehicle = new Field\Enum(new Name('vehicle'), ['school', 'own']);
	$vehicle->prefill('school');

	$schema->addEnumField('service', ['driving-lesson', 'truck-lesson', 'car-hire'])
		->pairWith($vehicle, function (FieldBuilder $rule, Field\Enum $v): void {
			$rule->when($this)->notEquals('driving-lesson')->thenMakeOptional($v)->thenIgnore($v);
		});

	// 4) Transmission — only for a school vehicle, and only when the service offers a choice.
	$transmission = new Field\Enum(new Name('transmission'), ['automatic', 'manual']);
	$transmission->prefill('automatic');

	$vehicle->pairWith($transmission, function (FieldBuilder $rule, Field\Enum $t): void {
		$rule->when($this)->notEquals('school')->thenMakeOptional($t)->thenIgnore($t);
	});

	// Truck lessons are manual-only -> hide + ignore the choice. (pairWith can't be re-applied
	// to transmission, so this companion rule uses the declarative builder, which now has
	// thenIgnore too; the fixed "manual" value itself is applied by the app.)
	$schema->whenAllMatch(fn($r) => $r
		->whenEquals('#/fields/service/value', 'truck-lesson')
		->thenMakeOptional('#/fields/transmission')
		->thenIgnore('#/fields/transmission'));

	// 2b) Who is the booking for? "Someone else" reveals the participant fields and a choice of
	//     who manages the lessons afterwards. The organiser always pays + is emailed the invoice.
	$schema->addEnumField('who_for', ['myself', 'someone_else'])
		->pairWith(new Field\Text(new Name('participant_name')),
			function (FieldBuilder $rule, Field\Text $f): void {
				$rule->when($this)->notEquals('someone_else')->thenMakeOptional($f)->thenIgnore($f);
			});

	// Who manages the lessons afterwards: the organiser keeps control, or the participant gets
	// their own manage link (an account-access decision). Only relevant when booking for someone
	// else — otherwise it's hidden + ignored (and its own step is skipped), defaulting to the
	// organiser. It sits on its own step before the participant details so it has settled the
	// participant-email visibility by the time that step renders.
	$schema->addEnumField('who_manages', ['organiser', 'participant'])->makeOptional()->prefill('organiser');
	$schema->whenAllMatch(fn($r) => $r
		->whenNotEquals('#/fields/who_for/value', 'someone_else')
		->thenMakeOptional('#/fields/who_manages')
		->thenIgnore('#/fields/who_manages'));

	// The participant's email is only needed — and only required — when the participant manages
	// their own lessons (to receive that manage link); otherwise it's hidden + ignored. "Show only
	// when (someone_else AND participant)" is the negation: hide on EITHER not-equals.
	$schema->addEmailAddressField('participant_email')->makeOptional();
	$schema->whenAnyMatch(fn($r) => $r
		->whenNotEquals('#/fields/who_for/value', 'someone_else')
		->orWhenNotEquals('#/fields/who_manages/value', 'participant')
		->thenMakeOptional('#/fields/participant_email')
		->thenIgnore('#/fields/participant_email'));
	$schema->whenAllMatch(fn($r) => $r
		->whenEquals('#/fields/who_for/value', 'someone_else')
		->andWhenEquals('#/fields/who_manages/value', 'participant')
		->thenRequire('#/fields/participant_email'));

	// 5) Payer / organiser — always captured, always emailed the invoice. The phone field
	//    is localised to AU, so a local format ("0412 345 678") is accepted, not just E.164.
	$schema->addNameField('name');
	$schema->addEmailAddressField('email');
	$schema->addPhoneNumberField('phone')->allow('AU');

	// 6) Lessons — repeatable, >=1; each item is its own invoice line, with a full pick-up
	//    address (composite fields are now allowed inside collection items).
	$schema->addCollectionField('lessons', function (Facade $item): void {
		$item->addDateTimeField('when');
		$item->addAddressField('pickup');
		$item->addTextField('notes')->makeOptional();
	})->minItems(1);

	// 7) Terms.
	$schema->addBooleanField('terms')->mustBeAccepted();

	return $schema;
}

function buildOptions(): FormOptions
{
	$options = new FormOptions();

	$options->configureOptionsFor('account')->renderAsButtonGroup()->labelOptions([
		'user' => 'I have an account',
		'guest' => 'Continue as a guest',
	]);
	$options->configureOptionsFor('service')->renderAsSelect()->labelOptions([
		'driving-lesson' => 'Driving lesson',
		'truck-lesson' => 'Truck lesson (manual only)',
		'car-hire' => 'Car hire (P-test)',
	]);
	$options->configureOptionsFor('who_for')->renderAsDropdown()->labelOptions([
		'myself'       => 'Myself',
		'someone_else' => 'Someone else',
	]);
	$options->configureOptionsFor('vehicle')->renderAsDropdown()->labelOptions([
		'school' => 'School vehicle',
		'own'    => 'My own vehicle',
	]);
	$options->configureOptionsFor('transmission')->renderAsDropdown()->labelOptions([
		'automatic' => 'Automatic',
		'manual'    => 'Manual',
	]);
	// Managed people: a no-JS combobox of saved people you can also type a new name into.
	$options->configureOptionsFor('participant_name')->label('Participant\'s name')
		->allowAddingOptions([
			'Jordan Lee' => 'Jordan Lee',
			'Sam Okafor' => 'Sam Okafor',
		], hint: 'Choose a saved person or type a new name');
	$options->configureOptionsFor('who_manages')->renderAsDropdown()->label('Who manages the lessons?')->labelOptions([
		'organiser'   => 'I will (the organiser)',
		'participant' => 'The participant (we\'ll email them a manage link)',
	]);
	$options->configureOptionsFor('participant_email')->label('Participant\'s email')
		->hint('Required if the participant manages their own lessons');
	$options->configureOptionsFor('phone')->hint('e.g. 0412 345 678');
	$options->configureOptionsFor('lessons')
		->addInDialog(trigger: 'Add another lesson', confirm: 'Add lesson')
		->inheritInNewItems('pickup', 'notes');
	$options->configureOptionsFor('terms')->label('I accept the terms and conditions');

	$options->group('Account', ['account']);
	$options->group('Service', ['service', 'who_for']);
	$options->group('Vehicle', ['vehicle']);
	$options->group('Transmission', ['transmission']);
	$options->group('Your details', ['name', 'email', 'phone']);
	// The "someone else" sub-flow: both steps are auto-skipped when booking for yourself.
	// "Who manages?" comes first because it decides whether the participant's email is shown.
	$options->group('Lesson management', ['who_manages']);
	$options->group('Participant details', ['participant_name', 'participant_email']);
	$options->group('Lessons', ['lessons']);
	$options->group('Terms', ['terms']);
	$options->requireConfirmation()->asDialog(confirm: 'Continue to payment', edit: 'Make changes');

	return $options;
}

function page(string $body): string
{
	$style = <<<CSS
		body { font: 16px/1.5 system-ui, sans-serif; max-width: 34rem; margin: 3rem auto; padding: 0 1rem; }
		.field { margin: 0 0 1rem; }
		.field label { display: block; font-weight: 600; }
		input, select, textarea { font: inherit; padding: .35rem; min-width: 16rem; }
		.errors p { color: #b00020; margin: .25rem 0 0; }
		.wizard-nav, .mf-dialog-actions { display: flex; gap: .5rem; margin-top: 1rem; }
		button { font: inherit; padding: .45rem 1rem; cursor: pointer; }
		fieldset { border: 1px solid #ddd; border-radius: .4rem; margin: 0 0 1rem; }
		.collection-item { border-left: 3px solid #ddd; padding: .5rem .75rem; margin-bottom: .5rem; }
		.collection-value { display: inline-block; margin-right: 1rem; }
		.wizard-summary dt { font-weight: 600; }
		.wizard-summary dd { margin: 0 0 .5rem; }
	CSS;

	$polyfill = '<script type="module" src="https://unpkg.com/invokers-polyfill/invoker.min.js"></script>';

	return "<!doctype html><html lang=\"en\"><head><meta charset=\"utf-8\">"
		. "<meta name=\"viewport\" content=\"width=device-width, initial-scale=1\">"
		. "<title>Book a lesson</title><style>{$style}</style>{$polyfill}</head><body>{$body}</body></html>";
}

$services = [
	'driving-lesson' => [
		'title' => 'Driving Lesson',
		'kind' => 'lesson',                            // lesson | hire | course
		'licence_class' => 'C',                                 // display-only (D-16) — never gates
		'price' => ['amount' => '85.00', 'currency' => 'AUD'],
		'duration' => 'PT1H',
		'booking_policy' => 'any',                               // online | phone | email | any
		'login_required' => false,
		'transmission_options' => [
			'automatic' => ['title' => 'Automatic', 'vehicle_policy' => 'either'],   // use_own | school_only | either
			'manual' => ['title' => 'Manual', 'vehicle_policy' => 'either'],
		],
	],

	'test-car-hire' => [
		'title' => 'Test-Car Hire',
		'kind' => 'hire',
		'licence_class' => 'C',
		'price' => ['amount' => '180.00', 'currency' => 'AUD'],
		'duration' => 'PT1H30M',
		'booking_policy' => 'online',
		'login_required' => true,                                // hidden unless logged in (D-22)
		'min_recent_lessons' => 3,                     // >= 3 lessons ...
		'recent_lessons_window_months' => 6,                     // ... in the last 6 months
		'transmission_options' => [
			'automatic' => ['title' => 'Automatic', 'vehicle_policy' => 'school_only'],  // you hire THEIR car
			'manual' => ['title' => 'Manual', 'vehicle_policy' => 'school_only'],
		],
	],

	'defensive-driving-lesson' => [
		'title' => 'Defensive Driving Lesson',
		'kind' => 'lesson',
		'licence_class' => 'C',
		'price' => ['amount' => '120.00', 'currency' => 'AUD'],
		'duration' => 'PT2H',
		'booking_policy' => 'phone',                             // never bookable online (D-22)
		'login_required' => false,
		'transmission_options' => [
			'automatic' => ['title' => 'Automatic', 'vehicle_policy' => 'either'],
			'manual' => ['title' => 'Manual', 'vehicle_policy' => 'either'],
		],
	],

	'truck-lesson' => [
		'title' => 'Heavy-Vehicle (Truck) Lesson',
		'kind' => 'lesson',
		'licence_class' => 'LR, MR, HR, HC, MC',                // display-only family
		'price' => ['amount' => '250.00', 'currency' => 'AUD'],
		'duration' => 'PT1H',
		'booking_policy' => 'any',
		'login_required' => false,
		'transmission_options' => [
			'automatic' => ['title' => 'Automatic', 'vehicle_policy' => 'either'],
			'synchromesh' => ['title' => 'Synchromesh', 'vehicle_policy' => 'either'],
			'non_synchromesh' => ['title' => 'Non-Synchromesh', 'vehicle_policy' => 'use_own'],  // crash box => own truck
		],
	],

	'motorcycle-lesson' => [
		'title' => 'Motorcycle Lesson',
		'kind' => 'lesson',
		'licence_class' => 'RE, R',
		'price' => ['amount' => '95.00', 'currency' => 'AUD'],
		'duration' => 'PT1H',
		'booking_policy' => 'any',
		'login_required' => false,
		'transmission_options' => [
			'manual' => ['title' => 'Manual', 'vehicle_policy' => 'either'],    // taught manual; school or own
			'automatic' => ['title' => 'Automatic', 'vehicle_policy' => 'use_own'],   // auto allowed but bring your own
		],
	],

	'q-ride' => [
		'title' => 'Q-Ride Course',
		'kind' => 'course',                           // enrolment, not a normal booking (D-23), redirects to course enrollment page
		'licence_class' => 'RE',
		'price' => ['amount' => '599.00', 'currency' => 'AUD'],
		'duration' => 'P2D',                              // admin-configurable per intake
		'booking_policy' => 'online',
		'login_required' => false,
		'eligibility_check' => true,                             // QLD-gov modal attestation (D-22/D-23)
		'own_vehicle' => 'use_own',                          // bring your own bike; no school vehicle
		'transmission_options' => [],                            // n/a for the course
	],
];


$form = new Form(buildSchema(), buildOptions(), new HiddenFieldStore());

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	$result = $form->handle($_POST);

	// On completion this is the payment payload that would be POSTed to the payment service.
	$body = $result->completed
		? '<h1>Ready for payment</h1><p>This payload would be handed to the payment service:</p><pre>'
			. htmlspecialchars(json_encode($result->data, JSON_PRETTY_PRINT) ?: '', ENT_QUOTES)
			. '</pre><p><a href="/">Start again</a></p>'
		: $result->html;
} else {
	$body = $form->start();
}

echo page($body);
