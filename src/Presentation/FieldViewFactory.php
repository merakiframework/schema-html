<?php
declare(strict_types=1);

namespace Meraki\Schema\Html\Presentation;

use Meraki\Schema\Field;
use Meraki\Schema\Field\Collection\Item;
use Meraki\Schema\Field\Collection\Result as CollectionResult;
use Meraki\Schema\FieldResult;
use Meraki\Schema\Html\Dialog;
use Meraki\Schema\Html\Exception\IncompatibleRenderer;
use Meraki\Schema\Html\FieldRenderContext;
use Meraki\Schema\Html\FormOptionResolver;
use Meraki\Schema\Html\Renderer;
use Meraki\Schema\Html\SettledPart;
use Meraki\Schema\Html\SettledValues;
use Meraki\Schema\Message\PartedSet;
use Meraki\Schema\ResolvedField;
use Meraki\Schema\ValueSource;
use Stringable;
use UnexpectedValueException;

/**
 * Builds the {@see FieldView} a theme draws from, out of a field's result for this request.
 *
 * Everything that is *behaviour* rather than markup is decided here, once, so every theme gets
 * it right:
 *
 * - the options: type defaults, then the caller's, then the condition behaviours;
 * - `required` from the **effective** definition, so a rule that made a field optional or
 *   required is reflected;
 * - what to show in the control: exactly what was submitted when something was, the value when
 *   it came from a prefill or the authored default, and never a password or a card's number or
 *   security code;
 * - the messages, in the form's language, placed against the part they are about;
 * - a structured field's parts ({@see PartLayout}), with settled parts left out, hidden or shown
 *   ({@see SettledPart});
 * - a collection's rows, each field in each row built the same way, plus a spare row named by
 *   the form's {@see \Meraki\Schema\Html\Request\RowKeys}.
 */
final class FieldViewFactory
{
	private readonly PartLayouts $layouts;

	public function __construct(
		private readonly FormOptionResolver $resolver = new FormOptionResolver(),
		?PartLayouts $layouts = null,
	) {
		$this->layouts = $layouts ?? PartLayouts::defaults();
	}

	/**
	 * @param array<string, mixed> $fieldOptions the caller's options for this field
	 *
	 * @throws IncompatibleRenderer when the field was configured to render as something its
	 *         type cannot be drawn as
	 */
	public function build(FieldResult $result, array $fieldOptions, Scene $scene): FieldView
	{
		$result = $result->withMessagesFrom($scene->translator);
		$field = $result->field;
		$o = $this->resolver->resolve($field, $fieldOptions);

		$this->applyBehaviours($result, $o, $scene);

		$renderer = Renderer::from((string) $o->renderer);
		$valid = Renderer::validFor($field);

		if ($valid !== [] && !in_array($renderer, $valid, true)) {
			throw IncompatibleRenderer::for($renderer, $field);
		}

		$inputName = (string) ($o->input_name ?? $field->name);
		$echo = self::echoOf($result);
		[$whole, $byPart] = self::messagesOf($result);

		$control = new Control(
			id: (string) $o->id,
			name: $inputName,
			type: $renderer->value,
			value: self::text($echo),
			checked: $echo === true,
			required: self::isRequired($field),
			readonly: (bool) $o->readonly,
			disabled: (bool) $o->disabled,
			hidden: (bool) $o->hidden,
			autofocus: (bool) $o->autofocus,
			autocomplete: $o->autocomplete,
			pattern: self::string($o->pattern ?? null),
			inputmode: self::string($o->inputmode ?? null),
			placeholder: self::string($o->hint ?? null),
			choices: $this->choicesFor($field, $o),
		);

		$parts = [];
		$layout = $this->layouts->for($field);

		if ($layout !== null) {
			$parts = $this->parts($layout, $field, $o, $fieldOptions, is_array($echo) ? $echo : [], $byPart, $control, $scene);
		}

		// A part that is not drawn — settled, or one the field has no use for — still has to
		// say what is wrong with it somewhere, and the field as a whole is the only place left.
		foreach ($byPart as $part => $said) {
			if (!isset($parts[$part])) {
				$whole = [...$whole, ...$said];
			}
		}

		[$rows, $blankRow] = $field instanceof Field\Collection
			? $this->rows($field, $result, $o, $fieldOptions, $inputName, $scene)
			: [[], null];

		return new FieldView(
			field: $field,
			result: $result,
			type: (string) $o->type,
			name: (string) ($o->data_name ?? $field->name),
			label: (string) $o->label,
			renderer: $renderer,
			control: $control,
			errors: $whole,
			parts: $parts,
			rows: $rows,
			blankRow: $blankRow,
			dialog: self::dialogFor($o),
			reveal: self::revealFor($o),
			addDialog: self::addDialogFor($o, $inputName),
			multiline: (bool) ($o->multiline ?? false) || $renderer === Renderer::Textarea,
			customizable: (bool) ($o->customizable ?? false),
			combobox: (bool) ($o->addOptions ?? false),
		);
	}

	/**
	 * The label a field is drawn with — type default, or whatever the caller configured.
	 *
	 * @param array<string, mixed> $fieldOptions
	 */
	public function labelFor(Field $field, array $fieldOptions): string
	{
		return (string) $this->resolver->resolve($field, $fieldOptions)->label;
	}

	private function applyBehaviours(FieldResult $result, object $o, Scene $scene): void
	{
		$behaviours = $scene->options->behaviours;

		if ($behaviours === []) {
			return;
		}

		$context = new FieldRenderContext($result, RuleEffects::of($result), $o, $scene->schema);

		foreach ($behaviours as $behaviour) {
			$behaviour->apply($context);
		}
	}

	/**
	 * @param array<string, mixed> $fieldOptions
	 * @param array<string, mixed> $values the field's value, by part
	 * @param array<string, list<string>> $byPart
	 * @return array<string, PartView>
	 */
	private function parts(
		PartLayout $layout,
		Field $field,
		object $o,
		array $fieldOptions,
		array $values,
		array $byPart,
		Control $whole,
		Scene $scene,
	): array {
		$settled = SettledValues::of($field);
		$strategy = SettledPart::tryFrom((string) ($o->settledParts ?? '')) ?? $scene->options->settledParts;
		$views = [];

		foreach ($layout->parts($field, $o) as $part => $spec) {
			$isSettled = array_key_exists($part, $settled);

			if ($isSettled && $strategy === SettledPart::Omit) {
				continue;
			}

			$user = is_array($fieldOptions[$part] ?? null) ? $fieldOptions[$part] : [];
			$po = array_merge($spec, $user);
			$value = $values[$part] ?? ($isSettled ? $settled[$part] : null);
			$required = !$field->optional && (bool) ($po['required'] ?? false);
			$errors = $byPart[$part] ?? [];

			$widget = match (true) {
				$isSettled && $strategy === SettledPart::Hidden => PartView::WIDGET_HIDDEN,
				($po['renderer'] ?? null) === Renderer::Dropdown->value => PartView::WIDGET_SELECT,
				($po['renderer'] ?? null) === Renderer::Text->value => PartView::WIDGET_INPUT,
				($po['renderer'] ?? null) === Renderer::Textarea->value => PartView::WIDGET_TEXTAREA,
				default => (string) ($po['widget'] ?? PartView::WIDGET_INPUT),
			};

			$views[$part] = $this->partView($part, $po, $widget, $value, $required, $errors, $isSettled, $whole);
		}

		return $views;
	}

	/**
	 * @param array<string, mixed> $po the part's options
	 * @param list<string> $errors
	 */
	private function partView(
		string $part,
		array $po,
		string $widget,
		mixed $value,
		bool $required,
		array $errors,
		bool $settled,
		Control $whole,
	): PartView {
		$labels = is_array($po['options'] ?? null) ? $po['options'] : [];
		$choices = [];

		foreach ((array) ($po['choices'] ?? []) as $choice => $label) {
			$choices[(string) $choice] = self::labelOf($labels, (string) $choice, (string) $label);
		}

		return new PartView(
			name: $part,
			label: (string) ($po['label'] ?? ucfirst(str_replace('_', ' ', $part))),
			control: new Control(
				id: (string) ($po['id'] ?? $whole->id . '-' . $part),
				name: $whole->name . '[' . $part . ']',
				type: (string) ($po['type'] ?? 'text'),
				// A part held as a list (an address's street) is shown one entry per line.
				value: self::text(is_array($value) ? implode("\n", array_filter($value, is_string(...))) : $value),
				required: $required,
				readonly: (bool) ($po['readonly'] ?? $whole->readonly),
				disabled: (bool) ($po['disabled'] ?? $whole->disabled),
				hidden: (bool) ($po['hidden'] ?? false),
				autofocus: (bool) ($po['autofocus'] ?? false),
				autocomplete: FormOptionResolver::autocompleteFor($po),
				pattern: self::string($po['pattern'] ?? null),
				inputmode: self::string($po['inputmode'] ?? null),
				placeholder: self::string($po['hint'] ?? null),
				choices: $choices,
				attributes: isset($po['rows']) ? ['rows' => (int) $po['rows']] : [],
			),
			widget: $widget,
			errors: $errors,
			settled: $settled,
		);
	}

	/**
	 * @param array<string, mixed> $fieldOptions
	 * @return array{list<RowView>, RowView}
	 */
	private function rows(
		Field\Collection $collection,
		FieldResult $result,
		object $o,
		array $fieldOptions,
		string $inputName,
		Scene $scene,
	): array {
		/** @var array<string, Item> $items */
		$items = $result instanceof CollectionResult ? $result->items : [];
		$rows = [];

		foreach ($items as $key => $item) {
			$fields = [];

			foreach ($collection->template as $template) {
				$local = (string) $template->name;
				$leaf = $item->forField($local) ?? self::resolved($template, null);
				$fields[] = $this->build($leaf, $this->rowOptions($collection, $o, $fieldOptions, $inputName, (string) $key, $local), $scene);
			}

			$rows[] = new RowView((string) $key, $fields);
		}

		$key = $scene->options->rowKeys->next(array_map(strval(...), array_keys($items)));
		$inherited = self::inherited($o, $items);
		$blank = [];

		foreach ($collection->template as $template) {
			$local = (string) $template->name;
			$blank[] = $this->build(
				self::resolved($template, $inherited[$local] ?? null),
				$this->rowOptions($collection, $o, $fieldOptions, $inputName, $key, $local),
				$scene,
			);
		}

		return [$rows, new RowView($key, $blank)];
	}

	/**
	 * A row field's options: labelled after its own name, named and identified per row, with
	 * whatever the caller configured for that template field laid over the label.
	 *
	 * @param array<string, mixed> $fieldOptions
	 * @return array<string, mixed>
	 */
	private function rowOptions(
		Field\Collection $collection,
		object $o,
		array $fieldOptions,
		string $inputName,
		string $key,
		string $local,
	): array {
		$user = is_array($fieldOptions[$local] ?? null) ? $fieldOptions[$local] : [];

		return array_merge(
			['label' => ucfirst(str_replace('_', ' ', $local))],
			$user,
			[
				'input_name' => $inputName . '[' . $key . '][' . $local . ']',
				'id' => $o->id . '-' . $key . '-' . $local,
				'data_name' => $collection->name . '.' . $local,
			],
		);
	}

	/**
	 * Values the spare row starts with, copied from the first row
	 * (FieldOptions::inheritInNewItems()).
	 *
	 * @param array<string, Item> $items
	 * @return array<string, mixed>
	 */
	private static function inherited(object $o, array $items): array
	{
		$names = is_array($o->inheritInNewItems ?? null) ? $o->inheritInNewItems : [];
		$first = reset($items);

		if ($names === [] || $first === false) {
			return [];
		}

		$values = [];

		foreach ($names as $local) {
			$leaf = $first->forField((string) $local);

			if ($leaf !== null && $leaf->given !== null) {
				$values[(string) $local] = $leaf->given;
			}
		}

		return $values;
	}

	private static function resolved(Field $field, mixed $given): FieldResult
	{
		$result = $field->resolve($given);

		return $result instanceof FieldResult
			? $result
			: throw new UnexpectedValueException(sprintf('Field "%s" did not resolve to a field result.', $field->name));
	}

	private static function isRequired(Field $field): bool
	{
		return match (true) {
			// A checkbox can only be required in the sense of "must be ticked". A Boolean that is
			// merely required accepts `false`, which an unticked box already says.
			$field instanceof Field\Boolean => $field->requiresAcceptance && !$field->optional,
			$field instanceof Field\Collection => false,
			default => !$field->optional,
		};
	}

	/**
	 * What to show in the control for this request.
	 *
	 * @return mixed a string or bool for a field with one value, an array of parts for a
	 *         structured one, or null for nothing
	 */
	private static function echoOf(FieldResult $result): mixed
	{
		$field = $result->field;

		if ($field instanceof Field\Password || !$result instanceof ResolvedField) {
			return null;
		}

		$echo = match ($result->source) {
			// Exactly what they typed: showing a coerced value turns a correction into a second
			// mistake.
			ValueSource::Submitted => self::wire($result->given),
			ValueSource::Prefilled, ValueSource::Default => self::fromValue($result->value),
			ValueSource::None => null,
		};

		// A card number and security code are never written back into a page.
		if ($field instanceof Field\CreditCard && is_array($echo)) {
			unset($echo['number'], $echo['security_code']);
		}

		return $echo;
	}

	/**
	 * A submitted value as scalars and arrays of them.
	 */
	private static function wire(mixed $given): mixed
	{
		if (is_object($given) && !$given instanceof Stringable) {
			$given = get_object_vars($given);
		}

		return is_array($given) ? array_map(self::wire(...), $given) : $given;
	}

	/**
	 * A parsed value in the form its control submits.
	 */
	private static function fromValue(mixed $value): mixed
	{
		return match (true) {
			$value instanceof Field\Boolean\Value => $value->answer,
			$value instanceof Field\PhoneNumber\Value => ['country' => $value->parts()['country'], 'number' => $value->toE164()],
			$value instanceof Field\CreditCard\Value => [
				'expiry' => $value->expiry === null ? null : substr((string) $value->expiry, 0, 7),
				'name' => $value->name,
			],
			$value instanceof Field\Address\Value,
			$value instanceof Field\Money\Value => array_map(
				static fn(mixed $part): string|array|null => match (true) {
					$part === null, $part === [] => null,
					is_array($part) => array_map(strval(...), $part),
					default => (string) $part,
				},
				$value->parts(),
			),
			$value instanceof Stringable => (string) $value,
			default => null,
		};
	}

	/**
	 * @return array{list<string>, array<string, list<string>>} the whole field's messages, and each part's
	 */
	private static function messagesOf(FieldResult $result): array
	{
		$messages = $result->messages;

		if (!$messages instanceof PartedSet) {
			return [array_values($messages->all), []];
		}

		$byPart = [];

		foreach ($messages->parts as $part) {
			$byPart[$part] = array_values($messages->forPart($part)->all);
		}

		return [array_values($messages->whole->all), $byPart];
	}

	/**
	 * @return array<string, string> value => label
	 */
	private function choicesFor(Field $field, object $o): array
	{
		$labels = is_array($o->options ?? null) ? $o->options : [];
		$choices = [];

		if ($field instanceof Field\Enum) {
			foreach ($field->cases as $case) {
				$choices[$case] = self::labelOf($labels, $case);
			}

			return $choices;
		}

		// A combobox on any other field offers the suggestions it was configured with.
		if ($o->addOptions ?? false) {
			foreach ($labels as $value => $config) {
				$choices[(string) $value] = is_array($config)
					? (string) ($config['label'] ?? $value)
					: (string) $config;
			}
		}

		return $choices;
	}

	/**
	 * @param array<array-key, mixed> $labels value => ['label' => …], as FieldOptions::labelOption() stores them
	 */
	private static function labelOf(array $labels, string $value, ?string $fallback = null): string
	{
		$config = $labels[$value] ?? null;

		return is_array($config) && isset($config['label']) ? (string) $config['label'] : ($fallback ?? $value);
	}

	private static function dialogFor(object $o): ?Dialog
	{
		$spec = $o->dialog ?? null;

		if (!is_array($spec)) {
			return null;
		}

		return new Dialog(
			id: $o->id . '-dialog',
			open: (bool) ($spec['open'] ?? false),
			trigger: (string) ($spec['trigger'] ?? 'Open'),
			confirm: (string) ($spec['confirm'] ?? 'Save'),
			close: (string) ($spec['close'] ?? 'Cancel'),
		);
	}

	private static function revealFor(object $o): ?Reveal
	{
		$spec = $o->reveal ?? null;

		if (!is_array($spec)) {
			return null;
		}

		return new Reveal(
			mode: (string) ($spec['mode'] ?? Reveal::INLINE),
			trigger: (string) ($spec['trigger'] ?? 'Show'),
			open: (bool) ($spec['open'] ?? false),
		);
	}

	private static function addDialogFor(object $o, string $inputName): ?Dialog
	{
		$spec = $o->addDialog ?? null;

		if (!is_array($spec)) {
			return null;
		}

		return new Dialog(
			id: $o->id . '-add',
			open: (bool) ($spec['open'] ?? false),
			trigger: (string) ($spec['trigger'] ?? 'Add'),
			confirm: (string) ($spec['confirm'] ?? 'Add'),
			close: (string) ($spec['close'] ?? 'Cancel'),
			action: 'add:' . $inputName,
		);
	}

	/** A value an attribute can hold, or null to leave the attribute off. */
	private static function text(mixed $value): ?string
	{
		return match (true) {
			is_string($value) => $value === '' ? null : $value,
			is_int($value), is_float($value) => (string) $value,
			$value instanceof Stringable => (string) $value,
			default => null,
		};
	}

	/** A non-empty string option, or null. */
	private static function string(mixed $value): ?string
	{
		return is_string($value) && $value !== '' ? $value : null;
	}
}
