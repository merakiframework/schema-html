<?php
declare(strict_types=1);

namespace Meraki\Schema\Html\Theme\Renderer;

use Meraki\Schema\Field;
use Meraki\Schema\Html\Element;
use Meraki\Schema\Html\Presentation\FieldView;
use Meraki\Schema\Html\Presentation\RowView;
use Meraki\Schema\Html\Theme\FieldRenderer;
use Meraki\Schema\Html\Theme\Theme;
use Meraki\Schema\Html\Theme\Widgets;

/**
 * A repeatable list of rows, built up one at a time with no JavaScript.
 *
 * Each existing row is drawn with its fields and a Remove button (`remove:<field>:<row>`),
 * followed by a spare row and an Add button (`add:<field>`). Rows are named, never numbered:
 * removing one leaves every other row's name — and its inputs, errors and row rules — as it was.
 *
 * With FieldOptions::addInDialog() the spare row goes in a dialog instead, and existing rows are
 * shown read-only (their values carried in hidden inputs). A hidden `__draft[<field>]` marks the
 * spare row so a stepped form can drop it unless it was the one being added.
 */
final class CollectionFieldRenderer implements FieldRenderer
{
	public function render(FieldView $view, Theme $theme): Element
	{
		$w = $theme->widgets();
		$control = $view->control;
		$children = [];

		foreach ($view->rows as $row) {
			$children[] = $view->addDialog !== null
				? $this->readOnlyRow($w, $control->name, $row)
				: $this->editableRow($theme, $control->name, $row, removable: true);
		}

		if ($view->blankRow !== null) {
			$blank = $this->editableRow($theme, $control->name, $view->blankRow, removable: false);

			if ($view->addDialog !== null) {
				$children[] = $w->dialog($view->addDialog, [$blank]);
				$children[] = $w->hidden('__draft[' . $view->name . ']', $view->blankRow->key);
			} else {
				$children[] = $w->collectionAdd([
					$blank,
					$w->button('Add', ['name' => '__wizard[action]', 'value' => 'add:' . $control->name]),
				]);
			}
		}

		return $w->field(
			$view->type,
			$view->name,
			$control,
			$view->errors,
			$w->fieldset($view->label, $children, ['id' => $control->id, 'class' => 'collection', 'data-name' => $view->name]),
		);
	}

	private function editableRow(Theme $theme, string $collection, RowView $row, bool $removable): Element
	{
		$children = [];

		foreach ($row->fields as $field) {
			$children[] = $theme->renderers()->for($field)->render($field, $theme);
		}

		if ($removable) {
			$children[] = $this->removeButton($theme->widgets(), $collection, $row);
		}

		return $theme->widgets()->collectionRow($row->key, $children);
	}

	/**
	 * A row shown as text, each value carried in a hidden input so it survives the round-trip.
	 */
	private function readOnlyRow(Widgets $w, string $collection, RowView $row): Element
	{
		$children = [];

		foreach ($row->fields as $field) {
			if ($field->parts !== []) {
				foreach ($field->parts as $part) {
					$value = $part->control->value ?? '';
					$children[] = $w->collectionValue($field->label . ' ' . strtolower($part->label), $value);
					$children[] = $w->hidden($part->control->name, $value);
				}

				continue;
			}

			if ($field->field instanceof Field\Boolean) {
				$children[] = $w->collectionValue($field->label, $field->control->checked ? 'Yes' : 'No');

				// Carried the way the checkbox would have submitted it (see BooleanFieldRenderer).
				if ($field->control->checked || !$field->field->requiresAcceptance) {
					$children[] = $w->hidden($field->control->name, $field->control->checked ? 'on' : '0');
				}

				continue;
			}

			$value = $field->control->value ?? '';
			$children[] = $w->collectionValue($field->label, $value);
			$children[] = $w->hidden($field->control->name, $value);
		}

		$children[] = $this->removeButton($w, $collection, $row);

		return $w->collectionRow($row->key, $children);
	}

	private function removeButton(Widgets $w, string $collection, RowView $row): Element
	{
		return $w->button('Remove', [
			'name' => '__wizard[action]',
			'value' => 'remove:' . $collection . ':' . $row->key,
			'formnovalidate' => true,
		]);
	}
}
