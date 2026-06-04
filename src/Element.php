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
	private array $attributes = [];

	/** @var list<string> */
	private array $children = [];

	/**
	 * @param array<string, mixed> $attributes
	 */
	public function __construct(private readonly string $tag, array $attributes = [])
	{
		foreach ($attributes as $name => $value) {
			$this->setAttribute($name, $value);
		}
	}

	/**
	 * @param array<string, mixed> $attributes
	 */
	public static function el(string $tag, array $attributes = []): self
	{
		return new self($tag, $attributes);
	}

	public function setAttribute(string $name, mixed $value): self
	{
		$this->attributes[$name] = $value;

		return $this;
	}

	/**
	 * Appends already-safe markup (an Element or a raw HTML string).
	 */
	public function addHtml(self|string $html): self
	{
		$this->children[] = (string) $html;

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
