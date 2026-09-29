<?php
declare(strict_types=1);

namespace Meraki\Schema\Html\Wizard;

use Meraki\Schema\Facade;
use Meraki\Schema\Field;
use Meraki\Schema\Html\Dialog;
use Meraki\Schema\Html\Element;
use Meraki\Schema\Html\FormOptions;
use Meraki\Schema\Html\FormRenderer;
use Meraki\Schema\Html\Presentation\Scene;
use Meraki\Schema\Html\Request\PayloadMapper;
use Meraki\Schema\Html\Theme\Widgets;
use Meraki\Schema\ResolvedField;
use Meraki\Schema\SchemaValidationResult;
use Stringable;

/**
 * Renders a single group of a stepped form (one group per request): the group's
 * fields (reusing {@see FormRenderer}), the hidden inputs that carry accumulated
 * state, the group index, and native (no-JS) navigation. The group's
 * {@see Container} chooses the wrapper — plain fields, a `<details>`, or a `<dialog>`.
 *
 * The answers gathered so far are resolved against the schema on every render, so rules see
 * them: a field a rule hides on this step is hidden, and a step with nothing left to fill in can
 * be skipped.
 */
final class Renderer
{
	public function __construct(
		private readonly FormRenderer $fields = new FormRenderer(),
		private readonly PayloadMapper $mapper = new PayloadMapper(),
	) {}

	/**
	 * @param SchemaValidationResult|null $result a failed validation to show, or null to draw the
	 *        step from the answers so far
	 */
	public function render(
		Facade $schema,
		FormOptions $options,
		StateStore $store,
		State $state,
		?SchemaValidationResult $result = null,
	): string {
		$groups = $options->groups;

		if ($groups === []) {
			throw new \RuntimeException('A stepped form requires at least one group (FormOptions::group()).');
		}

		$index = $state->currentStepIndex;

		if (!isset($groups[$index])) {
			throw new \OutOfBoundsException("No group at index {$index}.");
		}

		$group = $groups[$index];
		$scene = $this->fields->scene($schema, $options);
		$resolved = $schema->resolve($this->mapper->map($schema, $state->data));
		$form = $this->fields->startForm($schema, $options);
		$container = $group->containerOr($options->defaultContainer);

		if ($group->confirmation) {
			$this->renderConfirmation($form, $scene, $store, $state, $group, $container, $index, $result, $resolved);
		} else {
			$this->renderGroup($form, $scene, $store, $state, $group, $container, $index, count($groups), $result ?? $resolved);
		}

		return $this->fields->withStyles((string) $form, $schema, $options);
	}

	/**
	 * The first index at/after $from (stepping by $direction: +1 forward, -1 back) whose
	 * group still has visible fields — skipping groups a rule has fully hidden so the user never
	 * lands on an empty step. Confirmation/empty groups are never skipped; a no-op when
	 * {@see FormOptions::showAllSteps()} is set.
	 *
	 * @param array<string, mixed> $data
	 */
	public function resolveVisibleIndex(Facade $schema, FormOptions $options, array $data, int $from, int $direction): int
	{
		$groups = $options->groups;

		if (!$options->skipHiddenGroups || !isset($groups[$from])) {
			return $from;
		}

		$hidden = $this->fields->fieldsHiddenByRules($schema->resolve($this->mapper->map($schema, $data)), $options);
		$i = $from;

		while (isset($groups[$i]) && $this->groupFullyHidden($groups[$i], $hidden)) {
			$next = $i + $direction;

			if (!isset($groups[$next])) {
				break; // nothing visible this way; stay on the boundary group
			}

			$i = $next;
		}

		return $i;
	}

	/**
	 * @param array<string, true> $hidden
	 */
	private function groupFullyHidden(Group $group, array $hidden): bool
	{
		if ($group->confirmation || $group->fieldNames === []) {
			return false;
		}

		foreach ($group->fieldNames as $name) {
			if (!isset($hidden[$name])) {
				return false;
			}
		}

		return true;
	}

	private function renderGroup(
		Element $form,
		Scene $scene,
		StateStore $store,
		State $state,
		Group $group,
		Container $container,
		int $index,
		int $total,
		SchemaValidationResult $result,
	): void {
		$this->assertGroupFieldsExist($scene->schema, $group);

		$w = $this->fields->theme()->widgets();
		$elements = $this->fields->renderFields($scene, $result, $group->fieldNames);
		$isLast = $index === $total - 1;

		if ($container === Container::Dialog) {
			$form->append($w->dialog(new Dialog(
				id: 'mf-group-' . $index,
				open: $group->open,
				trigger: $group->trigger,
				confirm: $group->confirm,
				close: $group->close,
				action: $isLast ? 'submit' : 'next',
			), $elements));
			$this->appendState($form, $w, $store, $state, $group->fieldNames, $index);

			if ($index > 0) {
				$form->append($this->backButton($w));
			}

			return;
		}

		if ($container === Container::Details) {
			// On its own step a disclosure is shown open.
			$form->append($w->details($group->title, $elements, open: true));
		} else {
			foreach ($elements as $element) {
				$form->append($element);
			}
		}

		$this->appendState($form, $w, $store, $state, $group->fieldNames, $index);
		$form->append($this->navigation($w, $index, $total));
	}

	private function renderConfirmation(
		Element $form,
		Scene $scene,
		StateStore $store,
		State $state,
		Group $group,
		Container $container,
		int $index,
		?SchemaValidationResult $result,
		SchemaValidationResult $resolved,
	): void {
		$w = $this->fields->theme()->widgets();
		$summary = $this->answerSummary($w, $scene, $state, $resolved);
		$allFields = $this->fields->fieldNames($scene->schema);

		if ($container === Container::Dialog) {
			// The whole form renders (editable) beneath an open dialog holding the summary.
			// Passing the validation result surfaces any failure here (the confirmation is
			// the whole form / source of truth) instead of silently re-showing the summary.
			foreach ($this->fields->renderFields($scene, $result ?? $resolved, $allFields) as $element) {
				$form->append($element);
			}

			// With no JS, editing a field can't re-evaluate the conditionals (hide/show,
			// required) or the summary on its own — "Update" reloads the form to do so.
			$form->append($w->navigation([$this->updateButton($w, $group->update)]));

			$form->append($w->dialog(new Dialog(
				id: 'mf-confirm-' . $index,
				open: $group->open,
				trigger: 'Review',
				confirm: $group->confirm,
				close: $group->close,
				heading: $group->heading,
				content: $group->content,
				action: 'submit',
			), [$summary]));
			$this->appendState($form, $w, $store, $state, $allFields, $index);

			if ($index > 0) {
				$form->append($this->backButton($w));
			}

			return;
		}

		// Normal confirmation: list the answers inline with Back / Submit. If the final
		// whole-schema validation failed, also render the (editable) whole form with the
		// errors so they are visible and fixable rather than silently re-showing the summary.
		if ($result !== null && $result->anyFailed()) {
			foreach ($this->fields->renderFields($scene, $result, $allFields) as $element) {
				$form->append($element);
			}

			$form->append($w->navigation([$this->updateButton($w, $group->update)]));
			$form->append($summary);
			$this->appendState($form, $w, $store, $state, $allFields, $index);
		} else {
			$form->append($summary);
			$this->appendState($form, $w, $store, $state, [], $index);
		}

		$form->append($this->navigation($w, $index, count($scene->options->groups)));
	}

	private function assertGroupFieldsExist(Facade $schema, Group $group): void
	{
		foreach ($group->fieldNames as $name) {
			if ($schema->fields->findByName($name) === null) {
				throw new \OutOfBoundsException("Group '{$group->title}' references unknown field '{$name}'.");
			}
		}
	}

	/**
	 * @param list<string> $visibleFieldNames field names already rendered as editable inputs
	 */
	private function appendState(Element $form, Widgets $w, StateStore $store, State $state, array $visibleFieldNames, int $index): void
	{
		$carry = array_diff_key($state->data, array_flip($visibleFieldNames));

		foreach ($store->carry($state, $carry) as $hidden) {
			$form->append($hidden);
		}

		$form->append($w->hidden('__wizard[step]', (string) $index));
	}

	private function navigation(Widgets $w, int $index, int $total): Element
	{
		$buttons = [];

		if ($index > 0) {
			$buttons[] = $this->backButton($w);
		}

		$isLast = $index === $total - 1;

		$buttons[] = $w->button($isLast ? 'Submit' : 'Next', [
			'name'  => '__wizard[action]',
			'value' => $isLast ? 'submit' : 'next',
		]);

		return $w->navigation($buttons);
	}

	private function backButton(Widgets $w): Element
	{
		// formnovalidate so going Back never trips native field validation.
		return $w->button('Back', [
			'name'           => '__wizard[action]',
			'value'          => 'back',
			'formnovalidate' => true,
		]);
	}

	private function updateButton(Widgets $w, string $label): Element
	{
		// formnovalidate so a reload-to-refresh works even while some fields are still empty.
		return $w->button($label, [
			'name'           => '__wizard[action]',
			'value'          => 'update',
			'formnovalidate' => true,
		]);
	}

	/**
	 * The answers gathered so far, labelled as the form labels them.
	 */
	private function answerSummary(Widgets $w, Scene $scene, State $state, SchemaValidationResult $resolved): Element
	{
		$answers = [];

		foreach ($scene->schema->fields as $field) {
			$name = (string) $field->name;

			if (!array_key_exists($name, $state->data)) {
				continue;
			}

			$answer = self::answerOf($field, $resolved->forField($name));

			if ($answer !== null && $answer !== '') {
				$answers[] = [$this->fields->labelFor($field, $scene->options), $answer];
			}
		}

		return $w->summary($answers);
	}

	/**
	 * One answer as text: the value as the field read it, or what was typed when it could not
	 * be read. Nothing for a secret, or for a value with no single line of text.
	 */
	private static function answerOf(Field $field, mixed $result): ?string
	{
		if (!$result instanceof ResolvedField || $field instanceof Field\Password || $field instanceof Field\CreditCard) {
			return null;
		}

		$value = $result->value;

		return match (true) {
			$value instanceof Field\Boolean\Value => $value->answer ? 'Yes' : 'No',
			$value instanceof Stringable => (string) $value,
			$value === null && is_string($result->given) => $result->given,
			default => null,
		};
	}
}
