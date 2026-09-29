<?php
declare(strict_types=1);

namespace Meraki\Schema\Html\Presentation;

use Meraki\Schema\Facade;
use Meraki\Schema\Html\FormOptions;
use Meraki\Schema\Message\Translator;

/**
 * Everything one render shares: the schema, its options, and the language it speaks.
 */
final readonly class Scene
{
	public function __construct(
		public Facade $schema,
		public FormOptions $options,
		public Translator $translator,
	) {}
}
