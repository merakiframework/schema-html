<?php
declare(strict_types=1);

namespace Meraki\Schema\Html\Wizard;

use Meraki\Schema\Html\Element;

/**
 * Default, stateless store: accumulated answers travel in the page as hidden
 * inputs, so no server-side storage is required. It cannot carry file uploads and
 * exposes prior answers in the DOM.
 */
final class HiddenFieldStore implements StateStore
{
	public function load(array $request): State
	{
		$meta = $request['__wizard'] ?? [];
		$step = is_array($meta) ? (int) ($meta['step'] ?? 0) : 0;

		$data = $request;
		unset($data['__wizard']);

		return new State($step, $data);
	}

	public function carry(State $state, array $carry): array
	{
		$inputs = [];

		foreach ($this->flatten($carry) as $name => $value) {
			$inputs[] = new Element('input', ['type' => 'hidden', 'name' => $name, 'value' => $value]);
		}

		return $inputs;
	}

	/**
	 * Flattens nested (composite) values to bracketed input names, e.g.
	 * ['address' => ['street' => 'x']] => ['address[street]' => 'x']. Empty and
	 * null values are dropped (nothing to carry).
	 *
	 * @param array<string, mixed> $data
	 * @return array<string, string>
	 */
	private function flatten(array $data, string $prefix = ''): array
	{
		$flat = [];

		foreach ($data as $key => $value) {
			$name = $prefix === '' ? (string) $key : $prefix . '[' . $key . ']';

			if (is_array($value)) {
				$flat += $this->flatten($value, $name);
				continue;
			}

			if ($value === null || $value === '') {
				continue;
			}

			$flat[$name] = (string) $value;
		}

		return $flat;
	}
}
