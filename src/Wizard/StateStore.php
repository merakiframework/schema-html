<?php
declare(strict_types=1);

namespace Meraki\Schema\Html\Wizard;

use Meraki\Schema\Html\Element;

/**
 * Persists wizard state across the no-JS round-trips between steps. A store knows
 * how to (a) reconstruct the {@see State} from an incoming request and (b) emit any
 * hidden inputs needed to carry that state forward in the rendered form.
 */
interface StateStore
{
	/**
	 * @param array<string, mixed> $request
	 */
	public function load(array $request): State;

	/**
	 * Hidden inputs to embed so the wizard state survives the next round-trip.
	 *
	 * $carry is the accumulated data that is NOT shown as an editable input on the
	 * current step (the renderer renders those as real inputs). Server-side stores
	 * persist here and may return only a token; the hidden-field store returns
	 * $carry as hidden inputs.
	 *
	 * @param array<string, mixed> $carry
	 * @return list<Element>
	 */
	public function carry(State $state, array $carry): array;
}
