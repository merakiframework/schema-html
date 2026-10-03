<?php
declare(strict_types=1);

namespace Meraki\Schema\Html;

use CommerceGuys\Addressing\AddressFormat\AddressFormat;
use CommerceGuys\Addressing\AddressFormat\AddressFormatRepository;
use CommerceGuys\Addressing\Country\CountryRepository;

/**
 * The words needed to render a {@see \Meraki\Schema\Field\Address}, and the country names a
 * {@see \Meraki\Schema\Field\PhoneNumber} offers.
 *
 * Only words. What a country *asks for* — the parts it requires and uses, its subdivisions, its
 * postcode pattern — is the core's, read from `Address::requirementsFor()`, so the form marks
 * exactly what the server will insist on. What a country *calls* the thing in the `subdivision`
 * box is a presentation concern the core deliberately leaves out, so it is read from
 * `commerceguys/addressing` here.
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
		'subdivision' => [
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
		'subdivision' => 'Administrative Area',
		'locality' => 'Locality',
		'dependent_locality' => 'Dependent Locality',
		'postal_code' => 'Postal Code',
	];

	/**
	 * Hints for a free-form address, where there is no country to take terms from. Kept
	 * deliberately broad, since the field really will accept anything.
	 */
	private const FREE_FORM_HINTS = [
		'subdivision' => 'State, province, region, or territory',
		'locality' => 'City, town, or suburb',
		'dependent_locality' => 'Suburb, district, or neighbourhood',
		'postal_code' => 'Postal code, ZIP code, or postcode',
	];

	/**
	 * The label for a sub-field, or null to leave the caller's default alone (which is
	 * what the parts that mean the same thing everywhere — the street, the country — want).
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
		// address restricted to Singapore has no subdivision), so there is nothing
		// to hint at — the layout leaves it out. Only a free-form address gets the broad hint.
		if ($terms === []) {
			return $allowedCountries === [] ? self::FREE_FORM_HINTS[$localName] ?? null : null;
		}

		return count($terms) === 1 ? null : $this->asList($terms);
	}

	/**
	 * The distinct terms the allowed countries use for a sub-field. A country that does
	 * not use the part at all contributes nothing — Singapore has no subdivision,
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
			'subdivision' => $format->getAdministrativeAreaType(),
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
	 * showing "Australia". Limited to the given codes when there are any, in name order.
	 *
	 * @param array<string> $only
	 * @return array<string, string> code => name
	 */
	public function countryNames(array $only = []): array
	{
		$all = self::countries()->getList();

		return $only === [] ? $all : array_intersect_key($all, array_flip($only));
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

	private static function countries(): CountryRepository
	{
		static $repository = null;

		return $repository ??= new CountryRepository();
	}
}
