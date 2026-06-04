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
