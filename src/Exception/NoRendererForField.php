<?php
declare(strict_types=1);

namespace Meraki\Schema\Html\Exception;

use Meraki\Schema\Field;
use Meraki\Schema\Html\Exception;

/** The theme has no renderer for a field type. Register one with the theme's `withRenderer()`. */
final class NoRendererForField extends \RuntimeException implements Exception
{
	public static function for(Field $field): self
	{
		return new self('No renderer registered for field type: ' . $field::class);
	}
}
