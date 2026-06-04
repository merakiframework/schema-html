<?php
declare(strict_types=1);

namespace Meraki\Schema\Html;

use Stringable;

/**
 * A tiny HTML element builder that maps native PHP attribute values to HTML:
 *
 *   true        -> bare boolean attribute (e.g. `required`)
 *   false, null -> attribute omitted entirely
 *   scalar      -> escaped name="value"
 *
 *   new Element('input', ['type' => 'email', 'id' => 'x', 'required' => true]);
 *   // <input type="email" id="x" required>
 *
 * Attribute values and text are HTML-escaped; markup added via addHtml() is
 * treated as already-safe (it is produced by this class).
 */
final class Element implements Stringable
{
	private const VOID_ELEMENTS = [
		'area', 'base', 'br', 'col', 'embed', 'hr', 'img',
		'input', 'link', 'meta', 'param', 'source', 'track', 'wbr',
	];

	/** @var array<string, mixed> */
	public private(set) array $attributes = [];

	/** @var list<string> */
	public private(set) array $children = [];

	/**
	 * @param array<string, mixed> $attributes
	 */
	public function __construct(
		public readonly string $tag,
		array $attributes = [],
	) {
		$this->setAttributes($attributes);
	}

	/**
	 * Set multiple attributes at once, overwriting any existing values.
	 */
	public function setAttributes(array $attributes): self
	{
		foreach ($attributes as $name => $value) {
			$this->setAttribute($name, $value);
		}

		return $this;
	}

	/**
	 * Set an attribute, overwriting any existing value.
	 */
	public function setAttribute(string $name, mixed $value): self
	{
		$this->attributes[$name] = $value;

		return $this;
	}

	/**
	 * Set attribute without overwriting an existing value.
	 */
	public function addAttribute(string $name, mixed $value): self
	{
		if (!$this->hasAttribute($name)) {
			$this->attributes[$name] = $value;
		}

		return $this;
	}

	/**
	 * Set multiple attributes without overwriting existing values.
	 */
	public function addAttributes(array $attributes): self
	{
		foreach ($attributes as $name => $value) {
			$this->addAttribute($name, $value);
		}

		return $this;
	}

	/**
	 * Get an attribute's value, or null if it doesn't exist.
	 *
	 * Note that attributes with a value of `null` are treated as non-existent
	 * and will be omitted from the rendered output.
	 */
	public function getAttribute(string $name, mixed $default = null): mixed
	{
		return $this->attributes[$name] ?? $default;
	}

	/**
	 * Remove an attribute if it exists.
	 */
	public function removeAttribute(string $name): self
	{
		unset($this->attributes[$name]);

		return $this;
	}

	/**
	 * Check if an attribute exists.
	 *
	 * Note that attributes with a value of `null` or false are treated as non-existent.
	 */
	public function hasAttribute(string $name): bool
	{
		return isset($this->attributes[$name]) && $this->attributes[$name] !== null && $this->attributes[$name] !== false;
	}

	/**
	 * Prepends an element or a literal HTML string (considered to be already-safe).
	 */
	public function prepend(self|string $child, self|string ...$children): self
	{
		$this->children = array_merge(
			array_map(fn($c) => (string) $c, [$child, ...$children]),
			$this->children,
		);

		return $this;
	}

	/**
	 * Appends an element or a literal HTML string (considered to be already-safe).
	 */
	public function append(self|string $child, self|string ...$children): self
	{
		$this->children[] = (string) $child;

		foreach ($children as $child) {
			$this->children[] = (string) $child;
		}

		return $this;
	}

	/**
	 * Sets the element's text content (escaped), replacing any existing content.
	 */
	public function setText(string $text): self
	{
		$this->children = [htmlspecialchars($text, ENT_QUOTES)];

		return $this;
	}

	public function __toString(): string
	{
		$attributes = $this->renderAttributes();
		$open = '<' . $this->tag . ($attributes !== '' ? ' ' . $attributes : '') . '>';

		if (in_array($this->tag, self::VOID_ELEMENTS, true)) {
			return $open;
		}

		return $open . implode('', $this->children) . '</' . $this->tag . '>';
	}

	private function renderAttributes(): string
	{
		$parts = [];

		foreach ($this->attributes as $name => $value) {
			if ($value === false || $value === null) {
				continue;
			}

			if ($value === true) {
				$parts[] = $name;
				continue;
			}

			$parts[] = $name . '="' . htmlspecialchars((string) $value, ENT_QUOTES) . '"';
		}

		return implode(' ', $parts);
	}
}
