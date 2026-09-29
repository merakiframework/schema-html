<?php
declare(strict_types=1);

namespace Meraki\Schema\Html;

use Meraki\Schema\Field;

/**
 * How a field may be drawn. Most cases are an `<input type>`; `composite` is a field drawn as
 * several controls (a structured field's parts, a collection's rows).
 */
enum Renderer: string
{
	case Composite = 'composite';
	case Text = 'text';
	case Textarea = 'textarea';
	case Email = 'email';
	case Password = 'password';
	case Number = 'number';
	case Tel = 'tel';
	case Url = 'url';
	case Date = 'date';
	case DateTimeLocal = 'datetime-local';
	case Time = 'time';
	case File = 'file';
	case Checkbox = 'checkbox';
	case Radio = 'radio';
	case RadioGroup = 'radiogroup';
	case ButtonGroup = 'buttongroup';
	case Dropdown = 'dropdown';

	/** @return self[] */
	public static function validFor(Field $field): array
	{
		return match (true) {
			$field instanceof Field\Address,
			$field instanceof Field\Collection,
			$field instanceof Field\CreditCard,
			$field instanceof Field\Money,
			$field instanceof Field\PhoneNumber => [self::Composite],
			$field instanceof Field\Text => [self::Text, self::Textarea],
			$field instanceof Field\Enum => [self::Radio, self::RadioGroup, self::ButtonGroup, self::Dropdown],
			$field instanceof Field\Boolean => [self::Checkbox],
			$field instanceof Field\EmailAddress => [self::Email],
			$field instanceof Field\Number => [self::Number, self::Text],
			$field instanceof Field\Uri => [self::Url],
			$field instanceof Field\Date => [self::Date],
			$field instanceof Field\DateTime => [self::DateTimeLocal],
			$field instanceof Field\Time => [self::Time],
			$field instanceof Field\File => [self::File],
			$field instanceof Field\Password => [self::Password],
			$field instanceof Field\Name,
			$field instanceof Field\Duration,
			$field instanceof Field\Uuid => [self::Text],
			default => [],
		};
	}

	public static function isValidForField(Renderer $renderer, Field $field): bool
	{
		return in_array($renderer, self::validFor($field), true);
	}
}
