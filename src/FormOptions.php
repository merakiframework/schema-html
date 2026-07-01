<?php
declare(strict_types=1);

namespace Meraki\Schema\Html;

final class FormOptions implements Options
{
	public private(set) string $method = 'post';
	public private(set) string $action = '';
	public private(set) array $fields = [];

	/** @var list<ConditionUiBehaviour> */
	public private(set) array $behaviours;

	/** @var list<Wizard\Group> */
	public private(set) array $groups = [];

	/** 'stepped' (one group per request) or 'single-page' (all groups, one submit). */
	public private(set) string $flow = 'stepped';

	public private(set) Wizard\Container $defaultContainer = Wizard\Container::Fieldset;

	public private(set) bool $defaultStyles = true;

	/**
	 * In a stepped flow, skip past any group whose every field is currently hidden by a
	 * matched rule (nothing to fill in). On by default; {@see self::showAllSteps()} keeps
	 * such groups as (empty) steps.
	 */
	public private(set) bool $skipHiddenGroups = true;

	public function __construct()
	{
		// Default set: hiding fields a matched rule made optional is on by default.
		$this->behaviours = [new Behaviour\HideOptionalFieldsResolvedByRules()];
	}

	/**
	 * Keep every group as its own step even when all its fields are currently hidden,
	 * rather than skipping straight past it. (Stepped flow only.)
	 */
	public function showAllSteps(): self
	{
		$this->skipHiddenGroups = false;
		return $this;
	}

	/** One group per request (round-trips; per-group validation; needs a state store). */
	public function asSteps(): self
	{
		$this->flow = 'stepped';
		$this->defaultContainer = Wizard\Container::Fieldset;
		return $this;
	}

	/** All groups on one page, one submit (whole-form validation), each a `<fieldset>`. */
	public function asSinglePage(): self
	{
		$this->flow = 'single-page';
		$this->defaultContainer = Wizard\Container::Fieldset;
		return $this;
	}

	/** Single page; each group is a collapsible `<details>`. */
	public function asAccordion(): self
	{
		$this->flow = 'single-page';
		$this->defaultContainer = Wizard\Container::Details;
		return $this;
	}

	/** Single page; each group is a `<dialog>`. */
	public function asDialogs(): self
	{
		$this->flow = 'single-page';
		$this->defaultContainer = Wizard\Container::Dialog;
		return $this;
	}

	/**
	 * Opt out of the small default dialog stylesheet ({@see DialogStyles}); the host
	 * then supplies its own styles for `.mf-dialog`.
	 */
	public function withoutDefaultStyles(): self
	{
		$this->defaultStyles = false;
		return $this;
	}

	/**
	 * Append a group of related fields (presentation-only, rendered in order). Its
	 * {@see Wizard\Container} defaults to the form's default; the form's flow decides
	 * stepped vs single-page. Returns a {@see Wizard\GroupOptions} builder.
	 *
	 * @param list<string> $fieldNames
	 */
	public function group(string $title, array $fieldNames): Wizard\GroupOptions
	{
		$group = new Wizard\Group($title, $fieldNames);
		$this->groups[] = $group;
		return new Wizard\GroupOptions($this, $group);
	}

	/**
	 * Append the final review/confirmation group. Lists the answers inline by default;
	 * call {@see Wizard\ConfirmationOptions::asDialog()} to show it as a dialog over the
	 * full form.
	 */
	public function requireConfirmation(string $title = 'Review'): Wizard\ConfirmationOptions
	{
		$group = new Wizard\Group($title, [], confirmation: true);
		$this->groups[] = $group;
		return new Wizard\ConfirmationOptions($this, $group);
	}

	/**
	 * Add a condition UI behaviour to the set applied during rendering.
	 */
	public function withBehaviour(ConditionUiBehaviour $behaviour): self
	{
		$this->behaviours[] = $behaviour;
		return $this;
	}

	/**
	 * Remove every behaviour of the given class from the set (e.g. to turn the
	 * default hide behaviour off).
	 *
	 * @param class-string<ConditionUiBehaviour> $fqcn
	 */
	public function withoutBehaviour(string $fqcn): self
	{
		$this->behaviours = array_values(array_filter(
			$this->behaviours,
			static fn(ConditionUiBehaviour $b): bool => !($b instanceof $fqcn),
		));
		return $this;
	}

	/**
	 * Replace the entire behaviour set. Pass no arguments to disable all
	 * condition-driven rendering.
	 */
	public function behaviours(ConditionUiBehaviour ...$behaviours): self
	{
		$this->behaviours = $behaviours;
		return $this;
	}

	public function postTo(string $url): self
	{
		$this->method = 'post';
		$this->action = $url;
		return $this;
	}

	public function putTo(string $url): self
	{
		$this->method = 'put';
		$this->action = $url;
		return $this;
	}

	public function getFrom(string $url): self
	{
		$this->method = 'get';
		$this->action = $url;
		return $this;
	}

	public function configureOptionsFor(string $fieldName): FieldOptions
	{
		return new FieldOptions($this, [$fieldName]);
	}

	/**
	 * @alias configureOptionsFor
	 */
	public function configure(string $fieldName): FieldOptions
	{
		return $this->configureOptionsFor($fieldName);
	}

	public function toArray(): array
	{
		return [
			'method' => $this->method,
			'action' => $this->action,
			'fields' => $this->fields,
		];
	}

	/** @internal */
	public function setNestedValue(array $keys, mixed $value): void
	{
		$ref = &$this->fields;
		$lastKey = array_pop($keys);

		foreach ($keys as $key) {
			if (!isset($ref[$key]) || !is_array($ref[$key])) {
				$ref[$key] = [];
			}
			$ref = &$ref[$key];
		}

		$ref[$lastKey] = $value;
	}
}
