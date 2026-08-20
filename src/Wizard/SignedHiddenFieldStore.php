<?php
declare(strict_types=1);

namespace Meraki\Schema\Html\Wizard;

use Meraki\Schema\Html\Element;
use Meraki\Schema\Html\Signer;

/**
 * {@see HiddenFieldStore} with the carried answers signed, so a step's answers
 * cannot be edited after that step validated them.
 *
 * The plain hidden-field store re-emits every prior answer as an editable hidden
 * input; nothing stops the client rewriting one on a later round-trip and slipping
 * past the per-step validation it already passed. This store adds two more hidden
 * inputs — the list of carried names and an HMAC over both names and values — and
 * rejects any submission whose carried portion does not match.
 *
 * What this does and does not buy you:
 *  - It closes *tampering*, not *disclosure*: prior answers are still plainly
 *    readable in the page source, exactly as with {@see HiddenFieldStore}. Use
 *    {@see SessionStore} when the answers must not reach the client at all.
 *  - The final step re-validates the whole schema either way ({@see Form::handle()}),
 *    so this matters most for per-step rules, values the user should not be able to
 *    revise after the fact, and keeping a later step's branching honest.
 */
final class SignedHiddenFieldStore implements StateStore
{
	private readonly HiddenFieldStore $inner;

	public function __construct(
		private readonly Signer $signer,
		?HiddenFieldStore $inner = null,
	) {
		$this->inner = $inner ?? new HiddenFieldStore();
	}

	public function load(array $request): State
	{
		$meta = is_array($request['__wizard'] ?? null) ? $request['__wizard'] : [];
		$step = (int) ($meta['step'] ?? 0);
		$signature = $meta['sig'] ?? null;

		if (!is_string($signature) || $signature === '') {
			// Any step past the first was rendered with a signature, so its absence
			// means it was stripped. Without this the whole check is opt-out: an
			// attacker just deletes both inputs and submits arbitrary state.
			if ($step > 0) {
				throw StateTampered::missingSignature($step);
			}

			return $this->inner->load($request);
		}

		$data = $request;
		unset($data['__wizard']);

		if (!$this->signer->verify($this->payload($this->claimed($meta), $this->inner->flatten($data)), $signature)) {
			throw StateTampered::badSignature();
		}

		return new State($step, $data);
	}

	public function carry(State $state, array $carry): array
	{
		$inputs = $this->inner->carry($state, $carry);
		$flat = $this->inner->flatten($carry);
		$names = array_keys($flat);

		$inputs[] = new Element('input', [
			'type' => 'hidden',
			'name' => '__wizard[carried]',
			// JSON rather than a delimited list: nothing constrains a field name
			// (Property\Name accepts any string), so no separator is safe to assume.
			'value' => (string) json_encode($names, JSON_THROW_ON_ERROR),
		]);

		$inputs[] = new Element('input', [
			'type' => 'hidden',
			'name' => '__wizard[sig]',
			'value' => $this->signer->sign($this->payload($names, $flat)),
		]);

		return $inputs;
	}

	/**
	 * The signed material: the carried names *and* their values, in a canonical
	 * order.
	 *
	 * Signing the names alongside the values is what makes the check complete.
	 * Over values alone, an attacker could drop a name/value pair and replay the
	 * signature of the shorter set, or promote an editable field into the carried
	 * set; with the names inside the payload, either edit changes what must be
	 * signed.
	 *
	 * @param list<string> $names
	 * @param array<string, string> $flat
	 */
	private function payload(array $names, array $flat): string
	{
		sort($names);

		$values = array_map(static fn(string $name): ?string => $flat[$name] ?? null, $names);

		return (string) json_encode([$names, $values], JSON_THROW_ON_ERROR);
	}

	/**
	 * The carried names the request claims were signed. Only these keys are pulled
	 * out of the incoming data, so the step's own editable inputs — which the user
	 * is meant to be changing — are not part of the comparison.
	 *
	 * @param array<string, mixed> $meta
	 * @return list<string>
	 */
	private function claimed(array $meta): array
	{
		$carried = $meta['carried'] ?? '';

		if (!is_string($carried) || $carried === '') {
			return [];
		}

		// Decoded before the signature is checked, because the names are needed to
		// build the material being verified. Safe: json_decode instantiates nothing,
		// and any garbage here simply produces a payload that fails to verify.
		$names = json_decode($carried, true);

		if (!is_array($names)) {
			return [];
		}

		return array_values(array_filter($names, static fn(mixed $n): bool => is_string($n) && $n !== ''));
	}
}
