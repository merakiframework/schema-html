<?php
declare(strict_types=1);

namespace Meraki\Schema\Html;

use Meraki\Schema\Definition;
use Meraki\Schema\Html\Support\Forms;
use Meraki\Schema\Html\Theme\DefaultWidgets;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\CoversClass;

#[Group('html')]
#[CoversClass(FormRenderer::class)]
#[CoversClass(FormOptions::class)]
#[CoversClass(DefaultWidgets::class)]
final class GroupTest extends TestCase
{
	private function schema(): Definition
	{
		$schema = new Definition('signup');
		$schema->add(
			$schema->createNameField('name'),
			$schema->createEmailAddressField('email'),
		);

		return $schema;
	}

	#[Test]
	public function single_page_wraps_each_group_in_a_fieldset_with_a_legend(): void
	{
		$options = Forms::options();
		$options->asSinglePage();
		$options->group('Your name', ['name']);
		$options->group('Contact', ['email']);

		$html = (new FormRenderer())->render($this->schema(), $options);

		$this->assertStringContainsString('<fieldset class="mf-group"><legend>Your name</legend>', $html);
		$this->assertStringContainsString('<fieldset class="mf-group"><legend>Contact</legend>', $html);
		$this->assertStringContainsString('data-name="name"', $html);
		$this->assertStringContainsString('data-name="email"', $html);
		// one submit for the whole form
		$this->assertStringContainsString('<button type="submit">Submit</button>', $html);
	}

	#[Test]
	public function accordion_wraps_each_group_in_a_details_disclosure(): void
	{
		$options = Forms::options();
		$options->asAccordion();
		$options->group('Your name', ['name']);
		$options->group('Contact', ['email']);

		$html = (new FormRenderer())->render($this->schema(), $options);

		$this->assertStringContainsString('<details class="mf-group"><summary>Your name</summary>', $html);
		$this->assertStringContainsString('<summary>Contact</summary>', $html);
		$this->assertStringNotContainsString('<script', $html);
	}

	#[Test]
	public function dialogs_flow_wraps_each_group_in_a_dialog(): void
	{
		$options = Forms::options();
		$options->asDialogs();
		$options->group('Your name', ['name']);

		$html = (new FormRenderer())->render($this->schema(), $options);

		$this->assertStringContainsString('<dialog id="mf-group-0"', $html);
		$this->assertStringContainsString('command="show-modal" commandfor="mf-group-0"', $html);
		// the trigger defaults to the group title, and the dialog is headed by it
		$this->assertStringContainsString('>Your name</button>', $html);
		$this->assertStringContainsString('<h2 class="mf-dialog-heading">Your name</h2>', $html);
	}

	#[Test]
	public function a_per_group_container_overrides_the_form_default(): void
	{
		$options = Forms::options();
		$options->asSinglePage(); // default container is fieldset
		$options->group('Your name', ['name']);
		$options->group('Contact', ['email'])->asDetails(open: true);

		$html = (new FormRenderer())->render($this->schema(), $options);

		$this->assertStringContainsString('<fieldset class="mf-group"><legend>Your name</legend>', $html);
		$this->assertStringContainsString('<details class="mf-group" open><summary>Contact</summary>', $html);
	}

	// Titles: on by default, switched for the whole form, and overridden per group.

	#[Test]
	public function titles_can_be_hidden_for_the_whole_form(): void
	{
		$options = Forms::options();
		$options->asSinglePage()->hideGroupTitles();
		$options->group('Your name', ['name']);
		$options->group('Contact', ['email']);

		$html = (new FormRenderer())->render($this->schema(), $options);

		$this->assertStringNotContainsString('<legend>', $html);
		$this->assertStringContainsString('<fieldset class="mf-group"><div class="field" data-type="name" data-name="name">', $html);
	}

	#[Test]
	public function one_group_can_show_its_title_when_the_form_hides_them(): void
	{
		$options = Forms::options();
		$options->asSinglePage()->hideGroupTitles();
		$options->group('Your name', ['name']);
		$options->group('Contact', ['email'])->showTitle();

		$html = (new FormRenderer())->render($this->schema(), $options);

		$this->assertStringNotContainsString('<legend>Your name</legend>', $html);
		$this->assertStringContainsString('<fieldset class="mf-group"><legend>Contact</legend>', $html);
	}

	#[Test]
	public function one_group_can_hide_its_title_when_the_form_shows_them(): void
	{
		$options = Forms::options();
		$options->asSinglePage();
		$options->group('Your name', ['name'])->hideTitle();
		$options->group('Contact', ['email']);

		$html = (new FormRenderer())->render($this->schema(), $options);

		$this->assertStringNotContainsString('<legend>Your name</legend>', $html);
		$this->assertStringContainsString('<fieldset class="mf-group"><legend>Contact</legend>', $html);
	}

	/** The heading goes; the button that opens the dialog still needs its words. */
	#[Test]
	public function an_untitled_dialog_has_no_heading_but_keeps_its_trigger(): void
	{
		$options = Forms::options();
		$options->asDialogs()->hideGroupTitles();
		$options->group('Your name', ['name']);

		$html = (new FormRenderer())->render($this->schema(), $options);

		$this->assertStringNotContainsString('mf-dialog-heading', $html);
		$this->assertStringContainsString('>Your name</button>', $html);
	}

	/** A disclosure's summary is the control that opens it, not a title, so it stays. */
	#[Test]
	public function a_disclosure_keeps_its_summary_when_titles_are_hidden(): void
	{
		$options = Forms::options();
		$options->asAccordion()->hideGroupTitles();
		$options->group('Your name', ['name']);

		$html = (new FormRenderer())->render($this->schema(), $options);

		$this->assertStringContainsString('<summary>Your name</summary>', $html);
	}
}
