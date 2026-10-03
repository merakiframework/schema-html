<?php
declare(strict_types=1);

namespace Meraki\Schema\Html\Support;

use Meraki\Schema\Html\FormOptions;
use Meraki\Schema\Message\Mf2\Mf2Provider;

/**
 * What every test that renders needs: messages are mandatory, so every form is given the
 * fixture English pack.
 */
final class Forms
{
	public static function messages(): Mf2Provider
	{
		static $provider = null;

		return $provider ??= Mf2Provider::fromDirectory(__DIR__ . '/../fixtures/lang');
	}

	public static function options(): FormOptions
	{
		return (new FormOptions())->withMessages('en', self::messages());
	}
}
