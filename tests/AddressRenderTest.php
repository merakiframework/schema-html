<?php
declare(strict_types=1);

namespace Meraki\Schema\Html;

use Meraki\Schema\Facade;
use Meraki\Schema\Field\Address\Type as AddressType;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\CoversClass;

#[Group('html')]
#[CoversClass(FormRenderer::class)]
#[CoversClass(AddressVocabulary::class)]
#[CoversClass(ValidationMessages::class)]
final class AddressRenderTest extends TestCase
{
	// Labels follow the country when exactly one is allowed.

	#[Test]
	#[DataProvider('countrySpecificLabels')]
	public function it_labels_a_part_the_way_the_single_allowed_country_does(string $country, string $part, string $expected): void
	{
		$html = $this->render(['billing' => $country]);

		$this->assertMatchesRegularExpression(
			'/<label[^>]*>' . preg_quote($expected, '/') . '<\/label><(?:input|select)[^>]*name="billing\[' . $part . '\]"/',
			$html,
		);
	}

	public static function countrySpecificLabels(): array
	{
		return [
			'Australia calls it a suburb' => ['AU', 'locality', 'Suburb'],
			'Australia calls it a state' => ['AU', 'administrative_area', 'State'],
			'the United States calls it a city' => ['US', 'locality', 'City'],
			'the United States has ZIP codes' => ['US', 'postal_code', 'ZIP Code'],
			'Japan has prefectures' => ['JP', 'administrative_area', 'Prefecture'],
			'Ireland has counties' => ['IE', 'administrative_area', 'County'],
			'Ireland has eircodes' => ['IE', 'postal_code', 'Eircode'],
		];
	}

	/**
	 * With several countries allowed no single term is correct, so the label generalises
	 * and the hint carries the alternatives.
	 */
	#[Test]
	public function it_generalises_the_label_and_hints_the_terms_when_several_countries_are_allowed(): void
	{
		$html = $this->render(['billing' => ['AU', 'CA']]);

		$this->assertStringContainsString('>Administrative Area</label>', $html);
		$this->assertStringContainsString('placeholder="State or province"', $html);
	}

	#[Test]
	public function a_free_form_address_hints_broadly(): void
	{
		$html = $this->render(['billing' => []]);

		$this->assertStringContainsString('>Administrative Area</label>', $html);
		$this->assertStringContainsString('placeholder="State, province, region, or territory"', $html);
	}

	// Dropdowns.

	#[Test]
	public function a_single_allowed_country_renders_its_subdivisions_as_a_dropdown_of_names(): void
	{
		$html = $this->render(['billing' => 'AU']);

		$this->assertMatchesRegularExpression('/<select[^>]*name="billing\[administrative_area\]"/', $html);
		$this->assertStringContainsString('<option value="QLD">Queensland</option>', $html);
		$this->assertStringContainsString('<option value="NSW">New South Wales</option>', $html);
	}

	/**
	 * Which subdivisions are valid depends on the country chosen, so with several allowed
	 * there is no closed set to put in a dropdown.
	 */
	#[Test]
	public function several_allowed_countries_render_the_subdivision_as_a_text_input(): void
	{
		$html = $this->render(['billing' => ['AU', 'CA']]);

		$this->assertMatchesRegularExpression('/<input[^>]*name="billing\[administrative_area\]"/', $html);
		$this->assertMatchesRegularExpression('/<select[^>]*name="billing\[country_code\]"/', $html);
		$this->assertStringContainsString('<option value="AU">Australia</option>', $html);
	}

	// A country the whitelist has settled.

	#[Test]
	public function a_determined_country_is_hidden_but_still_submitted(): void
	{
		$html = $this->render(['billing' => 'AU']);

		// hidden on the wrapper as well, so it is gone for screen readers too
		$this->assertMatchesRegularExpression('/data-name="billing\.country_code" hidden/', $html);
		// ...yet the select is present and carries the value, so the country is submitted
		$this->assertMatchesRegularExpression('/<select[^>]*name="billing\[country_code\]"[^>]*hidden/', $html);
		$this->assertStringContainsString('<option value="AU" selected>Australia</option>', $html);
	}

	/**
	 * Nothing uses every part. Asking a Singaporean for a state, or a Hong Konger for a
	 * postcode, is worse than not asking.
	 */
	#[Test]
	#[DataProvider('unusedParts')]
	public function it_hides_a_part_no_allowed_country_uses(string $country, string $part): void
	{
		$html = $this->render(['billing' => $country]);

		$this->assertMatchesRegularExpression('/data-name="billing\.' . $part . '" hidden/', $html);
	}

	public static function unusedParts(): array
	{
		return [
			'Singapore has no administrative area' => ['SG', 'administrative_area'],
			'Hong Kong has no postal code' => ['HK', 'postal_code'],
			'Australia has no dependent locality' => ['AU', 'dependent_locality'],
		];
	}

	// Postal code input hints.

	#[Test]
	public function it_hints_the_postal_code_format_for_a_single_allowed_country(): void
	{
		$html = $this->render(['billing' => 'AU']);

		$this->assertMatchesRegularExpression(
			'/name="billing\[postal_code\]"[^>]*pattern="\\\\d\{4\}"[^>]*inputmode="numeric"/',
			$html,
		);
	}

	/**
	 * A numeric keyboard cannot type a letter, so it must only appear where the postal code
	 * really is digits-only.
	 */
	#[Test]
	#[DataProvider('countriesWithAlphanumericPostalCodes')]
	public function it_does_not_ask_for_a_numeric_keyboard_where_postal_codes_contain_letters(string $country): void
	{
		$this->assertStringNotContainsString('inputmode="numeric"', $this->render(['billing' => $country]));
	}

	public static function countriesWithAlphanumericPostalCodes(): array
	{
		return [['CA'], ['IE'], ['NL'], ['GB']];
	}

	/** With several countries allowed we cannot know whose format applies yet. */
	#[Test]
	public function it_hints_no_postal_code_format_when_several_countries_are_allowed(): void
	{
		$html = $this->render(['billing' => ['AU', 'CA']]);

		$this->assertDoesNotMatchRegularExpression('/name="billing\[postal_code\]"[^>]*pattern=/', $html);
	}

	// Messages.

	#[Test]
	public function it_reports_address_failures_in_terms_of_the_address_not_the_underlying_types(): void
	{
		$schema = new Facade('checkout');
		$schema->addAddressField('billing', ['AU'])->ofType(AddressType::Physical);

		$result = $schema->validate(['billing' => [
			'line1' => 'PO Box 12',
			'administrative_area' => 'QLD',
			'postal_code' => 'not-a-postcode',
		]]);

		$html = (new FormRenderer())->render($schema, null, $result);

		$this->assertStringContainsString('a PO box or bag service is not somewhere that can be visited', $html);
		$this->assertStringContainsString('Enter a valid postal code for the country selected', $html);
		// the omitted suburb reads as missing, not as a type mismatch
		$this->assertStringContainsString('This is required', $html);
		$this->assertStringNotContainsString('A valid text must be a string', $html);
	}

	/** @param array<string, string|array<string>> $addressFields name => allowed countries */
	private function render(array $addressFields): string
	{
		$schema = new Facade('checkout');

		foreach ($addressFields as $name => $countries) {
			$schema->addAddressField($name, is_array($countries) ? $countries : [$countries]);
		}

		return (new FormRenderer())->render($schema);
	}
}
