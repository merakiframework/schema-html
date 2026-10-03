<?php
declare(strict_types=1);

namespace Meraki\Schema\Html\Presentation\Layout;

use Meraki\Schema\Field;
use Meraki\Schema\Field\Address\Requirements;
use Meraki\Schema\Field\Address\Value;
use Meraki\Schema\Html\AddressVocabulary;
use Meraki\Schema\Html\Presentation\PartLayout;

/**
 * An address, asked for the way the countries it allows describe one.
 *
 * What is asked, and what is marked required, is the core's answer
 * ({@see Field\Address::requirementsFor()}), so the form never promises less or more than the
 * server checks:
 *
 * - **Required parts** are the ones every allowed country requires at the field's precision.
 *   With any country allowed nothing beyond the country can be known in advance, so nothing else
 *   is marked; the server still judges the submitted country's rules.
 * - **Unused parts are left out.** Singapore has no state, Hong Kong no postcode, and asking
 *   for one is worse than not asking.
 * - **Parts below the field's precision are left out.** A field asking only for a locality
 *   ({@see Field\Address::minPrecisionOf()}) is a service area, not a delivery address; the core
 *   would accept a street, but the form does not ask for one.
 * - **Dropdowns show names and submit codes**: the country always, the subdivision when one
 *   country is allowed (with several, which subdivisions are valid depends on the choice).
 * - **`pattern` and `inputmode`** on the postcode for a single allowed country, unless one of its
 *   subdivisions has a pattern of its own; `numeric` only where the postcode really is digits.
 *
 * Words come from {@see AddressVocabulary}: labels follow the country when exactly one is
 * allowed ("Suburb", "State", "ZIP Code"); with several they generalise and the hint lists the
 * alternatives.
 *
 * The street is one part holding a list of lines, drawn as one textarea (`street-address`, the
 * autofill token for exactly that) showing as many rows as the core accepts lines.
 *
 * The country part is always listed; whether a *settled* one (a single allowed country) is drawn
 * is the form's choice ({@see \Meraki\Schema\Html\SettledPart}).
 */
final class AddressLayout implements PartLayout
{
	/** The parts that mean the same thing everywhere, and so keep one label. */
	private const DEFAULTS = [
		'street' => ['label' => 'Address', 'widget' => 'textarea', 'autocompleteToken' => 'street-address'],
		'dependent_locality' => ['label' => 'Suburb', 'autocompleteToken' => 'address-level3'],
		'locality' => ['label' => 'City', 'autocompleteToken' => 'address-level2'],
		'subdivision' => ['label' => 'Administrative Area', 'autocompleteToken' => 'address-level1'],
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
		$requirements = $countries === [] ? [] : array_values($field->requirementsFor());
		$used = self::usedBy($requirements);
		$required = self::requiredByAll($requirements);
		$only = count($requirements) === 1 ? $requirements[0] : null;
		$parts = [];

		foreach (Value::partNames() as $part) {
			if (!$field->precision->covers($part) || ($used !== null && !in_array($part, $used, true))) {
				continue;
			}

			$spec = self::DEFAULTS[$part] ?? ['label' => ucfirst(str_replace('_', ' ', $part))];

			if (($label = $this->vocabulary->labelFor($part, $countries)) !== null) {
				$spec['label'] = $label;
			}

			if (($hint = $this->vocabulary->hintFor($part, $countries)) !== null) {
				$spec['hint'] = $hint;
			}

			$spec['required'] = $part === 'country' || in_array($part, $required, true);

			if ($part === 'street') {
				$spec['rows'] = $only?->streetLineLimit ?? Requirements::genericStreetLineLimit();
			}

			if ($part === 'postal_code' && $only !== null) {
				$spec += $this->postalCodeHints($only);
			}

			if ($part === 'country') {
				$spec['widget'] = 'select';
				$spec['choices'] = $this->vocabulary->countryNames($countries);
			}

			if ($part === 'subdivision' && $only !== null && $only->subdivisions !== []) {
				$spec['widget'] = 'select';
				$spec['choices'] = $only->subdivisions;
			}

			$parts[$part] = $spec;
		}

		return $parts;
	}

	/**
	 * The parts any allowed country uses, plus the country; null when any country is allowed, so
	 * every part may apply.
	 *
	 * @param list<Requirements> $requirements
	 * @return list<string>|null
	 */
	private static function usedBy(array $requirements): ?array
	{
		if ($requirements === []) {
			return null;
		}

		$used = ['country'];

		foreach ($requirements as $country) {
			$used = [...$used, ...$country->usedParts];
		}

		return array_values(array_unique($used));
	}

	/**
	 * The parts every allowed country requires — an intersection, so the `required` marker never
	 * over-promises when countries disagree.
	 *
	 * @param list<Requirements> $requirements
	 * @return list<string>
	 */
	private static function requiredByAll(array $requirements): array
	{
		$required = null;

		foreach ($requirements as $country) {
			$required = $required === null
				? $country->requiredParts
				: array_values(array_intersect($required, $country->requiredParts));
		}

		return $required ?? [];
	}

	/**
	 * Client-side hints for the postcode of the one allowed country. Left off where a subdivision
	 * replaces the country's pattern with its own (parts of China and Colombia): the browser
	 * cannot know the subdivision yet, and would refuse codes the server accepts.
	 *
	 * @return array<string, string>
	 */
	private function postalCodeHints(Requirements $country): array
	{
		$pattern = $country->postalCodeFormat;

		if ($pattern === null || $country->postalCodeFormatOverrides !== []) {
			return [];
		}

		return $this->vocabulary->postalCodeIsNumeric($pattern)
			? ['pattern' => $pattern, 'inputmode' => 'numeric']
			: ['pattern' => $pattern];
	}
}
