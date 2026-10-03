<?php
declare(strict_types=1);

namespace Meraki\Schema\Html\Theme\Renderer;

use Meraki\Schema\Html\Element;
use Meraki\Schema\Html\Presentation\FieldView;
use Meraki\Schema\Html\Presentation\PartView;
use Meraki\Schema\Html\Theme\FieldRenderer;
use Meraki\Schema\Html\Theme\Theme;
use Meraki\Schema\Html\Theme\Widgets;

/**
 * A field holding one value with named parts — an address, an amount of money, a card, a phone
 * number — drawn as a fieldset with a labelled control per part.
 *
 * When only one part is left to fill in (a phone number whose country is settled, an amount
 * whose currency is), it draws as a single control under the field's own label instead: a
 * fieldset around one input is noise.
 */
final class StructuredFieldRenderer implements FieldRenderer
{
	public function render(FieldView $view, Theme $theme): Element
	{
		$w = $theme->widgets();
		$hidden = [];
		$shown = [];

		foreach ($view->parts as $part) {
			if ($part->widget === PartView::WIDGET_HIDDEN) {
				$hidden[] = $w->hidden($part->control->name, $part->control->value ?? '');
			} else {
				$shown[] = $part;
			}
		}

		if (count($shown) === 1) {
			$part = $shown[0];

			return $w->field(
				$view->type,
				$view->name,
				$view->control,
				[...$view->errors, ...$part->errors],
				$w->label($part->control, $view->label),
				$this->control($w, $part),
				...$hidden,
			);
		}

		$children = [];

		foreach ($shown as $part) {
			$children[] = $w->field(
				$part->widget === PartView::WIDGET_SELECT ? 'enum' : $part->control->type,
				$view->name . '.' . $part->name,
				$part->control,
				$part->errors,
				$w->label($part->control, $part->label),
				$this->control($w, $part),
			);
		}

		return $w->field(
			$view->type,
			$view->name,
			$view->control,
			$view->errors,
			$w->fieldset($view->label, [...$children, ...$hidden], ['id' => $view->control->id, 'class' => 'composite-field']),
		);
	}

	private function control(Widgets $w, PartView $part): Element
	{
		return match ($part->widget) {
			PartView::WIDGET_SELECT => $w->select($part->control),
			PartView::WIDGET_TEXTAREA => $w->textarea($part->control),
			default => $w->input($part->control),
		};
	}
}
