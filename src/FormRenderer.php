<?php
declare(strict_types=1);

namespace Meraki\Schema\Html;

use Meraki\Schema\Facade;
use Meraki\Schema\Field;
use Meraki\Schema\Field\Composite as CompositeField;
use Meraki\Schema\Field\CompositeValidationResult;
use Meraki\Schema\Scope;
use Meraki\Schema\Rule\Outcome;
use Meraki\Schema\Rule\Outcome\MakeOptional;
use Meraki\Schema\Rule\Outcome\_Require;
use Meraki\Schema\SchemaValidationResult;
use Meraki\Schema\ValidationResult;

/**
 * Renders a schema as an HTML <form>. Option resolution/defaults live in
 * {@see FormOptionResolver} and validation copy in {@see ValidationMessages};
 * elements are built with {@see Element} so attribute types (booleans, null)
 * are handled natively.
 */
class FormRenderer
{
	/** @var array<class-string, callable(Field, object): Element> */
	private array $fieldRenderers = [];

	/**
	 * Per-render lookup of field (full) name => that field's validation result,
	 * populated for the duration of a single render() call. Lets wrap() surface
	 * inline errors without the field having to store its own result.
	 *
	 * @var array<string, ValidationResult>
	 */
	private array $fieldResults = [];

	/**
	 * Per-render state for condition UI behaviours, populated by render():
	 * the schema, the active behaviours, the current data, and the fields that
	 * a currently-matching rule made optional / required.
	 */
	private Facade $schema;

	/** @var list<ConditionUiBehaviour> */
	private array $behaviours = [];

	/** @var array<string, mixed> */
	private array $ruleData = [];

	/** @var array{optional: array<string, true>, required: array<string, true>} */
	private array $ruleEffects = ['optional' => [], 'required' => []];

	/** Whether a dialog was emitted this render (drives default-stylesheet inclusion). */
	private bool $emittedStyledWidget = false;

	public function __construct(
		private readonly ValidationMessageProvider $validationMessageProvider = new ValidationMessages(),
		private readonly FormOptionResolver $optionResolver = new FormOptionResolver(),
	) {
		$this->registerDefaultFieldRenderers();
	}

	public function render(Facade $schema, ?FormOptions $options = null, ?SchemaValidationResult $result = null): string
	{
		$options ??= new FormOptions();
		$form = $this->startForm($schema, $options);

		if ($options->groups !== [] && $options->flow === 'single-page') {
			$this->renderGroupedInto($form, $schema, $options, $result);
		} else {
			foreach ($this->renderFieldsFor($schema, $schema->fields, $options, $result) as $element) {
				$form->append($element);
			}
		}

		$form->append(new Element('button', ['type' => 'submit'])->setText('Submit'));

		return $this->withDialogStyles((string) $form, $schema, $options);
	}

	/**
	 * Single-page grouped rendering: every group on one page, wrapped per its
	 * {@see Wizard\Container} (fieldset / details / dialog), submitting together.
	 */
	private function renderGroupedInto(Element $form, Facade $schema, FormOptions $options, ?SchemaValidationResult $result): void
	{
		$this->prepareRenderState($schema, $options, $result);

		$byName = [];

		foreach ($schema->fields as $field) {
			$byName[$field->name->value] = $field;
		}

		foreach ($options->groups as $index => $group) {
			$groupFields = [];

			foreach ($group->fieldNames as $name) {
				if (isset($byName[$name])) {
					$groupFields[] = $byName[$name];
				}
			}

			$form->append($this->wrapGroup($group, $index, $options, $this->renderFieldList($groupFields, $options)));
		}
	}

	/**
	 * @param list<Element> $elements
	 */
	private function wrapGroup(Wizard\Group $group, int $index, FormOptions $options, array $elements): Element
	{
		$container = $group->containerOr($options->defaultContainer);

		if ($container === Wizard\Container::Dialog) {
			$this->emittedStyledWidget = true;
			$dialog = new Dialog(
				id: 'mf-group-' . $index,
				open: $group->open,
				trigger: $group->trigger !== 'Open' ? $group->trigger : $group->title,
				confirm: $group->confirm,
				close: $group->close,
			);

			return (new DialogView())->render($dialog, $elements);
		}

		if ($container === Wizard\Container::Details) {
			$this->emittedStyledWidget = true;
			$details = new Element('details', ['class' => 'mf-group']);

			if ($group->open) {
				$details->setAttribute('open', true);
			}

			$details->append(new Element('summary')->setText($group->title));

			foreach ($elements as $element) {
				$details->append($element);
			}

			return $details;
		}

		$fieldset = (new Element('fieldset', ['class' => 'mf-group']))->append(new Element('legend')->setText($group->title));

		foreach ($elements as $element) {
			$fieldset->append($element);
		}

		return $fieldset;
	}

	/**
	 * Prepends the scoped default dialog stylesheet when a dialog was emitted and the
	 * default styles are enabled. Shared with multi-step rendering.
	 */
	public function withDialogStyles(string $html, Facade $schema, FormOptions $options): string
	{
		if ($options->defaultStyles && $this->emittedStyledWidget) {
			return DialogStyles::for((string) $schema->name) . $html;
		}

		return $html;
	}

	public function emittedStyledWidget(): bool
	{
		return $this->emittedStyledWidget;
	}

	/**
	 * The resolved display label for a field (type default + any configured label),
	 * used by multi-step review summaries so they show labels, not field names.
	 */
	public function labelFor(Field $field, FormOptions $options): string
	{
		return $this->optionResolver->resolve($field, $options->fields[$field->name->value] ?? [])->label;
	}

	/**
	 * Opens the <form> element (id/method/action, multipart enctype when a file
	 * field is present, and the hidden _method field for non-POST methods) without
	 * any fields. Exposed so multi-step rendering can reuse the same form shell.
	 */
	public function startForm(Facade $schema, FormOptions $options): Element
	{
		$form = new Element('form', [
			'id' => (string) $schema->name,
			'novalidate' => true,
			'action' => $options->action,
			'method' => $options->method !== 'get' ? 'post' : 'get', // HTML forms only support GET and POST, so we use POST with a hidden _method field for PUT, PATCH, DELETE, etc.
		]);

		if ($this->hasFileField($schema)) {
			$form->setAttribute('enctype', 'multipart/form-data');
		}

		if ($options->method !== 'post') {
			$form->append(new Element('input', [
				'type' => 'hidden',
				'name' => '_method',
				'value' => $options->method,
			]));
		}

		return $form;
	}

	/**
	 * Renders the given fields (a subset of the schema's fields) to elements, after
	 * preparing per-render state (validation results and the rule-driven UI
	 * behaviours). Used by both the single-shot render() and multi-step rendering.
	 *
	 * @param iterable<Field> $fields
	 * @return list<Element>
	 */
	public function renderFieldsFor(Facade $schema, iterable $fields, FormOptions $options, ?SchemaValidationResult $result = null): array
	{
		$this->prepareRenderState($schema, $options, $result);

		return $this->renderFieldList($fields, $options);
	}

	/**
	 * Renders fields to elements without (re)preparing render state — for callers that
	 * prepare once and render several field subsets (e.g. single-page groups).
	 *
	 * @param iterable<Field> $fields
	 * @return list<Element>
	 */
	private function renderFieldList(iterable $fields, FormOptions $options): array
	{
		$elements = [];

		foreach ($fields as $field) {
			$elements[] = $this->renderField($field, $options->fields[$field->name->value] ?? []);
		}

		return $elements;
	}

	private function prepareRenderState(Facade $schema, FormOptions $options, ?SchemaValidationResult $result): void
	{
		$this->fieldResults = $this->indexResults($result);
		$this->schema = $schema;
		$this->emittedStyledWidget = false;
		$this->behaviours = $options->behaviours;
		[$this->ruleData, $this->ruleEffects] = $this->behaviours === []
			? [[], ['optional' => [], 'required' => []]]
			: $this->deriveRuleEffects($schema);
	}

	/**
	 * @param callable(Field, object): Element $renderer
	 */
	public function registerFieldRenderer(string $fqcn, callable $renderer): void
	{
		$this->fieldRenderers[$fqcn] = $renderer;
	}

	private function registerDefaultFieldRenderers(): void
	{
		$this->registerFieldRenderer(Field\Address::class,      $this->renderCompositeField(...));
		$this->registerFieldRenderer(Field\Boolean::class,      $this->renderBooleanField(...));
		$this->registerFieldRenderer(Field\Collection::class,   $this->renderCollectionField(...));
		$this->registerFieldRenderer(Field\Composite::class,    $this->renderCompositeField(...));
		$this->registerFieldRenderer(Field\CreditCard::class,   $this->renderCompositeField(...));
		$this->registerFieldRenderer(Field\Date::class,         $this->renderInputField(...));
		$this->registerFieldRenderer(Field\DateTime::class,     $this->renderInputField(...));
		$this->registerFieldRenderer(Field\Duration::class,     $this->renderInputField(...));
		$this->registerFieldRenderer(Field\EmailAddress::class, $this->renderInputField(...));
		$this->registerFieldRenderer(Field\Enum::class,         $this->renderEnumField(...));
		$this->registerFieldRenderer(Field\File::class,         $this->renderInputField(...));
		$this->registerFieldRenderer(Field\Money::class,        $this->renderCompositeField(...));
		$this->registerFieldRenderer(Field\Name::class,         $this->renderInputField(...));
		$this->registerFieldRenderer(Field\Number::class,       $this->renderInputField(...));
		$this->registerFieldRenderer(Field\Passphrase::class,   $this->renderInputField(...));
		$this->registerFieldRenderer(Field\Password::class,     $this->renderInputField(...));
		$this->registerFieldRenderer(Field\PhoneNumber::class,  $this->renderInputField(...));
		$this->registerFieldRenderer(Field\Text::class,         $this->renderTextField(...));
		$this->registerFieldRenderer(Field\Time::class,         $this->renderInputField(...));
		$this->registerFieldRenderer(Field\Uri::class,          $this->renderInputField(...));
		$this->registerFieldRenderer(Field\Uuid::class,         $this->renderInputField(...));
		$this->registerFieldRenderer(Field\Variant::class,      $this->renderInputField(...));
	}

	private function renderField(Field $field, array $options = []): Element
	{
		$renderer = $this->fieldRenderers[$field::class] ?? null;

		if ($renderer === null) {
			throw new \RuntimeException('No renderer registered for field type: ' . $field::class);
		}

		$options = $this->optionResolver->resolve($field, $options);
		$this->applyBehaviours($field, $options);

		$this->assertRendererIsCompatibleWithField(Renderer::from($options->renderer), $field);

		// A "pick or add" combobox overrides the type's normal control (FieldOptions::allowAddingOptions).
		$element = ($options->addOptions ?? false)
			? $this->renderCombobox($field, $options)
			: $renderer($field, $options);

		if (isset($options->dialog) && is_array($options->dialog)) {
			$element = $this->wrapFieldInDialog($options, $element);
		} elseif (isset($options->reveal) && is_array($options->reveal)) {
			$element = $this->wrapFieldRevealable($options, $element);
		}

		return $element;
	}

	/**
	 * Wraps a rendered field in a no-JS dialog (a `show-modal` invoker + a `<dialog>`)
	 * when the field's options carry a dialog spec (see FieldOptions::revealWithDialog()).
	 */
	private function wrapFieldInDialog(object $o, Element $field): Element
	{
		$this->emittedStyledWidget = true;
		$spec = $o->dialog;

		$dialog = new Dialog(
			id: $o->id . '-dialog',
			open: (bool) ($spec['open'] ?? false),
			trigger: $spec['trigger'] ?? 'Open',
			confirm: $spec['confirm'] ?? 'Save',
			close: $spec['close'] ?? 'Cancel',
		);

		return (new DialogView())->render($dialog, [$field]);
	}

	/**
	 * Reveals an (optional) field via a native no-JS toggle: <details>/<summary>
	 * (mode "inline") or a command-invoker popover (mode "popup"). See
	 * FieldOptions::revealInline()/revealWithPopup().
	 */
	private function wrapFieldRevealable(object $o, Element $field): Element
	{
		$spec = $o->reveal;
		$trigger = $spec['trigger'] ?? 'Show';

		if (($spec['mode'] ?? 'inline') === 'popup') {
			$this->emittedStyledWidget = true;
			$id = $o->id . '-reveal';

			return (new Element('div', ['class' => 'mf-reveal']))
				->append(new Element('button', [
					'type'       => 'button',
					'command'    => 'toggle-popover',
					'commandfor' => $id,
					'class'      => 'mf-reveal-trigger',
				])->setText($trigger))
				->append((new Element('div', ['id' => $id, 'popover' => 'auto', 'class' => 'mf-popup']))->append($field));
		}

		$this->emittedStyledWidget = true;
		$details = new Element('details', ['class' => 'mf-reveal']);

		if ($spec['open'] ?? false) {
			$details->setAttribute('open', true);
		}

		return $details
			->append(new Element('summary')->setText($trigger))
			->append($field);
	}

	/**
	 * Runs the active condition UI behaviours, letting each adjust the resolved
	 * options object in place before the field is rendered.
	 */
	private function applyBehaviours(Field $field, object $options): void
	{
		if ($this->behaviours === []) {
			return;
		}

		$name = $field->name->value;
		$context = new FieldRenderContext(
			field: $field,
			options: $options,
			schema: $this->schema,
			data: $this->ruleData,
			madeOptionalByMatchedRule: isset($this->ruleEffects['optional'][$name]),
			requiredByMatchedRule: isset($this->ruleEffects['required'][$name]),
		);

		foreach ($this->behaviours as $behaviour) {
			$behaviour->apply($context);
		}
	}

	/**
	 * Evaluates the schema's own rules against the current data and derives, per
	 * field, whether a currently-matching rule made it optional or required. This
	 * reads the rules the schema owns; it never defines conditions in the HTML
	 * layer.
	 *
	 * @return array{0: array<string, mixed>, 1: array{optional: array<string, true>, required: array<string, true>}}
	 */
	/**
	 * Field names that would render hidden (non-interactive) for the schema's current
	 * resolved values under these options — i.e. a matched rule made them optional and a
	 * hide behaviour is active. The schema must already have its data applied. Used by the
	 * stepped flow to skip groups with nothing to fill in.
	 *
	 * @return array<string, true>
	 */
	public function fieldsHiddenByRules(Facade $schema, FormOptions $options): array
	{
		$hidesOptional = false;

		foreach ($options->behaviours as $behaviour) {
			if ($behaviour instanceof Behaviour\HideOptionalFieldsResolvedByRules) {
				$hidesOptional = true;
				break;
			}
		}

		if (!$hidesOptional) {
			return [];
		}

		[, $effects] = $this->deriveRuleEffects($schema);

		// A field re-required by another matched rule stays visible.
		return array_diff_key($effects['optional'], $effects['required']);
	}

	private function deriveRuleEffects(Facade $schema): array
	{
		$data = [];

		foreach ($schema->fields as $field) {
			$data[$field->name->value] = $field->resolvedValue->unwrap();
		}

		$optional = [];
		$required = [];

		foreach ($schema->rules as $rule) {
			// A Scope advances its internal position when resolved and the host has
			// already evaluated these conditions during input(); rewind so this
			// re-evaluation starts from the beginning of each path.
			foreach ($rule->condition->getScopes() as $scope) {
				$scope->rewind();
			}

			if (!$rule->condition->matches($data, $schema)) {
				continue;
			}

			foreach ($rule->outcomes as $outcome) {
				$name = $this->outcomeTargetName($outcome, $schema);

				if ($name === null) {
					continue;
				}

				if ($outcome instanceof MakeOptional) {
					$optional[$name] = true;
				} elseif ($outcome instanceof _Require) {
					$required[$name] = true;
				}
			}
		}

		return [$data, ['optional' => $optional, 'required' => $required]];
	}

	private function outcomeTargetName(Outcome $outcome, Facade $schema): ?string
	{
		// Resolve a fresh Scope (the outcome's own may be left at an out-of-bounds
		// position by a prior resolve) to the target field and read its real name.
		$result = (new Scope((string) $outcome->getScope()))->resolve($schema);
		$target = is_object($result) ? ($result->value ?? null) : null;

		return $target instanceof Field ? $target->name->value : null;
	}

	private function assertRendererIsCompatibleWithField(Renderer $renderer, Field $field): void
	{
		if (!Renderer::isValidForField($renderer, $field)) {
			throw new \RuntimeException(sprintf(
				'Renderer "%s" is not compatible with field type: %s',
				$renderer->value,
				$field::class,
			));
		}
	}

	private function renderInputField(Field $field, object $o): Element
	{
		$input = new Element('input', [
			'type' => $o->renderer,
			'id' => $o->id,
			'name' => $o->input_name ?? $field->name->value,
		]);

		$this->applyValue($input, $field, $o);
		$this->applyHint($input, $o);
		$this->applyGlobalAttributes($input, $field, $o);

		return $this->wrap($o->type, $field, $o, $this->label($o), $input);
	}

	/**
	 * Sets the `placeholder` attribute from the field's hint (see FieldOptions::hint()).
	 * Harmless on input types that ignore it; dropdowns use the hint as their option text.
	 */
	private function applyHint(Element $element, object $o): void
	{
		if (isset($o->hint) && is_string($o->hint) && $o->hint !== '') {
			$element->setAttribute('placeholder', $o->hint);
		}
	}

	private function renderBooleanField(Field\Boolean $field, object $o): Element
	{
		$resolved = $o->value ?? $field->resolvedValue->unwrap();
		$input = new Element('input', [
			'type' => 'checkbox',
			'id' => $o->id,
			'name' => $o->input_name ?? $field->name->value,
			'autocomplete' => 'off',
			'checked' => !is_array($resolved) && (bool) $resolved,
		]);

		$this->applyGlobalAttributes($input, $field, $o);

		return $this->wrap('boolean', $field, $o, $this->label($o), $input);
	}

	private function renderTextField(Field\Text $field, object $o): Element
	{
		$useTextarea = ($o->multiline ?? false) || $o->renderer === 'textarea';
		$name = $o->input_name ?? $field->name->value;

		if ($useTextarea) {
			$element = new Element('textarea', ['id' => $o->id, 'name' => $name]);
			$value = $o->value ?? $field->resolvedValue->unwrap();

			if (is_string($value) && $value !== '') {
				$element->setText($value);
			}
		} else {
			$element = new Element('input', ['type' => 'text', 'id' => $o->id, 'name' => $name]);
			$this->applyValue($element, $field, $o);
		}

		$this->applyHint($element, $o);
		$this->applyGlobalAttributes($element, $field, $o);

		return $this->wrap('text', $field, $o, $this->label($o), $element);
	}

	private function renderEnumField(Field\Enum $field, object $o): Element
	{
		$name = $o->input_name ?? $field->name->value;
		$optionConfigs = $o->options ?? [];
		$selected = $o->value ?? $field->resolvedValue->unwrap() ?? $field->defaultValue->unwrap();

		if ($o->renderer === 'dropdown') {
			$customizable = (bool) ($o->customizable ?? false);
			$select = new Element('select', ['id' => $o->id, 'name' => $name, 'autocomplete' => 'none']);

			if ($customizable) {
				$this->emittedStyledWidget = true;
				$select->setAttribute('class', 'mf-select');
			}

			$this->applyGlobalAttributes($select, $field, $o);

			$hasSelection = in_array($selected, $field->oneOf, true);

			foreach ($field->oneOf as $choice) {
				$label = ($optionConfigs[$choice]['label'] ?? null) ?? $choice;
				$option = new Element('option', ['value' => $choice])->setText($label);
				$option->setAttribute('selected', $selected === $choice);
				$select->append($option);
			}

			// With nothing selected, prepend a non-choosable placeholder. It submits an
			// empty value so a required enum fails server-side validation (and a prior
			// selection is preserved via $selected above when re-rendering).
			if (!$hasSelection) {
				$select->prepend(new Element('option', [
					'value'    => '',
					'disabled' => true,
					'selected' => true,
					'hidden'   => true,
				])->setText($o->hint ?? 'Please select an option'));
			}

			// Customizable <select> (appearance: base-select): a button trigger whose
			// <selectedcontent> mirrors the chosen option. Must be the select's first child.
			if ($customizable) {
				$select->prepend((new Element('button', ['type' => 'button']))->append(new Element('selectedcontent')));
			}

			return $this->wrap('enum', $field, $o, $this->label($o), $select);
		}

		// radiogroup (default) or buttongroup — both are radios (no JS); a button group
		// just adds a class so the host can style the labels as a segmented control.
		$isButtons = $o->renderer === 'buttongroup';
		$fieldset = new Element('fieldset', [
			'id'    => $o->id,
			'class' => $isButtons ? 'mf-buttongroup' : 'mf-radiogroup',
		])->append(new Element('legend')->setText($o->label));

		foreach ($field->oneOf as $choice) {
			$label = ($optionConfigs[$choice]['label'] ?? null) ?? $choice;

			$radio = new Element('input', [
				'type' => 'radio',
				'name' => $name,
				'value' => $choice,
				'required' => !$field->optional,
				'readonly' => (bool) $o->readonly,
				'disabled' => (bool) $o->disabled,
				'autofocus' => (bool) $o->autofocus,
				'checked' => $selected === $choice,
			]);

			if ($o->hidden || $o->disabled || $o->readonly) {
				$radio->setAttribute('tabindex', '-1');
			}

			$fieldset->append((new Element('label', ['class' => $isButtons ? 'mf-button' : 'mf-radio']))
				->append($radio, $label));
		}

		return $this->wrap('enum', $field, $o, $fieldset);
	}

	/**
	 * A no-JS combobox: a text input bound to a native <datalist> of suggestions, letting
	 * the user pick an existing option or type a new one. The submitted value is whatever
	 * is in the input (existing or new). See FieldOptions::allowAddingOptions().
	 */
	private function renderCombobox(Field $field, object $o): Element
	{
		$name = $o->input_name ?? $field->name->value;
		$listId = $o->id . '-options';
		$selected = $o->value ?? $field->resolvedValue->unwrap() ?? $field->defaultValue->unwrap();

		$input = new Element('input', [
			'type'         => 'text',
			'id'           => $o->id,
			'name'         => $name,
			'list'         => $listId,
			'class'        => 'mf-combobox',
			'autocomplete' => 'off',
		]);

		if (is_string($selected) && $selected !== '') {
			$input->setAttribute('value', $selected);
		}

		$this->applyGlobalAttributes($input, $field, $o);
		$this->applyHint($input, $o);

		$datalist = new Element('datalist', ['id' => $listId]);

		foreach ($this->comboboxChoices($field, $o) as $value => $label) {
			$datalist->append(new Element('option', ['value' => (string) $value])->setText((string) $label));
		}

		return $this->wrap($o->type, $field, $o, $this->label($o), $input, $datalist);
	}

	/**
	 * The combobox's suggestions: an enum's own options, or the labelled options supplied
	 * via FieldOptions for any other field type.
	 *
	 * @return array<string, string> value => label
	 */
	private function comboboxChoices(Field $field, object $o): array
	{
		$configs = $o->options ?? [];
		$choices = [];

		if ($field instanceof Field\Enum) {
			foreach ($field->oneOf as $choice) {
				$choices[(string) $choice] = ($configs[$choice]['label'] ?? null) ?? (string) $choice;
			}

			return $choices;
		}

		foreach ($configs as $value => $config) {
			$choices[(string) $value] = is_array($config) ? ($config['label'] ?? (string) $value) : (string) $config;
		}

		return $choices;
	}

	private function renderCompositeField(CompositeField $field, object $o): Element
	{
		$fieldset = new Element('fieldset', ['id' => $o->id, 'class' => 'composite-field ' . $o->label])
			->append(new Element('legend')->setText($o->label));

		$parentInputName = $o->input_name ?? $field->name->value;
		// A passed value (e.g. one item of a collection) takes precedence over the shared
		// template field's resolved value. Such a value is keyed by local sub-name; a
		// top-level composite's resolved value is keyed by full prefixed name — honour both.
		$parentValue = $o->value ?? $field->resolvedValue->unwrap();
		$parentValueArray = is_array($parentValue) ? $parentValue : [];

		foreach ($field->fields as $subField) {
			$localName = $subField->name->removePrefix()->value;
			$defaultSubOptions = ($o->fields ?? [])[$localName] ?? [];
			$userSubOptions = (array) ($o->$localName ?? []);
			$subOptions = array_merge($defaultSubOptions, $userSubOptions, [
				'input_name' => $parentInputName . '[' . $localName . ']',
			]);

			if ($parentValueArray !== [] && !array_key_exists('value', $subOptions)) {
				$subOptions['value'] = $parentValueArray[$localName]
					?? $parentValueArray[$subField->name->value]
					?? null;
			}

			$fieldset->append($this->renderField($subField, $subOptions));
		}

		return $this->wrap($o->type, $field, $o, $fieldset);
	}

	private function renderCollectionField(Field\Collection $field, object $o): Element
	{
		$name = $o->input_name ?? $field->name->value;
		$items = $field->resolvedValue->unwrap();
		$items = is_array($items) ? array_values($items) : [];

		$fieldset = new Element('fieldset', [
			'id' => $o->id,
			'class' => 'collection',
			'data-name' => $field->name->value,
		])->append(new Element('legend')->setText($o->label));

		$inDialog = isset($o->addDialog) && is_array($o->addDialog);

		// When adding happens in a dialog, existing items are shown read-only in the main
		// form (carried via hidden inputs); editing/adding happens in the dialog. Inline
		// collections keep their items editable in place.
		foreach ($items as $index => $item) {
			$item = is_array($item) ? $item : [];
			$fieldset->append($inDialog
				? $this->renderCollectionItemView($field, $name, (int) $index, $item)
				: $this->renderCollectionItem($field, $name, (int) $index, $item, true));
		}

		// A blank "add" item rendered at the next index. Filling it and submitting the
		// "add:<field>" action appends it (empty items are dropped by the field). Some
		// sub-fields may inherit their default from the first item (FieldOptions::inheritInNewItems).
		$blankValues = [];

		if ($items !== [] && isset($o->inheritInNewItems) && is_array($o->inheritInNewItems)) {
			foreach ($o->inheritInNewItems as $local) {
				if (isset($items[0][$local])) {
					$blankValues[$local] = $items[0][$local];
				}
			}
		}

		$blank = $this->renderCollectionItem($field, $name, count($items), $blankValues, false);

		if ($inDialog) {
			$this->emittedStyledWidget = true;
			$spec = $o->addDialog;
			$dialog = new Dialog(
				id: $o->id . '-add',
				open: (bool) ($spec['open'] ?? false),
				trigger: $spec['trigger'] ?? 'Add',
				confirm: $spec['confirm'] ?? 'Add',
				close: $spec['close'] ?? 'Cancel',
				action: 'add:' . $name,
			);
			$fieldset->append((new DialogView())->render($dialog, [$blank]));
			// Mark which row is the dialog's draft. It is only committed by its add:<field>
			// action; the wizard drops it on any other action (next/back/remove) so a
			// prefilled/inherited draft never becomes a phantom item or survives a remove.
			$fieldset->append(new Element('input', [
				'type'  => 'hidden',
				'name'  => '__draft[' . $field->name->value . ']',
				'value' => (string) count($items),
			]));
		} else {
			$fieldset->append((new Element('div', ['class' => 'collection-add']))
				->append($blank)
				->append(new Element('button', [
					'type' => 'submit',
					'name' => '__wizard[action]',
					'value' => 'add:' . $name,
				])->setText('Add')));
		}

		return $this->wrap($o->type, $field, $o, $fieldset);
	}

	/**
	 * @param array<string, mixed> $item
	 */
	private function renderCollectionItem(Field\Collection $field, string $parentName, int $index, array $item, bool $removable): Element
	{
		$group = new Element('div', ['class' => 'collection-item', 'data-index' => (string) $index]);

		foreach ($field->fields as $subField) {
			$local = $subField->name->removePrefix()->value;
			$group->append($this->renderField($subField, [
				'input_name' => $parentName . '[' . $index . '][' . $local . ']',
				'value' => $item[$local] ?? null,
				'label' => ucfirst(str_replace('_', ' ', $local)),
			]));
		}

		if ($removable) {
			$group->append(new Element('button', [
				'type' => 'submit',
				'name' => '__wizard[action]',
				'value' => 'remove:' . $parentName . ':' . $index,
				'formnovalidate' => true,
			])->setText('Remove'));
		}

		return $group;
	}

	/**
	 * A read-only view of an existing collection item (used when items are added via a
	 * dialog): each value is shown as text and carried in a hidden input, with a Remove
	 * button. The editable form lives in the add dialog.
	 *
	 * @param array<string, mixed> $item
	 */
	private function renderCollectionItemView(Field\Collection $field, string $parentName, int $index, array $item): Element
	{
		$group = new Element('div', ['class' => 'collection-item', 'data-index' => (string) $index]);

		foreach ($field->fields as $subField) {
			$local = $subField->name->removePrefix()->value;
			$this->appendItemView($group, $parentName . '[' . $index . '][' . $local . ']', ucfirst(str_replace('_', ' ', $local)), $item[$local] ?? null);
		}

		$group->append(new Element('button', [
			'type'           => 'submit',
			'name'           => '__wizard[action]',
			'value'          => 'remove:' . $parentName . ':' . $index,
			'formnovalidate' => true,
		])->setText('Remove'));

		return $group;
	}

	/**
	 * Appends a read-only view of one collection-item value: a labelled text span plus a
	 * hidden input that carries it. Recurses into composite (array) values so a per-item
	 * address shows each leaf and carries `parent[i][field][leaf]`.
	 */
	private function appendItemView(Element $group, string $name, string $label, mixed $value): void
	{
		if (is_array($value)) {
			foreach ($value as $key => $leaf) {
				$childLabel = $label . ' ' . str_replace('_', ' ', (string) $key);
				$this->appendItemView($group, $name . '[' . $key . ']', $childLabel, $leaf);
			}

			return;
		}

		$group->append(new Element('span', ['class' => 'collection-value'])
			->setText($label . ': ' . (string) ($value ?? '')));

		$group->append(new Element('input', [
			'type'  => 'hidden',
			'name'  => $name,
			'value' => (string) ($value ?? ''),
		]));
	}

	private function label(object $o): Element
	{
		return new Element('label', ['for' => $o->id])->setText($o->label);
	}

	private function applyGlobalAttributes(Element $element, Field $field, object $o): void
	{
		$element->setAttributes([
			'required' => !$field->optional,
			'readonly' => (bool) $o->readonly,
			'disabled' => (bool) $o->disabled,
			'hidden' => (bool) $o->hidden,
			'autofocus' => (bool) $o->autofocus,
		]);

		// If the field is hidden, disabled, or readonly, then prevent keyboard focus.
		if ($o->hidden || $o->disabled || $o->readonly) {
			$element->setAttribute('tabindex', '-1');
		}
	}

	private function applyValue(Element $element, Field $field, object $o): void
	{
		if ($field instanceof Field\Password || $field instanceof Field\Passphrase) {
			return;
		}

		$value = $o->value ?? $field->resolvedValue->unwrap();

		if ($value === null || $value === '' || is_array($value)) {
			return;
		}

		$element->setAttribute('value', (string) $value);
	}

	private function wrap(string $type, Field $field, object $o, Element|string ...$children): Element
	{
		$wrapper = new Element('div', ['class' => 'field', 'data-type' => $type, 'data-name' => $field->name->value]);

		// hidden means "don't show this field at all", including from screen readers, so we hide the entire wrapper including label and errors
		if ($o->hidden) {
			$wrapper->setAttribute('hidden', true);
			$wrapper->setAttribute('tabindex', '-1');
		}

		// If the field is disabled or readonly, we still show it but prevent keyboard focus on the wrapper (e.g. for screen readers) since the inputs inside will also be non-interactive.
		if ($o->disabled || $o->readonly) {
			$wrapper->setAttribute('tabindex', '-1');
		}

		foreach ($children as $child) {
			$wrapper->append($child);
		}

		$errors = new Element('div', ['class' => 'errors']);
		$result = $this->fieldResults[$field->name->value] ?? null;

		foreach ($this->validationMessageProvider->errorsFor($field, $result) as $error) {
			$errors->append(new Element('p')->setText($error));
		}

		return $wrapper->append($errors);
	}

	/**
	 * Flattens a schema validation result into a (full) field-name => result map,
	 * including composite sub-field results so each rendered field can find its own.
	 *
	 * @return array<string, ValidationResult>
	 */
	private function indexResults(?SchemaValidationResult $result): array
	{
		if ($result === null) {
			return [];
		}

		$index = [];

		foreach ($result as $fieldResult) {
			if ($fieldResult instanceof CompositeValidationResult) {
				$index[$fieldResult->composite->name->value] = $fieldResult;

				foreach ($fieldResult as $subResult) {
					$index[$subResult->field->name->value] = $subResult;
				}

				continue;
			}

			$index[$fieldResult->field->name->value] = $fieldResult;
		}

		return $index;
	}

	private function hasFileField(Facade $schema): bool
	{
		foreach ($schema->fields as $field) {
			if ($field instanceof Field\File) {
				return true;
			}
		}

		return false;
	}
}
