<?php
declare(strict_types=1);

namespace Meraki\Schema\Html\Theme\Renderer;

use Meraki\Schema\Html\Element;
use Meraki\Schema\Html\Presentation\FieldView;
use Meraki\Schema\Html\Theme\FieldRenderer;
use Meraki\Schema\Html\Theme\Theme;

/** A label and one `<input>` of the field's renderer type. */
final class InputFieldRenderer implements FieldRenderer
{
	public function render(FieldView $view, Theme $theme): Element
	{
		$w = $theme->widgets();
		$control = $view->control;

		return $w->field($view->type, $view->name, $control, $view->errors, $w->label($control, $view->label), $w->input($control));
	}
}
