<?php
declare(strict_types=1);

namespace Meraki\Schema\Html\Presentation;

use Meraki\Schema\Field;
use Meraki\Schema\FieldResult;
use Meraki\Schema\Html\Dialog;
use Meraki\Schema\Html\Renderer;

/**
 * Everything a theme needs to draw one field, and nothing it has to work out.
 *
 * Built by {@see FieldViewFactory} from the field's result for this request, after the form's
 * options and condition behaviours have had their say. A theme renders from this and never
 * reaches back into `meraki/schema`: which label, which value to show, whether a rule has hidden
 * it and what went wrong are all settled here, so every theme gets them right.
 */
final readonly class FieldView
{
	/**
	 * @param Field $field the effective definition, including anything a rule changed
	 * @param string $type what kind of field it is, for `data-type` (`email-address`, `money`, …)
	 * @param string $name the field's name, for `data-name`
	 * @param list<string> $errors what is wrong with the field as a whole
	 * @param array<string, PartView> $parts a structured field's parts, in display order
	 * @param list<RowView> $rows a collection's existing rows
	 * @param RowView|null $blankRow a collection's spare row, for adding one more
	 * @param Dialog|null $dialog when set, the field is drawn inside this dialog
	 * @param Reveal|null $reveal when set, the field is drawn behind this toggle
	 * @param Dialog|null $addDialog a collection's "add one" form is drawn inside this dialog
	 */
	public function __construct(
		public Field $field,
		public FieldResult $result,
		public string $type,
		public string $name,
		public string $label,
		public Renderer $renderer,
		public Control $control,
		public array $errors = [],
		public array $parts = [],
		public array $rows = [],
		public ?RowView $blankRow = null,
		public ?Dialog $dialog = null,
		public ?Reveal $reveal = null,
		public ?Dialog $addDialog = null,
		public bool $multiline = false,
		public bool $customizable = false,
		public bool $combobox = false,
	) {}

	/** Whether anything is wrong with this field: itself, one of its parts, or one of its rows. */
	public function hasErrors(): bool
	{
		if ($this->errors !== []) {
			return true;
		}

		foreach ($this->parts as $part) {
			if ($part->errors !== []) {
				return true;
			}
		}

		foreach ($this->rows as $row) {
			if ($row->hasErrors()) {
				return true;
			}
		}

		return false;
	}
}
