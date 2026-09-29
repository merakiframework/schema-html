<?php
declare(strict_types=1);

namespace Meraki\Schema\Html\Theme;

use Meraki\Schema\Field;

/**
 * The stock look: {@see DefaultWidgets} put together by the default renderers.
 *
 * Swap the widgets to restyle everything at once, or one renderer to change how a single field
 * type is structured. Both return a copy.
 */
final class DefaultTheme implements Theme
{
	private readonly FieldRenderers $renderers;

	public function __construct(
		private readonly Widgets $widgets = new DefaultWidgets(),
		?FieldRenderers $renderers = null,
	) {
		$this->renderers = $renderers ?? self::defaultRenderers();
	}

	public function widgets(): Widgets
	{
		return $this->widgets;
	}

	public function renderers(): FieldRenderers
	{
		return $this->renderers;
	}

	public function withWidgets(Widgets $widgets): self
	{
		return new self($widgets, $this->renderers);
	}

	/**
	 * @param class-string<Field>|FieldRenderers::COMBOBOX $key
	 */
	public function withRenderer(string $key, FieldRenderer $renderer): self
	{
		return new self($this->widgets, $this->renderers->with($key, $renderer));
	}

	public static function defaultRenderers(): FieldRenderers
	{
		$input = new Renderer\InputFieldRenderer();
		$structured = new Renderer\StructuredFieldRenderer();

		return new FieldRenderers([
			Field\Address::class => $structured,
			Field\Boolean::class => new Renderer\BooleanFieldRenderer(),
			Field\Collection::class => new Renderer\CollectionFieldRenderer(),
			Field\CreditCard::class => $structured,
			Field\Date::class => $input,
			Field\DateTime::class => $input,
			Field\Duration::class => $input,
			Field\EmailAddress::class => $input,
			Field\Enum::class => new Renderer\EnumFieldRenderer(),
			Field\File::class => $input,
			Field\Money::class => $structured,
			Field\Name::class => $input,
			Field\Number::class => $input,
			Field\Password::class => $input,
			Field\PhoneNumber::class => $structured,
			Field\Text::class => new Renderer\TextFieldRenderer(),
			Field\Time::class => $input,
			Field\Uri::class => $input,
			Field\Uuid::class => $input,
			FieldRenderers::COMBOBOX => new Renderer\ComboboxFieldRenderer(),
		]);
	}
}
