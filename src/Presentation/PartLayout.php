<?php
declare(strict_types=1);

namespace Meraki\Schema\Html\Presentation;

use Meraki\Schema\Field;

/**
 * Which parts a structured field is drawn as, and how.
 *
 * A structured field (an address, an amount, a card) holds one value with named parts, and the
 * core says nothing about how to ask for them — that is presentation. A layout answers it for
 * one field type. Register your own for a field type of your own in {@see PartLayouts}.
 *
 * Each part's options use the same keys as `FieldOptions` (`label`, `hint`, `renderer`,
 * `autocompleteToken`, `pattern`, `inputmode`, `options`), plus:
 *
 * - `type`: the input type (`text`, `tel`, `month`, …);
 * - `widget`: `input` or `select`;
 * - `choices`: value => label for a select;
 * - `required`: whether the part needs an answer when the field does;
 * - `lines`: for a part held as a list, one label per line to draw (an address's `street`);
 * - `lineTokens`: the autocomplete token for each line, by position.
 *
 * Options a caller sets for a part (`$options->configure('price')->configure('amount')`) are
 * laid over these.
 */
interface PartLayout
{
	/**
	 * @param object $options the field's own resolved options
	 * @return array<string, array<string, mixed>> part name => options, in display order.
	 *         Leave out a part the field has no use for.
	 */
	public function parts(Field $field, object $options): array;
}
