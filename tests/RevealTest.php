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
#[CoversClass(FieldOptions::class)]
#[CoversClass(DialogStyles::class)]
final class RevealTest extends TestCase
{
	#[Test]
	public function reveal_inline_wraps_the_field_in_a_details_disclosure(): void
	{
		$schema = new Facade('signup');
		$schema->addTextField('note')->makeOptional();

		$options = new FormOptions();
		$options->configureOptionsFor('note')->revealInline(trigger: 'Add a note');

		$html = (new FormRenderer())->render($schema, $options);

		$this->assertStringContainsString('<details class="mf-reveal">', $html);
		$this->assertStringContainsString('<summary>Add a note</summary>', $html);
		$this->assertStringContainsString('data-name="note"', $html);
		$this->assertStringNotContainsString('<script', $html);
	}

	#[Test]
	public function reveal_inline_can_be_open_by_default(): void
	{
		$schema = new Facade('signup');
		$schema->addTextField('note')->makeOptional();

		$options = new FormOptions();
		$options->configureOptionsFor('note')->revealInline(trigger: 'Add a note', open: true);

		$html = (new FormRenderer())->render($schema, $options);

		$this->assertStringContainsString('<details class="mf-reveal" open>', $html);
	}

	#[Test]
	public function reveal_with_popup_uses_a_command_invoker_and_popover(): void
	{
		$schema = new Facade('signup');
		$schema->addTextField('coupon')->makeOptional();

		$options = new FormOptions();
		$options->configureOptionsFor('coupon')->revealWithPopup(trigger: 'Have a coupon?');

		$html = (new FormRenderer())->render($schema, $options);

		$this->assertMatchesRegularExpression('/<button type="button" command="toggle-popover" commandfor="[^"]+-reveal"/', $html);
		$this->assertStringContainsString('>Have a coupon?</button>', $html);
		$this->assertMatchesRegularExpression('/<div id="[^"]+-reveal" popover="auto" class="mf-popup">/', $html);
		$this->assertStringNotContainsString('<script', $html);
	}

	#[Test]
	public function render_as_select_emits_a_customizable_select(): void
	{
		$schema = new Facade('signup');
		$schema->addEnumField('plan', ['free', 'pro']);

		$options = new FormOptions();
		$options->configureOptionsFor('plan')->renderAsSelect();

		$html = (new FormRenderer())->render($schema, $options);

		// customizable select: a button trigger with <selectedcontent>, as the first child.
		$this->assertMatchesRegularExpression('/<select[^>]*class="mf-select"/', $html);
		$this->assertStringContainsString('<button type="button"><selectedcontent></selectedcontent></button>', $html);
		$this->assertStringContainsString('<option value="free">free</option>', $html);
		// scoped opt-in styling for the customizable select.
		$this->assertStringContainsString('.mf-select::picker(select){appearance:base-select;}', $html);
		$this->assertStringNotContainsString('<script', $html);
	}
}
