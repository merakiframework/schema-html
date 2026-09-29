<?php
declare(strict_types=1);

namespace Meraki\Schema\Html\Theme;

use Meraki\Schema\Field;
use Meraki\Schema\Html\Exception\NoRendererForField;
use Meraki\Schema\Html\Presentation\FieldView;

/**
 * Which {@see FieldRenderer} draws which field type.
 *
 * Looked up by the field's class, then its parents and interfaces — so a renderer registered
 * for `Meraki\Schema\Field` itself is a fallback for any field type nothing else claims.
 */
final class FieldRenderers
{
	/** The renderer for any field configured with FieldOptions::allowAddingOptions(). */
	public const COMBOBOX = 'combobox';

	/**
	 * @param array<string, FieldRenderer> $renderers field class (or {@see self::COMBOBOX}) => renderer
	 */
	public function __construct(private array $renderers = [])
	{
	}

	/**
	 * @param class-string<Field>|self::COMBOBOX $key
	 */
	public function with(string $key, FieldRenderer $renderer): self
	{
		$copy = clone $this;
		$copy->renderers[$key] = $renderer;

		return $copy;
	}

	/**
	 * @throws NoRendererForField
	 */
	public function for(FieldView $view): FieldRenderer
	{
		if ($view->combobox && isset($this->renderers[self::COMBOBOX])) {
			return $this->renderers[self::COMBOBOX];
		}

		$class = $view->field::class;

		foreach ([$class, ...array_values(class_parents($class) ?: []), ...array_values(class_implements($class) ?: [])] as $candidate) {
			if (isset($this->renderers[$candidate])) {
				return $this->renderers[$candidate];
			}
		}

		throw NoRendererForField::for($view->field);
	}
}
