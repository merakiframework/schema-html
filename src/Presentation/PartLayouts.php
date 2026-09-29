<?php
declare(strict_types=1);

namespace Meraki\Schema\Html\Presentation;

use Meraki\Schema\Field;
use Meraki\Schema\Html\AddressVocabulary;

/**
 * The {@see PartLayout} for each structured field type.
 */
final class PartLayouts
{
	/**
	 * @param array<class-string<Field>, PartLayout> $layouts
	 */
	public function __construct(private array $layouts = [])
	{
	}

	public static function defaults(AddressVocabulary $vocabulary = new AddressVocabulary()): self
	{
		return new self([
			Field\Address::class => new Layout\AddressLayout($vocabulary),
			Field\CreditCard::class => new Layout\CreditCardLayout(),
			Field\Money::class => new Layout\MoneyLayout(),
			Field\PhoneNumber::class => new Layout\PhoneNumberLayout($vocabulary),
		]);
	}

	/**
	 * @param class-string<Field> $fieldClass
	 */
	public function with(string $fieldClass, PartLayout $layout): self
	{
		$copy = clone $this;
		$copy->layouts[$fieldClass] = $layout;

		return $copy;
	}

	public function for(Field $field): ?PartLayout
	{
		return $this->layouts[$field::class] ?? null;
	}
}
