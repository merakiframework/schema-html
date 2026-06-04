# meraki/schema-html

Render a [`meraki/schema`](https://github.com/merakiframework/schema) as an HTML form.

Keeps HTML/form-rendering concerns out of the core schema domain. Reads only
`meraki/schema`'s public API.

## Usage

```php
use Meraki\Schema\Facade;
use Meraki\Schema\Html\FormRenderer;
use Meraki\Schema\Html\FormOptions;

$schema = new Facade('signup');
$schema->addNameField('full_name')->minLengthOf(1)->maxLengthOf(255);
$schema->addEmailAddressField('email');
$schema->addBooleanField('subscribe')->makeOptional();

// Optional: configure the form + per-field UI
$options = (new FormOptions($schema))->postTo('/signup');
$options->pickField('email')->label('Your email address');

$html = (new FormRenderer($options->toArray()))->render($schema);
```

Render after validation to surface inline error messages:

```php
$schema->validate($input);
echo (new FormRenderer($options->toArray()))->render($schema);
```

## Form options

`FormOptions` is a fluent builder producing the array the renderer consumes:

- `postTo($url)` / `getFrom($url)` — form method + action.
- `pickField($name)` → `FieldOptions`: `label()`, `renderAs(Renderer)`,
  `renderAsDropdown()`, `renderAsTextarea()`, `readonly()`, `disabled()`,
  `hidden()`, `withOption($value, $label)` (Enum), and `pickField()` for
  composite sub-fields.

`Renderer` enumerates the allowed input renderers and validates them per field
type via `Renderer::validFor($field)`.

Elements are built with a small internal `Element` class that maps native PHP
attribute values to HTML — `true` → bare attribute, `false`/`null` → omitted,
scalars → escaped `name="value"`.

## Request input

`Input` normalizes request data so it can be fed straight back to the schema,
smoothing over PHP's quirks:

- a present-but-unfilled field arrives as `''` → normalized to `null` (presence
  is still recoverable via `has()`);
- a checked checkbox submits `'on'` → normalized to `true`;
- uploaded files are merged in by input name as `{ name, type, size }` metadata
  (ready for the `File` field);
- nested names like `price[amount]` are accessible via chained `get()`,
  `ArrayAccess`, or object access.

```php
use Meraki\Schema\Html\Input;

$input = Input::fromGlobals();                 // $_POST + $_FILES
$input = Input::fromPsrRequest($request);      // PSR-7 parsed body + uploads
$input = new Input([...]);                     // already-merged array (tests)

$input->get('email');                          // null if absent or empty
$input->get('subscribe', false);              // true when checked
$input->get('price', [])->get('amount');      // chained nested access
$input->has('email');                          // presence (true even if empty)

$result = $schema->validate($input->toArray());
```

`Input` is read-only.

## Local development

`composer.json` links the sibling `../schema` checkout via a Composer path
repository.

```
composer install
composer test
```
