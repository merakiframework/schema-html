<?php
declare(strict_types=1);

namespace Meraki\Schema\Html\Presentation\Layout;

use Meraki\Schema\Field;
use Meraki\Schema\Html\Presentation\PartLayout;

/**
 * A card's number, expiry, holder and security code, with the autofill tokens browsers use
 * for them. The expiry is a `month` input, which submits the `YYYY-MM` the core reads.
 */
final class CreditCardLayout implements PartLayout
{
	public function parts(Field $field, object $options): array
	{
		return [
			'number' => ['label' => 'Card Number', 'inputmode' => 'numeric', 'autocompleteToken' => 'cc-number', 'required' => true],
			'expiry' => ['label' => 'Expiry', 'type' => 'month', 'autocompleteToken' => 'cc-exp', 'required' => true],
			'name' => ['label' => 'Name on Card', 'autocompleteToken' => 'cc-name', 'required' => true],
			'security_code' => ['label' => 'Security Code', 'inputmode' => 'numeric', 'autocompleteToken' => 'cc-csc'],
		];
	}
}
