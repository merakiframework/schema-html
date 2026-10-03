<?php
declare(strict_types=1);

namespace Meraki\Schema\Html;

use Meraki\Schema\Definition;
use Meraki\Schema\Html\Request\PayloadMapper;
use Meraki\Schema\Html\Support\Forms;
use Meraki\Schema\Html\Theme\Renderer\CollectionFieldRenderer;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\CoversClass;

#[Group('html')]
#[CoversClass(FormRenderer::class)]
#[CoversClass(CollectionFieldRenderer::class)]
final class CollectionRenderTest extends TestCase
{
	private function booking(): Definition
	{
		$schema = new Definition('booking');
		$schema->add($schema->createCollectionField(
			'lessons',
			$schema->createDateField('date'),
			$schema->createTimeField('time'),
		));

		return $schema;
	}

	/**
	 * @param array<string, mixed> $wire
	 */
	private function render(Definition $schema, array $wire, ?FormOptions $options = null): string
	{
		$result = $schema->resolve((new PayloadMapper())->map($schema, $wire));

		return (new FormRenderer())->render($schema, $options ?? Forms::options(), $result);
	}

	#[Test]
	public function it_renders_existing_rows_by_name_with_remove_and_an_add_control(): void
	{
		$schema = $this->booking();

		$html = $this->render($schema, ['lessons' => ['row1' => ['date' => '2026-01-01', 'time' => '10:00']]]);

		$this->assertStringContainsString('class="collection"', $html);
		// The existing row, named, with its value.
		$this->assertStringContainsString('name="lessons[row1][date]"', $html);
		$this->assertStringContainsString('name="lessons[row1][time]"', $html);
		$this->assertStringContainsString('value="2026-01-01"', $html);
		$this->assertStringContainsString('value="remove:lessons:row1"', $html);
		// A spare row under the next name, plus the add action.
		$this->assertStringContainsString('name="lessons[row2][date]"', $html);
		$this->assertStringContainsString('value="add:lessons"', $html);
	}

	/** A name is never reused, so a row keeps its identity when one before it goes. */
	#[Test]
	public function the_spare_row_takes_a_name_after_the_highest_in_use(): void
	{
		$html = $this->render($this->booking(), ['lessons' => [
			'row1' => ['date' => '2026-01-01', 'time' => '10:00'],
			'row3' => ['date' => '2026-01-03', 'time' => '10:00'],
		]]);

		$this->assertStringContainsString('name="lessons[row3][date]"', $html);
		$this->assertStringContainsString('name="lessons[row4][date]"', $html);
		$this->assertStringNotContainsString('name="lessons[row2][date]"', $html);
	}

	#[Test]
	public function each_rows_inputs_have_their_own_ids(): void
	{
		$html = $this->render($this->booking(), ['lessons' => ['row1' => ['date' => '2026-01-01', 'time' => '10:00']]]);

		preg_match_all('/ id="([^"]+)"/', $html, $ids);

		$this->assertSame(array_unique($ids[1]), $ids[1]);
	}

	#[Test]
	public function a_row_that_failed_says_so_in_that_row(): void
	{
		$schema = $this->booking();
		$result = $schema->validate((new PayloadMapper())->map($schema, ['lessons' => [
			'row1' => ['date' => 'not a date', 'time' => '10:00'],
		]]));

		$html = (new FormRenderer())->render($schema, Forms::options(), $result);

		$this->assertMatchesRegularExpression(
			'/data-row="row1".*name="lessons\[row1\]\[date\]"[^>]*value="not a date".*<p>That is not a valid date\.<\/p>.*data-row="row2"/s',
			$html,
		);
	}

	#[Test]
	public function rows_added_in_a_dialog_are_shown_read_only_in_the_main_form(): void
	{
		$options = Forms::options();
		$options->configureOptionsFor('lessons')->addInDialog(trigger: 'Add a lesson', confirm: 'Add');

		$html = $this->render($this->booking(), ['lessons' => ['row1' => ['date' => '2026-02-01', 'time' => '10:00']]], $options);

		[$beforeDialog, $insideDialog] = explode('<dialog', $html, 2);

		// the added lesson reads as a view (value + hidden carry) in the MAIN form...
		$this->assertStringContainsString('<span class="collection-value">Date: 2026-02-01</span>', $beforeDialog);
		$this->assertStringContainsString('<input type="hidden" name="lessons[row1][date]" value="2026-02-01">', $beforeDialog);
		$this->assertStringContainsString('value="remove:lessons:row1"', $beforeDialog);
		// ...and is NOT an editable date input in the main form
		$this->assertStringNotContainsString('type="date"', $beforeDialog);
		// the editable spare row lives inside the dialog, and is marked as the draft
		$this->assertStringContainsString('name="lessons[row2][date]"', $insideDialog);
		$this->assertStringContainsString('<input type="hidden" name="__draft[lessons]" value="row2">', $html);
	}

	/**
	 * Shown read-only, a row that failed would block the step with nothing to say why and nothing
	 * to fix, so it is drawn editable — with its messages — where it is.
	 */
	#[Test]
	public function a_row_added_in_a_dialog_that_failed_is_drawn_editable_with_its_messages(): void
	{
		$schema = $this->booking();
		$options = Forms::options();
		$options->configureOptionsFor('lessons')->addInDialog(trigger: 'Add a lesson', confirm: 'Add');
		$result = $schema->validate((new PayloadMapper())->map($schema, ['lessons' => [
			'row1' => ['date' => '2026-02-01', 'time' => '10:00'],
			'row2' => ['date' => 'not a date', 'time' => '11:00'],
		]]));

		[$beforeDialog] = explode('<dialog', (new FormRenderer())->render($schema, $options, $result), 2);

		$this->assertStringContainsString('<span class="collection-value">Date: 2026-02-01</span>', $beforeDialog);
		$this->assertMatchesRegularExpression(
			'/data-row="row2".*<input type="date"[^>]*name="lessons\[row2\]\[date\]"[^>]*value="not a date".*<p>That is not a valid date\.<\/p>.*value="remove:lessons:row2"/s',
			$beforeDialog,
		);
	}

	/** A dropdown's value reads as its label, not its code: the state, not `AU-QLD`. */
	#[Test]
	public function a_read_only_row_shows_a_choices_label(): void
	{
		$schema = new Definition('booking');
		$schema->add($schema->createCollectionField(
			'lessons',
			$schema->createEnumField('level', ['beginner', 'advanced']),
			$schema->createAddressField('pickup', ['AU']),
		));
		$options = Forms::options();
		$options->configureOptionsFor('lessons')->addInDialog();
		$options->configureOptionsFor('lessons')->configureOptionsFor('level')->labelOption('beginner', 'Beginner');

		$html = $this->render($schema, ['lessons' => ['row1' => ['level' => 'beginner', 'pickup' => [
			'street' => ['1 King St'], 'locality' => 'Brisbane', 'subdivision' => 'AU-QLD', 'postal_code' => '4000',
		]]]], $options);

		$this->assertStringContainsString('<span class="collection-value">Level: Beginner</span>', $html);
		$this->assertStringContainsString('<span class="collection-value">Pickup state: Queensland</span>', $html);
		$this->assertStringContainsString('<input type="hidden" name="lessons[row1][pickup][subdivision]" value="AU-QLD">', $html);
	}

	#[Test]
	public function the_add_form_can_be_shown_in_a_dialog(): void
	{
		$options = Forms::options();
		$options->configureOptionsFor('lessons')->addInDialog(trigger: 'Add a lesson', confirm: 'Add');

		$html = (new FormRenderer())->render($this->booking(), $options);

		$this->assertStringContainsString('command="show-modal"', $html);
		$this->assertStringContainsString('>Add a lesson</button>', $html);
		// The dialog's confirm submits the add action.
		$this->assertStringContainsString('value="add:lessons"', $html);
		$this->assertStringContainsString('name="lessons[row1][date]"', $html);
	}

	#[Test]
	public function rows_can_hold_a_structured_field_with_nested_input_names(): void
	{
		$schema = new Definition('booking');
		$schema->add($schema->createCollectionField(
			'lessons',
			$schema->createDateField('date'),
			$schema->createAddressField('pickup', ['AU']),
		));

		$html = $this->render($schema, ['lessons' => ['row1' => ['date' => '2026-01-01', 'pickup' => [
			'street' => ['1 King St'], 'locality' => 'Brisbane', 'subdivision' => 'AU-QLD', 'postal_code' => '4000',
		]]]]);

		// the per-row address renders with doubly-nested names and the row's value
		$this->assertStringContainsString('name="lessons[row1][pickup][street][0]"', $html);
		$this->assertStringContainsString('value="Brisbane"', $html);
		// and the spare row keeps the same nesting
		$this->assertStringContainsString('name="lessons[row2][pickup][postal_code]"', $html);
	}

	#[Test]
	public function a_new_row_can_start_with_values_from_the_first(): void
	{
		$schema = new Definition('booking');
		$schema->add($schema->createCollectionField(
			'lessons',
			$schema->createDateField('date'),
			$schema->createTextField('notes')->makeOptional(),
		));

		$options = Forms::options();
		$options->configureOptionsFor('lessons')->inheritInNewItems('notes');

		$html = $this->render($schema, ['lessons' => ['row1' => ['date' => '2026-01-01', 'notes' => 'Bring docs']]], $options);

		$this->assertMatchesRegularExpression('/name="lessons\[row2\]\[notes\]"[^>]*value="Bring docs"/', $html);
	}
}
