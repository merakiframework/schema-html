<?php
declare(strict_types=1);

namespace Meraki\Schema\Html\Presentation;

/**
 * An optional field drawn behind a no-JS toggle: a `<details>` disclosure (inline) or a
 * command-invoker popover (popup). See `FieldOptions::revealInline()` and `revealWithPopup()`.
 */
final readonly class Reveal
{
	public const INLINE = 'inline';
	public const POPUP = 'popup';

	public function __construct(
		public string $mode = self::INLINE,
		public string $trigger = 'Show',
		public bool $open = false,
	) {}
}
