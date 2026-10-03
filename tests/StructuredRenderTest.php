<?php
declare(strict_types=1);

namespace Meraki\Schema\Html;

use Meraki\Schema\Definition;
use Meraki\Schema\Html\Presentation\Layout\CreditCardLayout;
use Meraki\Schema\Html\Presentation\Layout\MoneyLayout;
use Meraki\Schema\Html\Presentation\Layout\PhoneNumberLayout;
use Meraki\Schema\Html\Support\Forms;
use Meraki\Schema\Html\Theme\Renderer\StructuredFieldRenderer;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\CoversClass;

#[Group('html')]
#[CoversClass(StructuredFieldRenderer::class)]
#[CoversClass(MoneyLayout::class)]
#[CoversClass(PhoneNumberLayout::class)]
#[CoversClass(CreditCardLayout::class)]
final class StructuredRenderTest extends TestCase
{
	private function render(Definition $schema, ?FormOptions $options = null, ?object $data = null): string
	{
		return (new FormRenderer())->render(
			$schema,
			$options ?? Forms::options(),
			$data === null ? null : $schema->validate($data),
		);
	}

	/** With only the amount left to fill in, a fieldset around one input would be noise. */
	#[Test]
	public function money_in_one_currency_draws_as_a_single_amount_input(): void
	{
		$schema = new Definition('checkout');
		$schema->add($schema->createMoneyField('price', ['AUD']));

		$html = $this->render($schema);

		$this->assertMatchesRegularExpression('/<label for="[^"]+">Price<\/label><input type="text"[^>]*name="price\[amount\]"[^>]*inputmode="decimal"/', $html);
		$this->assertStringNotContainsString('price[currency]', $html);
		$this->assertStringNotContainsString('<fieldset', $html);
	}

	#[Test]
	public function money_in_several_currencies_asks_for_the_currency_too(): void
	{
		$schema = new Definition('checkout');
		$schema->add($schema->createMoneyField('price', ['AUD', 'NZD']));

		$options = Forms::options();
		$options->configureOptionsFor('price')->configureOptionsFor('currency')->labelOption('AUD', 'A$');

		$html = $this->render($schema, $options);

		$this->assertStringContainsString('<legend>Price</legend>', $html);
		$this->assertMatchesRegularExpression('/<select[^>]*name="price\[currency\]"/', $html);
		$this->assertStringContainsString('<option value="AUD">A$</option>', $html);
		$this->assertStringContainsString('<option value="NZD">NZD</option>', $html);
	}

	#[Test]
	public function a_phone_number_in_one_country_draws_as_a_single_tel_input(): void
	{
		$schema = new Definition('contact');
		$schema->add($schema->createPhoneNumberField('mobile', ['AU']));

		$html = $this->render($schema);

		$this->assertMatchesRegularExpression('/<input type="tel"[^>]*name="mobile\[number\]"[^>]*required[^>]*autocomplete="tel"/', $html);
		$this->assertStringNotContainsString('mobile[country]', $html);
	}

	#[Test]
	public function a_phone_number_from_anywhere_asks_which_country(): void
	{
		$schema = new Definition('contact');
		$schema->add($schema->createPhoneNumberField('mobile'));

		$html = $this->render($schema);

		$this->assertMatchesRegularExpression('/<select[^>]*name="mobile\[country\]"/', $html);
		$this->assertStringContainsString('<option value="AU">Australia</option>', $html);
		$this->assertMatchesRegularExpression('/<input type="tel"[^>]*name="mobile\[number\]"/', $html);
	}

	#[Test]
	public function a_cards_expiry_is_a_month_input(): void
	{
		$schema = new Definition('checkout');
		$schema->add($schema->createCreditCardField('card'));

		$html = $this->render($schema);

		$this->assertMatchesRegularExpression('/<input type="month"[^>]*name="card\[expiry\]"/', $html);
	}

	#[Test]
	public function a_cards_number_and_security_code_are_never_written_back(): void
	{
		$schema = new Definition('checkout');
		$schema->add($schema->createCreditCardField('card'));

		$html = $this->render($schema, data: (object) ['card' => (object) [
			'number' => '4111111111111112',
			'expiry' => '2020-01',
			'name' => 'Jane Citizen',
			'security_code' => '123',
		]]);

		$this->assertStringNotContainsString('4111111111111112', $html);
		$this->assertStringNotContainsString('value="123"', $html);
		$this->assertStringContainsString('value="Jane Citizen"', $html);
		$this->assertStringContainsString('value="2020-01"', $html);
	}
}
