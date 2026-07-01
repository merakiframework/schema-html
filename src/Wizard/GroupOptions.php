<?php
declare(strict_types=1);

namespace Meraki\Schema\Html\Wizard;

use Meraki\Schema\Html\FormOptions;
use Meraki\Schema\Html\FieldOptions;

/**
 * Fluent configuration for a {@see Group}, returned by {@see FormOptions::group()}.
 * Sets the group's {@see Container} and proxies the form-level builders so chaining
 * (`->group()->asDialog()->group()…`) reads naturally.
 */
final class GroupOptions
{
	public function __construct(
		private readonly FormOptions $root,
		private readonly Group $group,
	) {}

	public function asFieldset(): self
	{
		$this->group->container = Container::Fieldset;
		return $this;
	}

	public function asDetails(bool $open = false): self
	{
		$this->group->container = Container::Details;
		$this->group->open = $open;
		return $this;
	}

	public function asDialog(string $trigger = 'Open', string $confirm = 'Continue', bool $open = false): self
	{
		$this->group->container = Container::Dialog;
		$this->group->trigger = $trigger;
		$this->group->confirm = $confirm;
		$this->group->open = $open;
		return $this;
	}

	public function group(string $title, array $fieldNames): self
	{
		return $this->root->group($title, $fieldNames);
	}

	public function requireConfirmation(string $title = 'Review'): ConfirmationOptions
	{
		return $this->root->requireConfirmation($title);
	}

	public function configureOptionsFor(string $fieldName): FieldOptions
	{
		return $this->root->configureOptionsFor($fieldName);
	}

	/** Return to the underlying form options. */
	public function done(): FormOptions
	{
		return $this->root;
	}
}
