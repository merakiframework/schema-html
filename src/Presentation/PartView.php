<?php
declare(strict_types=1);

namespace Meraki\Schema\Html\Presentation;

/**
 * One part of a structured field — the postcode of an address, the currency of an amount.
 */
final readonly class PartView
{
	public const WIDGET_INPUT = 'input';
	public const WIDGET_SELECT = 'select';
	public const WIDGET_TEXTAREA = 'textarea';
	public const WIDGET_HIDDEN = 'hidden';

	/**
	 * @param string $name the part's name, as submitted (`postal_code`)
	 * @param string $widget which widget draws it: one of the `WIDGET_*` constants
	 * @param list<string> $errors what is wrong with this part
	 * @param bool $settled whether the field's configuration already decides its value
	 */
	public function __construct(
		public string $name,
		public string $label,
		public Control $control,
		public string $widget = self::WIDGET_INPUT,
		public array $errors = [],
		public bool $settled = false,
	) {}
}
