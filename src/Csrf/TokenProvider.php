<?php
declare(strict_types=1);

namespace Meraki\Schema\Html\Csrf;

/**
 * Issues and checks CSRF tokens. Two implementations ship with the library —
 * {@see SynchroniserToken} (session-backed) and {@see SignedToken} (stateless) —
 * but a host that already has CSRF machinery (Laravel, Symfony, Slim, ...) should
 * implement this interface over its own and pass that to
 * {@see \Meraki\Schema\Html\FormOptions::withCsrfProtection()}.
 */
interface TokenProvider
{
	/**
	 * The token value to embed in a form being rendered now.
	 *
	 * Implementations must be safe to call repeatedly within one request: a form
	 * shell is built on every render, and a stepped wizard renders on every
	 * round-trip. Returning a *different* token per call is fine only if every
	 * issued token stays acceptable (as with {@see SignedToken}); a store-backed
	 * provider should return the value it already holds.
	 */
	public function issue(): string;

	/**
	 * Whether a submitted token is acceptable. Null means the field was absent
	 * from the request, which must be rejected like any other bad token.
	 */
	public function accepts(?string $token): bool;
}
