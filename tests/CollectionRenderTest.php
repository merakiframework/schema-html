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
#[CoversClass(FormOptionResolver::class)]
#[CoversClass(DialogView::class)]
final class CollectionRenderTest extends TestCase
{
	private function booking(): Facade
	{
		$schema = new Facade('booking');
		$schema->addCollectionField('lessons', function (Facade $item): void {
			$item->addDateField('date');
			$item->addTimeField('time');
		});

		return $schema;
	}

	#[Test]
	public function it_renders_existing_items_as_indexed_groups_with_remove_and_an_add_control(): void
	{
		$schema = $this->booking();
		$schema->input(['lessons' => [['date' => '2026-01-01', 'time' => '10:00:00']]]);

		$html = (new FormRenderer())->render($schema);

		$this->assertStringContainsString('class="collection"', $html);
		// Existing item 0 with indexed input names and the prefilled value.
		$this->assertStringContainsString('name="lessons[0][date]"', $html);
		$this->assertStringContainsString('name="lessons[0][time]"', $html);
		$this->assertStringContainsString('value="2026-01-01"', $html);
		$this->assertStringContainsString('value="remove:lessons:0"', $html);
		// A blank "add" row at the next index, plus the add action.
		$this->assertStringContainsString('name="lessons[1][date]"', $html);
		$this->assertStringContainsString('value="add:lessons"', $html);
	}

	#[Test]
	public function items_added_in_a_dialog_are_shown_read_only_in_the_main_form(): void
	{
		$schema = $this->booking();
		$schema->input(['lessons' => [['date' => '2026-02-01', 'time' => '10:00:00']]]);

		$options = new FormOptions();
		$options->configureOptionsFor('lessons')->addInDialog(trigger: 'Add a lesson', confirm: 'Add');

		$html = (new FormRenderer())->render($schema, $options);

		[$beforeDialog, $insideDialog] = explode('<dialog', $html, 2);

		// the added lesson reads as a view (value + hidden carry) in the MAIN form...
		$this->assertStringContainsString('<span class="collection-value">Date: 2026-02-01</span>', $beforeDialog);
		$this->assertStringContainsString('<input type="hidden" name="lessons[0][date]" value="2026-02-01">', $beforeDialog);
		$this->assertStringContainsString('value="remove:lessons:0"', $beforeDialog);
		// ...and is NOT an editable date input in the main form
		$this->assertStringNotContainsString('type="date"', $beforeDialog);
		// the editable blank add form (index 1) lives inside the dialog
		$this->assertStringContainsString('name="lessons[1][date]"', $insideDialog);
	}

	#[Test]
	public function the_add_form_can_be_shown_in_a_dialog(): void
	{
		$schema = $this->booking();

		$options = new FormOptions();
		$options->configureOptionsFor('lessons')->addInDialog(trigger: 'Add a lesson', confirm: 'Add');

		$html = (new FormRenderer())->render($schema, $options);

		$this->assertStringContainsString('command="show-modal"', $html);
		$this->assertStringContainsString('>Add a lesson</button>', $html);
		// The dialog's confirm submits the add action.
		$this->assertStringContainsString('value="add:lessons"', $html);
		$this->assertStringContainsString('name="lessons[0][date]"', $html);
	}

	#[Test]
	public function items_can_contain_a_composite_sub_field_with_nested_input_names(): void
	{
		$schema = new Facade('booking');
		$schema->addCollectionField('lessons', function (Facade $item): void {
			$item->addDateField('date');
			$item->addAddressField('pickup');
		})->minItems(1);
		$schema->input(['lessons' => [['date' => '2026-01-01', 'pickup' => [
			'street' => '1 King St', 'city' => 'Brisbane', 'state' => 'QLD', 'postcode' => '4000', 'country' => 'AU',
		]]]]);

		$html = (new FormRenderer())->render($schema);

		// the per-item composite renders with doubly-nested names and the item's value
		$this->assertStringContainsString('name="lessons[0][pickup][street]"', $html);
		$this->assertStringContainsString('value="Brisbane"', $html);
		// and a blank next item keeps the same nesting
		$this->assertStringContainsString('name="lessons[1][pickup][postcode]"', $html);
	}
}
