<?php
declare(strict_types=1);

namespace Meraki\Schema\Html;

use Meraki\Schema\Facade;
use Meraki\Schema\Html\Exception\MessagesNotConfigured;
use Meraki\Schema\Html\Exception\UnsupportedLocale;
use Meraki\Schema\Message\Provider;
use Meraki\Schema\Message\Translator;

/**
 * The language a form speaks, and where its wording comes from.
 *
 * `meraki/schema` owns the wording — installable MessageFormat 2 packs, so every port says the
 * same thing — and the port chooses which language to use. This is that choice, made per form
 * (so per request) with {@see FormOptions::withMessages()}.
 *
 * The provider is optional here because the schema may already carry one (`new Facade(...,
 * messages: $provider)`); one given here wins. Having neither is an error, as is a locale the
 * provider cannot serve: see {@see self::translatorFor()}.
 */
final readonly class Messages
{
	public function __construct(
		public string $locale,
		public ?Provider $provider = null,
	) {}

	/**
	 * The translator for this form's language.
	 *
	 * Strict, because the core is not: an unsupported locale there yields silence, and a form
	 * whose error boxes are silently empty is the one failure nobody notices until a user does.
	 *
	 * @throws MessagesNotConfigured when neither this nor the schema has a provider
	 * @throws UnsupportedLocale when the provider has nothing for the locale
	 */
	public function translatorFor(Facade $schema): Translator
	{
		$provider = $this->provider ?? $schema->messages ?? throw MessagesNotConfigured::noProvider((string) $schema->name);

		if (!$provider->supports($this->locale)) {
			throw UnsupportedLocale::named($this->locale);
		}

		return $provider->forLocale($this->locale);
	}
}
