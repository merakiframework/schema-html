<?php
declare(strict_types=1);

namespace Meraki\Schema\Html\Wizard;

/**
 * Immutable snapshot of a wizard in progress: the current step index and the
 * answers accumulated across all steps so far.
 */
final class State
{
	/**
	 * @param array<string, mixed> $data
	 */
	public function __construct(
		public readonly int $currentStepIndex = 0,
		public readonly array $data = [],
	) {}

	/**
	 * @param array<string, mixed> $data
	 */
	public function mergedWith(array $data): self
	{
		return new self($this->currentStepIndex, array_merge($this->data, $data));
	}

	public function movedTo(int $index): self
	{
		return new self(max(0, $index), $this->data);
	}
}
