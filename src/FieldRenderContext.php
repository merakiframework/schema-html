<?php
declare(strict_types=1);

namespace Meraki\Schema\Html;

use Meraki\Schema\Definition;
use Meraki\Schema\Field;
use Meraki\Schema\FieldResult;
use Meraki\Schema\Html\Presentation\RuleEffects;

/**
 * The render-time context handed to each {@see ConditionUiBehaviour}: the field's result for
 * this request, what the schema's rules did to it, its (mutable) resolved render options, and
 * the schema. Behaviours adjust rendering by mutating {@see self::$options} (for example
 * `$context->options->hidden = true`).
 */
final class FieldRenderContext
{
	public function __construct(
		/** This request's outcome for the field; `$result->field` is the effective definition. */
		public readonly FieldResult $result,
		/** What the schema's rules did to the field's optionality on this request. */
		public readonly RuleEffects $effects,
		public object $options,
		public readonly Definition $schema,
	) {}

	/** The effective definition, including anything a rule changed. */
	public Field $field {
		get => $this->result->field;
	}

	/** Whether a rule made the field optional (rather than the author writing it that way). */
	public bool $madeOptionalByMatchedRule {
		get => $this->effects->madeOptional();
	}

	/** Whether a rule made the field required. */
	public bool $requiredByMatchedRule {
		get => $this->effects->madeRequired();
	}
}
