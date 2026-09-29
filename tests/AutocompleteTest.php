<?php
declare(strict_types=1);

namespace Meraki\Schema\Html;

use Meraki\Schema\Facade;
use Meraki\Schema\Field;
use Meraki\Schema\Html\Presentation\FieldViewFactory;
use Meraki\Schema\Html\Support\Forms;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Autocomplete applies to every field, not just addresses: each type emits the semantic
 * token a browser needs to autofill it, and any field can opt out.
 */
#[Group('html')]
#[CoversClass(FormRenderer::class)]
#[CoversClass(FormOptionResolver::class)]
#[CoversClass(FieldOptions::class)]
#[CoversClass(FieldViewFactory::class)]
final class AutocompleteTest extends TestCase
{
	#[Test]
	#[DataProvider('fieldsWithTokens')]
	public function it_emits_the_semantic_token_for_a_field_type(string $method, string $expectedToken): void
	{
		$html = $this->render(fn(Facade $schema): Field => $schema->{$method}('subject'));

		$this->assertStringContainsString('autocomplete="' . $expectedToken . '"', $html);
	}

	public static function fieldsWithTokens(): array
	{
		return [
			'email address' => ['createEmailAddressField', 'email'],
			'phone number' => ['createPhoneNumberField', 'tel'],
			'name' => ['createNameField', 'name'],
			'uri' => ['createUriField', 'url'],
			'password' => ['createPasswordField', 'current-password'],
		];
	}

	#[Test]
	#[DataProvider('partsWithTokens')]
	public function it_emits_tokens_for_a_structured_fields_parts(string $method, string $part, string $expectedToken): void
	{
		$html = $this->render(fn(Facade $schema): Field => $schema->{$method}('subject'));

		$this->assertMatchesRegularExpression(
			'/name="subject\[' . preg_quote($part, '/') . '\]"[^>]*autocomplete="' . preg_quote($expectedToken, '/') . '"/',
			$html,
		);
	}

	/**
	 * A part means different things in different fields, so its token comes from the field it
	 * belongs to.
	 */
	public static function partsWithTokens(): array
	{
		return [
			'address line 1' => ['createAddressField', 'line1', 'address-line1'],
			'address line 2' => ['createAddressField', 'line2', 'address-line2'],
			'address locality' => ['createAddressField', 'locality', 'address-level2'],
			'address administrative area' => ['createAddressField', 'administrative_area', 'address-level1'],
			'address postal code' => ['createAddressField', 'postal_code', 'postal-code'],
			'address country' => ['createAddressField', 'country', 'country'],
			'card number' => ['createCreditCardField', 'number', 'cc-number'],
			'card holder' => ['createCreditCardField', 'name', 'cc-name'],
			'card expiry' => ['createCreditCardField', 'expiry', 'cc-exp'],
			'card security code' => ['createCreditCardField', 'security_code', 'cc-csc'],
		];
	}

	#[Test]
	public function it_emits_nothing_for_a_field_with_no_meaningful_token(): void
	{
		$html = $this->render(fn(Facade $schema): Field => $schema->createTextField('nickname'));

		$this->assertStringNotContainsString('autocomplete', $html);
	}

	#[Test]
	public function autofill_can_be_turned_off_for_a_field(): void
	{
		$options = Forms::options();
		$options->configureOptionsFor('email')->autocomplete(false);

		$html = $this->render(fn(Facade $schema): Field => $schema->createEmailAddressField('email'), $options);

		$this->assertStringContainsString('autocomplete="off"', $html);
		$this->assertStringNotContainsString('autocomplete="email"', $html);
	}

	#[Test]
	public function a_token_can_be_overridden(): void
	{
		$options = Forms::options();
		$options->configureOptionsFor('password')->autocomplete('new-password');

		$html = $this->render(fn(Facade $schema): Field => $schema->createPasswordField('password'), $options);

		$this->assertStringContainsString('autocomplete="new-password"', $html);
		$this->assertStringNotContainsString('autocomplete="current-password"', $html);
	}

	#[Test]
	public function a_parts_token_can_be_overridden(): void
	{
		$options = Forms::options();
		$options->configureOptionsFor('shipping')->configureOptionsFor('line1')->autocomplete('shipping address-line1');

		$html = $this->render(fn(Facade $schema): Field => $schema->createAddressField('shipping'), $options);

		$this->assertMatchesRegularExpression('/name="shipping\[line1\]"[^>]*autocomplete="shipping address-line1"/', $html);
	}

	/** `none` was never a valid value for the attribute; `off` is. */
	#[Test]
	public function a_dropdown_does_not_emit_an_invalid_autocomplete_value(): void
	{
		$options = Forms::options();
		$options->configureOptionsFor('plan')->renderAsDropdown();

		$html = $this->render(fn(Facade $schema): Field => $schema->createEnumField('plan', ['free', 'pro']), $options);

		$this->assertStringContainsString('<select', $html);
		$this->assertStringNotContainsString('autocomplete="none"', $html);
	}

	/**
	 * @param callable(Facade): Field $field
	 */
	private function render(callable $field, ?FormOptions $options = null): string
	{
		$schema = new Facade('signup');
		$schema->add($field($schema));

		return (new FormRenderer())->render($schema, $options ?? Forms::options());
	}
}
