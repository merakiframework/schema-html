<?php
declare(strict_types=1);

namespace Meraki\Schema\Html\Wizard;

use Meraki\Schema\Facade;
use Meraki\Schema\FieldResult;
use Meraki\Schema\SchemaValidationResult;

/**
 * Validates only the fields belonging to a single group. The whole-schema result
 * would fail not-yet-reached required fields, so a stepped form judges a step by its own fields.
 *
 * The whole schema is still validated — rules read other fields, including ones from earlier
 * steps — and the verdicts for the other groups' fields are simply left out.
 */
final class Validator
{
	public function validateGroup(Facade $schema, Group $group, object $payload): SchemaValidationResult
	{
		$full = $schema->validate($payload);
		$mine = [];

		foreach ($full as $result) {
			if ($result instanceof FieldResult && in_array((string) $result->field->name, $group->fieldNames, true)) {
				$mine[] = $result;
			}
		}

		return new SchemaValidationResult($full->evaluatedAt, ...$mine);
	}

	public function passed(SchemaValidationResult $result): bool
	{
		return !$result->anyFailed();
	}
}
