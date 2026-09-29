<?php
declare(strict_types=1);

namespace Meraki\Schema\Html\Wizard;

/**
 * Interprets the no-JS collection actions submitted via `__wizard[action]`:
 *
 *   add:<field>          — stay on the step (the new row rode in with the submission)
 *   remove:<field>:<row> — drop that row, then stay on the step
 *
 * Both keep the user on the current step (no advance, no validation) so they can
 * build up a collection a row at a time.
 *
 * Rows are named, so removing one leaves every other row exactly where it was — its name, and
 * with it its inputs, errors and row rules. Blank rows are dropped on the way to the schema
 * ({@see \Meraki\Schema\Html\Request\PayloadMapper}).
 */
final class CollectionActions
{
	public static function isCollectionAction(string $action): bool
	{
		return str_starts_with($action, 'add:') || str_starts_with($action, 'remove:');
	}

	public static function apply(string $action, State $state): State
	{
		// Neither a field name nor a row name can contain a colon, so splitting is safe.
		$parts = explode(':', $action);
		$verb = $parts[0];
		$field = $parts[1] ?? null;
		$row = $parts[2] ?? null;

		$data = $state->data;

		if ($verb === 'remove' && $field !== null && $row !== null && isset($data[$field]) && is_array($data[$field])) {
			unset($data[$field][$row]);
		}

		return new State($state->currentStepIndex, $data);
	}
}
