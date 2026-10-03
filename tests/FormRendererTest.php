<?php
declare(strict_types=1);

namespace Meraki\Schema\Html;

use Meraki\Schema\Definition;
use Meraki\Schema\Html\Exception\IncompatibleRenderer;
use Meraki\Schema\Html\Exception\MessagesNotConfigured;
use Meraki\Schema\Html\Exception\UnsupportedLocale;
use Meraki\Schema\Html\Presentation\FieldViewFactory;
use Meraki\Schema\Html\Request\PayloadMapper;
use Meraki\Schema\Html\Support\Forms;
use Meraki\Schema\Html\Theme\DefaultWidgets;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\CoversClass;

#[Group('html')]
#[CoversClass(FormRenderer::class)]
#[CoversClass(FormOptionResolver::class)]
#[CoversClass(FieldViewFactory::class)]
#[CoversClass(DefaultWidgets::class)]
#[CoversClass(Messages::class)]
final class FormRendererTest extends TestCase
{
	#[Test]
	public function it_renders_a_form_with_an_input_per_field(): void
	{
		$schema = new Definition('signup');
		$schema->add(
			$schema->createNameField('full_name'),
			$schema->createEmailAddressField('email'),
			$schema->createBooleanField('subscribe')->makeOptional(),
			$schema->createEnumField('plan', ['free', 'pro']),
		);

		$html = (new FormRenderer())->render($schema, Forms::options());

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
		$schema = new Definition('f');
		$schema->add($schema->createEmailAddressField('email')); // required (not optional)

		$html = (new FormRenderer())->render($schema, Forms::options());

		// required default true -> must appear as a bare attribute on the input
		$this->assertMatchesRegularExpression('/<input[^>]*\srequired(\s|>)/', $html);
		// readonly/disabled default false -> must NOT appear on the input
		$this->assertStringNotContainsString('readonly', $html);
		$this->assertStringNotContainsString('disabled', $html);
	}

	#[Test]
	public function it_applies_form_options_for_method_action_and_field_labels(): void
	{
		$schema = new Definition('signup');
		$schema->add($schema->createEmailAddressField('email'));

		$options = Forms::options()->postTo('/signup');
		$options->configureOptionsFor('email')->label('Your email address');

		$html = (new FormRenderer())->render($schema, $options);

		$this->assertStringContainsString('action="/signup"', $html);
		$this->assertStringContainsString('method="post"', $html);
		$this->assertStringContainsString('Your email address', $html);
	}

	#[Test]
	public function it_adds_multipart_encoding_when_a_file_field_is_present(): void
	{
		$schema = new Definition('upload');
		$schema->add($schema->createFileField('resume'));

		$html = (new FormRenderer())->render($schema, Forms::options()->postTo('/upload'));

		$this->assertStringContainsString('enctype="multipart/form-data"', $html);
		$this->assertStringContainsString('type="file"', $html);
	}

	#[Test]
	public function it_renders_inline_validation_errors_from_the_message_pack(): void
	{
		$schema = new Definition('signup');
		$schema->add($schema->createNameField('full_name'));

		$result = $schema->validate((object) ['full_name' => null]);

		$html = (new FormRenderer())->render($schema, Forms::options(), $result);

		$this->assertStringContainsString('<div class="errors"><p>This is required.</p></div>', $html);
	}

	#[Test]
	public function messages_follow_the_forms_language_not_the_one_validation_was_asked_for(): void
	{
		$schema = new Definition('signup');
		$schema->add($schema->createTextField('nickname')->minLengthOf(3));

		// validated with no locale at all: the core leaves every message empty
		$result = $schema->validate((object) ['nickname' => 'ab']);

		$html = (new FormRenderer())->render($schema, Forms::options(), $result);

		$this->assertStringContainsString('<p>Use at least 3 characters.</p>', $html);
	}

	#[Test]
	public function it_renders_a_phone_number_country_error(): void
	{
		$schema = new Definition('contact');
		$schema->add($schema->createPhoneNumberField('phone', ['AU']));

		// A valid US number, but the field only allows AU.
		$result = $schema->validate((object) ['phone' => (object) ['number' => '+12015550123', 'country' => 'US']]);

		$html = (new FormRenderer())->render($schema, Forms::options()->postTo('/contact'), $result);

		$this->assertStringContainsString('Enter a phone number from: AU.', $html);
	}

	#[Test]
	public function it_refuses_to_render_without_messages(): void
	{
		$schema = new Definition('signup');
		$schema->add($schema->createNameField('name'));

		$this->expectException(MessagesNotConfigured::class);

		(new FormRenderer())->render($schema, new FormOptions());
	}

	#[Test]
	public function it_refuses_a_locale_the_provider_cannot_serve(): void
	{
		$schema = new Definition('signup');
		$schema->add($schema->createNameField('name'));

		$this->expectException(UnsupportedLocale::class);

		(new FormRenderer())->render($schema, (new FormOptions())->withMessages('de', Forms::messages()));
	}

	/** The port chooses the language; a result validated in another is re-worded for the form. */
	#[Test]
	public function the_forms_language_wins_over_the_one_the_result_was_validated_in(): void
	{
		$schema = new Definition('checkout');
		$schema->add($schema->createAddressField('billing', ['AU']));
		$payload = (object) ['billing' => (object) [
			'street' => ['1 King St'], 'locality' => 'Brisbane', 'subdivision' => 'QLD', 'postal_code' => 'x', 'country' => 'AU',
		]];

		$result = $schema->validate($payload, locale: 'en-AU', messages: Forms::messages());
		$html = (new FormRenderer())->render($schema, Forms::options(), $result);

		$this->assertStringContainsString('That is not a valid postal code for the country you chose.', $html);
		$this->assertStringNotContainsString('postcode', $html);
	}

	#[Test]
	public function it_throws_an_exception_if_field_does_not_support_renderer(): void
	{
		$this->expectException(IncompatibleRenderer::class);
		$this->expectExceptionMessage('Renderer "text" is not compatible with field type: Meraki\Schema\Field\Boolean');

		$schema = new Definition('signup');
		$schema->add($schema->createBooleanField('subscribe'));

		$options = Forms::options()->postTo('/signup');
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
			$schema = new Definition('signup');
			$schema->add($schema->createEnumField('plan', ['free', 'pro']));
			$options = Forms::options();
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
		$schema = new Definition('booking');
		$schema->add($schema->createTextField('participant'));

		$options = Forms::options();
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
		$this->assertFalse($schema->validate((object) ['participant' => 'Brand New Person'])->anyFailed());
	}

	#[Test]
	public function an_enum_defaults_to_a_radio_group(): void
	{
		$schema = new Definition('signup');
		$schema->add($schema->createEnumField('plan', ['free', 'pro']));

		$html = (new FormRenderer())->render($schema, Forms::options());

		$this->assertStringContainsString('class="mf-radiogroup"', $html);
		$this->assertStringContainsString('type="radio"', $html);
	}

	#[Test]
	public function a_dropdown_without_a_selection_prepends_an_invalid_placeholder_option(): void
	{
		$schema = new Definition('signup');
		$schema->add($schema->createEnumField('plan', ['free', 'pro']));

		$options = Forms::options();
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
		$schema = new Definition('signup');
		$schema->add($schema->createEnumField('plan', ['free', 'pro']));

		$options = Forms::options();
		$options->configureOptionsFor('plan')->renderAsDropdown();

		// a prior selection surviving a round-trip
		$html = (new FormRenderer())->render($schema, $options, $schema->resolve((object) ['plan' => 'pro']));

		$this->assertStringNotContainsString('Please select an option', $html);
		$this->assertStringContainsString('<option value="pro" selected>pro</option>', $html);
	}

	#[Test]
	public function a_dropdown_placeholder_text_can_be_customised_via_hint(): void
	{
		$schema = new Definition('signup');
		$schema->add($schema->createEnumField('plan', ['free', 'pro']));

		$options = Forms::options();
		$options->configureOptionsFor('plan')->renderAsDropdown()->hint('Choose a plan');

		$html = (new FormRenderer())->render($schema, $options);

		$this->assertStringContainsString('>Choose a plan</option>', $html);
	}

	#[Test]
	public function hint_sets_the_placeholder_attribute_on_a_phone_numbers_number(): void
	{
		$schema = new Definition('contact');
		$schema->add($schema->createPhoneNumberField('phone'));

		$options = Forms::options();
		$options->configureOptionsFor('phone')->hint('e.g. 0412 345 678');

		$html = (new FormRenderer())->render($schema, $options);

		$this->assertMatchesRegularExpression('/name="phone\[number\]"[^>]*placeholder="e\.g\. 0412 345 678"/', $html);
	}

	#[Test]
	public function a_required_dropdown_left_unselected_fails_validation_and_shows_an_error(): void
	{
		$schema = new Definition('signup');
		$schema->add($schema->createEnumField('plan', ['free', 'pro']));

		// the empty placeholder option submits ''
		$result = $schema->validate((new PayloadMapper())->map($schema, new Input(['plan' => ''])));

		$options = Forms::options();
		$options->configureOptionsFor('plan')->renderAsDropdown();

		$html = (new FormRenderer())->render($schema, $options, $result);

		$this->assertStringContainsString('<div class="errors"><p>This is required.</p></div>', $html);
		// The empty submission re-shows the placeholder (nothing is selected).
		$this->assertStringContainsString('<option value="" disabled selected hidden>', $html);
	}

	#[Test]
	public function a_submitted_value_is_shown_back_exactly_as_it_was_typed(): void
	{
		$schema = new Definition('signup');
		$schema->add($schema->createEmailAddressField('email'));

		$result = $schema->validate((object) ['email' => 'not an email']);
		$html = (new FormRenderer())->render($schema, Forms::options(), $result);

		$this->assertStringContainsString('value="not an email"', $html);
		$this->assertStringContainsString('<p>That is not a valid email address.</p>', $html);
	}

	#[Test]
	public function an_authored_default_is_shown_on_a_first_render(): void
	{
		$schema = new Definition('signup');
		$schema->add($schema->createTextField('nickname')->defaultsTo('anonymous'));

		$html = (new FormRenderer())->render($schema, Forms::options());

		$this->assertStringContainsString('value="anonymous"', $html);
	}

	#[Test]
	public function a_prefill_is_shown_when_the_host_resolves_with_one(): void
	{
		$schema = new Definition('account');
		$schema->add($schema->createEmailAddressField('email'));

		$result = $schema->resolve(prefilledWith: (object) ['email' => 'alice@example.test']);
		$html = (new FormRenderer())->render($schema, Forms::options(), $result);

		$this->assertStringContainsString('value="alice@example.test"', $html);
	}

	#[Test]
	public function a_password_is_never_written_back_into_the_page(): void
	{
		$schema = new Definition('signup');
		$schema->add($schema->createPasswordField('secret'));

		$result = $schema->validate((object) ['secret' => 'short']);
		$html = (new FormRenderer())->render($schema, Forms::options(), $result);

		$this->assertStringNotContainsString('short', $html);
	}
}
