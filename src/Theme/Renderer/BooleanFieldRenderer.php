<?php
declare(strict_types=1);

namespace Meraki\Schema\Html\Theme\Renderer;

use Meraki\Schema\Field;
use Meraki\Schema\Html\Element;
use Meraki\Schema\Html\Presentation\FieldView;
use Meraki\Schema\Html\Theme\FieldRenderer;
use Meraki\Schema\Html\Theme\Theme;

/**
 * A label and a checkbox.
 *
 * An unticked checkbox submits nothing at all, which the core would read as "not answered". So
 * a hidden `0` goes in front of it: ticked, the checkbox's `on` wins; unticked, `0` arrives and
 * {@see \Meraki\Schema\Html\Request\PayloadMapper} reads it as `false`.
 *
 * Not for a box that must be ticked (`mustBeAccepted()`): there "not ticked" has to stay
 * "not answered", so that a rule making it optional can let it be left alone.
 */
final class BooleanFieldRenderer implements FieldRenderer
{
	public function render(FieldView $view, Theme $theme): Element
	{
		$w = $theme->widgets();
		$control = $view->control;
		$children = [$w->label($control, $view->label)];

		if (!($view->field instanceof Field\Boolean && $view->field->requiresAcceptance)) {
			$children[] = $w->hidden($control->name, '0');
		}

		$children[] = $w->checkbox($control);

		return $w->field($view->type, $view->name, $control, $view->errors, ...$children);
	}
}
