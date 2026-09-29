<?php
declare(strict_types=1);

namespace Meraki\Schema\Html\Theme;

use Meraki\Schema\Html\Element;
use Meraki\Schema\Html\Presentation\FieldView;

/**
 * Draws one kind of field by putting the theme's {@see Widgets} together.
 *
 * A renderer decides *structure* (a label, then a dropdown; a fieldset of parts; rows with a
 * remove button) and leaves every tag to the widgets, which is what lets one renderer serve
 * every theme.
 */
interface FieldRenderer
{
	public function render(FieldView $view, Theme $theme): Element;
}
