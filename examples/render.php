<?php
/**
 * Rendering a schema as an HTML form, then re-rendering it with validation errors.
 *
 * Run: php examples/render.php > form.html
 */
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Meraki\Schema\Facade;
use Meraki\Schema\Field;
use Meraki\Schema\Html\FormOptions;
use Meraki\Schema\Html\FormRenderer;

// `for()` declares the region once: the address and phone number fields below both
// pick up Australian rules, so the address gets a four-digit postcode, a dropdown
// of the eight states labelled "State", a "Suburb" rather than a "City", and no
// country input at all — the country is settled, hidden, and still submitted.
$schema = (new Facade('booking'))->for('AU');

$schema->addNameField('full_name')->minLengthOf(1)->maxLengthOf(255);
$schema->addEmailAddressField('email_address');
$schema->addPhoneNumberField('phone_number');
$schema->addAddressField('pickup_location');

$schema->addEnumField(
	'transmission_type',
	['automatic', 'manual'],
	fn(Field\Enum $type): Field\Enum => $type->prefill('automatic')
);

$schema->addBooleanField('use_own_vehicle')->makeOptional()->prefill(false);

$options = (new FormOptions())->postTo('/bookings');

// Per-field UI tweaks. Everything else — labels, autocomplete tokens, the postcode's
// pattern and numeric keyboard — is derived, so it does not need configuring.
$options->configure('transmission_type')->renderAsButtonGroup();
$options->configure('email_address')->label('Your email address');

$renderer = new FormRenderer();

echo "<!doctype html>\n<html><head><meta charset=\"utf-8\"><title>Booking</title></head><body>\n";

echo "<h1>Empty form</h1>\n";
echo $renderer->render($schema, $options), "\n";

// Passing a validation result back in surfaces the messages inline, against the
// individual parts that failed rather than the address as a whole.
$result = $schema->validate([
	'full_name' => 'Jane Doe',
	'email_address' => 'not-an-email',
	'phone_number' => '0411 222 333',
	'pickup_location' => [
		'line1' => '1 Queen St',
		'locality' => 'Brisbane',
		'administrative_area' => 'QLD',
		'postal_code' => 'not-a-postcode',
	],
	'transmission_type' => 'automatic',
]);

echo "<h1>After validation</h1>\n";
echo $renderer->render($schema, $options, $result), "\n";

echo "</body></html>\n";
