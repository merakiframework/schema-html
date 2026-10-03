# meraki/schema-html

Render a [`meraki/schema`](https://github.com/merakiframework/schema) as an HTML form, turn
the submission back into what the schema validates, and run multi-step forms — with no
JavaScript.

Keeps HTML/form-rendering concerns out of the core schema domain. Reads only
`meraki/schema`'s public API. Requires PHP 8.5 and `meraki/schema` 2.0.

## Usage

```php
use Meraki\Schema\Definition;
use Meraki\Schema\Html\FormOptions;
use Meraki\Schema\Html\FormRenderer;
use Meraki\Schema\Message\Mf2\Mf2Provider;

$schema = new Definition('signup');
$schema->add(
    $schema->createNameField('full_name'),
    $schema->createEmailAddressField('email'),
    $schema->createBooleanField('subscribe')->makeOptional(),
);

$options = (new FormOptions())
    ->postTo('/signup')
    ->withMessages('en-AU', Mf2Provider::fromPackage('meraki/schema-language-english'));

$options->configure('email')->label('Your email address');

echo (new FormRenderer())->render($schema, $options);
```

On a POST, map the submission for the schema, validate, and render the result back in to show
what went wrong, next to the part it went wrong with:

```php
use Meraki\Schema\Html\Input;
use Meraki\Schema\Html\Request\PayloadMapper;

$result = $schema->validate((new PayloadMapper())->map($schema, Input::fromGlobals()));

if ($result->anyFailed()) {
    echo (new FormRenderer())->render($schema, $options, $result);
}
```

The renderer always draws from a *result*. `meraki/schema` keeps nothing about a request on the
schema itself, so what was submitted, what a rule changed and what failed all live on
`$schema->resolve()` / `$schema->validate()`. With no result the renderer resolves the schema
itself — authored defaults, and the rules as they stand with nothing submitted. To show a
user's stored details, resolve with them:

```php
echo (new FormRenderer())->render($schema, $options, $schema->resolve(prefilledWith: $user));
```

## Messages

**Required.** Rendering throws `Exception\MessagesNotConfigured` until the form says which
language to speak, and `Exception\UnsupportedLocale` when the provider has nothing for it.

`meraki/schema` owns the wording: MessageFormat 2 packs installed with Composer, so every port
says the same thing. This package only chooses the pack and the language — per form, so per
request, the same way the core takes them per call on `validate()`. The English pack is
published, but not yet tagged:

```
composer require meraki/schema-language-english:dev-main
```

```php
$options->withMessages('en-AU', Mf2Provider::fromPackage('meraki/schema-language-english'));

// or your own pack, checked with the core's `vendor/bin/schema-lang validate <dir>`:
$options->withMessages('en', Mf2Provider::fromDirectory(__DIR__ . '/lang'));
```

The renderer applies the language itself, so it does not matter whether `validate()` was asked
for one, or for another. The core answers an unsupported language with silence; this package refuses instead,
because a form whose error boxes are silently empty is the failure nobody notices.

## Form options

`FormOptions` is a fluent builder:

- `postTo($url)` / `putTo($url)` / `getFrom($url)` — form method + action.
- `withMessages($locale, $provider)` — see above.
- `settledParts(SettledPart)` — see [Settled parts](#settled-parts).
- `withRowKeys(RowKeys)` — how new collection rows are named (`row1`, `row2`, … by default).
- `configure($name)` (or `configureOptionsFor($name)`) → `FieldOptions`:
  `label()`, `hint()`, `renderAs(Renderer)`, `renderAsDropdown()`, `renderAsSelect()`,
  `renderAsRadioGroup()`, `renderAsButtonGroup()`, `renderAsTextarea()`, `readonly()`,
  `disabled()`, `hidden()`, `autocomplete()`, `labelOption($value, $label)`,
  `allowAddingOptions()`, `revealInline()`, `revealWithPopup()`, `revealWithDialog()`,
  `addInDialog()`, `inheritInNewItems()`, `settledParts()`, `lines()` (an address's street
  lines), and `configureFor()` for a structured field's parts or a collection's template fields.

### Autocomplete

Every field emits the semantic `autocomplete` token a browser needs to autofill it — `email` for
an email address, `tel` for a phone number, `cc-number` and `address-line1` for the relevant
parts of a card or an address — or nothing at all where no token is meaningful.

`autocomplete(false)` emits `autocomplete="off"`; passing a token string overrides the default
outright, on a field or on one of its parts:

```php
$options->configure('password')->autocomplete('new-password');
$options->configure('shipping')->configureFor('postal_code')->autocomplete('shipping postal-code');
```

## Request input

Two steps, because they change for different reasons:

- **`Input`** is transport: it merges `$_POST` and `$_FILES` (or a PSR-7 request's parsed body
  and uploads), turns each upload into the `{ name, type, size }` record the core's `File` field
  reads, and turns the `''` an untouched box submits into `null`. Nothing else.
- **`Request\PayloadMapper`** is translation, and needs the schema. The core draws a line PHP
  does not — *an object is a record, an array is a list* — so the mapper decides which nested
  array is which: an address, amount, card or phone number becomes an object of its parts; a
  collection becomes an array of named rows, each an object. Along the way it drops the blank
  spare row a collection form always offers, fills back in any [settled part](#settled-parts)
  the form left out, and reads a checkbox's `on` (and its hidden `0`) as a boolean — for Boolean
  fields only.

```php
$input = Input::fromGlobals();                 // $_POST + $_FILES
$input = Input::fromPsrRequest($request);      // PSR-7 parsed body + uploads
$input = new Input([...]);                     // already-merged array (tests)

$payload = (new PayloadMapper())->map($schema, $input);
$result = $schema->validate($payload);
```

An unticked checkbox submits nothing at all, so the renderer puts a hidden `0` in front of each
one and the mapper reads it as `false`. A box that must be ticked (`mustBeAccepted()`) gets no
`0`: there, unticked has to stay "not answered".

## Themes

What a field *says* — its label, the value it shows, whether a rule has hidden it, what went
wrong — is decided before a theme is involved. The theme decides only how it looks.

A theme is **widgets** — the markup vocabulary (`select`, `input`, `checkbox`, `choices`,
`label`, `errors`, `dialog`, …) — put together by **field renderers**, one per field type. The
renderers never write a tag themselves, so restyle a widget once and every field drawn with it
changes: one `select()` draws an Enum, an address's state and country, a currency and a phone's
country.

```php
use Meraki\Schema\Html\Presentation\Control;
use Meraki\Schema\Html\Theme\DefaultTheme;
use Meraki\Schema\Html\Theme\DefaultWidgets;

final class BootstrapWidgets extends DefaultWidgets
{
    public function select(Control $control, bool $customizable = false): Element
    {
        return parent::select($control, $customizable)->setAttribute('class', 'form-select');
    }
}

$renderer = new FormRenderer(new DefaultTheme(new BootstrapWidgets()));
```

`DefaultTheme` is the stock look. Swap how one field type is structured with
`$theme->withRenderer(Field\Money::class, new MyMoneyRenderer())`; a renderer registered for the
`Meraki\Schema\Field` interface itself catches any field type nothing else claims, which is how a
field type of your own gets drawn.

## Structured fields

An address, an amount of money, a card and a phone number each hold one value with named parts,
drawn as a fieldset of labelled controls — or, when only one part is left to fill in, as a single
control under the field's own label. Each type's parts come from a `Presentation\PartLayout`.

### Settled parts

A part the field's configuration already decides — the country of an address that allows only
Australia, the currency of an amount in AUD only, the country of an Australian phone number — is
**left out of the form by default**. The core still requires it, so the mapper fills it back in.

```php
$options->settledParts(SettledPart::Hidden);                          // carry it in a hidden input
$options->configure('billing')->settledParts(SettledPart::Visible);  // or show it, for one field
```

All three validate identically; the choice is markup only.

### Addresses

`Field\Address` is laid out by `AddressLayout`. What it asks for, and what it marks required, is
the core's own answer — `Address::requirementsFor()` — so the form promises exactly what the
server checks. What a country *calls* each part is presentation the core leaves out, so
`AddressVocabulary` reads it from `commerceguys/addressing`.

- **The parts** are `street` (a list of lines), `dependent_locality`, `locality`, `subdivision`,
  `postal_code` and `country`.
- **The street is one input per line**, `billing[street][0]`, `billing[street][1]`: two by
  default, as most checkouts ask, with `address-line1`, `address-line2`… autocomplete tokens.
  Change them with `$options->configure('billing')->configureFor('street')->lines('Street', 'Unit', 'Building')`.
  The mapper drops empty lines, and also accepts one newline-separated textarea if a theme draws
  the street that way.
- **Required parts** are the ones every allowed country requires at the field's precision. With
  any country allowed, nothing beyond the country can be known in advance, so nothing else is
  marked; the server still applies the submitted country's rules, and a part that was left out
  is reported against its own box ("Enter a postcode.").
- **Parts below the field's precision are not asked for.** A field with
  `minPrecisionOf(Precision::Locality)` is a service area: the core would accept a street, but
  the form does not ask for one.
- **Labels follow the country** when exactly one is allowed: Australia gets "Suburb" and
  "State", Japan "Prefecture", the US "City" and "ZIP Code". With several allowed, the label
  generalises ("Administrative Area") and the hint carries the alternatives.
- **Dropdowns show names, submit codes** — "Queensland" for `AU-QLD` (the core canonicalises a
  subdivision to its full ISO 3166-2 code), "Australia" for `AU`. The country dropdown offers
  only the countries the field allows; the subdivision is a dropdown when one country is allowed.
- **Unused parts are left out** — Singapore has no state, Hong Kong no postcode.
- **`pattern` and `inputmode`** are set on the postcode for a single allowed country, with
  `inputmode="numeric"` only where the postcode really is digits-only — and left off where one of
  its subdivisions has a pattern of its own, which the browser could not know to apply.

## Collections

A collection's rows are **named**, never numbered: `lessons[row1][date]`, `lessons[row2][date]`.
A name means the same row on every request, so removing one leaves every other row — its inputs,
its errors, whatever row rules said about it — exactly as it was. The form names each new row
(`Request\SequentialRowKeys`: one past the highest in use), and Add / Remove are plain submit
buttons (`add:lessons`, `remove:lessons:row1`) handled by the wizard.

## Rule-driven hiding

`Behaviour\HideOptionalFieldsResolvedByRules` (on by default) hides any field one of the schema's
rules made optional, or whose input a rule is discarding, on this request. It reads what the
rules did from the result, so the page can never disagree with the validation. A field the
author made optional is never hidden by it. Turn it off with
`$options->withoutBehaviour(HideOptionalFieldsResolvedByRules::class)`, or write your own
`ConditionUiBehaviour`.

`required` is always drawn from the field *as the rules left it* on this request.

## CSRF protection

Off by default. `withCsrfProtection()` renders a hidden token into the form — for
both single-page forms and the wizard, since both open the form the same way:

```php
use Meraki\Schema\Html\Csrf\SynchroniserToken;
use Meraki\Schema\Html\Wizard\SessionStorage;

session_start();

$options = (new FormOptions())
    ->postTo('/signup')
    ->withMessages('en-AU', $provider)
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
$result = $schema->validate((new PayloadMapper())->map($schema, Input::fromGlobals()));
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

## Multi-step forms

Groups (`$options->group('Title', ['field', …])`) render one per request by default.
`Wizard\Form` sequences them: `start()` for the first GET, `handle($_POST)` for each step.
Each step validates only its own fields; the last validates the whole schema. On completion,
`$result->data` holds the answers as submitted and `$result->validation` the schema's verdict,
with the typed values: `$result->validation->forField('email')->value`.

A step whose every field a rule has hidden is skipped (`showAllSteps()` opts out).

## Examples

Runnable scripts live in [`examples/`](examples/). They use the published English pack, which is
a dev dependency of this repository:

- [`render.php`](examples/render.php) — a form, then the same form re-rendered
  with inline validation errors. `php examples/render.php > form.html`
- [`booking.php`](examples/booking.php) — a full stepped booking flow with rules and a
  collection of lessons.
- [`multi-step.php`](examples/multi-step.php) — the wizard, split across steps.
- [`csrf.php`](examples/csrf.php) — a token-protected form and the verify-on-POST
  branch.

Serve the last three rather than redirecting stdout, since they need real submissions:
`php -S localhost:8000 -t examples`.

## Migrating from 0.5

0.6 targets `meraki/schema` 2.0, a ground-up rewrite, so most of the changes follow from it.
See the core's `UPGRADING.md` for building schemas (`add($schema->createXField(...))`, rules as
values with `addRule()`).

- **The schema is a `Definition`** (`Meraki\Schema\Definition`, which was `Facade`).
- **Messages are required.** Add `->withMessages($locale, $provider)` to every `FormOptions`.
  `ValidationMessages` and `ValidationMessageProvider` are gone — the wording comes from the
  core's message packs.
- **Render from a result.** `$schema->input()` is gone from the core; pass
  `$schema->resolve($payload)` or `$schema->validate($payload)` as `render()`'s third argument.
- **Map submissions before validating.** `$schema->validate($input->toArray())` becomes
  `$schema->validate((new PayloadMapper())->map($schema, $input))`.
- **`Input` is transport only.** `get()`, `has()`, array and property access are gone, and `on`
  is no longer turned into `true` (the mapper does that, for Boolean fields). Read typed values
  from the result instead.
- **Collection rows are named.** `lessons[0][date]` is now `lessons[row1][date]`; the remove
  action is `remove:lessons:row1`, and nothing is renumbered.
- **Addresses** follow the core's rebuilt address: `line1`/`line2` became the `street` list
  (`billing[street][0]`, `billing[street][1]`), `administrative_area` became `subdivision`
  (submitted as `AU-QLD`), `organization` is gone, and the country part is `country` (was
  `country_code`). A settled country is left out of the form rather than hidden. Cards: the
  holder part is `name` (was `holder`). Phone numbers are drawn as a country and a number.
- **Rendering is themed.** `registerFieldRenderer($class, $callable)` becomes
  `new FormRenderer($theme->withRenderer($class, $renderer))`. `DialogView` and `DialogStyles`
  became `Theme\DefaultWidgets::dialog()` and `::styles()`.
- **`FieldRenderContext`** carries the field's `$result` and `$effects` (what the rules did)
  instead of `$data`; `$field`, `$madeOptionalByMatchedRule` and `$requiredByMatchedRule` still
  read the same.
- `Wizard\RuleScopes` is gone: the core's scopes no longer need rewinding.

## Local development

`composer.json` links the sibling `../schema` checkout via a Composer path
repository, so local changes to `meraki/schema` are picked up immediately. This
needs `"minimum-stability": "dev"`, because the linked checkout resolves as
`dev-main` (aliased to `2.0.x-dev` by the core's `extra.branch-alias`).

The url is written as a glob (`../{schema}`) on purpose. A plain `../schema`
makes `composer update` fail outright when the sibling checkout is not there,
which would break CI; a glob that matches nothing is simply skipped, so Composer
falls back to Packagist.

```
composer install
composer test
```

The tests use their own message pack, [`tests/fixtures/lang/`](tests/fixtures/lang/), so their
assertions do not move when the published pack's wording does. `Support\FixturePackTest` fails
when the core reports a message key the fixtures say nothing about.
