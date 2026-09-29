<?php
declare(strict_types=1);

namespace Meraki\Schema\Html\Presentation\Layout;

use Meraki\Schema\Field;
use Meraki\Schema\Field\Address\Value;
use Meraki\Schema\Html\AddressVocabulary;
use Meraki\Schema\Html\Presentation\PartLayout;

/**
 * An address, asked for the way the countries it allows describe one.
 *
 * - **Labels follow the country** when exactly one is allowed ("Suburb", "State",
 *   "ZIP Code"); with several they generalise and the hint lists the alternatives.
 * - **Dropdowns show names and submit codes** — the country always, the state only when one
 *   country is allowed (with several, which subdivisions are valid depends on the choice).
 * - **Unused parts are left out.** Singapore has no state, Hong Kong no postcode, and asking
 *   for one is worse than not asking.
 * - **`pattern` and `inputmode`** on the postcode for a single allowed country; `numeric` only
 *   where the postcode really is digits.
 *
 * The country part is always listed; whether a *settled* one (a single allowed country) is drawn
 * is the form's choice ({@see \Meraki\Schema\Html\SettledPart}).
 */
final class AddressLayout implements PartLayout
{
	/** The parts that mean the same thing everywhere, and so keep one label. */
	private const DEFAULTS = [
		'organization' => ['label' => 'Organisation', 'autocompleteToken' => 'organization'],
		'line1' => ['label' => 'Address', 'autocompleteToken' => 'address-line1'],
		'line2' => ['label' => 'Apartment, unit, etc.', 'autocompleteToken' => 'address-line2'],
		'dependent_locality' => ['label' => 'Suburb', 'autocompleteToken' => 'address-level3'],
		'locality' => ['label' => 'City', 'autocompleteToken' => 'address-level2'],
		'administrative_area' => ['label' => 'Administrative Area', 'autocompleteToken' => 'address-level1'],
		'postal_code' => ['label' => 'Postal Code', 'autocompleteToken' => 'postal-code'],
		'country' => ['label' => 'Country', 'autocompleteToken' => 'country'],
	];

	public function __construct(private readonly AddressVocabulary $vocabulary = new AddressVocabulary())
	{
	}

	public function parts(Field $field, object $options): array
	{
		assert($field instanceof Field\Address);

		$countries = $field->allowedCountries;
		$vocabulary = $this->vocabulary;
		$required = $vocabulary->requiredParts($countries);
		$parts = [];

		foreach (Value::partNames() as $part) {
			if (!$vocabulary->isUsedByAny($part, $countries)) {
				continue;
			}

			$spec = self::DEFAULTS[$part] ?? ['label' => ucfirst(str_replace('_', ' ', $part))];

			if (($label = $vocabulary->labelFor($part, $countries)) !== null) {
				$spec['label'] = $label;
			}

			if (($hint = $vocabulary->hintFor($part, $countries)) !== null) {
				$spec['hint'] = $hint;
			}

			$spec['required'] = match ($part) {
				'country' => true,
				'line1' => $field->mustBeSpecific,
				default => in_array($part, $required, true),
			};

			// Postcode rules belong to one country; with several allowed, which applies is not
			// known until the country is chosen, so the server-side constraint does the work.
			if ($part === 'postal_code' && count($countries) === 1) {
				$pattern = $vocabulary->postalCodePatternFor($countries[0]);

				if ($pattern !== null) {
					$spec['pattern'] = $pattern;

					if ($vocabulary->postalCodeIsNumeric($pattern)) {
						$spec['inputmode'] = 'numeric';
					}
				}
			}

			if ($part === 'country') {
				$spec['widget'] = 'select';
				$spec['choices'] = $vocabulary->countryNames($countries);
			}

			if ($part === 'administrative_area' && count($countries) === 1) {
				$subdivisions = $vocabulary->subdivisionNames($countries[0]);

				if ($subdivisions !== []) {
					$spec['widget'] = 'select';
					$spec['choices'] = $subdivisions;
				}
			}

			$parts[$part] = $spec;
		}

		return $parts;
	}
}
