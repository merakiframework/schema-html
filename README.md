# meraki/schema-html

Render a [`meraki/schema`](https://github.com/merakiframework/schema) as an HTML form.

Keeps HTML/form-rendering concerns out of the core schema domain. Reads only
`meraki/schema`'s public API.

## Usage

```php
use Meraki\Schema\Facade;
use Meraki\Schema\Html\SchemaHtmlRenderer;
use Meraki\Schema\Html\UiOptions;

$schema = new Facade('signup');
$schema->addNameField('full_name')->minLengthOf(1)->maxLengthOf(255);
$schema->addEmailAddressField('email');
$schema->addBooleanField('subscribe')->makeOptional();

// Optional: configure the form + per-field UI
$ui = (new UiOptions($schema))->postTo('/signup');
$ui->pickField('email')->label('Your email address');

$html = (new SchemaHtmlRenderer($ui->toArray()))->render($schema);
```

Render after validation to surface inline error messages:

```php
$schema->validate($_POST);
echo (new SchemaHtmlRenderer($ui->toArray()))->render($schema);
```

## UI options

`UiOptions` is a fluent builder producing the array the renderer consumes:

- `postTo($url)` / `getFrom($url)` — form method + action.
- `pickField($name)` → `FieldOptions`: `label()`, `renderAs(Renderer)`,
  `renderAsDropdown()`, `renderAsTextarea()`, `readonly()`, `disabled()`,
  `hidden()`, `withOption($value, $label)` (Enum), and `pickField()` for
  composite sub-fields.

`Renderer` enumerates the allowed input renderers and validates them per field
type via `Renderer::validFor($field)`.

## Local development

`composer.json` links the sibling `../schema` checkout via a Composer path
repository.

```
composer install
composer test
```
