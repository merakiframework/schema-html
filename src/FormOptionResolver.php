<?php
declare(strict_types=1);

namespace Meraki\Schema\Html;

use Meraki\Schema\Field;

/**
 * Resolves the effective UI options for a field by layering: global defaults,
 * per-field-type defaults, and the caller-supplied form options. Also derives id/label/type
 * fallbacks and the final `autocomplete` value.
 *
 * A structured field's *parts* are described by its {@see Presentation\PartLayout}, not here.
 */
final class FormOptionResolver
{
	private const DEFAULTS = [
		Field\Address::class => ['type' => 'address', 'label' => 'Address', 'renderer' => 'composite'],
		Field\Boolean::class => ['renderer' => 'checkbox'],
		Field\Collection::class => ['type' => 'collection', 'renderer' => 'composite'],
		Field\CreditCard::class => ['type' => 'credit-card', 'label' => 'Card', 'renderer' => 'composite'],
		Field\Date::class => ['renderer' => 'date'],
		Field\DateTime::class => ['renderer' => 'datetime-local'],
		Field\Duration::class => ['renderer' => 'text'],
		Field\EmailAddress::class => ['label' => 'Email Address', 'renderer' => 'email', 'autocompleteToken' => 'email'],
		Field\Enum::class => ['renderer' => 'radiogroup', 'hint' => 'Please select an option'],
		Field\File::class => ['renderer' => 'file'],
		Field\Money::class => ['type' => 'money', 'renderer' => 'composite'],
		Field\Name::class => ['label' => 'Full Name', 'renderer' => 'text', 'autocompleteToken' => 'name'],
		Field\Number::class => ['renderer' => 'number'],
		Field\Password::class => ['label' => 'Password', 'renderer' => 'password', 'autocompleteToken' => 'current-password'],
		Field\PhoneNumber::class => ['label' => 'Phone Number', 'renderer' => 'composite'],
		Field\Text::class => ['multiline' => false, 'renderer' => 'text'],
		Field\Time::class => ['renderer' => 'time'],
		Field\Uri::class => ['renderer' => 'url', 'autocompleteToken' => 'url'],
		Field\Uuid::class => ['renderer' => 'text'],
	];

	private const GLOBAL_DEFAULTS = [
		'readonly'  => false,
		'disabled'  => false,
		'hidden'    => false,
		'autofocus' => false,
	];

	/**
	 * @param array<string, mixed> $fieldSpecificOptions
	 */
	public function resolve(Field $field, array $fieldSpecificOptions = []): object
	{
		$fieldName = (string) $field->name;

		$options = array_merge(
			self::GLOBAL_DEFAULTS,
			self::DEFAULTS[$field::class] ?? [],
			$fieldSpecificOptions,
		);

		$options['id']    ??= 'input-' . hash('sha256', $fieldName);
		$options['label'] ??= ucfirst(str_replace('_', ' ', $fieldName));
		$options['type']  ??= $this->typeFor($field);
		$options['renderer'] ??= 'text';
		$options['autocomplete'] = self::autocompleteFor($options);

		return (object) $options;
	}

	/**
	 * Collapses the semantic token for this field type or part (`autocompleteToken`) and the
	 * caller's own wish (`autocomplete`, see FieldOptions::autocomplete()) into the attribute's
	 * final value.
	 *
	 * `null` means emit nothing, which is what fields with no meaningful token want —
	 * guessing a token is worse than leaving autofill to the browser's own heuristics.
	 *
	 * @param array<string, mixed> $options
	 */
	public static function autocompleteFor(array $options): ?string
	{
		$token = $options['autocompleteToken'] ?? null;
		$requested = $options['autocomplete'] ?? null;

		return match (true) {
			$requested === false => 'off',
			is_string($requested) => $requested,
			default => is_string($token) ? $token : null,
		};
	}

	private function typeFor(Field $field): string
	{
		$shortName = substr((string) strrchr('\\' . $field::class, '\\'), 1);

		return strtolower((string) preg_replace('/(?<!^)[A-Z]/', '-$0', $shortName));
	}
}
