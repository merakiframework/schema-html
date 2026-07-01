<?php
declare(strict_types=1);

namespace Meraki\Schema\Html\Wizard;

use Meraki\Schema\Facade;
use Meraki\Schema\Html\FormOptions;
use Meraki\Schema\Html\Input;

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
		private readonly Facade $schema,
		private readonly FormOptions $options,
		private readonly StateStore $store,
		private readonly Renderer $renderer = new Renderer(),
		private readonly Validator $validator = new Validator(),
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
		// Normalize PHP request quirks (empty strings -> null, 'on' -> true) so an
		// optional field submitted empty is skipped, not validated as a bad value.
		$request = (new Input($request))->toArray();

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
		if ($isLast) {
			RuleScopes::rewind($this->schema);
			$result = $this->schema->validate($state->data);
		} else {
			$result = $this->validator->validateGroup($this->schema, $groups[$index], $state->data);
		}

		if (!$this->validator->passed($result)) {
			return Result::render(
				$this->renderer->render($this->schema, $this->options, $this->store, $state, $result),
			);
		}

		if ($isLast) {
			return Result::completed($state->data);
		}

		$target = $this->renderer->resolveVisibleIndex($this->schema, $this->options, $state->data, $index + 1, 1);

		return Result::render(
			$this->renderer->render($this->schema, $this->options, $this->store, $state->movedTo($target)),
		);
	}

	/**
	 * Removes each collection's draft "add" row (marked by `__draft[<field>]=<index>`)
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

		foreach ($drafts as $field => $index) {
			// The field being explicitly added keeps its draft — that IS the new item.
			if ($action === 'add:' . $field) {
				continue;
			}

			if (isset($request[$field]) && is_array($request[$field])) {
				unset($request[$field][(int) $index]);
				$request[$field] = array_values($request[$field]);
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
