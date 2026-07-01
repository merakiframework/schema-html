<?php
declare(strict_types=1);

namespace Meraki\Schema\Html\Wizard;

/**
 * A named set of related fields. Groups are presentation-only (defined on
 * {@see \Meraki\Schema\Html\FormOptions}). How a group is wrapped is its
 * {@see Container} (inherits the form's default when unset); the form's *flow*
 * decides whether groups render one-per-request (stepped) or all on one page.
 *
 * The container-config properties are mutable so {@see GroupOptions}/
 * {@see ConfirmationOptions} can configure them fluently.
 */
final class Group
{
	/** @var list<string> */
	public readonly array $fieldNames;

	/** null = inherit the form's default container. */
	public ?Container $container = null;
	public bool $open = false;
	public string $trigger = 'Open';
	public string $confirm = 'Continue';
	public string $close = 'Cancel';
	public string $update = 'Update';
	public string $heading = '';
	public string $content = '';

	/**
	 * @param list<string> $fieldNames
	 */
	public function __construct(
		public readonly string $title,
		array $fieldNames,
		public readonly bool $confirmation = false,
	) {
		$this->fieldNames = array_values($fieldNames);
	}

	public function containerOr(Container $default): Container
	{
		return $this->container ?? $default;
	}
}
