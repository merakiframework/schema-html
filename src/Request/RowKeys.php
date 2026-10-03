<?php
declare(strict_types=1);

namespace Meraki\Schema\Html\Request;

/**
 * Names a new collection row.
 *
 * `meraki/schema` addresses collection rows by name rather than position, so a row keeps its
 * identity — and its errors, and whatever row rules said about it — when a row above it is
 * removed. A form has to invent that name for the blank row it offers.
 *
 * A name has the shape of a field name: letters, digits, `_` and `-`, never starting with a
 * digit.
 */
interface RowKeys
{
	/**
	 * @param list<string> $existing the names the collection already holds
	 */
	public function next(array $existing): string;
}
