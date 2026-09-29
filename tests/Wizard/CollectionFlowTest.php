<?php
declare(strict_types=1);

namespace Meraki\Schema\Html\Wizard;

use Meraki\Schema\Facade;
use Meraki\Schema\Html\Support\Forms;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\CoversClass;

#[Group('html')]
#[Group('wizard')]
#[CoversClass(Form::class)]
#[CoversClass(Renderer::class)]
#[CoversClass(CollectionActions::class)]
final class CollectionFlowTest extends TestCase
{
	private function bookingForm(): Form
	{
		$schema = new Facade('booking');
		$schema->add($schema->createCollectionField(
			'lessons',
			$schema->createDateField('date'),
			$schema->createTimeField('time'),
		));

		$options = Forms::options();
		$options->group('Lessons', ['lessons']);
		$options->requireConfirmation();

		return new Form($schema, $options, new HiddenFieldStore());
	}

	#[Test]
	public function adding_a_lesson_stays_on_the_step_and_grows_the_list(): void
	{
		$result = $this->bookingForm()->handle([
			'lessons' => ['row1' => ['date' => '2026-01-01', 'time' => '10:00']],
			'__wizard' => ['step' => '0', 'action' => 'add:lessons'],
		]);

		$this->assertFalse($result->completed);
		$this->assertStringContainsString('name="__wizard[step]" value="0"', $result->html);
		// The added lesson is now an existing row, and a fresh spare row follows it.
		$this->assertStringContainsString('value="2026-01-01"', $result->html);
		$this->assertStringContainsString('name="lessons[row2][date]"', $result->html);
	}

	#[Test]
	public function removing_a_lesson_drops_it_and_leaves_the_others_named_as_they_were(): void
	{
		$result = $this->bookingForm()->handle([
			'lessons' => [
				'row1' => ['date' => '2026-01-01', 'time' => '10:00'],
				'row2' => ['date' => '2026-01-02', 'time' => '11:00'],
			],
			'__wizard' => ['step' => '0', 'action' => 'remove:lessons:row1'],
		]);

		$this->assertFalse($result->completed);
		$this->assertStringContainsString('name="__wizard[step]" value="0"', $result->html);
		$this->assertStringContainsString('value="2026-01-02"', $result->html);
		$this->assertStringNotContainsString('value="2026-01-01"', $result->html);
		// row2 is still row2: nothing was renumbered
		$this->assertMatchesRegularExpression('/name="lessons\[row2\]\[date\]"[^>]*value="2026-01-02"/', $result->html);
		$this->assertStringContainsString('name="lessons[row3][date]"', $result->html);
	}

	#[Test]
	public function advancing_validates_the_collections_minimum(): void
	{
		// No lessons submitted — just the blank spare row; a required collection needs one.
		$result = $this->bookingForm()->handle([
			'lessons' => ['row1' => ['date' => '', 'time' => '']],
			'__wizard' => ['step' => '0', 'action' => 'next'],
		]);

		$this->assertFalse($result->completed);
		$this->assertStringContainsString('name="__wizard[step]" value="0"', $result->html);
		$this->assertStringContainsString('<p>Add at least 1.</p>', $result->html);
	}

	#[Test]
	public function completing_hands_back_the_rows_by_name(): void
	{
		$form = $this->bookingForm();

		$result = $form->handle([
			'lessons' => [
				'row1' => ['date' => '2026-01-01', 'time' => '10:00'],
				'row2' => ['date' => '', 'time' => ''], // the spare row
			],
			'__wizard' => ['step' => '1', 'action' => 'submit'],
		]);

		$this->assertTrue($result->completed);
		$this->assertSame(['row1'], $result->validation?->forField('lessons')?->value?->keys());
	}

	private function dialogBookingForm(): Form
	{
		$schema = new Facade('booking');
		$schema->add($schema->createCollectionField(
			'lessons',
			$schema->createDateField('date'),
			$schema->createTextField('notes')->makeOptional(),
		));

		$options = Forms::options();
		$options->configureOptionsFor('lessons')->addInDialog()->inheritInNewItems('notes');
		$options->group('Lessons', ['lessons']);
		$options->requireConfirmation();

		return new Form($schema, $options, new HiddenFieldStore());
	}

	#[Test]
	public function advancing_does_not_commit_the_prefilled_draft_row(): void
	{
		// A committed lesson plus the dialog's spare row, prefilled by inheritInNewItems
		// (notes copied, no date) and marked as the draft. Next must drop the draft, not
		// commit it as a phantom row.
		$result = $this->dialogBookingForm()->handle([
			'lessons' => [
				'row1' => ['date' => '2026-01-01', 'notes' => 'Bring docs'],
				'row2' => ['notes' => 'Bring docs'],
			],
			'__draft' => ['lessons' => 'row2'],
			'__wizard' => ['step' => '0', 'action' => 'next'],
		]);

		$this->assertStringContainsString('name="__wizard[step]" value="1"', $result->html);
		preg_match_all('/name="lessons\[(row\d+)\]\[date\]"/', $result->html, $m);
		$this->assertSame(['row1'], array_values(array_unique($m[1])));
	}

	#[Test]
	public function removing_with_a_prefilled_draft_present_still_clears_the_row(): void
	{
		$result = $this->dialogBookingForm()->handle([
			'lessons' => [
				'row1' => ['date' => '2026-01-01', 'notes' => 'Bring docs'],
				'row2' => ['notes' => 'Bring docs'], // the inherited draft
			],
			'__draft' => ['lessons' => 'row2'],
			'__wizard' => ['step' => '0', 'action' => 'remove:lessons:row1'],
		]);

		$this->assertFalse($result->completed);
		// the committed lesson is gone — no read-only view of it remains
		$this->assertStringNotContainsString('class="collection-value"', $result->html);
	}
}
