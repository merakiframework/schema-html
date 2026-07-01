<?php
declare(strict_types=1);

namespace Meraki\Schema\Html;

/**
 * The minimal default stylesheet for dialogs, scoped under the form's id so it is
 * trivially overridable (any author rule of equal-or-greater specificity wins).
 * Native `<dialog>` does the heavy lifting; this only adds light chrome.
 *
 * Emitted once per form by the renderer unless disabled via
 * {@see FormOptions::withoutDefaultStyles()}; also available for manual inclusion.
 */
final class DialogStyles
{
	public static function for(string $formId): string
	{
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
}
