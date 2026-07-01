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

	#[Test]
	public function an_enum_supports_three_render_modes(): void
	{
		$cases = [
			['radiogroup', ['class="mf-radiogroup"', 'type="radio"']],
			['buttongroup', ['class="mf-buttongroup"', 'type="radio"']],
			['select', ['class="mf-select"', '<select']],
		];

		foreach ($cases as [$mode, $expected]) {
			$schema = new Facade('signup');
			$schema->addEnumField('plan', ['free', 'pro']);
			$options = new FormOptions();
			$field = $options->configureOptionsFor('plan');
			match ($mode) {
				'radiogroup' => $field->renderAsRadioGroup(),
				'buttongroup' => $field->renderAsButtonGroup(),
				'select' => $field->renderAsSelect(),
			};

			$html = (new FormRenderer())->render($schema, $options);

			foreach ($expected as $needle) {
				$this->assertStringContainsString($needle, $html);
			}
			$this->assertStringNotContainsString('<script', $html);
		}
	}

	#[Test]
	public function allow_adding_options_renders_a_datalist_combobox(): void
	{
		$schema = new Facade('booking');
		$schema->addTextField('participant');

		$options = new FormOptions();
		$options->configureOptionsFor('participant')
			->allowAddingOptions(['jordan' => 'Jordan Lee', 'sam' => 'Sam Okafor'], hint: 'Pick or add someone');

		$html = (new FormRenderer())->render($schema, $options);

		// one text input bound to a <datalist> of the suggestions; no JS
		$this->assertMatchesRegularExpression('/<input[^>]*name="participant"[^>]*list="[^"]+"/', $html);
		$this->assertStringContainsString('<datalist id="', $html);
		$this->assertStringContainsString('<option value="jordan">Jordan Lee</option>', $html);
		$this->assertStringContainsString('placeholder="Pick or add someone"', $html);
		$this->assertStringNotContainsString('<script', $html);

		// a brand-new typed value is accepted (free-text field)
		$this->assertFalse($schema->validate(['participant' => 'Brand New Person'])->anyFailed());
	}

	#[Test]
	public function an_enum_defaults_to_a_radio_group(): void
	{
		$schema = new Facade('signup');
		$schema->addEnumField('plan', ['free', 'pro']);

		$html = (new FormRenderer())->render($schema);

		$this->assertStringContainsString('class="mf-radiogroup"', $html);
		$this->assertStringContainsString('type="radio"', $html);
	}

	#[Test]
	public function a_dropdown_without_a_selection_prepends_an_invalid_placeholder_option(): void
	{
		$schema = new Facade('signup');
		$schema->addEnumField('plan', ['free', 'pro']);

		$options = new FormOptions();
		$options->configureOptionsFor('plan')->renderAsDropdown();

		$html = (new FormRenderer())->render($schema, $options);

		$this->assertStringContainsString(
			'<option value="" disabled selected hidden>Please select an option</option>',
			$html,
		);
		// The placeholder is prepended before the real options.
		$this->assertStringContainsString('<option value="free">free</option>', $html);
	}

	#[Test]
	public function a_dropdown_with_a_selected_value_renders_no_placeholder(): void
	{
		$schema = new Facade('signup');
		$schema->addEnumField('plan', ['free', 'pro']);
		$schema->input(['plan' => 'pro']); // simulate a prior selection surviving a round-trip

		$options = new FormOptions();
		$options->configureOptionsFor('plan')->renderAsDropdown();

		$html = (new FormRenderer())->render($schema, $options);

		$this->assertStringNotContainsString('Please select an option', $html);
		$this->assertStringContainsString('<option value="pro" selected>pro</option>', $html);
	}

	#[Test]
	public function a_dropdown_placeholder_text_can_be_customised_via_hint(): void
	{
		$schema = new Facade('signup');
		$schema->addEnumField('plan', ['free', 'pro']);

		$options = new FormOptions();
		$options->configureOptionsFor('plan')->renderAsDropdown()->hint('Choose a plan');

		$html = (new FormRenderer())->render($schema, $options);

		$this->assertStringContainsString('>Choose a plan</option>', $html);
	}

	#[Test]
	public function hint_sets_the_placeholder_attribute_on_a_text_input(): void
	{
		$schema = new Facade('contact');
		$schema->addPhoneNumberField('phone');

		$options = new FormOptions();
		$options->configureOptionsFor('phone')->hint('e.g. 0412 345 678');

		$html = (new FormRenderer())->render($schema, $options);

		$this->assertStringContainsString('placeholder="e.g. 0412 345 678"', $html);
	}

	#[Test]
	public function a_required_dropdown_left_unselected_fails_validation_and_shows_an_error(): void
	{
		$schema = new Facade('signup');
		$schema->addEnumField('plan', ['free', 'pro']);

		$result = $schema->validate(['plan' => '']); // empty submission for a required enum

		$options = new FormOptions();
		$options->configureOptionsFor('plan')->renderAsDropdown();

		$html = (new FormRenderer())->render($schema, $options, $result);

		$this->assertStringContainsString('<div class="errors">', $html);
		$this->assertStringContainsString('<p>Value must be one of the allowed options</p>', $html);
		// The empty submission re-shows the placeholder (nothing is selected).
		$this->assertStringContainsString('<option value="" disabled selected hidden>', $html);
	}
}
