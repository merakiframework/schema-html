<?php
declare(strict_types=1);

namespace Meraki\Schema\Html\Wizard;

/**
 * In-memory {@see Storage}, primarily for tests and for simulating the round-trip
 * loop without a real session.
 */
final class InMemoryStorage implements Storage
{
	/** @var array<string, array<string, mixed>> */
	private array $store = [];

	public function read(string $key): array
	{
		return $this->store[$key] ?? [];
	}

	public function write(string $key, array $data): void
	{
		$this->store[$key] = $data;
	}
}
