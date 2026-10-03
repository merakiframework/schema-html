<?php
declare(strict_types=1);

namespace Meraki\Schema\Html\Exception;

use Meraki\Schema\Html\Exception;

/**
 * A form was rendered without saying which language its messages are in, or where they come
 * from.
 *
 * Messages are required rather than optional: a form that fails validation with nothing to
 * say about why is worse than one that refuses to render during development.
 */
final class MessagesNotConfigured extends \LogicException implements Exception
{
	public static function for(string $form): self
	{
		return new self(sprintf(
			'Form "%s" has no messages. Call FormOptions::withMessages($locale, $provider) before rendering, e.g. '
				. 'withMessages(\'en\', Mf2Provider::fromPackage(\'meraki/schema-language-english\')).',
			$form,
		));
	}
}
