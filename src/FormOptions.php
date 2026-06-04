<?php
declare(strict_types=1);

namespace Meraki\Schema\Html;

final class FormOptions implements Options
{
	public private(set) string $method = 'post';
	public private(set) string $action = '';
	public private(set) array $fields = [];

	public function __construct() {}

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

	public function configureOptionsFor(string $fieldName): FieldOptions
	{
		return new FieldOptions($this, [$fieldName]);
	}

	/**
	 * @alias configureOptionsFor
	 */
	public function configure(string $fieldName): FieldOptions
	{
		return $this->configureOptionsFor($fieldName);
	}

	public function toArray(): array
	{
		return [
			'method' => $this->method,
			'action' => $this->action,
			'fields' => $this->fields,
		];
	}

	/** @internal */
	public function setNestedValue(array $keys, mixed $value): void
	{
		$ref = &$this->fields;
		$lastKey = array_pop($keys);

		foreach ($keys as $key) {
			if (!isset($ref[$key]) || !is_array($ref[$key])) {
				$ref[$key] = [];
			}
			$ref = &$ref[$key];
		}

		$ref[$lastKey] = $value;
	}
}
