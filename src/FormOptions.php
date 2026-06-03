<?php
declare(strict_types=1);

namespace Meraki\Schema\Html;

use Meraki\Schema\Facade;
use Meraki\Schema\Field;

final class FormOptions implements Options
{
	private string $method = 'post';
	private string $action = '';
	private array $fieldConfigs = [];

	public function __construct(private readonly Facade $schema) {}

	public function postTo(string $url): self
	{
		$this->method = 'post';
		$this->action = $url;
		return $this;
	}

	public function getFrom(string $url): self
	{
		$this->method = 'get';
		$this->action = $url;
		return $this;
	}

	public function pickField(string $name): FieldOptions
	{
		$field = $this->findField($name, $this->schema->fields);

		if ($field === null) {
			throw new \InvalidArgumentException("Field '{$name}' does not exist in schema '{$this->schema->name}'.");
		}

		return new FieldOptions($this, $field, [$name]);
	}

	public function toArray(): array
	{
		return [
			'method' => $this->method,
			'action' => $this->action,
			'fields' => $this->fieldConfigs,
		];
	}

	/** @internal */
	public function setNestedValue(array $keys, mixed $value): void
	{
		$ref = &$this->fieldConfigs;
		$lastKey = array_pop($keys);

		foreach ($keys as $key) {
			if (!isset($ref[$key]) || !is_array($ref[$key])) {
				$ref[$key] = [];
			}
			$ref = &$ref[$key];
		}

		$ref[$lastKey] = $value;
	}

	private function findField(string $name, Field\Set $fields): ?Field
	{
		foreach ($fields as $field) {
			if ($field->name->removePrefix()->value === $name) {
				return $field;
			}
		}
		return null;
	}
}
