<?php
declare(strict_types=1);

namespace Meraki\Schema\Html;

use Meraki\Schema\Field;

/**
 * Resolves the effective UI options for a field by layering: global defaults,
 * per-field-type defaults, options inherited from a parent (composite) field,
 * and the caller-supplied form options. Also derives id/label/type fallbacks.
 */
final class FormOptionResolver
{
	private const DEFAULTS = [
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
		Field\Boolean::class => ['renderer' => 'checkbox'],
		Field\Composite::class => ['renderer' => 'composite'],
		Field\CreditCard::class => ['type' => 'credit-card', 'renderer' => 'composite'],
		Field\Date::class => ['renderer' => 'date'],
		Field\DateTime::class => ['renderer' => 'datetime-local'],
		Field\Duration::class => ['renderer' => 'text'],
		Field\EmailAddress::class => ['label' => 'Email Address', 'renderer' => 'email'],
		Field\Enum::class => ['renderer' => 'radio'],
		Field\File::class => ['renderer' => 'file'],
		Field\Money::class => [
			'type' => 'money',
			'renderer' => 'composite',
			'fields' => [
				'amount'   => ['label' => 'Amount',   'renderer' => 'text'],
				'currency' => ['label' => 'Currency', 'renderer' => 'dropdown'],
			],
		],
		Field\Name::class => ['label' => 'Full Name', 'renderer' => 'text'],
		Field\Number::class => ['renderer' => 'number'],
		Field\Passphrase::class => ['label' => 'Passphrase', 'renderer' => 'password'],
		Field\Password::class => ['label' => 'Password', 'renderer' => 'password'],
		Field\PhoneNumber::class => ['label' => 'Phone Number', 'renderer' => 'tel'],
		Field\Text::class => ['multiline' => false, 'renderer' => 'text'],
		Field\Time::class => ['renderer' => 'time'],
		Field\Uri::class => ['renderer' => 'url'],
		Field\Uuid::class => ['renderer' => 'text'],
		Field\Variant::class => ['renderer' => 'text'],
	];

	private const GLOBAL_DEFAULTS = [
		'readonly'  => false,
		'disabled'  => false,
		'hidden'    => false,
		'autofocus' => false,
	];

	/**
	 * @param array $formOptions The form-level options array (see FormOptions::toArray()).
	 */
	public function __construct(private readonly array $formOptions = [])
	{
	}

	public function resolve(Field $field, array $fieldSpecificOptions = []): object
	{
		$fieldName = $field->name->value;

		$options = array_merge(
			self::GLOBAL_DEFAULTS,
			self::DEFAULTS[$field::class] ?? [],
			$fieldSpecificOptions,
		);

		$options['id']    ??= 'input-' . hash('sha256', $fieldName);
		$options['label'] ??= ucfirst(str_replace('_', ' ', $fieldName));
		$options['type']  ??= $this->typeFor($field);

		return (object) $options;
	}

	private function typeFor(Field $field): string
	{
		$shortName = substr((string) strrchr('\\' . $field::class, '\\'), 1);

		return strtolower((string) preg_replace('/(?<!^)[A-Z]/', '-$0', $shortName));
	}
}
