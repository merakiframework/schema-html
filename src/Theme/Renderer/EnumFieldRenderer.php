<?php
declare(strict_types=1);

namespace Meraki\Schema\Html\Theme\Renderer;

use Meraki\Schema\Html\Element;
use Meraki\Schema\Html\Presentation\FieldView;
use Meraki\Schema\Html\Renderer;
use Meraki\Schema\Html\Theme\FieldRenderer;
use Meraki\Schema\Html\Theme\Theme;

/**
 * A dropdown, or a group of radios (a radio group by default, or buttons styled as a segmented
 * control). No JavaScript in either.
 */
final class EnumFieldRenderer implements FieldRenderer
{
	public function render(FieldView $view, Theme $theme): Element
	{
		$w = $theme->widgets();
		$control = $view->control;

		if ($view->renderer === Renderer::Dropdown) {
			return $w->field($view->type, $view->name, $control, $view->errors, $w->label($control, $view->label), $w->select($control, $view->customizable));
		}

		return $w->field(
			$view->type,
			$view->name,
			$control,
			$view->errors,
			$w->choices($control, $view->label, $view->renderer === Renderer::ButtonGroup),
		);
	}
}
