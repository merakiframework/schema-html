<?php
declare(strict_types=1);

namespace Meraki\Schema\Html;

use Meraki\Schema\Facade;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\CoversClass;

#[Group('html')]
#[CoversClass(FormRenderer::class)]
#[CoversClass(FormOptionResolver::class)]
#[CoversClass(ValidationMessages::class)]
final class FormRendererTest extends TestCase
{
	#[Test]
	public function it_renders_a_form_with_an_input_per_field(): void
	{
		$schema = new Facade('signup');
		$schema->addNameField('full_name')->minLengthOf(1)->maxLengthOf(255);
		$schema->addEmailAddressField('email');
		$schema->addBooleanField('subscribe')->makeOptional();
		$schema->addEnumField('plan', ['free', 'pro']);

		$html = (new FormRenderer())->render($schema);

		$this->assertStringContainsString('<form id="signup"', $html);
		$this->assertStringContainsString('type="email"', $html);
		$this->assertStringContainsString('type="checkbox"', $html);
		$this->assertStringContainsString('type="radio"', $html);
		$this->assertStringContainsString('<button type="submit">Submit</button>', $html);
		$this->assertStringContainsString('required', $html);
	}

	#[Test]
	public function boolean_attributes_render_bare_and_false_ones_are_omitted(): void
	{
		$schema = new Facade('f');
		$schema->addEmailAddressField('email'); // required (not optional)

		$html = (new FormRenderer())->render($schema);

		// required default true -> must appear as a bare attribute on the input
		$this->assertMatchesRegularExpression('/<input[^>]*\srequired(\s|>)/', $html);
		// readonly/disabled default false -> must NOT appear on the input
		$this->assertStringNotContainsString('readonly', $html);
		$this->assertStringNotContainsString('disabled', $html);
	}

	#[Test]
	public function it_applies_form_options_for_method_action_and_field_labels(): void
	{
		$schema = new Facade('signup');
		$schema->addEmailAddressField('email');

		$options = (new FormOptions())->postTo('/signup');
		$options->configureOptionsFor('email')->label('Your email address');

		$html = (new FormRenderer())->render($schema, $options);

		$this->assertStringContainsString('action="/signup"', $html);
		$this->assertStringContainsString('method="post"', $html);
		$this->assertStringContainsString('Your email address', $html);
	}

	#[Test]
	public function it_adds_multipart_encoding_when_a_file_field_is_present(): void
	{
		$schema = new Facade('upload');
		$schema->addFileField('resume');

		$options = (new FormOptions())->postTo('/upload');
		$html = (new FormRenderer())->render($schema, $options);

		$this->assertStringContainsString('enctype="multipart/form-data"', $html);
		$this->assertStringContainsString('type="file"', $html);
	}

	#[Test]
	public function it_renders_inline_validation_errors_after_validation(): void
	{
		$schema = new Facade('signup');
		$schema->addNameField('full_name')->minLengthOf(1);

		$result = $schema->validate(['full_name' => null]);

		$options = (new FormOptions())->postTo('/signup');
		$html = (new FormRenderer())->render($schema, $options, $result);

		$this->assertStringContainsString('<div class="errors">', $html);
		$this->assertStringContainsString('<p>', $html);
	}

	#[Test]
	public function it_renders_a_phone_number_country_error(): void
	{
		$schema = new Facade('contact');
		$schema->addPhoneNumberField('phone', ['AU']);

		// A valid US number, but the field only allows AU.
		$result = $schema->validate(['phone' => '+12015550123']);

		$options = (new FormOptions())->postTo('/contact');
		$html = (new FormRenderer())->render($schema, $options, $result);

		$this->assertStringContainsString('Enter a phone number from: AU', $html);
	}

	#[Test]
	public function it_throws_an_exception_if_field_does_not_support_renderer(): void
	{
		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('Renderer "text" is not compatible with field type: Meraki\Schema\Field\Boolean');

		$schema = new Facade('signup');
		$schema->addBooleanField('subscribe');

		$options = (new FormOptions())->postTo('/signup');
		$options->configureOptionsFor('subscribe')->renderAs(Renderer::Text); // text renderer not supported for boolean field

		(new FormRenderer())->render($schema, $options);
	}
}
