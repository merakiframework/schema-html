<?php
declare(strict_types=1);

namespace Meraki\Schema\Html\Wizard;

/**
 * Server-side store: accumulated answers live in {@see Storage} (e.g. the session)
 * under a fixed key, so they never appear in the DOM and file uploads can be
 * carried. Only the step index travels in the page. One wizard per key.
 */
final class SessionStore implements StateStore
{
	public function __construct(
		private readonly Storage $storage,
		private readonly string $key = 'meraki_wizard',
	) {}

	public function load(array $request): State
	{
		$meta = $request['__wizard'] ?? [];
		$step = is_array($meta) ? (int) ($meta['step'] ?? 0) : 0;

		$submitted = $request;
		unset($submitted['__wizard']);

		// This step's submission is layered over whatever was previously stored.
		$data = array_merge($this->storage->read($this->key), $submitted);

		return new State($step, $data);
	}

	public function carry(State $state, array $carry): array
	{
		// Persist the full accumulated state; only the step index (added by the
		// renderer) needs to travel in the page.
		$this->storage->write($this->key, $state->data);

		return [];
	}
}
