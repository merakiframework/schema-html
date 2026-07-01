<?php
declare(strict_types=1);

namespace Meraki\Schema\Html\Wizard;

use Meraki\Schema\Html\FormOptions;

/**
 * Fluent configuration for the review/confirmation group, returned by
 * {@see FormOptions::requireConfirmation()}. By default it lists the answers inline;
 * {@see self::asDialog()} instead shows the whole form with the summary in a dialog.
 */
final class ConfirmationOptions
{
	public function __construct(
		private readonly FormOptions $root,
		private readonly Group $group,
	) {}

	/**
	 * Show the confirmation as a dialog over the full (editable) form: `{confirm}`
	 * submits, `{edit}` closes the dialog to edit the form, and `{update}` reloads the
	 * form (re-applying rules so conditional fields and the summary refresh after edits).
	 * `open: true` (default) shows it on arrival.
	 */
	public function asDialog(bool $open = true, string $confirm = 'Confirm', string $edit = 'Make changes', string $update = 'Update'): self
	{
		$this->group->container = Container::Dialog;
		$this->group->open = $open;
		$this->group->confirm = $confirm;
		$this->group->close = $edit;
		$this->group->update = $update;
		return $this;
	}

	/** Custom already-safe HTML shown in the dialog (e.g. terms & conditions). */
	public function content(string $html): self
	{
		$this->group->content = $html;
		return $this;
	}

	public function heading(string $heading): self
	{
		$this->group->heading = $heading;
		return $this;
	}

	/** Return to the underlying form options. */
	public function done(): FormOptions
	{
		return $this->root;
	}
}
