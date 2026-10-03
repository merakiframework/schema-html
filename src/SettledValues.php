<?php
declare(strict_types=1);

namespace Meraki\Schema\Html;

use Meraki\Schema\Field;

/**
 * The parts of a structured field that its configuration has already decided.
 *
 * One place answers this for both sides of a form, so they cannot disagree: the renderer
 * leaves these parts out (see {@see SettledPart}) and {@see Request\PayloadMapper} puts them
 * back before the core sees the submission.
 */
final class SettledValues
{
	/**
	 * @return array<string, string> part => the only value it can take
	 */
	public static function of(Field $field): array
	{
		return match (true) {
			$field instanceof Field\Address,
			$field instanceof Field\PhoneNumber => count($field->allowedCountries) === 1
				? ['country' => $field->allowedCountries[0]]
				: [],
			$field instanceof Field\Money => count($field->allowedCurrencies) === 1
				? ['currency' => (string) array_key_first($field->allowedCurrencies)]
				: [],
			default => [],
		};
	}
}
