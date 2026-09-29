<?php
/**
 * Rendering a schema as an HTML form, then re-rendering it with validation errors.
 *
 * Run: php examples/render.php > form.html
 */
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Meraki\Schema\Facade;
use Meraki\Schema\Html\FormOptions;
use Meraki\Schema\Html\FormRenderer;
use Meraki\Schema\Html\Input;
use Meraki\Schema\Html\Request\PayloadMapper;
use Meraki\Schema\Message\Mf2\Mf2Provider;

// `for()` declares the region once: the address and phone number fields below both
// pick up Australian rules, so the address gets a four-digit postcode, a dropdown
// of the eight states labelled "State", a "Suburb" rather than a "City", and no
// country input at all — the country is settled, so it is left out of the form and
// filled back in when the submission is mapped for the schema.
$schema = (new Facade('booking'))->for('AU');

$schema->add(
	$schema->createNameField('full_name'),
	$schema->createEmailAddressField('email_address'),
	$schema->createPhoneNumberField('phone_number'),
	$schema->createAddressField('pickup_location'),
	$schema->createEnumField('transmission_type', ['automatic', 'manual'])->defaultsTo('automatic'),
	$schema->createBooleanField('use_own_vehicle')->makeOptional()->defaultsTo(false),
);

// Messages are required: the port chooses the language, the wording comes from a pack.
$options = (new FormOptions())
	->postTo('/bookings')
	->withMessages('en-AU', Mf2Provider::fromDirectory(__DIR__ . '/lang'));

// Per-field UI tweaks. Everything else — labels, autocomplete tokens, the postcode's
// pattern and numeric keyboard — is derived, so it does not need configuring.
$options->configure('transmission_type')->renderAsButtonGroup();
$options->configure('email_address')->label('Your email address');

$renderer = new FormRenderer();

echo "<!doctype html>\n<html><head><meta charset=\"utf-8\"><title>Booking</title></head><body>\n";

echo "<h1>Empty form</h1>\n";
echo $renderer->render($schema, $options), "\n";

// What the browser would POST for that form. The mapper turns it into what the schema
// validates: records for the phone number and address (with their settled country put
// back), and the checkbox's hidden "0" as false.
$submitted = new Input([
	'full_name' => 'Jane Doe',
	'email_address' => 'not-an-email',
	'phone_number' => ['number' => '0411 222 333'],
	'pickup_location' => [
		'line1' => '1 Queen St',
		'locality' => 'Brisbane',
		'administrative_area' => 'QLD',
		'postal_code' => 'not-a-postcode',
	],
	'transmission_type' => 'automatic',
	'use_own_vehicle' => '0',
]);

$result = $schema->validate((new PayloadMapper())->map($schema, $submitted));

// Passing the result back in surfaces the messages inline, against the individual
// parts that failed rather than the address as a whole.
echo "<h1>After validation</h1>\n";
echo $renderer->render($schema, $options, $result), "\n";

echo "</body></html>\n";
