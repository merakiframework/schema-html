<?php
declare(strict_types=1);

namespace Meraki\Schema\Html\Csrf;

use RuntimeException;

/**
 * The submitted CSRF token was missing, expired, or did not match.
 *
 * This is deliberately an exception rather than a validation failure: it is not
 * something the user can correct by editing a field, so the host should answer
 * 403 (or 419) rather than re-rendering the form with an error message.
 */
final class TokenMismatch extends RuntimeException
{
	public static function forField(string $fieldName): self
	{
		return new self("CSRF token check failed for form field '{$fieldName}'.");
	}
}
