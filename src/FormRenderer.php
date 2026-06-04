<?php
declare(strict_types=1);

namespace Meraki\Schema\Html;

use Meraki\Schema\Facade;
use Meraki\Schema\Field;
use Meraki\Schema\Field\Composite as CompositeField;

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

	private FormOptionResolver $resolver;

	public function __construct(
		private readonly ValidationMessageProvider $validationMessageProvider = new ValidationMessages()
	) {
		$this->resolver = new FormOptionResolver();
		$this->registerDefaultFieldRenderers();
	}

	public function render(Facade $schema, ?FormOptions $options = null): string
	{
		$options ??= new FormOptions();
		$this->resolver = $this->resolver->against($options);
		$form = new Element('form', [
			'id' => (string) $schema->name,
			'novalidate' => true,
			'action' => $this->resolver->action(),
			'method' => $this->resolver->method(),
		]);

		if ($this->hasFileField($schema)) {
			$form->setAttribute('enctype', 'multipart/form-data');
		}

		if ($this->resolver->hasMethod()) {
			$form->append(new Element('input', [
				'type' => 'hidden',
				'name' => '_method',
				'value' => $this->resolver->method(),
			]));
		}

		foreach ($schema->fields as $field) {
			$form->append($this->renderField($field, $options->fields[$field->name->value] ?? []));
		}

		$form->append(new Element('button', ['type' => 'submit'])->setText('Submit'));

		return (string) $form;
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

	private function renderField(Field $field, array $parentOptions = []): Element
	{
		$renderer = $this->fieldRenderers[$field::class] ?? null;

		if ($renderer === null) {
			throw new \RuntimeException('No renderer registered for field type: ' . $field::class);
		}

		return $renderer($field, $this->resolver->resolve($field, $parentOptions));
	}

	private function renderInputField(Field $field, object $o): Element
	{
		$input = new Element('input', [
			'type' => $o->renderer,
			'id' => $o->id,
			'name' => $o->input_name ?? $field->name->value,
		]);

		$this->applyValue($input, $field, $o);
		$this->applyGlobalAttributes($input, $field, $o);

		return $this->wrap($o->type, $field, $o, $this->label($o), $input);
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

		$this->applyGlobalAttributes($element, $field, $o);

		return $this->wrap('text', $field, $o, $this->label($o), $element);
	}

	private function renderEnumField(Field\Enum $field, object $o): Element
	{
		$name = $o->input_name ?? $field->name->value;
		$optionConfigs = $o->options ?? [];

		if ($o->renderer === 'dropdown') {
			$select = new Element('select', ['id' => $o->id, 'name' => $name, 'autocomplete' => 'none']);
			$this->applyGlobalAttributes($select, $field, $o);

			foreach ($field->oneOf as $choice) {
				$label = ($optionConfigs[$choice]['label'] ?? null) ?? $choice;
				$option = new Element('option', ['value' => $choice])->setText($label);
				$option->setAttribute('selected', $field->defaultValue->unwrap() === $choice);
				$select->append($option);
			}

			return $this->wrap('enum', $field, $o, $this->label($o), $select);
		}

		// Radio buttons (default)
		$fieldset = new Element('fieldset', ['id' => $o->id])->append(new Element('legend')->setText($o->label));

		foreach ($field->oneOf as $choice) {
			$label = ($optionConfigs[$choice]['label'] ?? null) ?? $choice;

			$radio = new Element('input', [
				'type' => 'radio',
				'id' => $o->id,
				'name' => $name,
				'value' => $choice,
				'required' => !$field->optional,
				'readonly' => (bool) $o->readonly,
				'disabled' => (bool) $o->disabled,
				'autofocus' => (bool) $o->autofocus,
				'checked' => $field->defaultValue->unwrap() === $choice,
			]);

			if ($o->hidden || $o->disabled || $o->readonly) {
				$radio->setAttribute('tabindex', '-1');
			}

			$fieldset->append(new Element('label', ['for' => $o->id])->setText($label));
			$fieldset->append($radio);
		}

		return $this->wrap('enum', $field, $o, $fieldset);
	}

	private function renderCompositeField(CompositeField $field, object $o): Element
	{
		$fieldset = new Element('fieldset', ['id' => $o->id, 'class' => 'composite-field ' . $o->label])
			->append(new Element('legend')->setText($o->label));

		$parentInputName = $o->input_name ?? $field->name->value;
		$parentValue = $field->resolvedValue->unwrap();
		$parentValueArray = is_array($parentValue) ? $parentValue : [];

		foreach ($field->fields as $subField) {
			$localName = $subField->name->removePrefix()->value;
			$defaultSubOptions = ($o->fields ?? [])[$localName] ?? [];
			$userSubOptions = (array) ($o->$localName ?? []);
			$subOptions = array_merge($defaultSubOptions, $userSubOptions, [
				'input_name' => $parentInputName . '[' . $localName . ']',
			]);

			if ($parentValueArray !== [] && !array_key_exists('value', $subOptions)) {
				$subOptions['value'] = $parentValueArray[$subField->name->value] ?? null;
			}

			$fieldset->append($this->renderField($subField, $subOptions));
		}

		return $this->wrap($o->type, $field, $o, $fieldset);
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

		foreach ($this->validationMessageProvider->errorsFor($field) as $error) {
			$errors->append(new Element('p')->setText($error));
		}

		return $wrapper->append($errors);
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
