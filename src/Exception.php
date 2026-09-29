<?php
declare(strict_types=1);

namespace Meraki\Schema\Html;

/**
 * Implemented by every exception this package raises for a mistake in how it was set up, so
 * one `catch` covers them.
 */
interface Exception extends \Throwable
{
}
