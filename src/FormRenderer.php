<?php
declare(strict_types=1);

namespace Meraki\Schema\Html;

use Meraki\Schema\Facade;
use Meraki\Schema\Field;
use Meraki\Schema\Field\Composite as CompositeField;
use Nette\Utils\Html;

/**
 * Renders a schema as an HTML <form>. Option resolution/defaults live in
 * {@see FormOptionResolver} and validation copy in {@see ValidationMessages};
 * elements are built with nette/utils Html so attribute types (booleans, null)
 * are handled natively.
 */
class FormRenderer
{
	/** @var array<class-string, callable(Field, object): Html> */
	private array $fieldRenderers = [];

	private readonly FormOptionResolver $resolver;

	private readonly ValidationMessages $messages;

	/**
	 * @param array $options Form options (see FormOptions::toArray()).
	 */
	public function __construct(array $options = [])
	{
		$this->resolver = new FormOptionResolver($options);
		$this->messages = new ValidationMessages();
		$this->registerDefaultFieldRenderers();
	}

	public function render(Facade $schema): string
	{
		$form = Html::el('form')
			->setAttribute('id', (string) $schema->name)
			->setAttribute('novalidate', true)
			->setAttribute('action', $this->resolver->action())
			->setAttribute('method', $this->resolver->method());

		if ($this->hasFileField($schema)) {
			$form->setAttribute('enctype', 'multipart/form-data');
		}

		if ($this->resolver->hasMethod()) {
			$form->addHtml(Html::el('input', [
				'type' => 'hidden',
				'name' => '_method',
				'value' => $this->resolver->method(),
			]));
		}

		foreach ($schema->fields as $field) {
			$form->addHtml($this->renderField($field));
		}

		$form->addHtml(Html::el('button')->setAttribute('type', 'submit')->setText('Submit'));

		return (string) $form;
	}

	/**
	 * @param callable(Field, object): Html $renderer
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

	private function renderField(Field $field, array $parentOptions = []): Html
	{
		$renderer = $this->fieldRenderers[$field::class] ?? null;

		if ($renderer === null) {
			throw new \RuntimeException('No renderer registered for field type: ' . $field::class);
		}

		return $renderer($field, $this->resolver->resolve($field, $parentOptions));
	}

	private function renderInputField(Field $field, object $o): Html
	{
		$input = Html::el('input')
			->setAttribute('type', $o->renderer)
			->setAttribute('id', $o->id)
			->setAttribute('name', $o->input_name ?? $field->name->value);

		$this->applyValue($input, $field, $o);
		$this->applyGlobalAttributes($input, $field, $o);

		return $this->wrap($o->type, $field, $o, $this->label($o), $input);
	}

	private function renderBooleanField(Field\Boolean $field, object $o): Html
	{
		$resolved = $o->value ?? $field->resolvedValue->unwrap();

		$input = Html::el('input')
			->setAttribute('type', 'checkbox')
			->setAttribute('id', $o->id)
			->setAttribute('name', $o->input_name ?? $field->name->value)
			->setAttribute('autocomplete', 'off')
			->setAttribute('checked', !is_array($resolved) && (bool) $resolved);

		$this->applyGlobalAttributes($input, $field, $o);

		return $this->wrap('boolean', $field, $o, $this->label($o), $input);
	}

	private function renderTextField(Field\Text $field, object $o): Html
	{
		$useTextarea = ($o->multiline ?? false) || $o->renderer === 'textarea';
		$name = $o->input_name ?? $field->name->value;

		if ($useTextarea) {
			$element = Html::el('textarea')->setAttribute('id', $o->id)->setAttribute('name', $name);
			$value = $o->value ?? $field->resolvedValue->unwrap();

			if (is_string($value) && $value !== '') {
				$element->setText($value);
			}
		} else {
			$element = Html::el('input')->setAttribute('type', 'text')->setAttribute('id', $o->id)->setAttribute('name', $name);
			$this->applyValue($element, $field, $o);
		}

		$this->applyGlobalAttributes($element, $field, $o);

		return $this->wrap('text', $field, $o, $this->label($o), $element);
	}

	private function renderEnumField(Field\Enum $field, object $o): Html
	{
		$name = $o->input_name ?? $field->name->value;
		$optionConfigs = $o->options ?? [];

		if ($o->renderer === 'dropdown') {
			$select = Html::el('select')->setAttribute('id', $o->id)->setAttribute('name', $name)->setAttribute('autocomplete', 'none');
			$this->applyGlobalAttributes($select, $field, $o);

			foreach ($field->oneOf as $choice) {
				$label = ($optionConfigs[$choice]['label'] ?? null) ?? $choice;
				$option = Html::el('option')->setAttribute('value', $choice)->setText($label);
				$option->setAttribute('selected', $field->defaultValue->unwrap() === $choice);
				$select->addHtml($option);
			}

			return $this->wrap('enum', $field, $o, $this->label($o), $select);
		}

		// Radio buttons (default)
		$fieldset = Html::el('fieldset')->addHtml(Html::el('legend')->setText($o->label));

		foreach ($field->oneOf as $choice) {
			$label = ($optionConfigs[$choice]['label'] ?? null) ?? $choice;

			$radio = Html::el('input')
				->setAttribute('type', 'radio')
				->setAttribute('id', $o->id)
				->setAttribute('name', $name)
				->setAttribute('value', $choice)
				->setAttribute('required', !$field->optional)
				->setAttribute('readonly', (bool) $o->readonly)
				->setAttribute('disabled', (bool) $o->disabled)
				->setAttribute('autofocus', (bool) $o->autofocus)
				->setAttribute('checked', $field->defaultValue->unwrap() === $choice);

			if ($o->hidden || $o->disabled || $o->readonly) {
				$radio->setAttribute('tabindex', '-1');
			}

			$fieldset->addHtml(Html::el('label')->setAttribute('for', $o->id)->setText($label));
			$fieldset->addHtml($radio);
		}

		return $this->wrap('enum', $field, $o, $fieldset);
	}

	private function renderCompositeField(CompositeField $field, object $o): Html
	{
		$fieldset = Html::el('fieldset')
			->setAttribute('id', $o->id)
			->setAttribute('class', 'composite-field ' . $o->label)
			->addHtml(Html::el('legend')->setText($o->label));

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

			$fieldset->addHtml($this->renderField($subField, $subOptions));
		}

		return $this->wrap($o->type, $field, $o, $fieldset);
	}

	private function label(object $o): Html
	{
		return Html::el('label')->setAttribute('for', $o->id)->setText($o->label);
	}

	private function applyGlobalAttributes(Html $element, Field $field, object $o): void
	{
		$element->setAttribute('required', !$field->optional)
			->setAttribute('readonly', (bool) $o->readonly)
			->setAttribute('disabled', (bool) $o->disabled)
			->setAttribute('hidden', (bool) $o->hidden)
			->setAttribute('autofocus', (bool) $o->autofocus);

		if ($o->hidden || $o->disabled || $o->readonly) {
			$element->setAttribute('tabindex', '-1');
		}
	}

	private function applyValue(Html $element, Field $field, object $o): void
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

	private function wrap(string $type, Field $field, object $o, Html|string ...$children): Html
	{
		$wrapper = Html::el('div')
			->setAttribute('class', 'field' . ($o->hidden ? ' visually-hidden' : ''))
			->setAttribute('data-type', $type)
			->setAttribute('data-name', $field->name->value);

		if ($o->disabled || $o->readonly || $o->hidden) {
			$wrapper->setAttribute('tabindex', '-1');
		}

		foreach ($children as $child) {
			$wrapper->addHtml($child);
		}

		$errors = Html::el('div')->setAttribute('class', 'errors');

		foreach ($this->messages->errorsFor($field) as $error) {
			$errors->addHtml(Html::el('p')->setText($error));
		}

		return $wrapper->addHtml($errors);
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
