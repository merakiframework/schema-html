<?php
declare(strict_types=1);

namespace Meraki\Schema\Html\Theme;

use Meraki\Schema\Html\Dialog;
use Meraki\Schema\Html\Element;
use Meraki\Schema\Html\Presentation\Control;

/**
 * The default markup: plain, semantic, no JavaScript, with `mf-*` classes to hang styles on
 * and a small scoped stylesheet for the widgets that need one.
 *
 * Not final — extend it and override the widgets your design system draws differently.
 */
class DefaultWidgets implements Widgets
{
	/** Markup whose presence means the page needs {@see self::styles()}. */
	private const STYLED = ['mf-dialog', 'mf-reveal', 'mf-popup', 'mf-select', '<details'];

	public function form(array $attributes): Element
	{
		return new Element('form', $attributes);
	}

	public function field(string $type, string $name, Control $control, array $errors, Element|string ...$children): Element
	{
		$wrapper = new Element('div', ['class' => 'field', 'data-type' => $type, 'data-name' => $name]);

		// Hidden means "don't show this at all", screen readers included, so the whole wrapper
		// goes — label and errors with it.
		if ($control->hidden) {
			$wrapper->setAttribute('hidden', true);
		}

		// Shown but not interactive: keep keyboard focus off the wrapper as well.
		if (!$control->isFocusable()) {
			$wrapper->setAttribute('tabindex', '-1');
		}

		foreach ($children as $child) {
			$wrapper->append($child);
		}

		return $wrapper->append($this->errors($errors));
	}

	public function label(Control $control, string $text): Element
	{
		return (new Element('label', ['for' => $control->id]))->setText($text);
	}

	public function errors(array $errors): Element
	{
		$element = new Element('div', ['class' => 'errors']);

		foreach ($errors as $error) {
			$element->append((new Element('p'))->setText($error));
		}

		return $element;
	}

	public function input(Control $control): Element
	{
		$input = new Element('input', ['type' => $control->type, 'id' => $control->id, 'name' => $control->name]);
		$input->setAttributes($control->attributes);
		$input->setAttribute('value', $control->type === 'file' ? null : $control->value);
		$input->setAttribute('placeholder', $control->placeholder);

		return $this->interactive($input, $control);
	}

	public function textarea(Control $control): Element
	{
		$textarea = new Element('textarea', ['id' => $control->id, 'name' => $control->name]);
		$textarea->setAttributes($control->attributes);

		if ($control->value !== null) {
			$textarea->setText($control->value);
		}

		$textarea->setAttribute('placeholder', $control->placeholder);

		return $this->interactive($textarea, $control);
	}

	public function select(Control $control, bool $customizable = false): Element
	{
		$select = new Element('select', ['id' => $control->id, 'name' => $control->name]);
		$select->setAttributes($control->attributes);

		if ($customizable) {
			$select->setAttribute('class', 'mf-select');
		}

		$this->interactive($select, $control, withInputHints: false);

		foreach ($control->choices as $value => $label) {
			$select->append((new Element('option', [
				'value' => (string) $value,
				'selected' => $control->value === (string) $value,
			]))->setText($label));
		}

		// With nothing chosen, offer a placeholder that cannot be chosen back. It submits an
		// empty value, so a required dropdown still fails validation.
		if (!array_key_exists((string) $control->value, $control->choices)) {
			$select->prepend((new Element('option', [
				'value' => '',
				'disabled' => true,
				'selected' => true,
				'hidden' => true,
			]))->setText($control->placeholder ?? 'Please select an option'));
		}

		// A customizable select's trigger mirrors the chosen option, and must come first.
		if ($customizable) {
			$select->prepend((new Element('button', ['type' => 'button']))->append(new Element('selectedcontent')));
		}

		return $select;
	}

	public function choices(Control $control, string $legend, bool $buttons = false): Element
	{
		$fieldset = (new Element('fieldset', [
			'id' => $control->id,
			'class' => $buttons ? 'mf-buttongroup' : 'mf-radiogroup',
		]))->append((new Element('legend'))->setText($legend));

		foreach ($control->choices as $value => $label) {
			$radio = new Element('input', [
				'type' => 'radio',
				'name' => $control->name,
				'value' => (string) $value,
				'required' => $control->required,
				'readonly' => $control->readonly,
				'disabled' => $control->disabled,
				'autofocus' => $control->autofocus,
				'checked' => $control->value === (string) $value,
			]);

			if (!$control->isFocusable()) {
				$radio->setAttribute('tabindex', '-1');
			}

			$fieldset->append((new Element('label', ['class' => $buttons ? 'mf-button' : 'mf-radio']))->append($radio, htmlspecialchars($label, ENT_QUOTES)));
		}

		return $fieldset;
	}

	public function checkbox(Control $control): Element
	{
		$checkbox = new Element('input', [
			'type' => 'checkbox',
			'id' => $control->id,
			'name' => $control->name,
			'checked' => $control->checked,
		]);

		return $this->interactive($checkbox, $control);
	}

	public function hidden(string $name, string $value): Element
	{
		return new Element('input', ['type' => 'hidden', 'name' => $name, 'value' => $value]);
	}

	public function datalist(string $id, array $options): Element
	{
		$datalist = new Element('datalist', ['id' => $id]);

		foreach ($options as $value => $label) {
			$datalist->append((new Element('option', ['value' => (string) $value]))->setText($label));
		}

		return $datalist;
	}

	public function fieldset(?string $legend, iterable $children, array $attributes = []): Element
	{
		$fieldset = new Element('fieldset', $attributes);

		if ($legend !== null) {
			$fieldset->append((new Element('legend'))->setText($legend));
		}

		foreach ($children as $child) {
			$fieldset->append($child);
		}

		return $fieldset;
	}

	public function details(string $summary, iterable $children, bool $open = false, string $class = 'mf-group'): Element
	{
		$details = (new Element('details', ['class' => $class, 'open' => $open]))
			->append((new Element('summary'))->setText($summary));

		foreach ($children as $child) {
			$details->append($child);
		}

		return $details;
	}

	/**
	 * Opened and closed with the HTML command-invoker API (`command`/`commandfor`); browsers
	 * without it need the host-supplied `invokers-polyfill` (this library ships no JS).
	 */
	public function dialog(Dialog $dialog, iterable $body): Element
	{
		$host = new Element('div', ['class' => 'mf-dialog-host']);

		// The invoker that (re)opens the dialog. type=button, so it never submits.
		$host->append((new Element('button', [
			'type'       => 'button',
			'command'    => 'show-modal',
			'commandfor' => $dialog->id,
			'class'      => 'mf-dialog-trigger',
		]))->setText($dialog->trigger));

		$element = new Element('dialog', ['id' => $dialog->id, 'class' => 'mf-dialog', 'open' => $dialog->open]);

		if ($dialog->heading !== '') {
			$element->append((new Element('h2', ['class' => 'mf-dialog-heading']))->setText($dialog->heading));
		}

		if ($dialog->content !== '') {
			$element->append($dialog->content); // caller-provided, already-safe HTML
		}

		foreach ($body as $child) {
			$element->append($child);
		}

		$actions = new Element('div', ['class' => 'mf-dialog-actions']);

		$actions->append((new Element('button', [
			'type'       => 'button',
			'command'    => 'close',
			'commandfor' => $dialog->id,
			'class'      => 'mf-dialog-close',
		]))->setText($dialog->close));

		$confirm = new Element('button', ['type' => 'submit', 'class' => 'mf-dialog-confirm']);

		if ($dialog->action !== null) {
			$confirm->setAttribute('name', '__wizard[action]');
			$confirm->setAttribute('value', $dialog->action);
		}

		$actions->append($confirm->setText($dialog->confirm));

		return $host->append($element->append($actions));
	}

	public function popover(string $id, string $trigger, Element|string $content): Element
	{
		return (new Element('div', ['class' => 'mf-reveal']))
			->append((new Element('button', [
				'type'       => 'button',
				'command'    => 'toggle-popover',
				'commandfor' => $id,
				'class'      => 'mf-reveal-trigger',
			]))->setText($trigger))
			->append((new Element('div', ['id' => $id, 'popover' => 'auto', 'class' => 'mf-popup']))->append($content));
	}

	public function button(string $label, array $attributes = []): Element
	{
		return (new Element('button', ['type' => 'submit', ...$attributes]))->setText($label);
	}

	public function collectionRow(string $key, iterable $children): Element
	{
		$row = new Element('div', ['class' => 'collection-item', 'data-row' => $key]);

		foreach ($children as $child) {
			$row->append($child);
		}

		return $row;
	}

	public function collectionAdd(iterable $children): Element
	{
		$add = new Element('div', ['class' => 'collection-add']);

		foreach ($children as $child) {
			$add->append($child);
		}

		return $add;
	}

	public function collectionValue(string $label, string $value): Element
	{
		return (new Element('span', ['class' => 'collection-value']))->setText($label . ': ' . $value);
	}

	public function navigation(iterable $buttons): Element
	{
		$nav = new Element('div', ['class' => 'wizard-nav']);

		foreach ($buttons as $button) {
			$nav->append($button);
		}

		return $nav;
	}

	public function summary(array $answers): Element
	{
		$list = new Element('dl', ['class' => 'wizard-summary']);

		foreach ($answers as [$label, $answer]) {
			$list->append((new Element('dt'))->setText($label));
			$list->append((new Element('dd'))->setText($answer));
		}

		return $list;
	}

	/**
	 * Scoped under the form's id, so any author rule of equal or greater specificity wins.
	 * Native `<dialog>` and `<details>` do the heavy lifting; this only adds light chrome.
	 */
	public function styles(string $formId, string $html): string
	{
		$used = false;

		foreach (self::STYLED as $marker) {
			if (str_contains($html, $marker)) {
				$used = true;
				break;
			}
		}

		if (!$used) {
			return '';
		}

		$id = '#' . $formId;

		$css = implode('', [
			"{$id} .mf-dialog{border:1px solid #ccc;border-radius:.5rem;padding:1rem;max-width:32rem;}",
			"{$id} .mf-dialog::backdrop{background:rgba(0,0,0,.4);}",
			"{$id} .mf-dialog-actions{display:flex;gap:.5rem;margin-top:1rem;}",
			"{$id} .mf-reveal summary{cursor:pointer;}",
			"{$id} .mf-popup{border:1px solid #ccc;border-radius:.5rem;padding:1rem;}",
			"{$id} .mf-popup::backdrop{background:rgba(0,0,0,.4);}",
			"{$id} .mf-select,{$id} .mf-select::picker(select){appearance:base-select;}",
		]);

		return "<style>{$css}</style>";
	}

	/**
	 * The attributes every interactive control shares, in the order 0.5 wrote them.
	 */
	private function interactive(Element $element, Control $control, bool $withInputHints = true): Element
	{
		$element->setAttributes([
			'required' => $control->required,
			'readonly' => $control->readonly,
			'disabled' => $control->disabled,
			'hidden' => $control->hidden,
			'autofocus' => $control->autofocus,
			// Each emits nothing when null: no token applies, and no client-side hint was asked for.
			'autocomplete' => $control->autocomplete,
		]);

		if ($withInputHints) {
			$element->setAttributes([
				'pattern' => $control->pattern,
				'inputmode' => $control->inputmode,
			]);
		}

		if (!$control->isFocusable()) {
			$element->setAttribute('tabindex', '-1');
		}

		return $element;
	}
}
