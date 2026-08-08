<?php
declare(strict_types=1);

namespace Meraki\Schema\Html;

use CommerceGuys\Addressing\AddressFormat\AddressFormat;
use CommerceGuys\Addressing\AddressFormat\AddressFormatRepository;
use CommerceGuys\Addressing\Country\CountryRepository;
use CommerceGuys\Addressing\Subdivision\SubdivisionRepository;

/**
 * The words and lists needed to render a {@see \Meraki\Schema\Field\Address}.
 *
 * `meraki/schema` deliberately exposes none of this: what a country calls the thing in
 * the `administrative_area` box is a presentation concern, useless to a JSON serializer.
 * So schema-html reads the address's public `$allowed` country list and asks
 * `commerceguys/addressing` for the terms itself.
 *
 * The rule for labels, given the countries a field allows:
 *
 *  - exactly one term applies — use it, so Australia says "Suburb" and "State", Japan
 *    says "Prefecture", and the United States says "City", "State" and "ZIP Code";
 *  - none or several — use the neutral term as the label and list the alternatives as the
 *    field's hint, so allowing Australia and Canada gives "Administrative Area" hinted
 *    with "State or province".
 */
final class AddressVocabulary
{
	/**
	 * Every term libaddressinput uses, per sub-field. These are closed sets — the whole
	 * vocabulary of the upstream data — so a missing key means the data gained a term,
	 * not that a country is unusual.
	 */
	private const TERMS = [
		'administrative_area' => [
			'area' => 'Area',
			'county' => 'County',
			'department' => 'Department',
			'district' => 'District',
			'do_si' => 'Do/Si',
			'emirate' => 'Emirate',
			'island' => 'Island',
			'parish' => 'Parish',
			'prefecture' => 'Prefecture',
			'province' => 'Province',
			'region' => 'Region',
			'state' => 'State',
		],
		'locality' => [
			'city' => 'City',
			'district' => 'District',
			'post_town' => 'Post Town',
			'suburb' => 'Suburb',
			'town_city' => 'Town/City',
		],
		'dependent_locality' => [
			'district' => 'District',
			'neighborhood' => 'Neighbourhood',
			'suburb' => 'Suburb',
			'townland' => 'Townland',
			'village_township' => 'Village/Township',
		],
		'postal_code' => [
			'eircode' => 'Eircode',
			'pin' => 'PIN Code',
			'postal' => 'Postal Code',
			'zip' => 'ZIP Code',
		],
	];

	/** Used when no single country's term applies. */
	private const NEUTRAL_TERMS = [
		'administrative_area' => 'Administrative Area',
		'locality' => 'Locality',
		'dependent_locality' => 'Dependent Locality',
		'postal_code' => 'Postal Code',
	];

	/**
	 * Hints for a free-form address, where there is no country to take terms from. Kept
	 * deliberately broad, since the field really will accept anything.
	 */
	private const FREE_FORM_HINTS = [
		'administrative_area' => 'State, province, region, or territory',
		'locality' => 'City, town, or suburb',
		'dependent_locality' => 'Suburb, district, or neighbourhood',
		'postal_code' => 'Postal code, ZIP code, or postcode',
	];

	/**
	 * The label for a sub-field, or null to leave the caller's default alone (which is
	 * what the parts that mean the same thing everywhere — the street lines, the
	 * organisation, the country — want).
	 *
	 * @param array<string> $allowedCountries
	 */
	public function labelFor(string $localName, array $allowedCountries): ?string
	{
		if (!isset(self::NEUTRAL_TERMS[$localName])) {
			return null;
		}

		$terms = $this->termsFor($localName, $allowedCountries);

		return count($terms) === 1 ? reset($terms) : self::NEUTRAL_TERMS[$localName];
	}

	/**
	 * The hint for a sub-field: null when the label already names the thing precisely,
	 * otherwise the alternatives the label had to generalise over.
	 *
	 * @param array<string> $allowedCountries
	 */
	public function hintFor(string $localName, array $allowedCountries): ?string
	{
		if (!isset(self::NEUTRAL_TERMS[$localName])) {
			return null;
		}

		$terms = $this->termsFor($localName, $allowedCountries);

		// No terms with countries allowed means none of them uses this part at all (an
		// address restricted to Singapore has no administrative area), so there is nothing
		// to hint at — the renderer hides it. Only a free-form address gets the broad hint.
		if ($terms === []) {
			return $allowedCountries === [] ? self::FREE_FORM_HINTS[$localName] ?? null : null;
		}

		return count($terms) === 1 ? null : $this->asList($terms);
	}

	/**
	 * Whether any of the allowed countries uses a part at all. Nothing uses every part:
	 * Singapore has no administrative area, Hong Kong no postal code, and most countries
	 * no dependent locality.
	 *
	 * @param array<string> $allowedCountries
	 */
	public function isUsedByAny(string $localName, array $allowedCountries): bool
	{
		// A free-form address makes no claim about which parts apply, so all of them do.
		if ($allowedCountries === []) {
			return true;
		}

		foreach ($allowedCountries as $country) {
			if (in_array($localName, $this->usedPartsOf($country), true)) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The sub-fields a country's format actually uses, translated from libaddressinput's
	 * field names to ours. Parts with no upstream counterpart (`country_code`) or that we
	 * do not model (the person-name parts, `addressLine3`, `sortingCode`) are absent.
	 *
	 * @return array<string>
	 */
	private function usedPartsOf(string $country): array
	{
		$map = [
			'organization' => 'organization',
			'addressLine1' => 'line1',
			'addressLine2' => 'line2',
			'dependentLocality' => 'dependent_locality',
			'locality' => 'locality',
			'administrativeArea' => 'administrative_area',
			'postalCode' => 'postal_code',
		];

		$used = ['country_code'];

		foreach (self::formats()->get($country)->getUsedFields() as $field) {
			if (isset($map[$field])) {
				$used[] = $map[$field];
			}
		}

		return $used;
	}

	/**
	 * The distinct terms the allowed countries use for a sub-field. A country that does
	 * not use the part at all contributes nothing — Singapore has no administrative area,
	 * so allowing only Singapore yields no term for it.
	 *
	 * @param array<string> $allowedCountries
	 * @return array<string>
	 */
	private function termsFor(string $localName, array $allowedCountries): array
	{
		$terms = [];

		foreach ($allowedCountries as $country) {
			$key = $this->termKeyFor($localName, self::formats()->get($country));

			if ($key !== null && isset(self::TERMS[$localName][$key])) {
				$terms[$key] = self::TERMS[$localName][$key];
			}
		}

		return $terms;
	}

	/**
	 * libaddressinput returns the term for a part the country uses (substituting its own
	 * default when the country's entry is silent) and null for a part it does not.
	 */
	private function termKeyFor(string $localName, AddressFormat $format): ?string
	{
		return match ($localName) {
			'administrative_area' => $format->getAdministrativeAreaType(),
			'locality' => $format->getLocalityType(),
			'dependent_locality' => $format->getDependentLocalityType(),
			'postal_code' => $format->getPostalCodeType(),
			default => null,
		};
	}

	/**
	 * "State", "State or province", "State, province or prefecture" — only the first term
	 * keeps its capital, since the rest are mid-sentence.
	 *
	 * @param array<string> $terms
	 */
	private function asList(array $terms): string
	{
		$terms = array_values($terms);
		$terms = [array_shift($terms), ...array_map(lcfirst(...), $terms)];
		$last = array_pop($terms);

		return $terms === [] ? $last : implode(', ', $terms) . ' or ' . $last;
	}

	/**
	 * Country names for the country dropdown's option labels, so it submits `AU` while
	 * showing "Australia".
	 *
	 * @return array<string, string> code => name
	 */
	public function countryNames(): array
	{
		return self::countries()->getList();
	}

	/**
	 * Subdivision names for the administrative-area dropdown, so it submits `QLD` while
	 * showing "Queensland". Empty for a country with none on file.
	 *
	 * @return array<string, string> code => name
	 */
	public function subdivisionNames(string $country): array
	{
		return self::subdivisions()->getList([$country]);
	}

	/** The country's postal code pattern, or null if it has no postal codes at all. */
	public function postalCodePatternFor(string $country): ?string
	{
		return self::formats()->get($country)->getPostalCodePattern();
	}

	/**
	 * Whether a postal code pattern accepts nothing but digits, and so can safely be given
	 * a numeric on-screen keyboard.
	 *
	 * Deliberately conservative: plenty of postal codes are alphanumeric (Canada's
	 * `[ABCEGHJKLMNPRSTVXY]\d…`, Ireland's eircode, the Netherlands' `\d{4} ?[A-Z]{2}`),
	 * and a numeric keyboard on those cannot type the value at all. Anything that is not
	 * obviously digits-only is treated as not numeric.
	 */
	public function postalCodeIsNumeric(string $pattern): bool
	{
		// Take out the digit class, then look for a letter. One anywhere in what remains —
		// literal, or inside a character class like `[ABCEGHJKLMNPRSTVXY]` or `[\dA-Z]` —
		// means the code can contain letters, and a numeric keyboard could not type it.
		return preg_match('/[A-Za-z]/', str_replace('\\d', '', $pattern)) !== 1;
	}

	private static function formats(): AddressFormatRepository
	{
		static $repository = null;

		return $repository ??= new AddressFormatRepository();
	}

	private static function subdivisions(): SubdivisionRepository
	{
		static $repository = null;

		return $repository ??= new SubdivisionRepository();
	}

	private static function countries(): CountryRepository
	{
		static $repository = null;

		return $repository ??= new CountryRepository();
	}
}
