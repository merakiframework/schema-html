<?php
declare(strict_types=1);

namespace Meraki\Schema\Html\Csrf;

use Meraki\Schema\Html\Element;

/**
 * Ties a {@see TokenProvider} to a form field name: emits the hidden input on the
 * way out and checks the submitted value on the way back in.
 *
 * Held on {@see \Meraki\Schema\Html\FormOptions}, so both rendering paths pick it
 * up from the same place — see
 * {@see \Meraki\Schema\Html\FormRenderer::startForm()}.
 */
final class Guard
{
	public function __construct(
		public private(set) TokenProvider $provider,
		public private(set) string $fieldName = '_token',
	) {}

	/**
	 * The hidden input carrying a freshly issued token. Values are escaped by
	 * {@see Element}, so a token can never break out of the attribute.
	 */
	public function hiddenInput(): Element
	{
		return new Element('input', [
			'type' => 'hidden',
			'name' => $this->fieldName,
			'value' => $this->provider->issue(),
		]);
	}

	/**
	 * @param array<string, mixed> $request
	 */
	public function accepts(array $request): bool
	{
		$token = $request[$this->fieldName] ?? null;

		return $this->provider->accepts(is_string($token) ? $token : null);
	}

	/**
	 * @param array<string, mixed> $request
	 * @throws TokenMismatch when the token is missing, expired, or does not match.
	 */
	public function verify(array $request): void
	{
		if (!$this->accepts($request)) {
			throw TokenMismatch::forField($this->fieldName);
		}
	}
}
