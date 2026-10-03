<?php
declare(strict_types=1);

namespace Meraki\Schema\Html\Wizard;

use Meraki\Schema\Definition;
use Meraki\Schema\Html\FormOptions;
use Meraki\Schema\Html\Support\Forms;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\CoversClass;

#[Group('html')]
#[Group('wizard')]
#[CoversClass(Form::class)]
#[CoversClass(Renderer::class)]
#[CoversClass(Validator::class)]
#[CoversClass(HiddenFieldStore::class)]
#[CoversClass(SessionStore::class)]
#[CoversClass(State::class)]
#[CoversClass(Action::class)]
#[CoversClass(Result::class)]
final class WizardTest extends TestCase
{
	private function schema(): Definition
	{
		$schema = new Definition('signup');
		$schema->add(
			$schema->createNameField('name'),
			$schema->createEmailAddressField('email'),
			$schema->createEnumField('plan', ['free', 'pro']),
		);

		return $schema;
	}

	private function options(): FormOptions
	{
		$options = Forms::options();
		$options->group('Account', ['name']);
		$options->group('Contact', ['email']);
		$options->group('Plan', ['plan']);

		return $options;
	}

	#[Test]
	public function start_renders_only_the_first_steps_fields_with_navigation(): void
	{
		$form = new Form($this->schema(), $this->options(), new HiddenFieldStore());

		$html = $form->start();

		$this->assertStringContainsString('data-name="name"', $html);
		$this->assertStringNotContainsString('data-name="email"', $html);
		$this->assertStringNotContainsString('data-name="plan"', $html);
		$this->assertStringContainsString('name="__wizard[step]" value="0"', $html);
		// First step has a Next button but no Back.
		$this->assertStringContainsString('value="next"', $html);
		$this->assertStringNotContainsString('value="back"', $html);
	}

	#[Test]
	public function the_hidden_field_store_carries_prior_answers_as_hidden_inputs(): void
	{
		$form = new Form($this->schema(), $this->options(), new HiddenFieldStore());

		$result = $form->handle(['name' => 'Alice', '__wizard' => ['step' => '0', 'action' => 'next']]);

		$this->assertFalse($result->completed);
		// Advanced to step 1 (email is now the visible field)...
		$this->assertStringContainsString('data-name="email"', $result->html);
		$this->assertStringContainsString('name="__wizard[step]" value="1"', $result->html);
		// ...and the step-0 answer rides along as a hidden input, not a visible field.
		$this->assertStringContainsString('<input type="hidden" name="name" value="Alice">', $result->html);
		$this->assertStringNotContainsString('data-name="name"', $result->html);
		// Step 1 shows a Back button.
		$this->assertStringContainsString('value="back"', $result->html);
	}

	#[Test]
	public function step_validation_only_fails_current_step_fields(): void
	{
		$form = new Form($this->schema(), $this->options(), new HiddenFieldStore());

		// Empty name fails on step 0 even though email (a later step) is also empty.
		$bad = $form->handle(['name' => '', '__wizard' => ['step' => '0', 'action' => 'next']]);
		$this->assertFalse($bad->completed);
		$this->assertStringContainsString('name="__wizard[step]" value="0"', $bad->html);
		$this->assertStringContainsString('<div class="errors"><p>This is required.</p></div>', $bad->html);

		// A valid name advances to step 1, even though the later required email is empty.
		$good = $form->handle(['name' => 'Alice Smith', '__wizard' => ['step' => '0', 'action' => 'next']]);
		$this->assertFalse($good->completed);
		$this->assertStringContainsString('name="__wizard[step]" value="1"', $good->html);
		$this->assertStringContainsString('data-name="email"', $good->html);
	}

	#[Test]
	public function back_navigation_returns_to_the_previous_step_without_validating(): void
	{
		$form = new Form($this->schema(), $this->options(), new HiddenFieldStore());

		$result = $form->handle([
			'name' => 'Alice',
			'email' => '', // invalid, but Back must not validate
			'__wizard' => ['step' => '1', 'action' => 'back'],
		]);

		$this->assertFalse($result->completed);
		$this->assertStringContainsString('name="__wizard[step]" value="0"', $result->html);
		$this->assertStringContainsString('data-name="name"', $result->html);
	}

	#[Test]
	public function submitting_the_final_step_completes_with_all_accumulated_data(): void
	{
		$form = new Form($this->schema(), $this->options(), new HiddenFieldStore());

		$result = $form->handle([
			'name' => 'Alice Smith',
			'email' => 'alice@example.com',
			'plan' => 'pro',
			'__wizard' => ['step' => '2', 'action' => 'submit'],
		]);

		$this->assertTrue($result->completed);
		$this->assertSame('Alice Smith', $result->data['name']);
		$this->assertSame('alice@example.com', $result->data['email']);
		$this->assertSame('pro', $result->data['plan']);
		// ...and the typed values, as the schema read them
		$this->assertSame('pro', (string) $result->validation?->forField('plan')?->value);
	}

	/** The settled country of an Australian-only address is filled back in for the core, so it is in what the core accepted. */
	#[Test]
	public function completion_hands_back_the_payload_the_schema_accepted(): void
	{
		$schema = new Definition('booking');
		$schema->add(
			$schema->createPhoneNumberField('phone', ['AU']),
			$schema->createAddressField('pickup', ['AU']),
			$schema->createBooleanField('terms')->mustBeAccepted(),
		);
		$options = Forms::options();
		$options->group('Details', ['phone', 'pickup', 'terms']);
		$form = new Form($schema, $options, new HiddenFieldStore());

		$result = $form->handle([
			'phone' => ['number' => '0412 345 678'],
			'pickup' => ['street' => "1 Queen St\r\n", 'locality' => 'Brisbane', 'subdivision' => 'AU-QLD', 'postal_code' => '4000'],
			'terms' => 'on',
			'__wizard' => ['step' => '0', 'action' => 'submit'],
		]);

		$this->assertTrue($result->completed);
		$this->assertEquals((object) [
			'phone' => (object) ['number' => '0412 345 678', 'country' => 'AU'],
			'pickup' => (object) [
				'street' => ['1 Queen St'],
				'locality' => 'Brisbane',
				'subdivision' => 'AU-QLD',
				'postal_code' => '4000',
				'country' => 'AU',
			],
			'terms' => true,
		], $result->payload);
		// The answers as they were submitted are still there, for re-rendering or storing.
		$this->assertSame(['number' => '0412 345 678'], $result->data['phone']);
	}

	/**
	 * The core discards what was submitted for a field a rule ignores, so handing it on would pass
	 * along an answer nobody accepted — here, detail typed before switching back to "simple".
	 */
	#[Test]
	public function the_accepted_payload_leaves_out_what_a_rule_ignored(): void
	{
		$form = new Form($this->conditionalSchema(), $this->conditionalOptions(), new HiddenFieldStore());

		$result = $form->handle([
			'mode' => 'simple',
			'detail' => 'typed while advanced',
			'name' => 'Alice',
			'__wizard' => ['step' => '2', 'action' => 'submit'],
		]);

		$this->assertTrue($result->completed);
		$this->assertEquals((object) ['mode' => 'simple', 'name' => 'Alice'], $result->payload);
		$this->assertSame('typed while advanced', $result->data['detail']);
	}

	/** A row rule's ignore is honoured inside the row, too. */
	#[Test]
	public function the_accepted_payload_leaves_out_what_a_row_rule_ignored(): void
	{
		$schema = new Definition('booking');
		$kind = $schema->createEnumField('kind', ['pickup', 'meet']);
		$address = $schema->createTextField('address');
		$schema->add($schema->createCollectionField('lessons', $kind, $address)->forEachRow(
			$kind->when()->equals('meet')->then($address->makeOptional())->thenIgnore($address),
		));
		$options = Forms::options();
		$options->group('Lessons', ['lessons']);
		$form = new Form($schema, $options, new HiddenFieldStore());

		$result = $form->handle([
			'lessons' => [
				'row1' => ['kind' => 'pickup', 'address' => '1 Queen St'],
				'row2' => ['kind' => 'meet', 'address' => 'typed before choosing meet'],
			],
			'__wizard' => ['step' => '0', 'action' => 'submit'],
		]);

		$this->assertTrue($result->completed);
		$this->assertEquals([
			'row1' => (object) ['kind' => 'pickup', 'address' => '1 Queen St'],
			'row2' => (object) ['kind' => 'meet'],
		], $result->payload?->lessons);
	}

	#[Test]
	public function a_step_is_a_fieldset_with_its_title_as_the_legend(): void
	{
		$form = new Form($this->schema(), $this->options(), new HiddenFieldStore());

		$this->assertMatchesRegularExpression(
			'/<fieldset class="mf-group"><legend>Account<\/legend>.*data-name="name".*<\/fieldset>/s',
			$form->start(),
		);
	}

	#[Test]
	public function a_steps_title_follows_the_form_default_unless_the_step_says_otherwise(): void
	{
		$options = Forms::options()->hideGroupTitles();
		$options->group('Account', ['name']);
		$options->group('Contact', ['email'])->showTitle();
		$options->group('Plan', ['plan']);
		$form = new Form($this->schema(), $options, new HiddenFieldStore());

		$this->assertStringContainsString('<fieldset class="mf-group"><div class="field"', $form->start());
		$this->assertStringContainsString(
			'<fieldset class="mf-group"><legend>Contact</legend>',
			$form->handle(['name' => 'Alice', '__wizard' => ['step' => '0', 'action' => 'next']])->html,
		);
	}

	#[Test]
	public function an_optional_field_submitted_empty_does_not_block_advancing(): void
	{
		// Regression: the browser submits a rule-hidden, optional field as '' (an
		// empty string). Without normalization that '' is "provided" and fails
		// validation, trapping the user on the step.
		$schema = new Definition('contact');
		$method = $schema->createEnumField('contact_method', ['email', 'phone']);
		$email = $schema->createEmailAddressField('email_address');
		$phone = $schema->createPhoneNumberField('phone_number', ['AU']);
		$schema->add($method, $email, $phone);
		$schema->addRule($method->when()->equals('email')->then($email->makeRequired(), $phone->makeOptional()));

		$options = Forms::options();
		$options->group('Method', ['contact_method']);
		$options->group('Reach', ['email_address', 'phone_number']);
		$options->requireConfirmation();

		$form = new Form($schema, $options, new HiddenFieldStore());

		$result = $form->handle([
			'contact_method' => 'email',
			'email_address' => 'alice@example.com',
			'phone_number' => ['number' => ''], // optional (rule) + hidden; submitted empty by the browser
			'__wizard' => ['step' => '1', 'action' => 'next'],
		]);

		$this->assertFalse($result->completed);
		// Advanced to the confirm step, not re-rendered on step 1 with a phone error.
		$this->assertStringContainsString('name="__wizard[step]" value="2"', $result->html);
		$this->assertStringNotContainsString('phone number', $result->html);
	}

	#[Test]
	public function the_session_store_keeps_answers_out_of_the_page(): void
	{
		$storage = new InMemoryStorage();
		$form = new Form($this->schema(), $this->options(), new SessionStore($storage));

		$result = $form->handle(['name' => 'Alice', '__wizard' => ['step' => '0', 'action' => 'next']]);

		$this->assertFalse($result->completed);
		$this->assertStringContainsString('name="__wizard[step]" value="1"', $result->html);
		$this->assertStringContainsString('data-name="email"', $result->html);
		// The answer is persisted server-side, not echoed into the page.
		$this->assertStringNotContainsString('Alice', $result->html);
		$this->assertSame(['name' => 'Alice'], $storage->read('meraki_wizard'));
	}

	private function conditionalSchema(): Definition
	{
		$schema = new Definition('demo');
		$mode = $schema->createEnumField('mode', ['simple', 'advanced']);
		$detail = $schema->createTextField('detail');
		$schema->add($mode, $detail, $schema->createTextField('name'));
		$schema->addRule($mode->when()->notEquals('advanced')->then($detail->makeOptional())->thenIgnore($detail));

		return $schema;
	}

	private function conditionalOptions(): FormOptions
	{
		$options = Forms::options();
		$options->group('Mode', ['mode']);
		$options->group('Detail', ['detail']);
		$options->group('Name', ['name']);

		return $options;
	}

	#[Test]
	public function it_skips_a_group_whose_fields_are_all_hidden_by_a_rule(): void
	{
		$form = new Form($this->conditionalSchema(), $this->conditionalOptions(), new HiddenFieldStore());

		// mode=simple hides 'detail' -> the Detail group (step 1) is skipped, landing on Name (step 2)
		$result = $form->handle(['mode' => 'simple', '__wizard' => ['step' => '0', 'action' => 'next']]);

		$this->assertStringContainsString('name="__wizard[step]" value="2"', $result->html);
		$this->assertStringContainsString('data-name="name"', $result->html);
		$this->assertStringNotContainsString('data-name="detail"', $result->html);
	}

	#[Test]
	public function it_does_not_skip_hidden_groups_when_show_all_steps_is_set(): void
	{
		$options = $this->conditionalOptions();
		$options->showAllSteps();
		$form = new Form($this->conditionalSchema(), $options, new HiddenFieldStore());

		$result = $form->handle(['mode' => 'simple', '__wizard' => ['step' => '0', 'action' => 'next']]);

		// the Detail group remains its own (empty) step
		$this->assertStringContainsString('name="__wizard[step]" value="1"', $result->html);
	}
}
