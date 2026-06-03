<?php
declare(strict_types=1);

namespace Meraki\Schema\Html;

use Meraki\Schema\Facade;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\CoversClass;

#[Group('html')]
#[CoversClass(SchemaHtmlRenderer::class)]
final class SchemaHtmlRendererTest extends TestCase
{
	#[Test]
	public function it_renders_a_form_with_an_input_per_field(): void
	{
		$schema = new Facade('signup');
		$schema->addNameField('full_name')->minLengthOf(1)->maxLengthOf(255);
		$schema->addEmailAddressField('email');
		$schema->addBooleanField('subscribe')->makeOptional();
		$schema->addEnumField('plan', ['free', 'pro']);

		$html = (new SchemaHtmlRenderer())->render($schema);

		$this->assertStringContainsString('<form id="signup"', $html);
		$this->assertStringContainsString('type="email"', $html);
		$this->assertStringContainsString('type="checkbox"', $html);
		$this->assertStringContainsString('type="radio"', $html);
		$this->assertStringContainsString('<button type="submit">Submit</button>', $html);
		// required field carries the attribute; optional one is rendered too
		$this->assertStringContainsString('required', $html);
	}

	#[Test]
	public function it_applies_ui_options_for_method_action_and_field_labels(): void
	{
		$schema = new Facade('signup');
		$schema->addEmailAddressField('email');

		$ui = (new UiOptions($schema))->postTo('/signup');
		$ui->pickField('email')->label('Your email address');

		$html = (new SchemaHtmlRenderer($ui->toArray()))->render($schema);

		$this->assertStringContainsString('action="/signup"', $html);
		$this->assertStringContainsString('method="post"', $html);
		$this->assertStringContainsString('Your email address', $html);
	}

	#[Test]
	public function it_adds_multipart_encoding_when_a_file_field_is_present(): void
	{
		$schema = new Facade('upload');
		$schema->addFileField('resume');

		$html = (new SchemaHtmlRenderer())->render($schema);

		$this->assertStringContainsString('enctype="multipart/form-data"', $html);
		$this->assertStringContainsString('type="file"', $html);
	}

	#[Test]
	public function it_renders_inline_validation_errors_after_validation(): void
	{
		$schema = new Facade('signup');
		$schema->addNameField('full_name')->minLengthOf(1);

		$schema->validate(['full_name' => null]); // required, missing -> fails

		$html = (new SchemaHtmlRenderer())->render($schema);

		$this->assertStringContainsString('<div class="errors">', $html);
		$this->assertStringContainsString('<p>', $html);
	}
}
