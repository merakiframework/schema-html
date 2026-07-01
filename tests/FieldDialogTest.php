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
#[CoversClass(Dialog::class)]
#[CoversClass(DialogView::class)]
#[CoversClass(DialogStyles::class)]
final class FieldDialogTest extends TestCase
{
	#[Test]
	public function a_field_can_be_rendered_inside_a_native_command_invoker_dialog(): void
	{
		$schema = new Facade('signup');
		$schema->addEmailAddressField('email');

		$options = new FormOptions();
		$options->configureOptionsFor('email')->revealWithDialog(trigger: 'Add email', confirm: 'Save');

		$html = (new FormRenderer())->render($schema, $options);

		// Native command-invoker open button (no JS), referencing the dialog by id.
		$this->assertMatchesRegularExpression('/<button type="button" command="show-modal" commandfor="[^"]+-dialog"/', $html);
		$this->assertStringContainsString('command="close"', $html);
		// The field is rendered inside the dialog.
		$this->assertStringContainsString('type="email"', $html);
		$this->assertStringContainsString('>Add email</button>', $html);
		$this->assertStringContainsString('>Save</button>', $html);
		// Scoped default styles were included.
		$this->assertStringContainsString('<style>#signup .mf-dialog', $html);
	}

	#[Test]
	public function an_open_by_default_dialog_renders_with_the_open_attribute(): void
	{
		$schema = new Facade('signup');
		$schema->addEmailAddressField('email');

		$options = new FormOptions();
		$options->configureOptionsFor('email')->revealWithDialog(open: true);

		$html = (new FormRenderer())->render($schema, $options);

		$this->assertMatchesRegularExpression('/<dialog id="[^"]+" class="mf-dialog" open>/', $html);
	}

	#[Test]
	public function default_dialog_styles_can_be_disabled(): void
	{
		$schema = new Facade('signup');
		$schema->addEmailAddressField('email');

		$options = (new FormOptions())->withoutDefaultStyles();
		$options->configureOptionsFor('email')->revealWithDialog();

		$html = (new FormRenderer())->render($schema, $options);

		$this->assertStringNotContainsString('<style>', $html);
		// The dialog itself still renders.
		$this->assertStringContainsString('class="mf-dialog"', $html);
	}

	#[Test]
	public function forms_without_dialogs_emit_no_dialog_styles(): void
	{
		$schema = new Facade('signup');
		$schema->addEmailAddressField('email');

		$html = (new FormRenderer())->render($schema);

		$this->assertStringNotContainsString('mf-dialog', $html);
	}
}
