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
	public static function noLocale(string $form): self
	{
		return new self(sprintf(
			'Form "%s" has no message locale. Call FormOptions::withMessages($locale, $provider) before rendering.',
			$form,
		));
	}

	public static function noProvider(string $form): self
	{
		return new self(sprintf(
			'Form "%s" has no message provider. Pass one to FormOptions::withMessages(), or give the schema one '
				. '(new Facade(..., messages: $provider)).',
			$form,
		));
	}
}
