<?php
declare(strict_types=1);

namespace Meraki\Schema\Html;

final class FieldOptions implements Options
{
	public function __construct(
		private readonly FormOptions $root,
		private readonly array $path,
	) {}

	public function label(string $label): self
	{
		$this->root->setNestedValue([...$this->path, 'label'], $label);
		return $this;
	}

	public function renderAs(Renderer $renderer): self
	{
		$this->root->setNestedValue([...$this->path, 'renderer'], $renderer->value);
		return $this;
	}

	public function renderAsDropdown(): self { return $this->renderAs(Renderer::Dropdown); }

	/** Render this enum as a group of radio buttons (the default). */
	public function renderAsRadioGroup(): self { return $this->renderAs(Renderer::RadioGroup); }

	/**
	 * Render this enum as a group of buttons — radios styled as a segmented control, so
	 * selection still works with no JavaScript (and clicking never submits the form).
	 */
	public function renderAsButtonGroup(): self { return $this->renderAs(Renderer::ButtonGroup); }

	/**
	 * Render this enum as a customizable native <select> (`appearance: base-select`):
	 * a button trigger plus styleable options. Opt-in; degrades to a normal select.
	 */
	public function renderAsSelect(): self
	{
		$this->renderAs(Renderer::Dropdown);
		$this->root->setNestedValue([...$this->path, 'customizable'], true);
		return $this;
	}

	/**
	 * The field's empty-state hint, rendered type-appropriately: the `placeholder`
	 * attribute on inputs, and the placeholder <option> on dropdowns (which submits an
	 * empty value so a required dropdown fails validation).
	 */
	public function hint(string $text): self
	{
		$this->root->setNestedValue([...$this->path, 'hint'], $text);
		return $this;
	}

	public function renderAsTextarea(): self { return $this->renderAs(Renderer::Textarea); }

	/**
	 * Render this field as a no-JS combobox — a text input backed by a native
	 * `<datalist>` of suggestions — so the user can pick an existing option OR type a new
	 * one to "add to the list". On an enum the suggestions are its options; on any other
	 * field pass them in as `value => label`. A typed value is submitted as-is, so on a
	 * strict enum it must be one of the options (the host persists genuinely new entries).
	 *
	 * @param array<string, string> $suggestions value => label (for non-enum fields)
	 */
	public function allowAddingOptions(array $suggestions = [], ?string $hint = null): self
	{
		$this->root->setNestedValue([...$this->path, 'addOptions'], true);

		foreach ($suggestions as $value => $label) {
			$this->labelOption((string) $value, $label);
		}

		if ($hint !== null) {
			$this->hint($hint);
		}

		return $this;
	}

	public function readonly(): self
	{
		$this->root->setNestedValue([...$this->path, 'readonly'], true);
		return $this;
	}

	public function disabled(): self
	{
		$this->root->setNestedValue([...$this->path, 'disabled'], true);
		return $this;
	}

	public function hidden(): self
	{
		$this->root->setNestedValue([...$this->path, 'hidden'], true);
		return $this;
	}

	public function labelOption(string $value, string $label): self
	{
		$this->root->setNestedValue([...$this->path, 'options', $value, 'label'], $label);
		return $this;
	}

	public function labelOptions(array $options): self
	{
		foreach ($options as $value => $label) {
			$this->labelOption($value, $label);
		}

		return $this;
	}

	/**
	 * Reveal this (optional) field inline via a native <details>/<summary> toggle.
	 * `open: true` renders it expanded on load. No JS, no round-trip.
	 */
	public function revealInline(string $trigger = 'Show', bool $open = false): self
	{
		$this->root->setNestedValue([...$this->path, 'reveal'], [
			'mode'    => 'inline',
			'trigger' => $trigger,
			'open'    => $open,
		]);
		return $this;
	}

	/**
	 * Reveal this (optional) field in a popover overlay, toggled by a native
	 * command-invoker button (`command="toggle-popover"`). No JS.
	 */
	public function revealWithPopup(string $trigger = 'Show'): self
	{
		$this->root->setNestedValue([...$this->path, 'reveal'], [
			'mode'    => 'popup',
			'trigger' => $trigger,
		]);
		return $this;
	}

	/**
	 * Reveal this field inside a no-JS modal dialog: a `{trigger}` button opens it, a
	 * `{confirm}` submit saves (submits the form), `{close}` cancels. `open: true`
	 * renders it open on load.
	 */
	public function revealWithDialog(string $trigger = 'Open', string $confirm = 'Save', string $close = 'Cancel', bool $open = false): self
	{
		$this->root->setNestedValue([...$this->path, 'dialog'], [
			'trigger' => $trigger,
			'confirm' => $confirm,
			'close'   => $close,
			'open'    => $open,
		]);
		return $this;
	}

	/**
	 * For a collection field: render the "add one" blank item form inside a no-JS
	 * dialog opened by a `{trigger}` button; `{confirm}` adds the item.
	 */
	public function addInDialog(string $trigger = 'Add', string $confirm = 'Add', bool $open = false): self
	{
		// Distinct key from revealWithDialog()'s 'dialog' so the whole collection isn't
		// itself wrapped in a field-dialog — only the "add one" form goes in a dialog.
		$this->root->setNestedValue([...$this->path, 'addDialog'], [
			'trigger' => $trigger,
			'confirm' => $confirm,
			'open'    => $open,
		]);
		return $this;
	}

	/**
	 * For a collection field: when a new (blank) item is shown, prefill these sub-field
	 * (local) names from the first existing item — convenient defaults, still editable.
	 */
	public function inheritInNewItems(string ...$localNames): self
	{
		$this->root->setNestedValue([...$this->path, 'inheritInNewItems'], array_values($localNames));
		return $this;
	}

	public function configureOptionsFor(string $name): self
	{
		return new self($this->root, [...$this->path, $name]);
	}

	public function configureFor(string $name): self
	{
		return $this->configureOptionsFor($name);
	}

	public function toArray(): array { return $this->root->toArray(); }
}
