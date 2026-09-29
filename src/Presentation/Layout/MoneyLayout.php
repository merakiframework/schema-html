<?php
declare(strict_types=1);

namespace Meraki\Schema\Html\Presentation\Layout;

use Meraki\Schema\Field;
use Meraki\Schema\Html\Presentation\PartLayout;

/**
 * An amount and its currency. The currency is a dropdown of the field's allowed codes; with
 * only one allowed it is settled, and by default not drawn at all.
 */
final class MoneyLayout implements PartLayout
{
	public function parts(Field $field, object $options): array
	{
		assert($field instanceof Field\Money);

		$codes = array_map(strval(...), array_keys($field->allowedCurrencies));

		$currency = $codes === []
			? ['label' => 'Currency', 'pattern' => '[A-Za-z]{3}', 'required' => true]
			: ['label' => 'Currency', 'widget' => 'select', 'choices' => array_combine($codes, $codes), 'required' => true];

		return [
			'currency' => $currency,
			'amount' => ['label' => 'Amount', 'inputmode' => 'decimal', 'required' => true],
		];
	}
}
