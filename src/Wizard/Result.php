<?php
declare(strict_types=1);

namespace Meraki\Schema\Html\Wizard;

use Meraki\Schema\SchemaValidationResult;

/**
 * The outcome of handling a wizard submission: either HTML to render next (the
 * next step, or the current step re-rendered with errors), or completion with the
 * full set of accumulated answers for the host to process.
 *
 * On completion the answers come three ways, for three jobs:
 *
 * - {@see self::$payload} — what the schema accepted, as plain data: what to hand on or store;
 * - {@see self::$validation} — the same, typed: `forField('email')->value`;
 * - {@see self::$data} — exactly what the browser sent, for re-rendering the form.
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
		/**
		 * On completion, the answers as the schema accepted them: mapped for it (a settled
		 * country filled back in, a ticked box as `true`, a street as its lines), and without
		 * any field a rule said to ignore, since the schema discarded what was sent for those.
		 * Plain data, ready for `json_encode()`.
		 */
		public readonly ?object $payload = null,
	) {}

	public static function render(string $html): self
	{
		return new self(false, $html, []);
	}

	/**
	 * @param array<string, mixed> $data
	 */
	public static function completed(array $data, ?SchemaValidationResult $validation = null, ?object $payload = null): self
	{
		return new self(true, null, $data, $validation, $payload);
	}
}
