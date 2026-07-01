<?php
declare(strict_types=1);

namespace Meraki\Schema\Html\Wizard;

/**
 * Minimal key/value persistence used by {@see SessionStore}. Implementations may be
 * backed by $_SESSION, a cache, or (in tests) memory.
 */
interface Storage
{
	/**
	 * @return array<string, mixed>
	 */
	public function read(string $key): array;

	/**
	 * @param array<string, mixed> $data
	 */
	public function write(string $key, array $data): void;
}
