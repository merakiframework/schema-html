<?php
declare(strict_types=1);

namespace Meraki\Schema\Html\Behaviour;

use Meraki\Schema\Html\ConditionUiBehaviour;
use Meraki\Schema\Html\FieldRenderContext;

/**
 * Hides any field that one of the schema's rules made optional, or whose input a rule is
 * discarding, on this request.
 *
 * Because the hide is read from the rule's own outcome on the result, presentation and
 * validation stay consistent: the rule that hides the field is the same one that made it
 * optional (or ignored it), so an empty hidden field still validates. A field the author made
 * optional is never hidden by this on its own.
 *
 * This behaviour is part of the default set (hiding is on by default). Remove it
 * with {@see \Meraki\Schema\Html\FormOptions::withoutBehaviour()} passing this
 * class name.
 */
final class HideOptionalFieldsResolvedByRules implements ConditionUiBehaviour
{
	public function apply(FieldRenderContext $context): void
	{
		if ($context->effects->madeOptional() || $context->effects->ignored) {
			$context->options->hidden = true;
		}
	}
}
