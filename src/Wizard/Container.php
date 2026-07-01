<?php
declare(strict_types=1);

namespace Meraki\Schema\Html\Wizard;

/**
 * How a {@see Group}'s content is visually wrapped. Independent of the form's flow
 * (stepped vs single-page): a group can be a plain fieldset, a collapsible
 * `<details>`, or a `<dialog>`.
 */
enum Container: string
{
	case Fieldset = 'fieldset';
	case Details = 'details';
	case Dialog = 'dialog';
}
