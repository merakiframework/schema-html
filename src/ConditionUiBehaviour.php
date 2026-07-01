<?php
declare(strict_types=1);

namespace Meraki\Schema\Html;

/**
 * A hook that adjusts how a single field is rendered, based on the schema rules
 * that currently match the submitted data.
 *
 * Behaviours never define conditions — rule/condition definitions live in the
 * schema (`meraki/schema`). A behaviour only *reacts* to the rules the schema
 * already owns, by mutating the field's resolved render options via the supplied
 * {@see FieldRenderContext}. A behaviour that resolves a {@see \Meraki\Schema\Scope}
 * against the schema therefore throws if it references a field that does not exist.
 */
interface ConditionUiBehaviour
{
	public function apply(FieldRenderContext $context): void;
}
