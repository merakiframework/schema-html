<?php
declare(strict_types=1);

namespace Meraki\Schema\Html\Exception;

use Meraki\Schema\Html\Exception;

/**
 * The message provider has no wording for the locale a form asked for.
 *
 * The core answers an unsupported locale with silence — every message empty, every verdict
 * unchanged — which on a form means error boxes that say nothing. This package refuses instead.
 */
final class UnsupportedLocale extends \InvalidArgumentException implements Exception
{
	public static function named(string $locale): self
	{
		return new self(sprintf('The message provider has no messages for locale "%s".', $locale));
	}
}
