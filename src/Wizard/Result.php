<?php
declare(strict_types=1);

namespace Meraki\Schema\Html\Wizard;

use Meraki\Schema\SchemaValidationResult;

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
		/** @var array<string, mixed> the answers as they were submitted */
		public readonly array $data,
		/**
		 * On completion, the whole schema's passing verdict — where the typed values are:
		 * `$result->validation->forField('email')->value`.
		 */
		public readonly ?SchemaValidationResult $validation = null,
	) {}

	public static function render(string $html): self
	{
		return new self(false, $html, []);
	}

	/**
	 * @param array<string, mixed> $data
	 */
	public static function completed(array $data, ?SchemaValidationResult $validation = null): self
	{
		return new self(true, null, $data, $validation);
	}
}
