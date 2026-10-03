<?php
declare(strict_types=1);

namespace Meraki\Schema\Html\Theme\Renderer;

use Meraki\Schema\Html\Element;
use Meraki\Schema\Html\Presentation\FieldView;
use Meraki\Schema\Html\Theme\FieldRenderer;
use Meraki\Schema\Html\Theme\Theme;

/**
 * "Pick or add": a text input bound to a native `<datalist>` of suggestions, so the user can
 * choose an existing option or type a new one. No JavaScript. See
 * FieldOptions::allowAddingOptions().
 */
final class ComboboxFieldRenderer implements FieldRenderer
{
	public function render(FieldView $view, Theme $theme): Element
	{
		$w = $theme->widgets();
		$control = $view->control;
		$listId = $control->id . '-options';

		$input = $w->input($control->with([
			'type' => 'text',
			'attributes' => [...$control->attributes, 'list' => $listId, 'class' => 'mf-combobox'],
		]));

		return $w->field(
			$view->type,
			$view->name,
			$control,
			$view->errors,
			$w->label($control, $view->label),
			$input,
			$w->datalist($listId, $control->choices),
		);
	}
}
