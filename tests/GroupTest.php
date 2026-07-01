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
#[CoversClass(FormOptions::class)]
#[CoversClass(DialogView::class)]
final class GroupTest extends TestCase
{
	private function schema(): Facade
	{
		$schema = new Facade('signup');
		$schema->addNameField('name');
		$schema->addEmailAddressField('email');

		return $schema;
	}

	#[Test]
	public function single_page_wraps_each_group_in_a_fieldset_with_a_legend(): void
	{
		$options = new FormOptions();
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
		$options = new FormOptions();
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
		$options = new FormOptions();
		$options->asDialogs();
		$options->group('Your name', ['name']);

		$html = (new FormRenderer())->render($this->schema(), $options);

		$this->assertStringContainsString('<dialog id="mf-group-0"', $html);
		$this->assertStringContainsString('command="show-modal" commandfor="mf-group-0"', $html);
		// the trigger defaults to the group title
		$this->assertStringContainsString('>Your name</button>', $html);
	}

	#[Test]
	public function a_per_group_container_overrides_the_form_default(): void
	{
		$options = new FormOptions();
		$options->asSinglePage(); // default container is fieldset
		$options->group('Your name', ['name']);
		$options->group('Contact', ['email'])->asDetails(open: true);

		$html = (new FormRenderer())->render($this->schema(), $options);

		$this->assertStringContainsString('<fieldset class="mf-group"><legend>Your name</legend>', $html);
		$this->assertStringContainsString('<details class="mf-group" open><summary>Contact</summary>', $html);
	}
}
