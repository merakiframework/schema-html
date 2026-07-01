<?php
declare(strict_types=1);

namespace Meraki\Schema\Html\Wizard;

use Meraki\Schema\Facade;

/**
 * Resets the iterator position of every scope used by the schema's rules.
 *
 * A {@see \Meraki\Schema\Scope} advances its internal position when resolved, and
 * `Scope::resolve()` reads the current position before rewinding — so resolving the
 * same outcome scope a second time (which happens when a wizard calls input()/
 * validate() more than once on the same schema with a matching rule) throws. Calling
 * this before each such call makes those re-applications safe without touching core.
 */
final class RuleScopes
{
	public static function rewind(Facade $schema): void
	{
		foreach ($schema->rules as $rule) {
			foreach ($rule->condition->getScopes() as $scope) {
				$scope->rewind();
			}

			foreach ($rule->outcomes as $outcome) {
				$outcome->getScope()->rewind();
			}
		}
	}
}
