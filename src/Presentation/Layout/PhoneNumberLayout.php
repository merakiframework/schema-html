<?php
declare(strict_types=1);

namespace Meraki\Schema\Html\Presentation\Layout;

use Meraki\Schema\Field;
use Meraki\Schema\Html\AddressVocabulary;
use Meraki\Schema\Html\Presentation\PartLayout;

/**
 * A number and the country it belongs to — the core cannot read one without the other. With a
 * single allowed country the country is settled, and by default the field draws as one `tel`
 * input.
 *
 * The field's own hint and autofill setting apply to the number, since that is what gets typed.
 */
final class PhoneNumberLayout implements PartLayout
{
	public function __construct(private readonly AddressVocabulary $vocabulary = new AddressVocabulary())
	{
	}

	public function parts(Field $field, object $options): array
	{
		assert($field instanceof Field\PhoneNumber);

		$number = ['label' => 'Number', 'type' => 'tel', 'autocompleteToken' => 'tel', 'required' => true];

		foreach (['hint', 'autocomplete'] as $key) {
			if (isset($options->{$key})) {
				$number[$key] = $options->{$key};
			}
		}

		return [
			'country' => [
				'label' => 'Country',
				'widget' => 'select',
				'choices' => $this->vocabulary->countryNames($field->allowedCountries),
				'required' => true,
			],
			'number' => $number,
		];
	}
}
