<?php
declare(strict_types=1);

namespace Meraki\Schema\Html\Theme;

use Meraki\Schema\Definition;
use Meraki\Schema\Field;
use Meraki\Schema\Html\Element;
use Meraki\Schema\Html\Exception\NoRendererForField;
use Meraki\Schema\Html\FormRenderer;
use Meraki\Schema\Html\Presentation\Control;
use Meraki\Schema\Html\Presentation\FieldView;
use Meraki\Schema\Html\Support\Forms;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\CoversClass;

#[Group('html')]
#[Group('theme')]
#[CoversClass(DefaultTheme::class)]
#[CoversClass(DefaultWidgets::class)]
#[CoversClass(FieldRenderers::class)]
final class ThemeTest extends TestCase
{
	private function schema(): Definition
	{
		$schema = new Definition('checkout');
		$schema->add(
			$schema->createEnumField('plan', ['free', 'pro']),
			$schema->createAddressField('billing', ['AU', 'NZ']),
		);

		return $schema;
	}

	/** One widget, restyled once, changes every field that draws with it. */
	#[Test]
	public function a_widget_is_shared_by_every_field_that_uses_it(): void
	{
		$widgets = new class extends DefaultWidgets {
			public function select(Control $control, bool $customizable = false): Element
			{
				return parent::select($control, $customizable)->setAttribute('class', 'form-select');
			}
		};

		$options = Forms::options();
		$options->configureOptionsFor('plan')->renderAsDropdown();

		$html = (new FormRenderer(new DefaultTheme($widgets)))->render($this->schema(), $options);

		$this->assertMatchesRegularExpression('/<select[^>]*name="plan"[^>]*class="form-select"/', $html);
		$this->assertMatchesRegularExpression('/<select[^>]*name="billing\[country\]"[^>]*class="form-select"/', $html);
	}

	#[Test]
	public function one_field_types_renderer_can_be_replaced(): void
	{
		$renderer = new class implements FieldRenderer {
			public function render(FieldView $view, Theme $theme): Element
			{
				return (new Element('p', ['class' => 'custom']))->setText($view->label);
			}
		};

		$theme = DefaultTheme::create()->withRenderer(Field\Enum::class, $renderer);

		$html = (new FormRenderer($theme))->render($this->schema(), Forms::options());

		$this->assertStringContainsString('<p class="custom">Plan</p>', $html);
		$this->assertStringContainsString('name="billing[street]"', $html);
	}

	#[Test]
	public function a_field_type_with_no_renderer_is_refused(): void
	{
		$this->expectException(NoRendererForField::class);

		(new FormRenderer(new DefaultTheme(renderers: new FieldRenderers())))->render($this->schema(), Forms::options());
	}

	/** Registered against the Field interface, a renderer catches every type nothing else claims. */
	#[Test]
	public function a_renderer_for_any_field_is_a_fallback(): void
	{
		$fallback = new class implements FieldRenderer {
			public function render(FieldView $view, Theme $theme): Element
			{
				return (new Element('p', ['class' => 'fallback']))->setText($view->name);
			}
		};

		$theme = new DefaultTheme(renderers: (new FieldRenderers())->with(Field::class, $fallback));

		$html = (new FormRenderer($theme))->render($this->schema(), Forms::options());

		$this->assertStringContainsString('<p class="fallback">plan</p>', $html);
		$this->assertStringContainsString('<p class="fallback">billing</p>', $html);
	}
}
