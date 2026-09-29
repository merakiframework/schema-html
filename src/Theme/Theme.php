<?php
declare(strict_types=1);

namespace Meraki\Schema\Html\Theme;

/**
 * How a form looks: the widgets it is drawn with, and the renderer each field type uses to put
 * them together.
 *
 * {@see DefaultTheme} is the stock look. A theme pack — Bootstrap, say — usually supplies its own
 * {@see Widgets} and keeps the default renderers, since those only compose widgets:
 *
 *     $theme = new DefaultTheme(new BootstrapWidgets());
 *
 * Everything that is behaviour rather than markup (options, rule-driven hiding, messages, CSRF,
 * wizard state, the payload the core validates) happens before a theme is involved, so every
 * theme gets it right.
 */
interface Theme
{
	public function widgets(): Widgets;

	public function renderers(): FieldRenderers;
}
