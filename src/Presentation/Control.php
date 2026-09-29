<?php
declare(strict_types=1);

namespace Meraki\Schema\Html\Presentation;

/**
 * One form control, described once so any widget can draw it.
 *
 * A whole field ({@see FieldView::$control}) and one part of a structured field
 * ({@see PartView::$control}) are both described this way, which is what lets one dropdown
 * widget draw an Enum, an address's state and country, a currency, and a phone's country.
 */
final readonly class Control
{
	/**
	 * @param string $name the submitted input name, e.g. `billing[postal_code]`
	 * @param string $type the input type for an `<input>` (`text`, `email`, `month`, …)
	 * @param string|null $value what to show in it; null shows nothing
	 * @param string|null $placeholder the field's hint; a dropdown's empty option uses it too
	 * @param array<string, string> $choices value => label, for dropdowns and radio groups
	 * @param array<string, mixed> $attributes anything else the element needs (`list`, `class`, …)
	 */
	public function __construct(
		public string $id,
		public string $name,
		public string $type = 'text',
		public ?string $value = null,
		public bool $checked = false,
		public bool $required = false,
		public bool $readonly = false,
		public bool $disabled = false,
		public bool $hidden = false,
		public bool $autofocus = false,
		public ?string $autocomplete = null,
		public ?string $pattern = null,
		public ?string $inputmode = null,
		public ?string $placeholder = null,
		public array $choices = [],
		public array $attributes = [],
	) {}

	/**
	 * A copy with some properties changed.
	 *
	 * @param array<string, mixed> $changes
	 */
	public function with(array $changes): self
	{
		return new self(...array_merge(get_object_vars($this), $changes));
	}

	/** Whether it can take keyboard focus: not when hidden, disabled or read-only. */
	public function isFocusable(): bool
	{
		return !$this->hidden && !$this->disabled && !$this->readonly;
	}
}
