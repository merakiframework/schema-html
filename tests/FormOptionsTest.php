<?php
declare(strict_types=1);

namespace Meraki\Schema\Html;

use Meraki\Schema\Facade;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\CoversClass;

#[Group('html')]
#[CoversClass(FormOptions::class)]
#[CoversClass(FieldOptions::class)]
#[CoversClass(Renderer::class)]
final class FormOptionsTest extends TestCase
{
	#[Test]
	public function it_builds_a_nested_options_array(): void
	{
		$schema = new Facade('signup');
		$schema->addEmailAddressField('email');
		$schema->addTextField('bio');

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
	public function can_configure_composite_field_options(): void
	{
		$schema = new Facade('signup');
		$schema->addMoneyField('price', ['AUD' => 2]);

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
}
