<?php
declare(strict_types=1);

namespace Meraki\Schema\Html\Request;

use InvalidArgumentException;
use Meraki\Schema\FieldName;

/**
 * `row1`, `row2`, `row3`, … — one past the highest number already used, never reused and never
 * renumbered.
 *
 * Deterministic on purpose: the same submission always draws the same form, which keeps signed
 * wizard state stable and makes the markup easy to assert on.
 */
final class SequentialRowKeys implements RowKeys
{
	public function __construct(private readonly string $prefix = 'row')
	{
		if (!FieldName::isUsable($this->prefix . '1')) {
			throw new InvalidArgumentException(sprintf('"%s" cannot start a row name.', $this->prefix));
		}
	}

	public function next(array $existing): string
	{
		$highest = 0;
		$pattern = '/^' . preg_quote($this->prefix, '/') . '(\d+)$/';

		foreach ($existing as $key) {
			if (preg_match($pattern, (string) $key, $matches) === 1) {
				$highest = max($highest, (int) $matches[1]);
			}
		}

		return $this->prefix . ($highest + 1);
	}
}
