<?php
declare(strict_types=1);

namespace Meraki\Schema\Html;

use Meraki\Schema\Facade;
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
final class AutocompleteTest extends TestCase
{
	#[Test]
	#[DataProvider('fieldsWithTokens')]
	public function it_emits_the_semantic_token_for_a_field_type(string $method, string $expectedToken): void
	{
		$schema = new Facade('signup');
		$schema->{$method}('subject');

		$this->assertStringContainsString(
			'autocomplete="' . $expectedToken . '"',
			(new FormRenderer())->render($schema),
		);
	}

	public static function fieldsWithTokens(): array
	{
		return [
			'email address' => ['addEmailAddressField', 'email'],
			'phone number' => ['addPhoneNumberField', 'tel'],
			'name' => ['addNameField', 'name'],
			'uri' => ['addUriField', 'url'],
			'password' => ['addPasswordField', 'current-password'],
			'passphrase' => ['addPassphraseField', 'current-password'],
		];
	}

	#[Test]
	#[DataProvider('compositePartsWithTokens')]
	public function it_emits_tokens_for_a_composites_parts(string $method, string $part, string $expectedToken): void
	{
		$schema = new Facade('checkout');
		$schema->{$method}('subject');

		$html = (new FormRenderer())->render($schema);

		$this->assertMatchesRegularExpression(
			'/name="subject\[' . preg_quote($part, '/') . '\]"[^>]*autocomplete="' . preg_quote($expectedToken, '/') . '"/',
			$html,
		);
	}

	/**
	 * A Field\Text means different things in different parents, so the token has to come
	 * from the composite it sits in rather than from its own type.
	 */
	public static function compositePartsWithTokens(): array
	{
		return [
			'address line 1' => ['addAddressField', 'line1', 'address-line1'],
			'address line 2' => ['addAddressField', 'line2', 'address-line2'],
			'address locality' => ['addAddressField', 'locality', 'address-level2'],
			'address administrative area' => ['addAddressField', 'administrative_area', 'address-level1'],
			'address postal code' => ['addAddressField', 'postal_code', 'postal-code'],
			'address country' => ['addAddressField', 'country_code', 'country'],
			'card number' => ['addCreditCardField', 'number', 'cc-number'],
			'card holder' => ['addCreditCardField', 'holder', 'cc-name'],
			'card expiry' => ['addCreditCardField', 'expiry', 'cc-exp'],
			'card security code' => ['addCreditCardField', 'security_code', 'cc-csc'],
		];
	}

	#[Test]
	public function it_emits_nothing_for_a_field_with_no_meaningful_token(): void
	{
		$schema = new Facade('signup');
		$schema->addTextField('nickname');

		$this->assertStringNotContainsString('autocomplete', (new FormRenderer())->render($schema));
	}

	#[Test]
	public function autofill_can_be_turned_off_for_a_field(): void
	{
		$schema = new Facade('signup');
		$schema->addEmailAddressField('email');

		$options = new FormOptions();
		$options->configureOptionsFor('email')->autocomplete(false);

		$html = (new FormRenderer())->render($schema, $options);

		$this->assertStringContainsString('autocomplete="off"', $html);
		$this->assertStringNotContainsString('autocomplete="email"', $html);
	}

	#[Test]
	public function a_token_can_be_overridden(): void
	{
		$schema = new Facade('signup');
		$schema->addPasswordField('password');

		$options = new FormOptions();
		$options->configureOptionsFor('password')->autocomplete('new-password');

		$html = (new FormRenderer())->render($schema, $options);

		$this->assertStringContainsString('autocomplete="new-password"', $html);
		$this->assertStringNotContainsString('autocomplete="current-password"', $html);
	}

	/** `none` was never a valid value for the attribute; `off` is. */
	#[Test]
	public function a_dropdown_does_not_emit_an_invalid_autocomplete_value(): void
	{
		$schema = new Facade('signup');
		$schema->addEnumField('plan', ['free', 'pro']);

		$options = new FormOptions();
		$options->configureOptionsFor('plan')->renderAsDropdown();

		$html = (new FormRenderer())->render($schema, $options);

		$this->assertStringContainsString('<select', $html);
		$this->assertStringNotContainsString('autocomplete="none"', $html);
	}
}
