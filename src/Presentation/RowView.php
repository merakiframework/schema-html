<?php
declare(strict_types=1);

namespace Meraki\Schema\Html\Presentation;

/**
 * One row of a collection: its name, and a view of each template field in it.
 */
final readonly class RowView
{
	/**
	 * @param string $key the row's name (`row1`), which its input names carry
	 * @param list<FieldView> $fields
	 */
	public function __construct(
		public string $key,
		public array $fields,
	) {}
}
