<?php
declare(strict_types=1);

namespace Meraki\Schema\Html\Exception;

use Meraki\Schema\Field;
use Meraki\Schema\Html\Exception;
use Meraki\Schema\Html\Renderer;

/** A field was configured to render as something its type cannot be drawn as. */
final class IncompatibleRenderer extends \RuntimeException implements Exception
{
	public static function for(Renderer $renderer, Field $field): self
	{
		return new self(sprintf(
			'Renderer "%s" is not compatible with field type: %s',
			$renderer->value,
			$field::class,
		));
	}
}
