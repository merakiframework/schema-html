<?php
declare(strict_types=1);

namespace Meraki\Schema\Html;

/**
 * Renders a {@see Dialog} as native, no-JS markup: a `show-modal` invoker button,
 * the `<dialog>` (with the given body), and `close`/submit action buttons. Shared by
 * field-level rendering ({@see FormRenderer}) and the wizard ({@see Wizard\Renderer}).
 *
 * Open/close uses the HTML command-invoker API (`command`/`commandfor`); browsers
 * without it need the host-supplied `invokers-polyfill` (this library ships no JS).
 */
final class DialogView
{
	/**
	 * @param iterable<Element|string> $body the dialog's inner content (fields, summary, custom)
	 */
	public function render(Dialog $dialog, iterable $body): Element
	{
		$host = new Element('div', ['class' => 'mf-dialog-host']);

		// Invoker that (re)opens the dialog. type=button => never submits.
		$host->append(new Element('button', [
			'type'       => 'button',
			'command'    => 'show-modal',
			'commandfor' => $dialog->id,
			'class'      => 'mf-dialog-trigger',
		])->setText($dialog->trigger));

		$attributes = ['id' => $dialog->id, 'class' => 'mf-dialog'];

		if ($dialog->open) {
			$attributes['open'] = true;
		}

		$el = new Element('dialog', $attributes);

		if ($dialog->heading !== '') {
			$el->append(new Element('h2', ['class' => 'mf-dialog-heading'])->setText($dialog->heading));
		}

		if ($dialog->content !== '') {
			$el->append($dialog->content); // caller-provided, already-safe HTML
		}

		foreach ($body as $child) {
			$el->append($child);
		}

		$actions = new Element('div', ['class' => 'mf-dialog-actions']);

		$actions->append(new Element('button', [
			'type'       => 'button',
			'command'    => 'close',
			'commandfor' => $dialog->id,
			'class'      => 'mf-dialog-close',
		])->setText($dialog->close));

		$confirm = new Element('button', ['type' => 'submit', 'class' => 'mf-dialog-confirm']);

		if ($dialog->action !== null) {
			$confirm->setAttribute('name', '__wizard[action]');
			$confirm->setAttribute('value', $dialog->action);
		}

		$actions->append($confirm->setText($dialog->confirm));

		return $host->append($el->append($actions));
	}
}
