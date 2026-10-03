<?php
declare(strict_types=1);

namespace Meraki\Schema\Html;

use Meraki\Schema\Html\Exception\UnsupportedLocale;
use Meraki\Schema\Message\Provider;
use Meraki\Schema\Message\Translator;

/**
 * The language a form speaks, and where its wording comes from.
 *
 * `meraki/schema` owns the wording — installable MessageFormat 2 packs, so every port says the
 * same thing — and the port chooses which pack and which language. This is that choice, made per
 * form (so per request) with {@see FormOptions::withMessages()}, the same way the core takes it
 * per call on `Definition::validate()`.
 */
final readonly class Messages
{
	public function __construct(
		public string $locale,
		public Provider $provider,
	) {}

	/**
	 * The translator for this form's language.
	 *
	 * Strict, because the core is not: an unsupported locale there yields silence, and a form
	 * whose error boxes are silently empty is the one failure nobody notices until a user does.
	 *
	 * @throws UnsupportedLocale when the provider has nothing for the locale
	 */
	public function translator(): Translator
	{
		if (!$this->provider->supports($this->locale)) {
			throw UnsupportedLocale::named($this->locale);
		}

		return $this->provider->forLocale($this->locale);
	}
}
