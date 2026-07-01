<?php
declare(strict_types=1);

namespace Meraki\Schema\Html\Wizard;

use Meraki\Schema\Facade;
use Meraki\Schema\Html\FormOptions;
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
		$schema->addCollectionField('lessons', function (Facade $item): void {
			$item->addDateField('date');
			$item->addTimeField('time');
		})->minItems(1);

		$options = new FormOptions();
		$options->group('Lessons', ['lessons']);
		$options->requireConfirmation();

		return new Form($schema, $options, new HiddenFieldStore());
	}

	#[Test]
	public function adding_a_lesson_stays_on_the_step_and_grows_the_list(): void
	{
		$result = $this->bookingForm()->handle([
			'lessons' => [['date' => '2026-01-01', 'time' => '10:00:00']],
			'__wizard' => ['step' => '0', 'action' => 'add:lessons'],
		]);

		$this->assertFalse($result->completed);
		$this->assertStringContainsString('name="__wizard[step]" value="0"', $result->html);
		// The added lesson is now an existing item, and a fresh blank row follows it.
		$this->assertStringContainsString('value="2026-01-01"', $result->html);
		$this->assertStringContainsString('name="lessons[1][date]"', $result->html);
	}

	#[Test]
	public function removing_a_lesson_drops_it_and_reindexes(): void
	{
		$result = $this->bookingForm()->handle([
			'lessons' => [
				['date' => '2026-01-01', 'time' => '10:00:00'],
				['date' => '2026-01-02', 'time' => '11:00:00'],
			],
			'__wizard' => ['step' => '0', 'action' => 'remove:lessons:0'],
		]);

		$this->assertFalse($result->completed);
		$this->assertStringContainsString('name="__wizard[step]" value="0"', $result->html);
		$this->assertStringContainsString('value="2026-01-02"', $result->html);
		$this->assertStringNotContainsString('value="2026-01-01"', $result->html);
	}

	#[Test]
	public function advancing_validates_the_collection_min_items(): void
	{
		// No lessons submitted; minItems(1) must keep the user on the step.
		$result = $this->bookingForm()->handle([
			'__wizard' => ['step' => '0', 'action' => 'next'],
		]);

		$this->assertFalse($result->completed);
		$this->assertStringContainsString('name="__wizard[step]" value="0"', $result->html);
	}

	private function dialogBookingForm(): Form
	{
		$schema = new Facade('booking');
		$schema->addCollectionField('lessons', function (Facade $item): void {
			$item->addDateField('date');
			$item->addTextField('notes')->makeOptional();
		})->minItems(1);

		$options = new FormOptions();
		$options->configureOptionsFor('lessons')->addInDialog()->inheritInNewItems('notes');
		$options->group('Lessons', ['lessons']);
		$options->requireConfirmation();

		return new Form($schema, $options, new HiddenFieldStore());
	}

	#[Test]
	public function advancing_does_not_commit_the_prefilled_draft_row(): void
	{
		// A committed lesson plus the dialog's blank draft, prefilled by inheritInNewItems
		// (notes copied, no date) and marked as the draft. Next must drop the draft, not
		// commit it as a phantom item.
		$result = $this->dialogBookingForm()->handle([
			'lessons' => [
				0 => ['date' => '2026-01-01', 'notes' => 'Bring docs'],
				1 => ['notes' => 'Bring docs'],
			],
			'__draft' => ['lessons' => '1'],
			'__wizard' => ['step' => '0', 'action' => 'next'],
		]);

		$this->assertStringContainsString('name="__wizard[step]" value="1"', $result->html);
		preg_match_all('/name="lessons\[(\d+)\]\[date\]"/', $result->html, $m);
		$this->assertCount(1, array_unique($m[1]));
	}

	#[Test]
	public function removing_with_a_prefilled_draft_present_still_clears_the_item(): void
	{
		$result = $this->dialogBookingForm()->handle([
			'lessons' => [
				0 => ['date' => '2026-01-01', 'notes' => 'Bring docs'],
				1 => ['notes' => 'Bring docs'], // the inherited draft
			],
			'__draft' => ['lessons' => '1'],
			'__wizard' => ['step' => '0', 'action' => 'remove:lessons:0'],
		]);

		$this->assertFalse($result->completed);
		// the committed lesson is gone — no read-only view of it remains
		$this->assertStringNotContainsString('class="collection-value"', $result->html);
	}
}
