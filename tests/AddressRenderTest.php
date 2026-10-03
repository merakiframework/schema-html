<?php
declare(strict_types=1);

namespace Meraki\Schema\Html;

use Meraki\Schema\Definition;
use Meraki\Schema\Field\Address\Precision;
use Meraki\Schema\Html\Presentation\Layout\AddressLayout;
use Meraki\Schema\Html\Support\Forms;
use Meraki\Schema\Html\Theme\Renderer\StructuredFieldRenderer;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\CoversClass;

#[Group('html')]
#[CoversClass(FormRenderer::class)]
#[CoversClass(AddressVocabulary::class)]
#[CoversClass(AddressLayout::class)]
#[CoversClass(StructuredFieldRenderer::class)]
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
			'Australia calls it a state' => ['AU', 'subdivision', 'State'],
			'the United States calls it a city' => ['US', 'locality', 'City'],
			'the United States has ZIP codes' => ['US', 'postal_code', 'ZIP Code'],
			'Japan has prefectures' => ['JP', 'subdivision', 'Prefecture'],
			'Ireland has counties' => ['IE', 'subdivision', 'County'],
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

		$this->assertMatchesRegularExpression('/<select[^>]*name="billing\[subdivision\]"/', $html);
		$this->assertStringContainsString('<option value="AU-QLD">Queensland</option>', $html);
		$this->assertStringContainsString('<option value="AU-NSW">New South Wales</option>', $html);
	}

	/**
	 * Which subdivisions are valid depends on the country chosen, so with several allowed
	 * there is no closed set to put in a dropdown.
	 */
	#[Test]
	public function several_allowed_countries_render_the_subdivision_as_a_text_input(): void
	{
		$html = $this->render(['billing' => ['AU', 'CA']]);

		$this->assertMatchesRegularExpression('/<input[^>]*name="billing\[subdivision\]"/', $html);
		$this->assertMatchesRegularExpression('/<select[^>]*name="billing\[country\]"/', $html);
		$this->assertStringContainsString('<option value="AU">Australia</option>', $html);
	}

	/** The country dropdown offers only the countries the field allows. */
	#[Test]
	public function the_country_dropdown_is_limited_to_the_allowed_countries(): void
	{
		$html = $this->render(['billing' => ['AU', 'CA']]);

		$this->assertStringContainsString('<option value="CA">Canada</option>', $html);
		$this->assertStringNotContainsString('<option value="US">', $html);
	}

	// A country the whitelist has settled.

	#[Test]
	public function a_settled_country_is_left_out_of_the_form_by_default(): void
	{
		$html = $this->render(['billing' => 'AU']);

		$this->assertStringNotContainsString('name="billing[country]"', $html);
	}

	#[Test]
	public function a_settled_country_can_be_carried_in_a_hidden_input_instead(): void
	{
		$options = Forms::options()->settledParts(SettledPart::Hidden);

		$html = $this->render(['billing' => 'AU'], $options);

		$this->assertStringContainsString('<input type="hidden" name="billing[country]" value="AU">', $html);
	}

	#[Test]
	public function a_settled_country_can_be_shown_like_any_other_part(): void
	{
		$options = Forms::options();
		$options->configureOptionsFor('billing')->settledParts(SettledPart::Visible);

		$html = $this->render(['billing' => 'AU'], $options);

		$this->assertMatchesRegularExpression('/<select[^>]*name="billing\[country\]"/', $html);
		$this->assertStringContainsString('<option value="AU" selected>Australia</option>', $html);
	}

	/**
	 * Nothing uses every part. Asking a Singaporean for a state, or a Hong Konger for a
	 * postcode, is worse than not asking.
	 */
	#[Test]
	#[DataProvider('unusedParts')]
	public function it_leaves_out_a_part_no_allowed_country_uses(string $country, string $part): void
	{
		$html = $this->render(['billing' => $country]);

		$this->assertStringNotContainsString('name="billing[' . $part . ']"', $html);
	}

	public static function unusedParts(): array
	{
		return [
			'Singapore has no subdivision' => ['SG', 'subdivision'],
			'Hong Kong has no postal code' => ['HK', 'postal_code'],
			'Australia has no dependent locality' => ['AU', 'dependent_locality'],
		];
	}

	// Which parts are required.

	/**
	 * The street is one part holding a list of lines, drawn as one input per line. Only the
	 * first is ever required.
	 */
	#[Test]
	public function the_street_is_drawn_as_one_input_per_line(): void
	{
		$html = $this->render(['billing' => 'AU']);

		$this->assertMatchesRegularExpression('/name="billing\[street\]\[0\]"[^>]*required[^>]*autocomplete="address-line1"/', $html);
		$this->assertMatchesRegularExpression('/name="billing\[street\]\[1\]"[^>]*autocomplete="address-line2"/', $html);
		$this->assertDoesNotMatchRegularExpression('/name="billing\[street\]\[1\]"[^>]*required/', $html);
	}

	#[Test]
	public function the_street_lines_can_be_configured(): void
	{
		$options = Forms::options();
		$options->configureOptionsFor('billing')->configureOptionsFor('street')->lines('Street', 'Unit', 'Building');

		$html = $this->render(['billing' => 'AU'], $options);

		$this->assertMatchesRegularExpression('/>Street<\/label><input[^>]*name="billing\[street\]\[0\]"[^>]*autocomplete="address-line1"/', $html);
		$this->assertMatchesRegularExpression('/>Building<\/label><input[^>]*name="billing\[street\]\[2\]"[^>]*autocomplete="address-line3"/', $html);
	}

	/**
	 * A field that asks only for a locality is a service area, not a delivery address: the core
	 * would accept a street, but the form does not ask for one.
	 */
	#[Test]
	public function parts_below_the_fields_precision_are_not_asked_for(): void
	{
		$schema = new Definition('checkout');
		$schema->add($schema->createAddressField('area', ['AU'])->minPrecisionOf(Precision::Locality));

		$html = (new FormRenderer())->render($schema, Forms::options());

		$this->assertStringNotContainsString('name="area[street]', $html);
		$this->assertMatchesRegularExpression('/name="area\[locality\]"[^>]*required/', $html);
		$this->assertMatchesRegularExpression('/name="area\[postal_code\]"[^>]*required/', $html);
	}

	/** Marked from the core's own answer, so the form promises exactly what the server checks. */
	#[Test]
	public function the_parts_a_country_requires_are_marked_required(): void
	{
		$html = $this->render(['billing' => 'AU']);

		$this->assertMatchesRegularExpression('/name="billing\[locality\]"[^>]*required/', $html);
		$this->assertMatchesRegularExpression('/name="billing\[postal_code\]"[^>]*required/', $html);
		$this->assertMatchesRegularExpression('/name="billing\[subdivision\]"[^>]*required/', $html);
	}

	/** Japan does not require a locality, Australia does: only what both require is marked. */
	#[Test]
	public function only_what_every_allowed_country_requires_is_marked(): void
	{
		$html = $this->render(['billing' => ['AU', 'JP']]);

		$this->assertMatchesRegularExpression('/name="billing\[postal_code\]"[^>]*required/', $html);
		$this->assertDoesNotMatchRegularExpression('/name="billing\[locality\]"[^>]*required/', $html);
	}

	#[Test]
	public function nothing_is_marked_required_on_an_optional_address(): void
	{
		$schema = new Definition('checkout');
		$schema->add($schema->createAddressField('billing', ['AU'])->makeOptional());

		$html = (new FormRenderer())->render($schema, Forms::options());

		$this->assertDoesNotMatchRegularExpression('/name="billing\[[a-z0-9_]+\]"[^>]*required/', $html);
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

	/**
	 * Some of China's subdivisions replace its postcode pattern with their own, and the browser
	 * cannot know the subdivision yet: a `pattern` would refuse codes the server accepts.
	 */
	#[Test]
	public function it_hints_no_postal_code_format_where_a_subdivision_has_its_own(): void
	{
		$html = $this->render(['billing' => 'CN']);

		$this->assertMatchesRegularExpression('/name="billing\[postal_code\]"/', $html);
		$this->assertDoesNotMatchRegularExpression('/name="billing\[postal_code\]"[^>]*pattern=/', $html);
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
	public function it_reports_each_failure_against_the_part_it_is_about(): void
	{
		$schema = new Definition('checkout');
		$schema->add($schema->createAddressField('billing', ['AU'])->mustBeVisitable());

		$result = $schema->validate((object) ['billing' => (object) [
			'street' => ['PO Box 12'],
			'locality' => 'Brisbane',
			'subdivision' => 'QLD',
			'postal_code' => 'not-a-postcode',
			'country' => 'AU',
		]]);

		$html = (new FormRenderer())->render($schema, Forms::options(), $result);

		$this->assertMatchesRegularExpression(
			'/name="billing\[street\]\[0\]"[^>]*>(?:(?!data-name).)*a PO box or bag service is not somewhere that can be visited/s',
			$html,
		);
		$this->assertMatchesRegularExpression(
			'/name="billing\[postal_code\]"[^>]*>(?:(?!data-name).)*That is not a valid postal code for the country you chose\./s',
			$html,
		);
	}

	/** The country asks for a postcode, so leaving it out is reported against that box. */
	#[Test]
	public function a_required_part_that_was_left_out_is_reported_against_it(): void
	{
		$schema = new Definition('checkout');
		$schema->add($schema->createAddressField('billing', ['AU']));
		$wire = ['billing' => ['street' => ['1 Queen St', null], 'locality' => 'Brisbane', 'subdivision' => 'AU-QLD']];

		$result = $schema->validate((new Request\PayloadMapper())->map($schema, $wire));
		$html = (new FormRenderer())->render($schema, Forms::options(), $result);

		$this->assertMatchesRegularExpression(
			'/name="billing\[postal_code\]"[^>]*>(?:(?!data-name).)*Enter a postal code\./s',
			$html,
		);
		$this->assertMatchesRegularExpression('/name="billing\[street\]\[0\]"[^>]*value="1 Queen St"/', $html);
		$this->assertStringContainsString('<option value="AU-QLD" selected>Queensland</option>', $html);
	}

	/** A prefilled address is drawn from its value: each street line, and the subdivision's code. */
	#[Test]
	public function a_prefilled_address_draws_each_line(): void
	{
		$schema = new Definition('checkout');
		$schema->add($schema->createAddressField('billing', ['AU']));

		$result = $schema->resolve(prefilledWith: (object) ['billing' => (object) [
			'street' => ['Level 2', '1 Queen St'],
			'locality' => 'Brisbane',
			'subdivision' => 'Queensland',
			'postal_code' => '4000',
			'country' => 'AU',
		]]);
		$html = (new FormRenderer())->render($schema, Forms::options(), $result);

		$this->assertMatchesRegularExpression('/name="billing\[street\]\[0\]"[^>]*value="Level 2"/', $html);
		$this->assertMatchesRegularExpression('/name="billing\[street\]\[1\]"[^>]*value="1 Queen St"/', $html);
		$this->assertStringContainsString('<option value="AU-QLD" selected>Queensland</option>', $html);
	}

	#[Test]
	public function an_address_that_was_not_filled_in_reads_as_missing(): void
	{
		$schema = new Definition('checkout');
		$schema->add($schema->createAddressField('billing', ['AU']));

		$html = (new FormRenderer())->render($schema, Forms::options(), $schema->validate((object) []));

		$this->assertStringContainsString('<p>This is required.</p>', $html);
	}

	/**
	 * @param array<string, string|array<string>> $addressFields name => allowed countries
	 */
	private function render(array $addressFields, ?FormOptions $options = null): string
	{
		$schema = new Definition('checkout');

		foreach ($addressFields as $name => $countries) {
			$schema->add($schema->createAddressField($name, is_array($countries) ? $countries : [$countries]));
		}

		return (new FormRenderer())->render($schema, $options ?? Forms::options());
	}
}
