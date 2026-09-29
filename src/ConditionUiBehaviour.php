<?php
declare(strict_types=1);

namespace Meraki\Schema\Html;

/**
 * A hook that adjusts how a single field is rendered, based on what the schema's rules did to
 * it on this request.
 *
 * Behaviours never define conditions — rule/condition definitions live in the
 * schema (`meraki/schema`). A behaviour only *reacts* to the rules the schema
 * already owns, by mutating the field's resolved render options via the supplied
 * {@see FieldRenderContext}.
 */
interface ConditionUiBehaviour
{
	public function apply(FieldRenderContext $context): void;
}
