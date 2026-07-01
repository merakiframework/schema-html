<?php
declare(strict_types=1);

namespace Meraki\Schema\Html\Wizard;

use Meraki\Schema\Facade;
use Meraki\Schema\Field;
use Meraki\Schema\SchemaValidationResult;
use Meraki\Schema\Html\Dialog;
use Meraki\Schema\Html\DialogView;
use Meraki\Schema\Html\DialogStyles;
use Meraki\Schema\Html\Element;
use Meraki\Schema\Html\FormOptions;
use Meraki\Schema\Html\FormRenderer;

/**
 * Renders a single group of a stepped form (one group per request): the group's
 * fields (reusing {@see FormRenderer}), the hidden inputs that carry accumulated
 * state, the group index, and native (no-JS) navigation. The group's
 * {@see Container} chooses the wrapper — plain fields, a `<details>`, or a `<dialog>`.
 */
final class Renderer
{
	public function __construct(
		private readonly FormRenderer $fields = new FormRenderer(),
		private readonly DialogView $dialogs = new DialogView(),
	) {}

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

		// Reflect the answers gathered so far (and (re)apply rules) before rendering.
		RuleScopes::rewind($schema);
		$schema->input($state->data);

		$form = $this->fields->startForm($schema, $options);
		$container = $group->containerOr($options->defaultContainer);

		if ($group->confirmation) {
			$this->renderConfirmation($form, $schema, $options, $store, $state, $group, $container, $index, $result);
		} else {
			$this->renderGroup($form, $schema, $options, $store, $state, $group, $container, $index, count($groups), $result);
		}

		$html = (string) $form;

		if ($options->defaultStyles && ($container === Container::Dialog || $this->fields->emittedStyledWidget())) {
			$html = DialogStyles::for((string) $schema->name) . $html;
		}

		return $html;
	}

	/**
	 * The first index at/after $from (stepping by $direction: +1 forward, -1 back) whose
	 * group still has visible fields — skipping groups a matched rule has fully hidden so
	 * the user never lands on an empty step. Confirmation/empty groups are never skipped;
	 * a no-op when {@see FormOptions::showAllSteps()} is set.
	 *
	 * @param array<string, mixed> $data
	 */
	public function resolveVisibleIndex(Facade $schema, FormOptions $options, array $data, int $from, int $direction): int
	{
		$groups = $options->groups;

		if (!$options->skipHiddenGroups || !isset($groups[$from])) {
			return $from;
		}

		// Reflect the answers so far so rule-driven visibility is current.
		RuleScopes::rewind($schema);
		$schema->input($data);
		$hidden = $this->fields->fieldsHiddenByRules($schema, $options);

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
		Facade $schema,
		FormOptions $options,
		StateStore $store,
		State $state,
		Group $group,
		Container $container,
		int $index,
		int $total,
		?SchemaValidationResult $result,
	): void {
		$groupFields = $this->fieldsForGroup($schema, $group);
		$elements = $this->fields->renderFieldsFor($schema, $groupFields, $options, $result);
		$isLast = $index === $total - 1;

		if ($container === Container::Dialog) {
			$dialog = new Dialog(
				id: 'mf-group-' . $index,
				open: $group->open,
				trigger: $group->trigger,
				confirm: $group->confirm,
				close: $group->close,
				action: $isLast ? 'submit' : 'next',
			);
			$form->append($this->dialogs->render($dialog, $elements));
			$this->appendState($form, $store, $state, $this->namesOf($groupFields), $index);

			if ($index > 0) {
				$form->append($this->backButton());
			}

			return;
		}

		if ($container === Container::Details) {
			// On its own step a disclosure is shown open.
			$details = (new Element('details', ['class' => 'mf-group', 'open' => true]))
				->append(new Element('summary')->setText($group->title));

			foreach ($elements as $element) {
				$details->append($element);
			}

			$form->append($details);
		} else {
			foreach ($elements as $element) {
				$form->append($element);
			}
		}

		$this->appendState($form, $store, $state, $this->namesOf($groupFields), $index);
		$form->append($this->navigation($index, $total));
	}

	private function renderConfirmation(
		Element $form,
		Facade $schema,
		FormOptions $options,
		StateStore $store,
		State $state,
		Group $group,
		Container $container,
		int $index,
		?SchemaValidationResult $result = null,
	): void {
		$summary = $this->answerSummary($schema, $options, $state);

		if ($container === Container::Dialog) {
			// The whole form renders (editable) beneath an open dialog holding the summary.
			// Passing the validation result surfaces any failure here (the confirmation is
			// the whole form / source of truth) instead of silently re-showing the summary.
			$allFields = $this->allFields($schema);

			foreach ($this->fields->renderFieldsFor($schema, $allFields, $options, $result) as $element) {
				$form->append($element);
			}

			// With no JS, editing a field can't re-evaluate the conditionals (hide/show,
			// required) or the summary on its own — "Update" reloads the form to do so.
			$form->append((new Element('div', ['class' => 'wizard-nav']))->append($this->updateButton($group->update)));

			$dialog = new Dialog(
				id: 'mf-confirm-' . $index,
				open: $group->open,
				trigger: 'Review',
				confirm: $group->confirm,
				close: $group->close,
				heading: $group->heading,
				content: $group->content,
				action: 'submit',
			);
			$form->append($this->dialogs->render($dialog, [$summary]));
			$this->appendState($form, $store, $state, $this->namesOf($allFields), $index);

			if ($index > 0) {
				$form->append($this->backButton());
			}

			return;
		}

		// Normal confirmation: list the answers inline with Back / Submit. If the final
		// whole-schema validation failed, also render the (editable) whole form with the
		// errors so they are visible and fixable rather than silently re-showing the summary.
		$failed = $result !== null && $result->anyFailed();

		if ($failed) {
			$allFields = $this->allFields($schema);

			foreach ($this->fields->renderFieldsFor($schema, $allFields, $options, $result) as $element) {
				$form->append($element);
			}

			$form->append((new Element('div', ['class' => 'wizard-nav']))->append($this->updateButton($group->update)));
			$form->append($summary);
			$this->appendState($form, $store, $state, $this->namesOf($allFields), $index);
		} else {
			$form->append($summary);
			$this->appendState($form, $store, $state, [], $index);
		}

		$form->append($this->navigation($index, count($options->groups)));
	}

	/**
	 * @return list<Field>
	 */
	private function fieldsForGroup(Facade $schema, Group $group): array
	{
		$byName = [];

		foreach ($schema->fields as $field) {
			$byName[$field->name->value] = $field;
		}

		$fields = [];

		foreach ($group->fieldNames as $name) {
			if (!isset($byName[$name])) {
				throw new \OutOfBoundsException("Group '{$group->title}' references unknown field '{$name}'.");
			}

			$fields[] = $byName[$name];
		}

		return $fields;
	}

	/**
	 * @return list<Field>
	 */
	private function allFields(Facade $schema): array
	{
		$fields = [];

		foreach ($schema->fields as $field) {
			$fields[] = $field;
		}

		return $fields;
	}

	/**
	 * @param list<Field> $fields
	 * @return list<string>
	 */
	private function namesOf(array $fields): array
	{
		return array_map(static fn(Field $f): string => $f->name->value, $fields);
	}

	/**
	 * @param list<string> $visibleFieldNames field names already rendered as editable inputs
	 */
	private function appendState(Element $form, StateStore $store, State $state, array $visibleFieldNames, int $index): void
	{
		$carry = array_diff_key($state->data, array_flip($visibleFieldNames));

		foreach ($store->carry($state, $carry) as $hidden) {
			$form->append($hidden);
		}

		$form->append(new Element('input', ['type' => 'hidden', 'name' => '__wizard[step]', 'value' => (string) $index]));
	}

	private function navigation(int $index, int $total): Element
	{
		$nav = new Element('div', ['class' => 'wizard-nav']);

		if ($index > 0) {
			$nav->append($this->backButton());
		}

		$isLast = $index === $total - 1;

		$nav->append(new Element('button', [
			'type'  => 'submit',
			'name'  => '__wizard[action]',
			'value' => $isLast ? 'submit' : 'next',
		])->setText($isLast ? 'Submit' : 'Next'));

		return $nav;
	}

	private function backButton(): Element
	{
		// formnovalidate so going Back never trips native field validation.
		return new Element('button', [
			'type'           => 'submit',
			'name'           => '__wizard[action]',
			'value'          => 'back',
			'formnovalidate' => true,
		])->setText('Back');
	}

	private function updateButton(string $label): Element
	{
		// formnovalidate so a reload-to-refresh works even while some fields are still empty.
		return new Element('button', [
			'type'           => 'submit',
			'name'           => '__wizard[action]',
			'value'          => 'update',
			'formnovalidate' => true,
		])->setText($label);
	}

	private function answerSummary(Facade $schema, FormOptions $options, State $state): Element
	{
		$list = new Element('dl', ['class' => 'wizard-summary']);

		foreach ($schema->fields as $field) {
			$name = $field->name->value;

			if (!array_key_exists($name, $state->data)) {
				continue;
			}

			$value = $state->data[$name];

			if (is_array($value) || $value === null || $value === '') {
				continue;
			}

			$list->append(new Element('dt')->setText($this->fields->labelFor($field, $options)));
			$list->append(new Element('dd')->setText((string) $value));
		}

		return $list;
	}
}
