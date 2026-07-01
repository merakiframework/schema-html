<?php
declare(strict_types=1);

namespace Meraki\Schema\Html\Behaviour;

use Meraki\Schema\Html\ConditionUiBehaviour;
use Meraki\Schema\Html\FieldRenderContext;

/**
 * Hides any field that a currently-matching schema rule made optional.
 *
 * Because the hide is derived from the schema's own `make_optional` outcome,
 * presentation and validation stay consistent: the rule that hides the field is
 * the same one that made it optional, so an empty hidden field still validates.
 *
 * This behaviour is part of the default set (hiding is on by default). Remove it
 * with {@see \Meraki\Schema\Html\FormOptions::withoutBehaviour()} passing this
 * class name.
 */
final class HideOptionalFieldsResolvedByRules implements ConditionUiBehaviour
{
	public function apply(FieldRenderContext $context): void
	{
		if ($context->madeOptionalByMatchedRule) {
			$context->options->hidden = true;
		}
	}
}
