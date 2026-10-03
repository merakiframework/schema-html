<?php
declare(strict_types=1);

namespace Meraki\Schema\Html;

use Meraki\Schema\Definition;
use Meraki\Schema\Field;
use Meraki\Schema\FieldResult;
use Meraki\Schema\Html\Exception\MessagesNotConfigured;
use Meraki\Schema\Html\Exception\UnsupportedLocale;
use Meraki\Schema\Html\Presentation\FieldView;
use Meraki\Schema\Html\Presentation\FieldViewFactory;
use Meraki\Schema\Html\Presentation\Reveal;
use Meraki\Schema\Html\Presentation\RuleEffects;
use Meraki\Schema\Html\Presentation\Scene;
use Meraki\Schema\Html\Theme\DefaultTheme;
use Meraki\Schema\Html\Theme\Theme;
use Meraki\Schema\SchemaValidationResult;
use OutOfBoundsException;

/**
 * Renders a schema as an HTML `<form>`.
 *
 * It draws from a *result*, never from the schema's fields: `meraki/schema` keeps nothing about
 * a request on its definition, so what was submitted, what a rule changed and what failed all
 * live on the result of `$schema->resolve()` or `$schema->validate()`. With no result it
 * resolves the schema itself, which gives authored defaults and the rules as they stand with
 * nothing submitted — a first render.
 *
 *     echo $renderer->render($schema, $options);                          // first render
 *     echo $renderer->render($schema, $options, $schema->validate($data)); // with errors
 *     echo $renderer->render($schema, $options, $schema->resolve(prefilledWith: $user));
 *
 * What each field looks like is the {@see Theme}'s business; what it *says* — its label, value,
 * whether a rule hid it, its messages — is decided before the theme is asked.
 */
class FormRenderer
{
	public function __construct(
		private readonly Theme $theme = new DefaultTheme(),
		private readonly FieldViewFactory $views = new FieldViewFactory(),
	) {
	}

	public function theme(): Theme
	{
		return $this->theme;
	}

	/**
	 * @throws MessagesNotConfigured when the options say nothing about messages
	 * @throws UnsupportedLocale when the provider cannot serve the language asked for
	 */
	public function render(Definition $schema, ?FormOptions $options = null, ?SchemaValidationResult $result = null): string
	{
		$options ??= new FormOptions();
		$scene = $this->scene($schema, $options);
		$result ??= $schema->resolve();
		$form = $this->startForm($schema, $options);

		if ($options->groups !== [] && $options->flow === 'single-page') {
			foreach ($options->groups as $index => $group) {
				$form->append($this->group($group, $index, $options, $this->renderFields($scene, $result, $group->fieldNames, strict: false)));
			}
		} else {
			foreach ($this->renderFields($scene, $result, $this->fieldNames($schema)) as $element) {
				$form->append($element);
			}
		}

		$form->append($this->theme->widgets()->button('Submit'));

		return $this->withStyles((string) $form, $schema, $options);
	}

	/**
	 * What one render shares, including the translator for the form's language.
	 *
	 * @throws MessagesNotConfigured
	 * @throws UnsupportedLocale
	 */
	public function scene(Definition $schema, FormOptions $options): Scene
	{
		$messages = $options->messages ?? throw MessagesNotConfigured::for((string) $schema->name);

		return new Scene($schema, $options, $messages->translator());
	}

	/**
	 * The named fields, drawn from their results. Shared with stepped rendering, which draws
	 * one group of fields at a time.
	 *
	 * @param list<string> $names
	 * @param bool $strict throw for a name the schema does not have, rather than skipping it
	 * @return list<Element>
	 */
	public function renderFields(Scene $scene, SchemaValidationResult $result, array $names, bool $strict = true): array
	{
		$elements = [];

		foreach ($names as $name) {
			$fieldResult = $result->forField($name);

			if ($fieldResult === null) {
				if ($strict) {
					throw new OutOfBoundsException("The result has nothing for field '{$name}'.");
				}

				continue;
			}

			$elements[] = $this->renderField($this->viewOf($fieldResult, $scene));
		}

		return $elements;
	}

	public function viewOf(FieldResult $result, Scene $scene): FieldView
	{
		$name = (string) $result->field->name;
		$fieldOptions = $scene->options->fields[$name] ?? [];

		return $this->views->build($result, is_array($fieldOptions) ? $fieldOptions : [], $scene);
	}

	/**
	 * One field, by its theme's renderer, inside its dialog or behind its toggle if it has one.
	 */
	public function renderField(FieldView $view): Element
	{
		$w = $this->theme->widgets();
		$element = $this->theme->renderers()->for($view)->render($view, $this->theme);

		if ($view->dialog !== null) {
			return $w->dialog($view->dialog, [$element]);
		}

		if ($view->reveal !== null) {
			return $view->reveal->mode === Reveal::POPUP
				? $w->popover($view->control->id . '-reveal', $view->reveal->trigger, $element)
				: $w->details($view->reveal->trigger, [$element], $view->reveal->open, 'mf-reveal');
		}

		return $element;
	}

	/**
	 * The `<form>` element with no fields in it yet: id, method and action, a multipart
	 * encoding when there is a file to upload, the `_method` field for methods HTML forms cannot
	 * send, and the CSRF token when protection is on. Shared with stepped rendering.
	 */
	public function startForm(Definition $schema, FormOptions $options): Element
	{
		$w = $this->theme->widgets();

		$form = $w->form([
			'id' => (string) $schema->name,
			'novalidate' => true,
			'action' => $options->action,
			// HTML forms only GET and POST, so other methods POST with a hidden _method.
			'method' => $options->method !== 'get' ? 'post' : 'get',
			'enctype' => self::hasFileField($schema->fields) ? 'multipart/form-data' : null,
		]);

		if ($options->method !== 'post') {
			$form->append($w->hidden('_method', $options->method));
		}

		// GET forms are excluded: the token would land in the query string, leaking through
		// Referer headers, logs and history, and a GET should not be changing state anyway.
		if ($options->csrf !== null && $options->method !== 'get') {
			$form->append($options->csrf->hiddenInput());
		}

		return $form;
	}

	/**
	 * Prepends the theme's scoped stylesheet for whatever the markup uses, unless the default
	 * styles are turned off. Shared with stepped rendering.
	 */
	public function withStyles(string $html, Definition $schema, FormOptions $options): string
	{
		if (!$options->defaultStyles) {
			return $html;
		}

		return $this->theme->widgets()->styles((string) $schema->name, $html) . $html;
	}

	/**
	 * The label a field is drawn with (type default, or what the caller configured), for
	 * stepped review summaries, which show labels rather than field names.
	 */
	public function labelFor(Field $field, FormOptions $options): string
	{
		$fieldOptions = $options->fields[(string) $field->name] ?? [];

		return $this->views->labelFor($field, is_array($fieldOptions) ? $fieldOptions : []);
	}

	/**
	 * Fields the default hide behaviour would hide on this request — those a rule made
	 * optional or is ignoring. Stepped rendering uses it to skip a step with nothing left to
	 * fill in.
	 *
	 * @return array<string, true>
	 */
	public function fieldsHiddenByRules(SchemaValidationResult $result, FormOptions $options): array
	{
		$hides = false;

		foreach ($options->behaviours as $behaviour) {
			if ($behaviour instanceof Behaviour\HideOptionalFieldsResolvedByRules) {
				$hides = true;
				break;
			}
		}

		if (!$hides) {
			return [];
		}

		$hidden = [];

		foreach ($result as $fieldResult) {
			if (!$fieldResult instanceof FieldResult) {
				continue;
			}

			$effects = RuleEffects::of($fieldResult);

			if ($effects->madeOptional() || $effects->ignored) {
				$hidden[(string) $fieldResult->field->name] = true;
			}
		}

		return $hidden;
	}

	/**
	 * @return list<string>
	 */
	public function fieldNames(Definition $schema): array
	{
		$names = [];

		foreach ($schema->fields as $field) {
			$names[] = (string) $field->name;
		}

		return $names;
	}

	/**
	 * One group of a single-page form, in its {@see Wizard\Container}.
	 *
	 * @param list<Element> $elements
	 */
	private function group(Wizard\Group $group, int $index, FormOptions $options, array $elements): Element
	{
		$w = $this->theme->widgets();

		return match ($group->containerOr($options->defaultContainer)) {
			Wizard\Container::Dialog => $w->dialog(new Dialog(
				id: 'mf-group-' . $index,
				open: $group->open,
				trigger: $group->trigger !== 'Open' ? $group->trigger : $group->title,
				confirm: $group->confirm,
				close: $group->close,
				heading: $group->title,
			), $elements),
			Wizard\Container::Details => $w->details($group->title, $elements, $group->open),
			Wizard\Container::Fieldset => $w->fieldset($group->title, $elements, ['class' => 'mf-group']),
		};
	}

	/**
	 * @param iterable<Field> $fields
	 */
	private static function hasFileField(iterable $fields): bool
	{
		foreach ($fields as $field) {
			if ($field instanceof Field\File) {
				return true;
			}

			if ($field instanceof Field\Collection && self::hasFileField($field->template)) {
				return true;
			}
		}

		return false;
	}
}
