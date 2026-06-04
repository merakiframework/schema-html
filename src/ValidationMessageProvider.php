<?php
declare(strict_types=1);

namespace Meraki\Schema\Html;

use Meraki\Schema\Field;

/**
 * Produces human-readable validation error messages for a field's failed
 * constraints, based on the field's last validation run.
 */
interface ValidationMessageProvider
{
	/**
	 * @return string[]
	 */
	public function errorsFor(Field $field): array;
}
