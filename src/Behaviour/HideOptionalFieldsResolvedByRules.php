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
 * A field that failed is never hidden. Optional is not ignored — a value that is there is still
 * checked — so a field can fail after a rule made it optional: a phone number typed badly before
 * the contact method was switched to email. Hiding it would hide its message too, leaving a form
 * that will not submit and nothing on the page to say why.
 *
 * This behaviour is part of the default set (hiding is on by default). Remove it
 * with {@see \Meraki\Schema\Html\FormOptions::withoutBehaviour()} passing this
 * class name.
 */
final class HideOptionalFieldsResolvedByRules implements ConditionUiBehaviour
{
	public function apply(FieldRenderContext $context): void
	{
		if ($context->result->status->failed()) {
			return;
		}

		if ($context->effects->madeOptional() || $context->effects->ignored) {
			$context->options->hidden = true;
		}
	}
}
