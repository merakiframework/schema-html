<?php
declare(strict_types=1);

/**
 * Functional no-JS multi-step "booking" demo.
 *
 *     php -S localhost:8000 examples/multi-step.php
 *
 * then open http://localhost:8000/. It shows:
 *   - a multi-step wizard (state carried in hidden inputs; no session)
 *   - contact_method as a customizable <select> (renderAsSelect), paired (pairWith)
 *     to email/phone so only the chosen branch is required and shown
 *   - a repeatable lessons collection (add/remove), "Add a lesson" opening a native
 *     <dialog> via the command-invoker API
 *   - an optional "notes" field revealed inline via <details> (revealInline)
 *   - a "must be accepted" terms checkbox
 *   - a confirmation shown as a dialog over the whole form
 *
 * The library emits NO JavaScript. The optional polyfill below enables the
 * command-invoker API on browsers that lack it; remove it on modern browsers.
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
	$schema->addNameField('name');

	// One enum, paired to the two contact fields. Only the chosen branch stays
	// required; the other is made optional + ignored (and hidden by the default hook).
	$schema->addEnumField('contact_method', ['email', 'phone'])
		->pairWith(new Field\EmailAddress(new Name('email_address')),
			function (FieldBuilder $rule, Field\EmailAddress $email): void {
				$rule->when($this)->notEquals('email')->thenMakeOptional($email)->thenIgnore($email);
			})
		->pairWith(new Field\PhoneNumber(new Name('phone_number')),
			function (FieldBuilder $rule, Field\PhoneNumber $phone): void {
				$rule->when($this)->notEquals('phone')->thenMakeOptional($phone)->thenIgnore($phone);
			});

	$schema->addCollectionField('lessons', function (Facade $item): void {
		$item->addDateField('date');
		$item->addTimeField('time');
	})->minItems(1);

	$schema->addTextField('notes')->makeOptional();
	$schema->addBooleanField('terms')->mustBeAccepted();

	return $schema;
}

function buildOptions(): FormOptions
{
	$options = new FormOptions();

	$options->configureOptionsFor('contact_method')->renderAsSelect();
	$options->configureOptionsFor('lessons')->addInDialog(trigger: 'Add a lesson', confirm: 'Add lesson');
	$options->configureOptionsFor('notes')->label('Notes')->revealInline(trigger: 'Add a note');
	$options->configureOptionsFor('terms')->label('I agree to the terms and conditions');

	$options->group('Your details', ['name', 'contact_method']);
	$options->group('Contact details', ['email_address', 'phone_number'])->asDialog(trigger: 'Provide contact details', confirm: 'Save contact details', open: true);
	$options->group('Your lessons', ['lessons']);
	$options->group('Anything else?', ['notes', 'terms']);
	$options->requireConfirmation()->asDialog(confirm: 'Confirm booking', edit: 'Make changes');

	// Flow is stepped by default (one group per request). To render all groups on a
	// single page instead, add $options->asAccordion() (collapsible <details> per group)
	// or $options->asSinglePage() (a <fieldset> per group) and render with FormRenderer.

	return $options;
}

function page(string $body): string
{
	$style = <<<CSS
		body { font: 16px/1.5 system-ui, sans-serif; max-width: 34rem; margin: 3rem auto; padding: 0 1rem; }
		.field { margin: 0 0 1rem; }
		.field label { display: block; font-weight: 600; }
		input, select { font: inherit; padding: .35rem; min-width: 16rem; }
		.errors p { color: #b00020; margin: .25rem 0 0; }
		.wizard-nav, .mf-dialog-actions { display: flex; gap: .5rem; margin-top: 1rem; }
		button { font: inherit; padding: .45rem 1rem; cursor: pointer; }
		fieldset.collection { margin: 0 0 1rem; }
		.collection-item { border-left: 3px solid #ddd; padding-left: .75rem; margin-bottom: .75rem; }
		.wizard-summary dt { font-weight: 600; }
		.wizard-summary dd { margin: 0 0 .5rem; }
	CSS;

	$polyfill = '<script type="module" src="https://unpkg.com/invokers-polyfill/invoker.min.js"></script>';

	return "<!doctype html><html lang=\"en\"><head><meta charset=\"utf-8\">"
		. "<meta name=\"viewport\" content=\"width=device-width, initial-scale=1\">"
		. "<title>Book lessons</title><style>{$style}</style>{$polyfill}</head><body>{$body}</body></html>";
}

$form = new Form(buildSchema(), buildOptions(), new HiddenFieldStore());

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	$result = $form->handle($_POST);

	$body = $result->completed
		? '<h1>Booked!</h1><p>We received your booking:</p><pre>'
			. htmlspecialchars(json_encode($result->data, JSON_PRETTY_PRINT) ?: '', ENT_QUOTES)
			. '</pre><p><a href="/">Start again</a></p>'
		: $result->html;
} else {
	$body = $form->start();
}

echo page($body);
