<?php
declare(strict_types=1);

namespace Meraki\Schema\Html\Wizard;

/**
 * Interprets the no-JS collection actions submitted via `__wizard[action]`:
 *
 *   add:<field>            — stay on the step (the new item rode in with the submission)
 *   remove:<field>:<index> — drop that item, then stay on the step
 *
 * Both keep the user on the current step (no advance, no validation) so they can
 * build up a collection a row at a time. Empty items are dropped by the field itself.
 */
final class CollectionActions
{
	public static function isCollectionAction(string $action): bool
	{
		return str_starts_with($action, 'add:') || str_starts_with($action, 'remove:');
	}

	public static function apply(string $action, State $state): State
	{
		$parts = explode(':', $action);
		$verb = $parts[0] ?? '';
		$field = $parts[1] ?? null;
		$index = $parts[2] ?? null;

		$data = $state->data;

		if ($verb === 'remove' && $field !== null && $index !== null && isset($data[$field]) && is_array($data[$field])) {
			unset($data[$field][(int) $index]);
			$data[$field] = array_values($data[$field]);
		}

		return new State($state->currentStepIndex, $data);
	}
}
