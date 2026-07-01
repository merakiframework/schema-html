<?php
declare(strict_types=1);

namespace Meraki\Schema\Html;

/**
 * Presentation config for a no-JS dialog rendered with native command invokers.
 *
 * The dialog itself is a `<dialog>` element; it is opened/closed by
 * `<button type="button" command="show-modal|close" commandfor="{id}">` invokers
 * (no JavaScript). Rendered open-by-default via the `open` attribute when
 * {@see self::$open} is true. A real submit button (`{confirm}`) inside submits the
 * surrounding form. See {@see DialogView} for the markup.
 */
final class Dialog
{
	public function __construct(
		public readonly string $id,
		public readonly bool $open = false,
		public readonly string $trigger = 'Open',
		public readonly string $confirm = 'Confirm',
		public readonly string $close = 'Cancel',
		public readonly string $heading = '',
		/** Already-safe HTML rendered verbatim inside the dialog (e.g. terms text). */
		public readonly string $content = '',
		/** When set, the confirm submit carries name="__wizard[action]" value="{action}". */
		public readonly ?string $action = null,
	) {}
}
