<?php
declare(strict_types=1);

namespace Meraki\Schema\Html\Theme\Renderer;

use Meraki\Schema\Html\Element;
use Meraki\Schema\Html\Presentation\FieldView;
use Meraki\Schema\Html\Theme\FieldRenderer;
use Meraki\Schema\Html\Theme\Theme;

/** A label and a text input, or a textarea when the field is multiline. */
final class TextFieldRenderer implements FieldRenderer
{
	public function render(FieldView $view, Theme $theme): Element
	{
		$w = $theme->widgets();
		$control = $view->control;
		$input = $view->multiline ? $w->textarea($control) : $w->input($control->with(['type' => 'text']));

		return $w->field($view->type, $view->name, $control, $view->errors, $w->label($control, $view->label), $input);
	}
}
