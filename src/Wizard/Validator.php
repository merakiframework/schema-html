<?php
declare(strict_types=1);

namespace Meraki\Schema\Html\Wizard;

use Meraki\Schema\Facade;
use Meraki\Schema\SchemaValidationResult;

/**
 * Validates only the fields belonging to a single group. The whole-schema validator
 * ({@see Facade::validate()}) would fail not-yet-reached required fields, so a
 * stepped form must validate group by group.
 */
final class Validator
{
	/**
	 * @param array<string, mixed> $data
	 */
	public function validateGroup(Facade $schema, Group $group, array $data): SchemaValidationResult
	{
		// input() feeds the values and (re)applies the schema's rules, so optionality
		// resolved by a matched rule is honoured here too.
		RuleScopes::rewind($schema);
		$schema->input($data);

		$results = [];

		foreach ($schema->fields as $field) {
			if (in_array($field->name->value, $group->fieldNames, true)) {
				$results[] = $field->validate();
			}
		}

		return new SchemaValidationResult(...$results);
	}

	public function passed(SchemaValidationResult $result): bool
	{
		return !$result->anyFailed();
	}
}
