<?php
declare(strict_types=1);

namespace Meraki\Schema\Html\Wizard;

use Closure;
use Meraki\Schema\Definition;
use Meraki\Schema\Field\Collection;
use Meraki\Schema\FieldResult;
use Meraki\Schema\Html\FormOptions;
use Meraki\Schema\Html\Input;
use Meraki\Schema\Html\Presentation\RuleEffects;
use Meraki\Schema\Html\Request\PayloadMapper;
use stdClass;

/**
 * Convenience driver tying a schema, its form options (which carry the steps), and
 * a {@see StateStore} together.
 *
 * The host owns the request/response loop: call {@see self::start()} for the initial
 * GET and {@see self::handle()} for each POST. The library owns rendering, state and
 * per-step validation; this class just sequences them.
 */
final class Form
{
	public function __construct(
		private readonly Definition $schema,
		private readonly FormOptions $options,
		private readonly StateStore $store,
		private readonly Renderer $renderer = new Renderer(),
		private readonly Validator $validator = new Validator(),
		private readonly PayloadMapper $mapper = new PayloadMapper(),
	) {}

	/**
	 * Render the first step (initial GET).
	 */
	public function start(): string
	{
		$index = $this->renderer->resolveVisibleIndex($this->schema, $this->options, [], 0, 1);

		return $this->renderer->render($this->schema, $this->options, $this->store, (new State())->movedTo($index));
	}

	/**
	 * Process a submitted step and decide what happens next.
	 *
	 * @param array<string, mixed> $request
	 */
	public function handle(array $request): Result
	{
		// Normalize the one request quirk every form has (an untouched box submits '') so an
		// optional field submitted empty is skipped, not validated as a bad value.
		$request = (new Input($request))->toArray();

		// Before anything reads the request: a forged submission must not reach the
		// state store, the schema, or the host's completion handler.
		$this->options->csrf?->verify($request);

		$request = $this->stripFormMetadata($request);

		$rawAction = $this->rawAction($request);

		// A dialog collection's blank "add" row rides in with every submission. It is only
		// committed by its own add:<field> action; on any other action drop it so a
		// prefilled/inherited draft never becomes a phantom item or survives a remove.
		$request = $this->dropDraftRows($request, $rawAction);

		$state = $this->store->load($request);

		if (CollectionActions::isCollectionAction($rawAction)) {
			$state = CollectionActions::apply($rawAction, $state);

			return Result::render($this->renderer->render($this->schema, $this->options, $this->store, $state));
		}

		// "update" re-renders the current step with the edited values (re-applying the
		// schema's rules so conditional fields/visibility and the summary refresh). Used on
		// the review step, where the whole form is editable; no validation, no advance.
		if ($rawAction === 'update') {
			return Result::render($this->renderer->render($this->schema, $this->options, $this->store, $state));
		}

		$action = Action::fromRequest($request);
		$groups = $this->options->groups;
		$index = $state->currentStepIndex;
		$isLast = $index >= count($groups) - 1;

		if ($action === Action::Back) {
			$target = $this->renderer->resolveVisibleIndex($this->schema, $this->options, $state->data, $index - 1, -1);

			return Result::render(
				$this->renderer->render($this->schema, $this->options, $this->store, $state->movedTo($target)),
			);
		}

		// The last group validates the whole schema (catching anything earlier groups
		// missed); earlier groups validate only their own fields.
		$payload = $this->mapper->map($this->schema, $state->data);
		$result = $isLast
			? $this->schema->validate($payload)
			: $this->validator->validateGroup($this->schema, $groups[$index], $payload);

		if (!$this->validator->passed($result)) {
			return Result::render(
				$this->renderer->render($this->schema, $this->options, $this->store, $state, $result),
			);
		}

		if ($isLast) {
			return Result::completed($state->data, $result, self::accepted($payload, $result->forField(...)));
		}

		$target = $this->renderer->resolveVisibleIndex($this->schema, $this->options, $state->data, $index + 1, 1);

		return Result::render(
			$this->renderer->render($this->schema, $this->options, $this->store, $state->movedTo($target)),
		);
	}

	/**
	 * The payload the schema judged, less the fields its rules ignored — at the top level and in
	 * each collection row, where a row rule may ignore a field of its own.
	 *
	 * The core withholds what was submitted for an ignored field and validates it as empty, so
	 * handing it on would pass along an answer nobody accepted: a participant's name typed before
	 * switching back to booking for yourself.
	 *
	 * @param Closure(string): ?FieldResult $resultFor
	 */
	private static function accepted(object $record, Closure $resultFor): object
	{
		$accepted = new stdClass();

		foreach (get_object_vars($record) as $name => $value) {
			$result = $resultFor((string) $name);

			if ($result !== null && RuleEffects::of($result)->ignored) {
				continue;
			}

			if ($result instanceof Collection\Result && is_array($value)) {
				foreach ($value as $key => $row) {
					$item = $result->itemAt((string) $key);

					if (is_object($row) && $item !== null) {
						$value[$key] = self::accepted($row, $item->forField(...));
					}
				}
			}

			$accepted->{$name} = $value;
		}

		return $accepted;
	}

	/**
	 * Drops the transport-level inputs the form shell adds, so they never reach the
	 * state store.
	 *
	 * Both {@see HiddenFieldStore::load()} and {@see SessionStore::load()} treat the
	 * whole request minus `__wizard` as wizard data, so anything left here would be
	 * (a) re-emitted as a *stale* hidden input by carry(), shadowing the fresh one
	 * that startForm() writes on the next step, and (b) handed to the host inside
	 * Result::completed(). Neither is field data, so neither belongs in the state.
	 *
	 * @param array<string, mixed> $request
	 * @return array<string, mixed>
	 */
	private function stripFormMetadata(array $request): array
	{
		if ($this->options->csrf !== null) {
			unset($request[$this->options->csrf->fieldName]);
		}

		unset($request['_method']);

		return $request;
	}

	/**
	 * Removes each collection's draft "add" row (marked by `__draft[<field>]=<row>`)
	 * unless that field's own add action committed it. Keeps a prefilled/inherited draft
	 * from becoming a phantom item or surviving a remove.
	 *
	 * @param array<string, mixed> $request
	 * @return array<string, mixed>
	 */
	private function dropDraftRows(array $request, string $action): array
	{
		$drafts = $request['__draft'] ?? [];
		unset($request['__draft']);

		if (!is_array($drafts)) {
			return $request;
		}

		foreach ($drafts as $field => $row) {
			// The field being explicitly added keeps its draft — that IS the new item.
			if ($action === 'add:' . $field) {
				continue;
			}

			if (is_string($row) && isset($request[$field]) && is_array($request[$field])) {
				unset($request[$field][$row]);
			}
		}

		return $request;
	}

	/**
	 * @param array<string, mixed> $request
	 */
	private function rawAction(array $request): string
	{
		$meta = $request['__wizard'] ?? [];
		$value = is_array($meta) ? ($meta['action'] ?? '') : '';

		return is_string($value) ? $value : '';
	}
}
