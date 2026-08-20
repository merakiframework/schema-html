<?php
declare(strict_types=1);

namespace Meraki\Schema\Html\Wizard;

use RuntimeException;

/**
 * Carried wizard state came back altered, unsigned, or signed with another key.
 *
 * Like a CSRF failure this is not user-correctable, so the host should abort
 * (403, or restart the wizard) rather than re-render the step.
 */
final class StateTampered extends RuntimeException
{
	public static function badSignature(): self
	{
		return new self('Carried wizard state failed its signature check: it was altered in transit.');
	}

	public static function missingSignature(int $step): self
	{
		return new self("Carried wizard state arrived unsigned at step {$step}; the signature was stripped.");
	}
}
