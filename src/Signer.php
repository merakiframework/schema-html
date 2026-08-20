<?php
declare(strict_types=1);

namespace Meraki\Schema\Html;

use InvalidArgumentException;

/**
 * Keyed HMAC over a string payload, used wherever the library has to hand a value
 * to the client and later trust that it came back unchanged: CSRF tokens
 * ({@see Csrf\SignedToken}) and carried wizard state
 * ({@see Wizard\SignedHiddenFieldStore}).
 *
 * A signature proves the payload was not tampered with. It does *not* hide it —
 * signed values remain readable in the page source.
 */
final class Signer
{
	/**
	 * @param string $secret A high-entropy application secret, kept off the client.
	 *                       Rotating it invalidates every outstanding signature.
	 */
	public function __construct(
		private readonly string $secret,
		private readonly string $algorithm = 'sha256',
	) {
		if ($secret === '') {
			throw new InvalidArgumentException('Signing secret cannot be empty.');
		}

		if (!in_array($algorithm, hash_hmac_algos(), true)) {
			throw new InvalidArgumentException("Unsupported HMAC algorithm: {$algorithm}.");
		}
	}

	public function sign(string $payload): string
	{
		return hash_hmac($this->algorithm, $payload, $this->secret);
	}

	/**
	 * Constant-time comparison, so a wrong signature leaks nothing through timing.
	 */
	public function verify(string $payload, string $signature): bool
	{
		return hash_equals($this->sign($payload), $signature);
	}
}
