<?php
declare(strict_types=1);

namespace Meraki\Schema\Html;

use Meraki\Schema\Field;
use Meraki\Schema\Field\Composite as CompositeField;

final class FieldOptions implements Options
{
	public function __construct(
		private readonly FormOptions $root,
		private readonly Field $field,
		private readonly array $path,
	) {}

	public function label(string $label): self
	{
		$this->root->setNestedValue([...$this->path, 'label'], $label);
		return $this;
	}

	public function renderAs(Renderer $renderer): self
	{
		$valid = Renderer::validFor($this->field);

		if (!in_array($renderer, $valid, true)) {
			throw new \LogicException(sprintf(
				'Renderer "%s" is not valid for %s. Valid renderers: %s.',
				$renderer->value,
				$this->field::class,
				implode(', ', array_map(fn($r) => $r->value, $valid)),
			));
		}

		$this->root->setNestedValue([...$this->path, 'renderer'], $renderer->value);
		return $this;
	}

	public function renderAsDropdown(): self { return $this->renderAs(Renderer::Dropdown); }

	public function renderAsTextarea(): self { return $this->renderAs(Renderer::Textarea); }

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

	public function withOption(string $value, string $label): self
	{
		if (!$this->field instanceof Field\Enum) {
			throw new \LogicException('withOption() is only available for Enum fields.');
		}

		$this->root->setNestedValue([...$this->path, 'options', $value, 'label'], $label);
		return $this;
	}

	public function pickField(string $name): self
	{
		if (!$this->field instanceof CompositeField) {
			throw new \LogicException(sprintf(
				'pickField() is only available for composite fields; "%s" is %s.',
				implode('.', $this->path),
				$this->field::class,
			));
		}

		$subField = null;

		foreach ($this->field->fields as $candidate) {
			if ($candidate->name->removePrefix()->value === $name) {
				$subField = $candidate;
				break;
			}
		}

		if ($subField === null) {
			throw new \InvalidArgumentException(sprintf(
				'Sub-field "%s" does not exist in composite field "%s".',
				$name,
				implode('.', $this->path),
			));
		}

		return new self($this->root, $subField, [...$this->path, $name]);
	}

	public function toArray(): array { return $this->root->toArray(); }
}
