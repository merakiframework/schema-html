<?php
declare(strict_types=1);

namespace Meraki\Schema\Html\Csrf;

use Meraki\Schema\Html\Wizard\Storage;

/**
 * The classic synchroniser-token pattern: a random token is held server-side and
 * mirrored into the form, and a submission is accepted only when the two match.
 *
 * Reuses the wizard's {@see Storage} abstraction, so it works with
 * {@see \Meraki\Schema\Html\Wizard\SessionStorage} in production and
 * {@see \Meraki\Schema\Html\Wizard\InMemoryStorage} in tests. The caller is
 * responsible for having started the session.
 *
 * Prefer this when you have a session. {@see SignedToken} is the stateless
 * alternative, for hosts running without one.
 */
final class SynchroniserToken implements TokenProvider
{
	private const BYTES = 32;

	public function __construct(
		private readonly Storage $storage,
		private readonly string $key = 'meraki_csrf',
	) {}

	/**
	 * Returns the stored token, minting one on first use.
	 *
	 * Deliberately idempotent: rotating on every render would invalidate the token
	 * held by any other open tab, and would break the wizard, which re-renders the
	 * form on every step. Call {@see self::rotate()} at the points where rotation
	 * is actually wanted.
	 */
	public function issue(): string
	{
		$stored = $this->stored();

		if ($stored !== null) {
			return $stored;
		}

		$token = bin2hex(random_bytes(self::BYTES));
		$this->storage->write($this->key, ['token' => $token]);

		return $token;
	}

	public function accepts(?string $token): bool
	{
		$stored = $this->stored();

		if ($stored === null || $token === null) {
			return false;
		}

		return hash_equals($stored, $token);
	}

	/**
	 * Discard the current token and mint a fresh one. Worth calling after a
	 * privilege change (login, logout, password change) so a token captured
	 * beforehand cannot be replayed against the new session.
	 */
	public function rotate(): string
	{
		$this->storage->write($this->key, []);

		return $this->issue();
	}

	private function stored(): ?string
	{
		$token = $this->storage->read($this->key)['token'] ?? null;

		return is_string($token) && $token !== '' ? $token : null;
	}
}
