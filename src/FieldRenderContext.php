<?php
declare(strict_types=1);

namespace Meraki\Schema\Html;

use Meraki\Schema\Facade;
use Meraki\Schema\Field;

/**
 * The render-time context handed to each {@see ConditionUiBehaviour}. It carries
 * the field being rendered, its (mutable) resolved render options, the schema, the
 * current data, and which rule outcomes a currently-matching rule applied to this
 * field. Behaviours adjust rendering by mutating {@see self::$options} (for example
 * `$context->options->hidden = true`).
 */
final class FieldRenderContext
{
	public function __construct(
		public readonly Field $field,
		public object $options,
		public readonly Facade $schema,
		/** @var array<string, mixed> field name => current resolved value */
		public readonly array $data,
		public readonly bool $madeOptionalByMatchedRule,
		public readonly bool $requiredByMatchedRule,
	) {}
}
