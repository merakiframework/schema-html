<?php
declare(strict_types=1);

namespace Meraki\Schema\Html\Theme;

use Meraki\Schema\Html\Dialog;
use Meraki\Schema\Html\Element;
use Meraki\Schema\Html\Presentation\Control;

/**
 * A theme's markup vocabulary: every tag this package writes is written by one of these.
 *
 * Field renderers only put widgets together, so a theme that restyles the widgets restyles
 * every field that uses them — override {@see self::select()} once and an Enum, an address's
 * state and country, a currency and a phone's country all change with it. Extend
 * {@see DefaultWidgets} and override what differs.
 *
 * Widgets draw; they do not decide. Whether a control is required, hidden, or what it shows is
 * already on the {@see Control} they are handed.
 */
interface Widgets
{
	/**
	 * The `<form>` element, before any fields go in.
	 *
	 * @param array<string, mixed> $attributes `id`, `method`, `action`, `enctype`, …
	 */
	public function form(array $attributes): Element;

	/**
	 * The wrapper around one field or part: its label, its control, and its errors.
	 *
	 * @param string $type what kind of field it is, e.g. `email-address`
	 * @param string $name the field's name, or `field.part` for a part
	 * @param Control $control decides whether the wrapper is hidden, and focusable
	 * @param list<string> $errors
	 */
	public function field(string $type, string $name, Control $control, array $errors, Element|string ...$children): Element;

	public function label(Control $control, string $text): Element;

	/**
	 * @param list<string> $errors
	 */
	public function errors(array $errors): Element;

	/** An `<input>` of the control's type. */
	public function input(Control $control): Element;

	public function textarea(Control $control): Element;

	/**
	 * A dropdown of the control's choices, with its placeholder offered when nothing is chosen.
	 *
	 * @param bool $customizable a styleable `appearance: base-select` select
	 */
	public function select(Control $control, bool $customizable = false): Element;

	/**
	 * A group of radios for the control's choices, captioned by `$legend`.
	 *
	 * @param bool $buttons styled as a segmented control rather than a list of radios
	 */
	public function choices(Control $control, string $legend, bool $buttons = false): Element;

	public function checkbox(Control $control): Element;

	public function hidden(string $name, string $value): Element;

	/**
	 * Suggestions for an input with `list="{$id}"`.
	 *
	 * @param array<string, string> $options value => label
	 */
	public function datalist(string $id, array $options): Element;

	/**
	 * @param iterable<Element|string> $children
	 * @param array<string, mixed> $attributes
	 */
	public function fieldset(string $legend, iterable $children, array $attributes = []): Element;

	/**
	 * A disclosure.
	 *
	 * @param iterable<Element|string> $children
	 */
	public function details(string $summary, iterable $children, bool $open = false, string $class = 'mf-group'): Element;

	/**
	 * A no-JS dialog: an invoker that opens it, the dialog, and its close and confirm buttons.
	 *
	 * @param iterable<Element|string> $body
	 */
	public function dialog(Dialog $dialog, iterable $body): Element;

	/** A no-JS popover opened by a `toggle-popover` invoker. */
	public function popover(string $id, string $trigger, Element|string $content): Element;

	/**
	 * @param array<string, mixed> $attributes `type` defaults to `submit`
	 */
	public function button(string $label, array $attributes = []): Element;

	/**
	 * One row of a collection.
	 *
	 * @param iterable<Element|string> $children
	 */
	public function collectionRow(string $key, iterable $children): Element;

	/**
	 * The collection's spare row and its "add" button.
	 *
	 * @param iterable<Element|string> $children
	 */
	public function collectionAdd(iterable $children): Element;

	/** One value of a row shown read-only (its editing happens in a dialog). */
	public function collectionValue(string $label, string $value): Element;

	/**
	 * A stepped form's back / next / submit buttons.
	 *
	 * @param iterable<Element|string> $buttons
	 */
	public function navigation(iterable $buttons): Element;

	/**
	 * The answers so far, on a stepped form's review step.
	 *
	 * @param list<array{0: string, 1: string}> $answers [label, answer] pairs, in order
	 */
	public function summary(array $answers): Element;

	/**
	 * A stylesheet for the widgets `$html` actually uses, scoped to the form, or `''` for none.
	 */
	public function styles(string $formId, string $html): string;
}
