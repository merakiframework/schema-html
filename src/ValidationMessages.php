<?php
declare(strict_types=1);

namespace Meraki\Schema\Html;

use Meraki\Schema\Field;
use Meraki\Schema\Field\ConstraintValidationResult;
use Meraki\Schema\Field\ValidationResult as FieldValidationResult;
use Meraki\Schema\ValidationResult;

final class ValidationMessages implements ValidationMessageProvider
{
	/**
	 * @return string[]
	 */
	public function errorsFor(Field $field, ?ValidationResult $result = null): array
	{
		// Only atomic field results carry constraint-level failures. A null result
		// means the field was not validated; a composite result is handled via its
		// individually-rendered sub-fields, so it produces no messages of its own.
		if (!$result instanceof FieldValidationResult) {
			return [];
		}

		$errors = [];

		foreach ($result->getFailed() as $constraint) {
			$errors[] = $this->messageFor($field, $constraint);
		}

		return $errors;
	}

	public function messageFor(Field $field, ConstraintValidationResult $constraint): string
	{
		return match (true) {
			$field instanceof Field\Address      => $this->forAddress($field, $constraint),
			$field instanceof Field\Boolean      => 'Value must be a boolean',
			$field instanceof Field\CreditCard   => $this->forCreditCard($field, $constraint),
			$field instanceof Field\DateTime     => $this->forDateTime($field, $constraint),
			$field instanceof Field\Date         => $this->forDate($field, $constraint),
			$field instanceof Field\Duration     => $this->forDuration($field, $constraint),
			$field instanceof Field\EmailAddress => $this->forEmailAddress($field, $constraint),
			$field instanceof Field\Enum         => 'Value must be one of the allowed options',
			$field instanceof Field\File         => $this->forFile($field, $constraint),
			$field instanceof Field\Money        => $this->forMoney($field, $constraint),
			$field instanceof Field\Name         => $this->forName($field, $constraint),
			$field instanceof Field\Number       => $this->forNumber($field, $constraint),
			$field instanceof Field\Passphrase   => $this->forPassphrase($field, $constraint),
			$field instanceof Field\Password     => $this->forPassword($field, $constraint),
			$field instanceof Field\PhoneNumber  => $this->forPhoneNumber($field, $constraint),
			$field instanceof Field\Text         => $this->forText($field, $constraint),
			$field instanceof Field\Time         => $this->forTime($field, $constraint),
			$field instanceof Field\Uri          => $this->forUri($field, $constraint),
			$field instanceof Field\Uuid         => $this->forUuid($field, $constraint),
			default                              => 'Value is invalid',
		};
	}

	private function forAddress(Field $field, ConstraintValidationResult $constraint): string
	{
		return match ($constraint->name) {
			'min'   => 'Value is too short: Expected at least ' . $field->{$constraint->name} . ' characters',
			'max'   => 'Value is too long: Expected at most ' . $field->{$constraint->name} . ' characters',
			default => 'A valid address must contain a street, suburb, state or territory, and postcode',
		};
	}

	private function forCreditCard(Field $field, ConstraintValidationResult $constraint): string
	{
		return match ($constraint->name) {
			'min'   => 'Value is too short: Expected at least ' . $field->{$constraint->name} . ' digits',
			'max'   => 'Value is too long: Expected at most ' . $field->{$constraint->name} . ' digits',
			default => 'A valid credit card number must be between 13 and 19 digits',
		};
	}

	private function forDate(Field $field, ConstraintValidationResult $constraint): string
	{
		return match ($constraint->name) {
			'min'   => 'Value is too early: Expected a date after ' . $field->{$constraint->name},
			'max'   => 'Value is too late: Expected a date before ' . $field->{$constraint->name},
			default => 'A valid date must be in the format YYYY-MM-DD',
		};
	}

	private function forDateTime(Field $field, ConstraintValidationResult $constraint): string
	{
		return match ($constraint->name) {
			'min'   => 'Value is too early: Expected a date and time after ' . $field->{$constraint->name},
			'max'   => 'Value is too late: Expected a date and time before ' . $field->{$constraint->name},
			default => 'A valid date and time must be in the format YYYY-MM-DDTHH:MM',
		};
	}

	private function forDuration(Field $field, ConstraintValidationResult $constraint): string
	{
		return match ($constraint->name) {
			'min'   => 'Value is too short: Expected at least ' . $field->{$constraint->name} . ' seconds',
			'max'   => 'Value is too long: Expected at most ' . $field->{$constraint->name} . ' seconds',
			default => 'A valid duration must be in the format PTHnMn',
		};
	}

	private function forEmailAddress(Field $field, ConstraintValidationResult $constraint): string
	{
		return match ($constraint->name) {
			'min'   => 'Email address must have at least ' . $field->{$constraint->name} . ' characters',
			'max'   => 'Email address cannot have more than ' . $field->{$constraint->name} . ' characters',
			default => 'A valid email address must contain an @ symbol and a domain',
		};
	}

	private function forFile(Field $field, ConstraintValidationResult $constraint): string
	{
		return match ($constraint->name) {
			'minCount' => 'Expected at least ' . $field->{$constraint->name} . ' file(s)',
			'maxCount' => 'Expected at most ' . $field->{$constraint->name} . ' file(s)',
			'minSize'  => 'File is too small: Expected at least ' . $field->{$constraint->name} . ' bytes',
			'maxSize'  => 'File is too large: Expected at most ' . $field->{$constraint->name} . ' bytes',
			default    => 'Invalid file',
		};
	}

	private function forMoney(Field $field, ConstraintValidationResult $constraint): string
	{
		return match ($constraint->name) {
			'min'   => 'Value is too low: Expected at least ' . $field->{$constraint->name} . ' cents',
			'max'   => 'Value is too high: Expected at most ' . $field->{$constraint->name} . ' cents',
			default => 'A valid amount of money must be in cents',
		};
	}

	private function forName(Field $field, ConstraintValidationResult $constraint): string
	{
		return match ($constraint->name) {
			'min'   => 'Name must have at least ' . $field->{$constraint->name} . ' characters',
			'max'   => 'Name cannot have more than ' . $field->{$constraint->name} . ' characters',
			default => 'A valid name must contain one or more words separated by spaces',
		};
	}

	private function forNumber(Field $field, ConstraintValidationResult $constraint): string
	{
		return match ($constraint->name) {
			'min'   => 'Value is too low: Expected at least ' . $field->{$constraint->name},
			'max'   => 'Value is too high: Expected at most ' . $field->{$constraint->name},
			default => 'Value must be a number',
		};
	}

	private function forPassphrase(Field $field, ConstraintValidationResult $constraint): string
	{
		return match ($constraint->name) {
			'min'   => 'Value is too short: Expected at least ' . $field->{$constraint->name} . ' characters',
			'max'   => 'Value is too long: Expected at most ' . $field->{$constraint->name} . ' characters',
			default => 'A valid passphrase must contain at least one uppercase letter, one lowercase letter, one number, and one special character',
		};
	}

	private function forPassword(Field $field, ConstraintValidationResult $constraint): string
	{
		return match ($constraint->name) {
			'min'   => 'Value is too short: Expected at least ' . $field->{$constraint->name} . ' characters',
			'max'   => 'Value is too long: Expected at most ' . $field->{$constraint->name} . ' characters',
			default => 'A valid password must contain at least one uppercase letter, one lowercase letter, one number, and one special character',
		};
	}

	private function forPhoneNumber(Field $field, ConstraintValidationResult $constraint): string
	{
		return match ($constraint->name) {
			'allowedCountries' => 'Enter a phone number from: ' . implode(', ', $field->allowed),
			'numberType'       => match ($field->allowedType) {
				\Meraki\Schema\Field\PhoneNumber\Type::Mobile    => 'Enter a mobile phone number',
				\Meraki\Schema\Field\PhoneNumber\Type::FixedLine => 'Enter a landline phone number',
				default                                          => 'Enter a mobile or landline phone number',
			},
			default => 'Enter a valid phone number',
		};
	}

	private function forText(Field $field, ConstraintValidationResult $constraint): string
	{
		return match ($constraint->name) {
			'min'   => 'Value is too short: Expected at least ' . $field->{$constraint->name} . ' characters',
			'max'   => 'Value is too long: Expected at most ' . $field->{$constraint->name} . ' characters',
			default => 'A valid text must be a string',
		};
	}

	private function forTime(Field $field, ConstraintValidationResult $constraint): string
	{
		return match ($constraint->name) {
			'min'   => 'Value is too early: Expected a time after ' . $field->{$constraint->name},
			'max'   => 'Value is too late: Expected a time before ' . $field->{$constraint->name},
			default => 'A valid time must be in the format HH:MM:SS',
		};
	}

	private function forUri(Field $field, ConstraintValidationResult $constraint): string
	{
		return match ($constraint->name) {
			'min'   => 'Value is too short: Expected at least ' . $field->{$constraint->name} . ' characters',
			'max'   => 'Value is too long: Expected at most ' . $field->{$constraint->name} . ' characters',
			default => 'A valid URI must begin with a scheme such as http:// or https://',
		};
	}

	private function forUuid(Field $field, ConstraintValidationResult $constraint): string
	{
		return match ($constraint->name) {
			'min'   => 'Value is too short: Expected at least ' . $field->{$constraint->name} . ' characters',
			'max'   => 'Value is too long: Expected at most ' . $field->{$constraint->name} . ' characters',
			default => 'A valid UUID must be a 32-character hexadecimal string',
		};
	}
}
