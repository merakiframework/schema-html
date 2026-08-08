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
		// Labels here are the country-agnostic fallbacks; FormRenderer::renderAddressField
		// replaces them with the terms the allowed country actually uses ("Suburb", "State").
		// The autocomplete tokens are the HTML spec's names, which differ from our own.
		//
		// No 'renderer' is pinned per part: `country_code` and `administrative_area` become
		// enums once the whitelist makes them a closed set, so each part has to fall back to
		// the default for whatever type it currently is.
		Field\Address::class => [
			'type' => 'address',
			'label' => 'Address',
			'fields' => [
				'organization'        => ['label' => 'Organisation',          'autocompleteToken' => 'organization'],
				'line1'               => ['label' => 'Address',               'autocompleteToken' => 'address-line1'],
				'line2'               => ['label' => 'Apartment, unit, etc.', 'autocompleteToken' => 'address-line2'],
				'dependent_locality'  => ['label' => 'Suburb',                'autocompleteToken' => 'address-level3'],
				'locality'            => ['label' => 'City',                  'autocompleteToken' => 'address-level2'],
				'administrative_area' => ['label' => 'Administrative Area',   'autocompleteToken' => 'address-level1'],
				'postal_code'         => ['label' => 'Postal Code',           'autocompleteToken' => 'postal-code'],
				'country_code'        => ['label' => 'Country',               'autocompleteToken' => 'country'],
			],
			'renderer' => 'composite',
		],
		Field\Boolean::class => ['renderer' => 'checkbox'],
		Field\Collection::class => ['type' => 'collection', 'renderer' => 'composite'],
		Field\Composite::class => ['renderer' => 'composite'],
		Field\CreditCard::class => [
			'type' => 'credit-card',
			'renderer' => 'composite',
			'fields' => [
				'holder'        => ['autocompleteToken' => 'cc-name'],
				'number'        => ['autocompleteToken' => 'cc-number'],
				'expiry'        => ['autocompleteToken' => 'cc-exp'],
				'security_code' => ['autocompleteToken' => 'cc-csc'],
			],
		],
		Field\Date::class => ['renderer' => 'date'],
		Field\DateTime::class => ['renderer' => 'datetime-local'],
		Field\Duration::class => ['renderer' => 'text'],
		Field\EmailAddress::class => ['label' => 'Email Address', 'renderer' => 'email', 'autocompleteToken' => 'email'],
		Field\Enum::class => ['renderer' => 'radiogroup', 'hint' => 'Please select an option'],
		Field\File::class => ['renderer' => 'file'],
		Field\Money::class => [
			'type' => 'money',
			'renderer' => 'composite',
			'fields' => [
				'amount'   => ['label' => 'Amount',   'renderer' => 'text'],
				'currency' => ['label' => 'Currency', 'renderer' => 'dropdown'],
			],
		],
		Field\Name::class => ['label' => 'Full Name', 'renderer' => 'text', 'autocompleteToken' => 'name'],
		Field\Number::class => ['renderer' => 'number'],
		Field\Passphrase::class => ['label' => 'Passphrase', 'renderer' => 'password', 'autocompleteToken' => 'current-password'],
		Field\Password::class => ['label' => 'Password', 'renderer' => 'password', 'autocompleteToken' => 'current-password'],
		Field\PhoneNumber::class => ['label' => 'Phone Number', 'renderer' => 'tel', 'autocompleteToken' => 'tel'],
		Field\Text::class => ['multiline' => false, 'renderer' => 'text'],
		Field\Time::class => ['renderer' => 'time'],
		Field\Uri::class => ['renderer' => 'url', 'autocompleteToken' => 'url'],
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
		$options['autocomplete'] = $this->autocompleteFor($options);

		return (object) $options;
	}

	/**
	 * Collapses the semantic token for this field type (`autocompleteToken`, set above or
	 * inherited from a parent composite's `fields` map) and the caller's own wish
	 * (`autocomplete`, see FieldOptions::autocomplete()) into the attribute's final value.
	 *
	 * `null` means emit nothing, which is what fields with no meaningful token want —
	 * guessing a token is worse than leaving autofill to the browser's own heuristics.
	 *
	 * @param array<string, mixed> $options
	 */
	private function autocompleteFor(array $options): ?string
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
