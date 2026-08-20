<?php
declare(strict_types=1);

namespace Meraki\Schema\Html\Csrf;

use Meraki\Schema\Html\Signer;
use InvalidArgumentException;

/**
 * Stateless CSRF token: an expiring, HMAC-signed payload that is verified by
 * recomputing the signature, so nothing is stored server-side. Pairs naturally
 * with {@see \Meraki\Schema\Html\Wizard\HiddenFieldStore}, which runs the wizard
 * without a session either.
 *
 * The token is `<payload>.<signature>`, where the payload carries an expiry and a
 * nonce. The binding is mixed into the signed material but is *not* part of the
 * payload, so it never reaches the client.
 */
final class SignedToken implements TokenProvider
{
	private const NONCE_BYTES = 8;

	/**
	 * @param string $binding A value tied to *this* visitor that an attacker can
	 *   neither read nor set — `session_id()`, or a random value in a `SameSite=Lax`,
	 *   `HttpOnly` cookie when running without sessions. It must be stable for the
	 *   life of the token, and identical at issue and verify time.
	 *
	 *   This parameter is required, and that is the point. A signed token with no
	 *   per-visitor binding is *not* CSRF protection: every visitor is handed a
	 *   validly-signed token, so an attacker simply fetches one from their own visit
	 *   and embeds it in the forged request. Binding is what makes a token useless to
	 *   anyone but the person it was issued to.
	 *
	 * @param int $ttl Seconds a token stays acceptable. Long enough to fill the form
	 *   in, short enough to limit replay; the default is two hours.
	 */
	public function __construct(
		private readonly Signer $signer,
		private readonly string $binding,
		private readonly int $ttl = 7200,
	) {
		if ($binding === '') {
			throw new InvalidArgumentException(
				'A CSRF token binding cannot be empty: an unbound signed token is valid for every '
				. 'visitor and therefore provides no CSRF protection. Pass session_id() or an '
				. 'equivalent per-visitor secret.',
			);
		}

		if ($ttl <= 0) {
			throw new InvalidArgumentException('A CSRF token TTL must be a positive number of seconds.');
		}
	}

	public function issue(): string
	{
		$payload = self::encode((string) json_encode([
			'exp' => time() + $this->ttl,
			'n' => bin2hex(random_bytes(self::NONCE_BYTES)),
		], JSON_THROW_ON_ERROR));

		return $payload . '.' . $this->signer->sign($this->material($payload));
	}

	public function accepts(?string $token): bool
	{
		if ($token === null) {
			return false;
		}

		$separator = strrpos($token, '.');

		if ($separator === false) {
			return false;
		}

		$payload = substr($token, 0, $separator);
		$signature = substr($token, $separator + 1);

		// Check the signature before decoding: an unsigned payload is untrusted input.
		if (!$this->signer->verify($this->material($payload), $signature)) {
			return false;
		}

		$decoded = json_decode(self::decode($payload), true);

		if (!is_array($decoded) || !isset($decoded['exp']) || !is_int($decoded['exp'])) {
			return false;
		}

		return $decoded['exp'] > time();
	}

	/**
	 * The signed material. The binding is folded in here rather than into the payload
	 * so it stays server-side; the separator keeps `a` + `bc` from colliding with
	 * `ab` + `c`.
	 */
	private function material(string $payload): string
	{
		return $payload . '|' . $this->binding;
	}

	private static function encode(string $value): string
	{
		return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
	}

	private static function decode(string $value): string
	{
		return (string) base64_decode(strtr($value, '-_', '+/'), true);
	}
}
