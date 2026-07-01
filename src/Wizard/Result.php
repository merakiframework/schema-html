<?php
declare(strict_types=1);

namespace Meraki\Schema\Html\Wizard;

/**
 * The outcome of handling a wizard submission: either HTML to render next (the
 * next step, or the current step re-rendered with errors), or completion with the
 * full set of accumulated answers for the host to process.
 */
final class Result
{
	private function __construct(
		public readonly bool $completed,
		public readonly ?string $html,
		/** @var array<string, mixed> */
		public readonly array $data,
	) {}

	public static function render(string $html): self
	{
		return new self(false, $html, []);
	}

	/**
	 * @param array<string, mixed> $data
	 */
	public static function completed(array $data): self
	{
		return new self(true, null, $data);
	}
}
