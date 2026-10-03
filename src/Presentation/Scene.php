<?php
declare(strict_types=1);

namespace Meraki\Schema\Html\Presentation;

use Meraki\Schema\Definition;
use Meraki\Schema\Html\FormOptions;
use Meraki\Schema\Message\Translator;

/**
 * Everything one render shares: the schema, its options, and the language it speaks.
 */
final readonly class Scene
{
	public function __construct(
		public Definition $schema,
		public FormOptions $options,
		public Translator $translator,
	) {}
}
