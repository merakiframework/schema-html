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

		$options = (new FormOptions($schema))->postTo('/signup');
		$options->pickField('email')->label('Your email');
		$options->pickField('bio')->renderAsTextarea()->readonly();

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
		$options = (new FormOptions(new Facade('search')))->getFrom('/search');

		$this->assertSame('get', $options->toArray()['method']);
		$this->assertSame('/search', $options->toArray()['action']);
	}

	#[Test]
	public function picking_an_unknown_field_throws(): void
	{
		$this->expectException(\InvalidArgumentException::class);

		(new FormOptions(new Facade('signup')))->pickField('nope');
	}

	#[Test]
	public function an_invalid_renderer_for_a_field_throws(): void
	{
		$schema = new Facade('signup');
		$schema->addEmailAddressField('email');

		$this->expectException(\LogicException::class);

		(new FormOptions($schema))->pickField('email')->renderAsDropdown();
	}

	#[Test]
	public function renderer_lists_valid_renderers_per_field_type(): void
	{
		$schema = new Facade('s');
		$schema->addTextField('bio');

		$valid = Renderer::validFor($schema->fields->getByName('bio'));

		$this->assertSame([Renderer::Text, Renderer::Textarea], $valid);
	}
}
