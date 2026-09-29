<?php
declare(strict_types=1);

namespace Meraki\Schema\Html\Request;

use Meraki\Schema\Facade;
use Meraki\Schema\Field;
use Meraki\Schema\Html\Input;
use Meraki\Schema\Html\SettledValues;
use stdClass;

/**
 * Turns form data into the payload `meraki/schema` validates.
 *
 * The core draws a line PHP does not: **an object is a record, an array is a list.** An
 * address, an amount of money or a card arrives as an object of named parts; a collection
 * arrives as an array of rows, each row itself a record. `$_POST` is associative arrays all the
 * way down, so something has to say which nested array is which — and only the schema knows.
 *
 * Per field:
 *
 * - a nested array reaching a field that holds one value becomes a record (`stdClass`), with any
 *   {@see SettledValues settled part} the form left out filled back in. A record with nothing but
 *   settled parts in it is treated as not submitted, so it reports *missing* rather than as an
 *   address that is all country and no street;
 * - a collection becomes an array of named rows, each mapped through the template. **Blank rows
 *   are dropped** — the spare row a form always offers arrives empty, and the core takes whatever
 *   it is given as intentional. Row names pass through untouched, so a positional list still
 *   fails in the core as unreadable;
 * - a Boolean takes a checkbox's `on` as true and its hidden `0` sentinel as false. Anything
 *   else passes through, so already-normalised data (real booleans) keeps working;
 * - everything else passes through.
 *
 * A field that was not submitted at all stays absent, so its authored default can apply.
 */
final class PayloadMapper
{
	/**
	 * @param Input|array<array-key, mixed> $wire
	 */
	public function map(Facade $schema, Input|array $wire): object
	{
		$data = $wire instanceof Input ? $wire->toArray() : $wire;
		$payload = new stdClass();

		foreach ($schema->fields as $field) {
			$name = (string) $field->name;
			$value = $this->mapField($field, $data[$name] ?? null);

			if ($value !== null) {
				$payload->{$name} = $value;
			}
		}

		return $payload;
	}

	/**
	 * One field's submitted value, as the core expects to receive it.
	 */
	public function mapField(Field $field, mixed $raw): mixed
	{
		return match (true) {
			$raw === null => null,
			$field instanceof Field\Collection => $this->rows($field, $raw),
			$field instanceof Field\Boolean => $this->boolean($raw),
			is_array($raw) => $this->record($field, $raw),
			default => $raw,
		};
	}

	private function boolean(mixed $raw): mixed
	{
		return match ($raw) {
			'on' => true,
			'0' => false,
			default => $raw,
		};
	}

	/**
	 * @param array<array-key, mixed> $parts
	 */
	private function record(Field $field, array $parts): ?stdClass
	{
		$settled = SettledValues::of($field);
		$said = array_diff_key($parts, $settled);

		if (self::isBlank($said)) {
			return null;
		}

		foreach ($settled as $part => $value) {
			$parts[$part] ??= $value;
		}

		return (object) $parts;
	}

	/**
	 * @return array<array-key, mixed>|mixed an array of rows, or the input unchanged when it was
	 *         not an array at all (so the core can report it as unreadable)
	 */
	private function rows(Field\Collection $collection, mixed $raw): mixed
	{
		if (!is_array($raw)) {
			return $raw;
		}

		$rows = [];

		foreach ($raw as $key => $row) {
			if (!is_array($row)) {
				$rows[$key] = $row;

				continue;
			}

			$mapped = [];
			$blank = true;

			foreach ($collection->template as $field) {
				$name = (string) $field->name;
				$value = $this->mapField($field, $row[$name] ?? null);

				if ($value === null) {
					continue;
				}

				$mapped[$name] = $value;

				// An unchecked box on its own says nothing: the spare row carries its sentinel too.
				if (!($field instanceof Field\Boolean && $value === false)) {
					$blank = false;
				}
			}

			if (!$blank) {
				$rows[$key] = (object) $mapped;
			}
		}

		return $rows;
	}

	/**
	 * @param array<array-key, mixed> $parts
	 */
	private static function isBlank(array $parts): bool
	{
		foreach ($parts as $part) {
			if (is_array($part) ? !self::isBlank($part) : $part !== null) {
				return false;
			}
		}

		return true;
	}
}
