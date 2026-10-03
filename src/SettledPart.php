<?php
declare(strict_types=1);

namespace Meraki\Schema\Html;

/**
 * How a *settled* part is drawn — one whose value the field's own configuration already
 * decides, like the country of an address that allows only Australia, or the currency of a
 * money field that takes only AUD.
 *
 * The core still requires that part on every request, so {@see Request\PayloadMapper} fills it
 * in whenever it is missing. That makes every choice here markup only: all three validate
 * identically.
 */
enum SettledPart: string
{
	/** Not drawn at all; the value is filled in server-side. The default. */
	case Omit = 'omit';

	/** Carried as a hidden input. */
	case Hidden = 'hidden';

	/** Drawn like any other part (a dropdown with its one option). */
	case Visible = 'visible';
}
