<?php
declare(strict_types=1);

namespace Meraki\Schema\Html;

use Meraki\Schema\Field;
use Meraki\Schema\ValidationResult;

/**
 * Produces human-readable validation error messages for a field's failed
 * constraints, given that field's validation result.
 */
interface ValidationMessageProvider
{
	/**
	 * @return string[]
	 */
	public function errorsFor(Field $field, ?ValidationResult $result = null): array;
}
