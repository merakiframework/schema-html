<?php
declare(strict_types=1);

namespace Meraki\Schema\Html\Wizard;

/**
 * The navigation intent of a submitted step, read from the shared
 * `__wizard[action]` submit button. Defaults to {@see self::Next}.
 */
enum Action: string
{
	case Next = 'next';
	case Back = 'back';
	case Submit = 'submit';

	/**
	 * @param array<string, mixed> $request
	 */
	public static function fromRequest(array $request): self
	{
		$meta = $request['__wizard'] ?? [];
		$value = is_array($meta) ? ($meta['action'] ?? null) : null;

		return is_string($value) ? (self::tryFrom($value) ?? self::Next) : self::Next;
	}
}
