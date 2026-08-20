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
$options = (new FormOptions())->postTo('/signup');
$options->configure('email')->label('Your email address');

$html = (new FormRenderer())->render($schema, $options);
```

Pass a validation result back in to surface inline error messages, against the
individual fields that failed:

```php
$result = $schema->validate($input);

echo (new FormRenderer())->render($schema, $options, $result);
```

## Form options

`FormOptions` is a fluent builder producing the array the renderer consumes:

- `postTo($url)` / `getFrom($url)` — form method + action.
- `configure($name)` (or `configureOptionsFor($name)`) → `FieldOptions`:
  `label()`, `hint()`, `renderAs(Renderer)`, `renderAsDropdown()`,
  `renderAsTextarea()`, `readonly()`, `disabled()`, `hidden()`,
  `autocomplete()`, `labelOption($value, $label)` (Enum), and
  `configureFor()` for composite sub-fields.

### Autocomplete

Every field emits the semantic `autocomplete` token a browser needs to autofill
it — `email` for an email address, `tel` for a phone number, `cc-number` and
`address-line1` for the relevant parts of a credit card or address — or nothing
at all where no token is meaningful. A `Field\Text` gets its token from the
composite it sits in, not from its own type.

`autocomplete(false)` emits `autocomplete="off"` for anything a browser should
not remember; passing a token string overrides the default outright:

```php
$options->configure('password')->autocomplete('new-password');
$options->configure('one_time_code')->autocomplete(false);
```

### Addresses

`Field\Address` gets a dedicated renderer that reads the countries the field
allows and asks `commerceguys/addressing` how they describe an address. The core
library deliberately holds none of this — what a country calls the thing in the
`administrative_area` box is presentation, useless to a JSON serializer — so
`AddressVocabulary` owns it here.

- **Labels follow the country** when exactly one is allowed: Australia gets
  "Suburb" and "State", Japan "Prefecture", the US "City" and "ZIP Code". With
  several allowed, the label generalises ("Administrative Area") and the hint
  carries the alternatives ("State or province").
- **Dropdowns show names, submit codes** — "Queensland" for `QLD`, "Australia"
  for `AU`. The administrative area is only a dropdown when one country is
  allowed; with several, which subdivisions are valid depends on the country
  chosen, so it stays a text input and the server checks it.
- **Settled and unused parts are hidden.** A single allowed country settles
  `country_code`, which is hidden but still submitted so the address never
  serializes without it. Parts no allowed country uses are hidden too — Singapore
  has no administrative area, Hong Kong no postal code.
- **`pattern` and `inputmode`** are set on the postal code for a single allowed
  country. `inputmode="numeric"` only where the postal code really is digits-only:
  a numeric keyboard cannot type Canada's `K1A 0B1` or an Irish eircode.

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

## CSRF protection

Off by default. `withCsrfProtection()` renders a hidden token into the form — for
both single-page forms and the wizard, since both open the form the same way:

```php
use Meraki\Schema\Html\Csrf\SynchroniserToken;
use Meraki\Schema\Html\Wizard\SessionStorage;

session_start();

$options = (new FormOptions())
    ->postTo('/signup')
    ->withCsrfProtection(new SynchroniserToken(new SessionStorage()));
```

Two providers ship with the library:

- **`SynchroniserToken`** — a random token held in `Storage` (the same
  abstraction the wizard's `SessionStore` uses) and mirrored into the form. Use
  it whenever you have a session. `issue()` is idempotent, so re-rendering never
  invalidates a token another tab is holding; call `rotate()` after a login or
  other privilege change.
- **`SignedToken`** — an expiring HMAC-signed token verified by recomputing the
  signature, so nothing is stored server-side. Pairs with `HiddenFieldStore`,
  which also runs the wizard without a session.

  ```php
  new SignedToken(new Signer($secret), binding: session_id(), ttl: 7200);
  ```

  The `binding` is required, and it is what makes the token CSRF protection
  rather than a formality: it must be a per-visitor value an attacker can
  neither read nor set (`session_id()`, or a random value in a `SameSite=Lax`,
  `HttpOnly` cookie). Without one, every visitor gets a validly-signed token and
  an attacker simply uses their own.

Hosts that already have CSRF machinery should implement `Csrf\TokenProvider`
over it and pass that instead.

**Verifying.** The wizard verifies for you — `Wizard\Form::handle()` throws
`Csrf\TokenMismatch` before the submission reaches the state store, the schema,
or your completion handler. Single-page forms have no request side in this
library, so verify them yourself before validating:

```php
$options->csrf?->verify($_POST);          // throws Csrf\TokenMismatch
$result = $schema->validate($input->toArray());
```

`TokenMismatch` is an exception rather than a validation failure because it is
not user-correctable: answer 403 (or 419), don't re-render the form.

### Signing carried wizard state

`HiddenFieldStore` re-emits every prior answer as a hidden input, and nothing
stops the client rewriting one on a later round-trip to get past validation a
step already applied. `SignedHiddenFieldStore` wraps it and signs the carried
names and values, throwing `Wizard\StateTampered` if either changed:

```php
$store = new SignedHiddenFieldStore(new Signer($secret));
$form = new Wizard\Form($schema, $options, $store);
```

This closes *tampering*, not *disclosure* — the answers are still readable in
the page source. Use `SessionStore` when they must not reach the client at all.

## Examples

Runnable scripts live in [`examples/`](examples/) — each writes HTML to stdout,
so redirect it to a file to open in a browser:

- [`render.php`](examples/render.php) — a form, then the same form re-rendered
  with inline validation errors.
- [`booking.php`](examples/booking.php) — a repeatable collection of items.
- [`multi-step.php`](examples/multi-step.php) — the wizard, split across steps.
- [`csrf.php`](examples/csrf.php) — a token-protected form and the verify-on-POST
  branch. Serve it (`php -S localhost:8000 -t examples`) rather than redirecting,
  since it needs a session and a real submission.

## Local development

`composer.json` links the sibling `../schema` checkout via a Composer path
repository, so local changes to `meraki/schema` are picked up immediately. This
needs `"minimum-stability": "dev"`, because the linked checkout resolves as
`dev-main` (aliased to `1.13.x-dev` by the core's `extra.branch-alias`).

The url is written as a glob (`../{schema}`) on purpose. A plain `../schema`
makes `composer update` fail outright when the sibling checkout is not there,
which would break CI; a glob that matches nothing is simply skipped, so Composer
falls back to the VCS repository below it and resolves `meraki/schema` from
GitHub as before.

```
composer install
composer test
```
