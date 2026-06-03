<?php
declare(strict_types=1);

namespace Meraki\Schema\Html;

use Meraki\Schema\Facade;
use Meraki\Schema\Field;
use Meraki\Schema\Field\Composite as CompositeField;
use Meraki\Schema\Field\ConstraintValidationResult;
use Meraki\Schema\ValidationStatus;

class SchemaHtmlRenderer
{
	// --- Constants ---

	private const DEFAULT_UI_OPTIONS = [
		Field\Address::class => [
			'type' => 'address',
			'label' => 'Address',
			'fields' => [
				'street'   => ['label' => 'Street',   'renderer' => 'text'],
				'city'     => ['label' => 'City',     'renderer' => 'text'],
				'state'    => ['label' => 'State',    'renderer' => 'text'],
				'postcode' => ['label' => 'Postcode', 'renderer' => 'text'],
				'country'  => ['label' => 'Country',  'renderer' => 'text'],
			],
		],
		Field\Boolean::class => [
			'renderer' => 'checkbox',
		],
		Field\Composite::class => [
			'renderer' => 'composite',
		],
		Field\CreditCard::class => [
			'type' => 'credit-card',
			'renderer' => 'composite',
		],
		Field\Date::class => [
			'renderer' => 'date',
		],
		Field\DateTime::class => [
			'renderer' => 'datetime-local',
		],
		Field\Duration::class => [
			'renderer' => 'text',
		],
		Field\EmailAddress::class => [
			'label' => 'Email Address',
			'renderer' => 'email',
		],
		Field\Enum::class => [
			'renderer' => 'radio',
		],
		Field\File::class => [
			'renderer' => 'file',
		],
		Field\Money::class => [
			'type' => 'money',
			'renderer' => 'composite',
			'fields' => [
				'amount'   => ['label' => 'Amount',   'renderer' => 'text'],
				'currency' => ['label' => 'Currency', 'renderer' => 'dropdown'],
			],
		],
		Field\Name::class => [
			'label' => 'Full Name',
			'renderer' => 'text',
		],
		Field\Number::class => [
			'renderer' => 'number',
		],
		Field\Passphrase::class => [
			'label' => 'Passphrase',
			'renderer' => 'password',
		],
		Field\Password::class => [
			'label' => 'Password',
			'renderer' => 'password',
		],
		Field\PhoneNumber::class => [
			'label' => 'Phone Number',
			'renderer' => 'tel',
		],
		Field\Text::class => [
			'multiline' => false,
			'renderer' => 'text',
		],
		Field\Time::class => [
			'renderer' => 'time',
		],
		Field\Uri::class => [
			'renderer' => 'url',
		],
		Field\Uuid::class => [
			'renderer' => 'text',
		],
		Field\Variant::class => [
			'renderer' => 'text',
		],
	];

	private const GLOBAL_DEFAULT_UI_OPTIONS = [
		'readonly'  => false,
		'disabled'  => false,
		'hidden'    => false,
		'autofocus' => false,
	];

	// --- Constructor & Public API ---

	private array $fieldRenderers = [];

	public function __construct(private array $ui = [])
	{
		$this->registerDefaultFieldRenderers();
	}

	public function render(Facade $schema): string
	{
		$uiOptions = (object) $this->ui;
		$html = sprintf('<form id="%s" novalidate=""', $schema->name);
		$html .= ' action="' . ($uiOptions->action ?? '') . '"';
		$html .= ' method="' . ($uiOptions->method ?? 'post') . '"';

		if ($this->hasFileField($schema)) {
			$html .= ' enctype="multipart/form-data"';
		}

		$html .= '>';

		if (isset($uiOptions->method)) {
			$html .= sprintf('<input type="hidden" name="_method" value="%s">', htmlspecialchars($uiOptions->method));
		}

		foreach ($schema->fields as $field) {
			$html .= $this->renderField($field);
		}

		$html .= '<button type="submit">Submit</button>';
		$html .= '</form>';

		return $html;
	}

	public function registerFieldRenderer(string $fqcn, callable $renderer): void
	{
		$this->fieldRenderers[$fqcn] = $renderer;
	}

	// --- Field Dispatching ---

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

	private function renderField(Field $field, array $parentOptions = []): string
	{
		$renderer = $this->fieldRenderers[$field::class] ?? null;

		if ($renderer === null) {
			throw new \RuntimeException('No renderer registered for field type: ' . $field::class);
		}

		$fieldName = $field->name->value;
		$uiOptions = array_merge(
			self::GLOBAL_DEFAULT_UI_OPTIONS,
			self::DEFAULT_UI_OPTIONS[$field::class] ?? [],
			$parentOptions,
			$this->ui['fields'][$fieldName] ?? [],
		);
		$uiOptions['id']    ??= $this->generateDeterministicId($fieldName);
		$uiOptions['label'] ??= $this->generateLabelFromName($fieldName);
		$uiOptions['type']  ??= $this->pascalCaseToDashCase($field);

		return $renderer($field, (object) $uiOptions);
	}

	// --- Composite Fields ---

	private function renderCompositeField(CompositeField $field, object $uiOptions): string
	{
		$html = sprintf('<fieldset id="%s" class="composite-field %s">', $uiOptions->id, $uiOptions->label);
		$html .= sprintf('<legend>%s</legend>', $uiOptions->label);

		$parentInputName = $uiOptions->input_name ?? $field->name->value;
		$parentValue = $field->resolvedValue->unwrap();
		$parentValueArray = is_array($parentValue) ? $parentValue : [];

		foreach ($field->fields as $subField) {
			$localName = $subField->name->removePrefix()->value;
			$defaultSubOptions = ($uiOptions->fields ?? [])[$localName] ?? [];
			$userSubOptions = (array) ($uiOptions->$localName ?? []);
			$subOptions = array_merge($defaultSubOptions, $userSubOptions, [
				'input_name' => $parentInputName . '[' . $localName . ']',
			]);
			if ($parentValueArray !== [] && !array_key_exists('value', $subOptions)) {
				$subOptions['value'] = $parentValueArray[$localName] ?? null;
			}
			$html .= $this->renderField($subField, $subOptions);
		}

		$html .= '</fieldset>';

		return $this->wrapWithFieldElement($uiOptions->type, $html, $field, $uiOptions);
	}

	// --- Standard Input Fields ---

	private function renderInputField(Field $field, object $uiOptions): string
	{
		$html = $this->buildLabel($uiOptions);
		$html .= sprintf('<input type="%s" id="%s" name="%s"%s',
			$uiOptions->renderer, $uiOptions->id, $uiOptions->input_name ?? $field->name->value, $this->renderValueAttribute($field, $uiOptions));
		$html = $this->addGlobalAttributes($html, $field, $uiOptions);
		$html .= '>';

		return $this->wrapWithFieldElement($uiOptions->type, $html, $field, $uiOptions);
	}

	private function renderBooleanField(Field\Boolean $field, object $uiOptions): string
	{
		$html = $this->buildLabel($uiOptions);
		$html .= sprintf('<input type="checkbox" id="%s" name="%s" autocomplete="off"', $uiOptions->id, $uiOptions->input_name ?? $field->name->value);
		$resolvedVal = $uiOptions->value ?? $field->resolvedValue->unwrap();
		$html .= (!is_array($resolvedVal) && $resolvedVal) ? ' checked' : '';
		$html = $this->addGlobalAttributes($html, $field, $uiOptions);
		$html .= '>';

		return $this->wrapWithFieldElement('boolean', $html, $field, $uiOptions);
	}

	private function renderTextField(Field\Text $field, object $uiOptions): string
	{
		$useTextarea = ($uiOptions->multiline ?? false) || $uiOptions->renderer === 'textarea';
		$html = $this->buildLabel($uiOptions);
		$html .= $useTextarea ? '<textarea' : '<input type="text"';
		$html .= sprintf(' id="%s" name="%s"%s', $uiOptions->id, $uiOptions->input_name ?? $field->name->value, $this->renderValueAttribute($field, $uiOptions));
		$html = $this->addGlobalAttributes($html, $field, $uiOptions);
		$html .= $useTextarea ? '></textarea>' : '>';

		return $this->wrapWithFieldElement('text', $html, $field, $uiOptions);
	}

	// --- Enum Fields ---

	private function renderEnumField(Field\Enum $field, object $uiOptions): string
	{
		if ($uiOptions->renderer === 'dropdown') {
			$selectHtml = sprintf('<select id="%s" name="%s" autocomplete="none"', $uiOptions->id, $uiOptions->input_name ?? $field->name->value);
			$selectHtml = $this->addGlobalAttributes($selectHtml, $field, $uiOptions);
			$selectHtml .= '>';

			foreach ($field->oneOf as $option) {
				$optionUiOptions = (object) ($uiOptions->options[$option] ?? []);
				$label = $optionUiOptions->label ?? $option;
				$selected = $field->defaultValue === $option ? ' selected' : '';
				$selectHtml .= sprintf('<option value="%s"%s>%s</option>', $option, $selected, $label);
			}

			$html = $this->buildLabel($uiOptions) . $selectHtml . '</select>';
			return $this->wrapWithFieldElement('enum', $html, $field, $uiOptions);
		}

		// Radio buttons (default)
		$optionsHtml = '';

		foreach ($field->oneOf as $option) {
			$optionUiOptions = (object) array_merge($uiOptions->options[$option] ?? [], [
				'hidden'   => $uiOptions->hidden ?? false,
				'disabled' => $uiOptions->disabled ?? false,
				'readonly' => $uiOptions->readonly ?? false,
				'autofocus'=> $uiOptions->autofocus ?? false,
			]);
			$labelHtml = sprintf('<label for="%s">%s</label>', $uiOptions->id, $optionUiOptions->label ?? $option);
			$inputHtml = sprintf('<input type="radio" id="%s" name="%s" value="%s"', $uiOptions->id, $uiOptions->input_name ?? $field->name->value, $option);
			$inputHtml .= !$field->optional ? ' required' : '';
			$inputHtml .= $uiOptions->readonly  ? ' readonly'  : '';
			$inputHtml .= $uiOptions->disabled  ? ' disabled'  : '';
			$inputHtml .= $uiOptions->autofocus ? ' autofocus' : '';

			if ($uiOptions->hidden || $uiOptions->disabled || $uiOptions->readonly) {
				$inputHtml .= ' tabindex="-1"';
			}

			if ($field->defaultValue->unwrap() === $option) {
				$inputHtml .= ' checked';
			}

			$optionsHtml .= $labelHtml . $inputHtml . '>';
		}

		$legend = sprintf('<legend>%s</legend>', $uiOptions->label);
		return $this->wrapWithFieldElement('enum', "<fieldset>{$legend}{$optionsHtml}</fieldset>", $field, $uiOptions);
	}

	// --- Helpers ---

	private function hasFileField(Facade $schema): bool
	{
		foreach ($schema->fields as $field) {
			if ($field instanceof Field\File) {
				return true;
			}
		}
		return false;
	}

	private function buildLabel(object $uiOptions): string
	{
		return sprintf('<label for="%s">%s</label>', $uiOptions->id, $uiOptions->label);
	}

	private function addGlobalAttributes(string $html, Field $field, object $uiOptions): string
	{
		$html .= !$field->optional    ? ' required'  : '';
		$html .= $uiOptions->readonly ? ' readonly'  : '';
		$html .= $uiOptions->disabled ? ' disabled'  : '';
		$html .= $uiOptions->hidden   ? ' hidden'    : '';
		$html .= $uiOptions->autofocus? ' autofocus' : '';

		if ($uiOptions->hidden || $uiOptions->disabled || $uiOptions->readonly) {
			$html .= ' tabindex="-1"';
		}

		return $html;
	}

	private function wrapWithFieldElement(string $type, string $innerHtml, Field $fieldObj, object $uiOptions): string
	{
		$classes = 'field' . ($uiOptions->hidden ? ' visually-hidden' : '');
		$html = "<div class=\"{$classes}\" data-type=\"{$type}\" data-name=\"{$fieldObj->name->value}\"";

		if ($uiOptions->disabled || $uiOptions->readonly || $uiOptions->hidden) {
			$html .= ' tabindex="-1"';
		}

		$html .= '>' . $innerHtml . '<div class="errors">';

		foreach ($this->buildValidationErrors($fieldObj) as $error) {
			$html .= "<p>{$error}</p>";
		}

		return $html . '</div></div>';
	}

	private function renderValueAttribute(Field $field, object $uiOptions): string
	{
		if (!$this->shouldRetainValue($field)) {
			return '';
		}

		$value = $uiOptions->value ?? $field->resolvedValue->unwrap();

		if ($value === null || $value === '' || is_array($value)) {
			return '';
		}

		return sprintf(' value="%s"', htmlspecialchars((string) $value, ENT_QUOTES));
	}

	private function shouldRetainValue(Field $field): bool
	{
		return !($field instanceof Field\Password)
			&& !($field instanceof Field\Passphrase);
	}

	private function generateDeterministicId(string $name): string
	{
		return 'input-' . hash('sha256', $name);
	}

	private function generateLabelFromName(string $name): string
	{
		return ucfirst(str_replace('_', ' ', $name));
	}

	private function pascalCaseToDashCase(Field $field): string
	{
		$parts = preg_split('/(?=[A-Z])/', $field::class);
		return strtolower(implode('-', $parts));
	}

	// --- Validation ---

	private function buildValidationErrors(Field $field): array
	{
		$validationResult = $field->validationResult;

		if (!$validationResult || $validationResult->status === ValidationStatus::Passed) {
			return [];
		}

		$errors = [];

		foreach ($validationResult->getFailed() as $constraint) {
			$errors[] = $this->generateValidationMessages($field, $constraint);
		}

		return $errors;
	}

	private function generateValidationMessages(Field $field, ConstraintValidationResult $constraint): string
	{
		return match ((string) $field->type) {
			'address'       => $this->getValidationMessageForAddress($field, $constraint),
			'boolean'       => $this->getValidationMessageForBoolean($field, $constraint),
			'credit_card'   => $this->getValidationMessageForCreditCard($field, $constraint),
			'date'          => $this->getValidationMessageForDate($field, $constraint),
			'date_time'     => $this->getValidationMessageForDateTime($field, $constraint),
			'duration'      => $this->getValidationMessageForDuration($field, $constraint),
			'email_address' => $this->getValidationMessageForEmailAddress($field, $constraint),
			'enum'          => $this->getValidationMessageForEnum($field, $constraint),
			'file'          => $this->getValidationMessageForFile($field, $constraint),
			'money'         => $this->getValidationMessageForMoney($field, $constraint),
			'name'          => $this->getValidationMessageForName($field, $constraint),
			'number'        => $this->getValidationMessageForNumber($field, $constraint),
			'passphrase'    => $this->getValidationMessageForPassphrase($field, $constraint),
			'password'      => $this->getValidationMessageForPassword($field, $constraint),
			'phone_number'  => $this->getValidationMessageForPhoneNumber($field, $constraint),
			'text'          => $this->getValidationMessageForText($field, $constraint),
			'time'          => $this->getValidationMessageForTime($field, $constraint),
			'url'           => $this->getValidationMessageForUrl($field, $constraint),
			'uuid'          => $this->getValidationMessageForUuid($field, $constraint),
			default         => 'Value is invalid',
		};
	}

	private function getValidationMessageForAddress(Field $field, ConstraintValidationResult $constraint): string
	{
		return match ($constraint->name) {
			'min'   => 'Value is too short: Expected at least ' . $field->{$constraint->name} . ' characters',
			'max'   => 'Value is too long: Expected at most ' . $field->{$constraint->name} . ' characters',
			default => 'A valid address must contain a street, suburb, state or territory, and postcode',
		};
	}

	private function getValidationMessageForBoolean(Field $field, ConstraintValidationResult $constraint): string
	{
		return 'Value must be a boolean';
	}

	private function getValidationMessageForCreditCard(Field $field, ConstraintValidationResult $constraint): string
	{
		return match ($constraint->name) {
			'min'   => 'Value is too short: Expected at least ' . $field->{$constraint->name} . ' digits',
			'max'   => 'Value is too long: Expected at most ' . $field->{$constraint->name} . ' digits',
			default => 'A valid credit card number must be between 13 and 19 digits',
		};
	}

	private function getValidationMessageForDate(Field $field, ConstraintValidationResult $constraint): string
	{
		return match ($constraint->name) {
			'min'   => 'Value is too early: Expected a date after ' . $field->{$constraint->name},
			'max'   => 'Value is too late: Expected a date before ' . $field->{$constraint->name},
			default => 'A valid date must be in the format YYYY-MM-DD',
		};
	}

	private function getValidationMessageForDateTime(Field $field, ConstraintValidationResult $constraint): string
	{
		return match ($constraint->name) {
			'min'   => 'Value is too early: Expected a date and time after ' . $field->{$constraint->name},
			'max'   => 'Value is too late: Expected a date and time before ' . $field->{$constraint->name},
			default => 'A valid date and time must be in the format YYYY-MM-DDTHH:MM',
		};
	}

	private function getValidationMessageForDuration(Field $field, ConstraintValidationResult $constraint): string
	{
		return match ($constraint->name) {
			'min'   => 'Value is too short: Expected at least ' . $field->{$constraint->name} . ' seconds',
			'max'   => 'Value is too long: Expected at most ' . $field->{$constraint->name} . ' seconds',
			default => 'A valid duration must be in the format PTHnMn',
		};
	}

	private function getValidationMessageForEmailAddress(Field $field, ConstraintValidationResult $constraint): string
	{
		return match ($constraint->name) {
			'min'   => 'Email address must have at least ' . $field->{$constraint->name} . ' characters',
			'max'   => 'Email address cannot have more than ' . $field->{$constraint->name} . ' characters',
			default => 'A valid email address must contain an @ symbol and a domain',
		};
	}

	private function getValidationMessageForEnum(Field $field, ConstraintValidationResult $constraint): string
	{
		return 'Value must be one of the allowed options';
	}

	private function getValidationMessageForFile(Field $field, ConstraintValidationResult $constraint): string
	{
		return match ($constraint->name) {
			'minCount' => 'Expected at least ' . $field->{$constraint->name} . ' file(s)',
			'maxCount' => 'Expected at most ' . $field->{$constraint->name} . ' file(s)',
			'minSize'  => 'File is too small: Expected at least ' . $field->{$constraint->name} . ' bytes',
			'maxSize'  => 'File is too large: Expected at most ' . $field->{$constraint->name} . ' bytes',
			default    => 'Invalid file',
		};
	}

	private function getValidationMessageForMoney(Field $field, ConstraintValidationResult $constraint): string
	{
		return match ($constraint->name) {
			'min'   => 'Value is too low: Expected at least ' . $field->{$constraint->name} . ' cents',
			'max'   => 'Value is too high: Expected at most ' . $field->{$constraint->name} . ' cents',
			default => 'A valid amount of money must be in cents',
		};
	}

	private function getValidationMessageForName(Field $field, ConstraintValidationResult $constraint): string
	{
		return match ($constraint->name) {
			'min'   => 'Name must have at least ' . $field->{$constraint->name} . ' characters',
			'max'   => 'Name cannot have more than ' . $field->{$constraint->name} . ' characters',
			default => 'A valid name must contain one or more words separated by spaces',
		};
	}

	private function getValidationMessageForNumber(Field $field, ConstraintValidationResult $constraint): string
	{
		return match ($constraint->name) {
			'min'   => 'Value is too low: Expected at least ' . $field->{$constraint->name},
			'max'   => 'Value is too high: Expected at most ' . $field->{$constraint->name},
			default => 'Value must be a number',
		};
	}

	private function getValidationMessageForPassphrase(Field $field, ConstraintValidationResult $constraint): string
	{
		return match ($constraint->name) {
			'min'   => 'Value is too short: Expected at least ' . $field->{$constraint->name} . ' characters',
			'max'   => 'Value is too long: Expected at most ' . $field->{$constraint->name} . ' characters',
			default => 'A valid passphrase must contain at least one uppercase letter, one lowercase letter, one number, and one special character',
		};
	}

	private function getValidationMessageForPassword(Field $field, ConstraintValidationResult $constraint): string
	{
		return match ($constraint->name) {
			'min'   => 'Value is too short: Expected at least ' . $field->{$constraint->name} . ' characters',
			'max'   => 'Value is too long: Expected at most ' . $field->{$constraint->name} . ' characters',
			default => 'A valid password must contain at least one uppercase letter, one lowercase letter, one number, and one special character',
		};
	}

	private function getValidationMessageForPhoneNumber(Field $field, ConstraintValidationResult $constraint): string
	{
		return match ($constraint->name) {
			'min'   => 'Phone number should be at least ' . $field->{$constraint->name} . ' digits long',
			'max'   => 'Phone number cannot be more than ' . $field->{$constraint->name} . ' digits long',
			default => 'A phone number must begin with a plus sign followed by digits and may contain spaces, parentheses, dashes, and periods',
		};
	}

	private function getValidationMessageForText(Field $field, ConstraintValidationResult $constraint): string
	{
		return match ($constraint->name) {
			'min'   => 'Value is too short: Expected at least ' . $field->{$constraint->name} . ' characters',
			'max'   => 'Value is too long: Expected at most ' . $field->{$constraint->name} . ' characters',
			default => 'A valid text must be a string',
		};
	}

	private function getValidationMessageForTime(Field $field, ConstraintValidationResult $constraint): string
	{
		return match ($constraint->name) {
			'min'   => 'Value is too early: Expected a time after ' . $field->{$constraint->name},
			'max'   => 'Value is too late: Expected a time before ' . $field->{$constraint->name},
			default => 'A valid time must be in the format HH:MM:SS',
		};
	}

	private function getValidationMessageForUrl(Field $field, ConstraintValidationResult $constraint): string
	{
		return match ($constraint->name) {
			'min'   => 'Value is too short: Expected at least ' . $field->{$constraint->name} . ' characters',
			'max'   => 'Value is too long: Expected at most ' . $field->{$constraint->name} . ' characters',
			default => 'A valid URL must begin with http:// or https://',
		};
	}

	private function getValidationMessageForUuid(Field $field, ConstraintValidationResult $constraint): string
	{
		return match ($constraint->name) {
			'min'   => 'Value is too short: Expected at least ' . $field->{$constraint->name} . ' characters',
			'max'   => 'Value is too long: Expected at most ' . $field->{$constraint->name} . ' characters',
			default => 'A valid UUID must be a 32-character hexadecimal string',
		};
	}
}
