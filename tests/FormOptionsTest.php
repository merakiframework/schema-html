<?php
declare(strict_types=1);

namespace Meraki\Schema\Html;

use Meraki\Schema\Html\Request\SequentialRowKeys;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\CoversClass;

#[Group('html')]
#[CoversClass(FormOptions::class)]
#[CoversClass(FieldOptions::class)]
#[CoversClass(Renderer::class)]
#[CoversClass(Messages::class)]
#[CoversClass(SequentialRowKeys::class)]
final class FormOptionsTest extends TestCase
{
	#[Test]
	public function it_builds_a_nested_options_array(): void
	{
		$options = (new FormOptions())->postTo('/signup');
		$options->configureOptionsFor('email')->label('Your email');
		$options->configureOptionsFor('bio')->renderAsTextarea()->readonly();

		$this->assertSame([
			'method' => 'post',
			'action' => '/signup',
			'fields' => [
				'email' => ['label' => 'Your email'],
				'bio' => ['renderer' => 'textarea', 'readonly' => true],
			],
		], $options->toArray());
	}

	#[Test]
	public function get_from_sets_method_and_action(): void
	{
		$options = (new FormOptions())->getFrom('/search');

		$this->assertSame(
			[
				'method' => 'get',
				'action' => '/search',
				'fields' => [],
			],
			$options->toArray()
		);
	}

	#[Test]
	public function can_configure_a_structured_fields_parts(): void
	{
		$options = new FormOptions();
		$options->configureOptionsFor('price')->configureOptionsFor('amount')->label('Enter Amount');
		$options->configureOptionsFor('price')->configureOptionsFor('currency')->renderAsDropdown()->labelOption('AUD', 'A$');

		$this->assertSame([
			'method' => 'post',
			'action' => '',
			'fields' => [
				'price' => [
					'amount' => ['label' => 'Enter Amount'],
					'currency' => [
						'renderer' => 'dropdown',
						'options' => [
							'AUD' => ['label' => 'A$']
						],
					],
				],
			],
		], $options->toArray());
	}

	#[Test]
	public function messages_settled_parts_and_row_keys_are_configurable(): void
	{
		$rowKeys = new SequentialRowKeys('line');
		$options = (new FormOptions())
			->withMessages('en-AU')
			->settledParts(SettledPart::Hidden)
			->withRowKeys($rowKeys);

		$this->assertSame('en-AU', $options->messages?->locale);
		$this->assertNull($options->messages?->provider);
		$this->assertSame(SettledPart::Hidden, $options->settledParts);
		$this->assertSame($rowKeys, $options->rowKeys);
	}

	#[Test]
	public function by_default_settled_parts_are_omitted_and_no_language_is_chosen(): void
	{
		$options = new FormOptions();

		$this->assertNull($options->messages);
		$this->assertSame(SettledPart::Omit, $options->settledParts);
	}

	#[Test]
	public function a_fields_settled_parts_can_be_configured_on_their_own(): void
	{
		$options = new FormOptions();
		$options->configureOptionsFor('billing')->settledParts(SettledPart::Visible);

		$this->assertSame(['billing' => ['settledParts' => 'visible']], $options->toArray()['fields']);
	}
}
