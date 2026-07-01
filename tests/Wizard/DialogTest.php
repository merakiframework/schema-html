<?php
declare(strict_types=1);

namespace Meraki\Schema\Html\Wizard;

use Meraki\Schema\Facade;
use Meraki\Schema\Field;
use Meraki\Schema\Property\Name;
use Meraki\Schema\Rule\FieldBuilder;
use Meraki\Schema\Html\FormOptions;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\CoversClass;

#[Group('html')]
#[Group('wizard')]
#[CoversClass(Form::class)]
#[CoversClass(Renderer::class)]
#[CoversClass(ConfirmationOptions::class)]
#[CoversClass(StepOptions::class)]
final class DialogTest extends TestCase
{
	#[Test]
	public function a_normal_confirmation_step_lists_answers_inline_using_labels(): void
	{
		$schema = new Facade('signup');
		$schema->addNameField('full_name');

		$options = new FormOptions();
		$options->group('Account', ['full_name']);
		$options->requireConfirmation();

		$result = (new Form($schema, $options, new HiddenFieldStore()))
			->handle(['full_name' => 'Alice Smith', '__wizard' => ['step' => '0', 'action' => 'next']]);

		$this->assertFalse($result->completed);
		// Inline summary (not a dialog), using the field LABEL "Full Name", not "full_name".
		$this->assertStringContainsString('<dl class="wizard-summary">', $result->html);
		$this->assertStringContainsString('<dt>Full Name</dt>', $result->html);
		$this->assertStringContainsString('<dd>Alice Smith</dd>', $result->html);
		$this->assertStringNotContainsString('<dialog', $result->html);
	}

	#[Test]
	public function a_confirmation_dialog_shows_the_full_form_with_an_open_summary_dialog(): void
	{
		$schema = new Facade('signup');
		$schema->addNameField('full_name');
		$schema->addEmailAddressField('email');

		$options = new FormOptions();
		$options->group('Details', ['full_name', 'email']);
		$options->requireConfirmation()->asDialog(confirm: 'Place order', edit: 'Make changes');

		$result = (new Form($schema, $options, new HiddenFieldStore()))->handle([
			'full_name' => 'Alice Smith',
			'email' => 'alice@example.com',
			'__wizard' => ['step' => '0', 'action' => 'next'],
		]);

		$this->assertFalse($result->completed);
		// The full form is editable beneath (both fields rendered)...
		$this->assertStringContainsString('data-name="full_name"', $result->html);
		$this->assertStringContainsString('data-name="email"', $result->html);
		// ...with an OPEN dialog holding the summary and confirm/edit controls.
		$this->assertMatchesRegularExpression('/<dialog id="mf-confirm-\d+" class="mf-dialog" open>/', $result->html);
		$this->assertStringContainsString('<dd>Alice Smith</dd>', $result->html);
		$this->assertStringContainsString('command="close"', $result->html);
		$this->assertStringContainsString('>Make changes</button>', $result->html);
		$this->assertStringContainsString('>Place order</button>', $result->html);
	}

	#[Test]
	public function terms_and_conditions_content_can_be_shown_in_the_confirmation_dialog(): void
	{
		$schema = new Facade('signup');
		$schema->addNameField('full_name');

		$options = new FormOptions();
		$options->group('Details', ['full_name']);
		$options->requireConfirmation()
			->asDialog(confirm: 'I agree', edit: 'Cancel')
			->content('<h2>Terms</h2><p>Be excellent to each other.</p>');

		$result = (new Form($schema, $options, new HiddenFieldStore()))->handle([
			'full_name' => 'Alice Smith',
			'__wizard' => ['step' => '0', 'action' => 'next'],
		]);

		$this->assertStringContainsString('Be excellent to each other.', $result->html);
		$this->assertStringContainsString('>I agree</button>', $result->html);
	}

	#[Test]
	public function submitting_from_the_confirmation_completes(): void
	{
		$schema = new Facade('signup');
		$schema->addNameField('full_name');

		$options = new FormOptions();
		$options->group('Account', ['full_name']);
		$options->requireConfirmation();

		$result = (new Form($schema, $options, new HiddenFieldStore()))
			->handle(['full_name' => 'Alice Smith', '__wizard' => ['step' => '1', 'action' => 'submit']]);

		$this->assertTrue($result->completed);
		$this->assertSame('Alice Smith', $result->data['full_name']);
	}

	#[Test]
	public function the_final_step_runs_full_schema_validation(): void
	{
		$schema = new Facade('signup');
		$schema->addNameField('full_name');
		$schema->addEmailAddressField('email'); // required but never collected by a step

		$options = new FormOptions();
		$options->group('Account', ['full_name']);
		$options->requireConfirmation();

		$result = (new Form($schema, $options, new HiddenFieldStore()))
			->handle(['full_name' => 'Alice Smith', '__wizard' => ['step' => '1', 'action' => 'submit']]);

		$this->assertFalse($result->completed);
		$this->assertNotNull($result->html);
	}

	#[Test]
	public function a_failed_final_validation_surfaces_the_editable_form_on_the_confirmation(): void
	{
		$schema = new Facade('signup');
		$schema->addNameField('full_name');
		$schema->addEmailAddressField('email'); // required but never collected by a step

		$options = new FormOptions();
		$options->group('Account', ['full_name']);
		$options->requireConfirmation();

		$result = (new Form($schema, $options, new HiddenFieldStore()))
			->handle(['full_name' => 'Alice Smith', '__wizard' => ['step' => '1', 'action' => 'submit']]);

		// Instead of silently re-showing the summary, the confirmation (the source of truth)
		// renders the editable field so the error is visible and fixable.
		$this->assertFalse($result->completed);
		$this->assertStringContainsString('data-name="email"', $result->html);
		$this->assertStringContainsString('<dl class="wizard-summary">', $result->html);
	}

	#[Test]
	public function the_review_step_reloads_conditional_fields_via_the_update_action(): void
	{
		$schema = new Facade('demo');
		$schema->addEnumField('mode', ['simple', 'advanced'])
			->pairWith(new Field\Text(new Name('detail')), function (FieldBuilder $rule, Field\Text $d): void {
				$rule->when($this)->notEquals('advanced')->thenMakeOptional($d)->thenIgnore($d);
			});

		$options = new FormOptions();
		$options->group('Mode', ['mode', 'detail']);
		$options->requireConfirmation()->asDialog(update: 'Update');

		$form = new Form($schema, $options, new HiddenFieldStore());

		// On the review step (index 1) the whole form is editable and carries an Update control.
		// Switching mode to "advanced" and pressing Update reloads — 'detail' becomes visible.
		$result = $form->handle(['mode' => 'advanced', '__wizard' => ['step' => '1', 'action' => 'update']]);

		$this->assertFalse($result->completed);
		$this->assertStringContainsString('name="__wizard[step]" value="1"', $result->html);
		$this->assertStringContainsString('value="update"', $result->html);
		$this->assertStringContainsString('data-name="detail"', $result->html);
		$this->assertStringNotContainsString('data-name="detail" hidden', $result->html);
		$this->assertStringContainsString('<dd>advanced</dd>', $result->html);

		// With mode "simple", the same reload hides 'detail' again.
		$simple = $form->handle(['mode' => 'simple', '__wizard' => ['step' => '1', 'action' => 'update']]);
		$this->assertStringContainsString('data-name="detail" hidden', $simple->html);
	}

	#[Test]
	public function a_step_can_be_shown_in_a_dialog(): void
	{
		$schema = new Facade('signup');
		$schema->addNameField('full_name');
		$schema->addDateField('start_date');

		$options = new FormOptions();
		$options->group('Account', ['full_name']);
		$options->group('When', ['start_date'])->asDialog(trigger: 'Pick a date', confirm: 'Set date');
		$options->requireConfirmation();

		// advance from step 0 to the dialog step (1)
		$result = (new Form($schema, $options, new HiddenFieldStore()))
			->handle(['full_name' => 'Alice Smith', '__wizard' => ['step' => '0', 'action' => 'next']]);

		$this->assertStringContainsString('command="show-modal" commandfor="mf-group-1"', $result->html);
		$this->assertStringContainsString('<dialog id="mf-group-1"', $result->html);
		$this->assertStringContainsString('>Pick a date</button>', $result->html);
		$this->assertStringContainsString('data-name="start_date"', $result->html);
	}
}
