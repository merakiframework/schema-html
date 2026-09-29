<?php
declare(strict_types=1);

namespace Meraki\Schema\Html\Presentation;

use Meraki\Schema\FieldResult;
use Meraki\Schema\ResolvedField;
use Meraki\Schema\Rule\Outcome\Ignore;
use Meraki\Schema\Rule\Outcome\Reconfigure;

/**
 * What the schema's own rules did to a field on this request: its optionality, and whether its
 * input is being discarded.
 *
 * Read from the result (`ResolvedField::$appliedOutcomes`) rather than by re-running the rules,
 * so the rendering can never disagree with the validation. An author who made a field optional
 * wrote it that way; only a *rule* doing it counts here.
 *
 * A rule records only what it *changed*, so making an already-optional field optional records
 * nothing. Such rules usually ignore the field's input as well, which is recorded — see
 * {@see self::$ignored}.
 */
final readonly class RuleEffects
{
	private function __construct(
		/** The optionality the last rule to touch it left, or null when no rule did. */
		public ?bool $optional,
		/** Whether a rule said to discard whatever is submitted for it. */
		public bool $ignored,
	) {}

	public static function of(FieldResult $result): self
	{
		$optional = null;
		$ignored = false;
		$applied = $result instanceof ResolvedField ? $result->appliedOutcomes : [];

		foreach ($applied as $outcome) {
			if ($outcome->outcome instanceof Ignore) {
				$ignored = true;
			}

			if ($outcome->outcome instanceof Reconfigure && array_key_exists('optional', $outcome->outcome->changes)) {
				$optional = (bool) $outcome->outcome->changes['optional'];
			}
		}

		return new self($optional, $ignored);
	}

	public static function none(): self
	{
		return new self(null, false);
	}

	public function madeOptional(): bool
	{
		return $this->optional === true;
	}

	public function madeRequired(): bool
	{
		return $this->optional === false;
	}
}
