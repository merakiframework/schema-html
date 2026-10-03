<?php
declare(strict_types=1);

/**
 * CSRF protection on a single-page form.
 *
 * Unlike the other examples this one needs a session and a real POST, so serve it
 * rather than redirecting stdout to a file:
 *
 *     php -S localhost:8000 -t examples
 *     # then open http://localhost:8000/csrf.php
 *
 * Things worth trying once it is running:
 *  - View source: exactly one `<input type="hidden" name="_token">`.
 *  - Edit that value in devtools and submit — rejected, not re-rendered with errors.
 *  - Delete the input entirely and submit — rejected the same way.
 */

require __DIR__ . '/../vendor/autoload.php';

use Meraki\Schema\Definition;
use Meraki\Schema\Html\Csrf\SynchroniserToken;
use Meraki\Schema\Html\Csrf\TokenMismatch;
use Meraki\Schema\Html\FormOptions;
use Meraki\Schema\Html\FormRenderer;
use Meraki\Schema\Html\Input;
use Meraki\Schema\Html\Request\PayloadMapper;
use Meraki\Schema\Html\Wizard\SessionStorage;
use Meraki\Schema\Message\Mf2\Mf2Provider;

session_start();

function buildSchema(): Definition
{
	$schema = new Definition('signup');
	$schema->add(
		$schema->createNameField('name'),
		$schema->createEmailAddressField('email'),
		$schema->createEnumField('plan', ['free', 'pro']),
	);

	return $schema;
}

$schema = buildSchema();

// SessionStorage is the same Storage the wizard's SessionStore uses; the session
// must already be started (above).
$options = (new FormOptions())
	->postTo('/csrf.php')
	->withMessages('en', Mf2Provider::fromPackage('meraki/schema-language-english'))
	->withCsrfProtection(new SynchroniserToken(new SessionStorage()));

$renderer = new FormRenderer();

function page(string $body): string
{
	$style = <<<CSS
		body { font: 16px/1.5 system-ui, sans-serif; margin: 2rem auto; max-width: 40rem; }
		.field { margin-bottom: 1rem; }
		label { display: block; font-weight: 600; }
		input, select { font: inherit; padding: .35rem; }
		.errors p { color: #b00; margin: .25rem 0 0; }
		.rejected { border-left: 4px solid #b00; padding-left: 1rem; }
	CSS;

	return '<!doctype html><html lang="en"><head><meta charset="utf-8">'
		. '<meta name="viewport" content="width=device-width, initial-scale=1">'
		. "<title>CSRF-protected form</title><style>{$style}</style></head><body>{$body}</body></html>";
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
	echo page('<h1>Sign up</h1>' . $renderer->render($schema, $options));
	exit;
}

// Verify before doing anything else with the request. A single-page form has no
// request side in this library, so this line is the host's job; the wizard's
// Form::handle() does the equivalent internally.
try {
	$options->csrf?->verify($_POST);
} catch (TokenMismatch $e) {
	// Not user-correctable, so abort rather than re-rendering with an error.
	http_response_code(403);
	echo page(
		'<h1>Rejected</h1><div class="rejected"><p>'
		. htmlspecialchars($e->getMessage(), ENT_QUOTES)
		. '</p><p><a href="/csrf.php">Start again</a></p></div>',
	);
	exit;
}

$payload = (new PayloadMapper())->map($schema, Input::fromGlobals());
$result = $schema->validate($payload);

if ($result->anyFailed()) {
	echo page('<h1>Sign up</h1>' . $renderer->render($schema, $options, $result));
	exit;
}

echo page(
	'<h1>Signed up!</h1><pre>'
	. htmlspecialchars((string) json_encode($payload, JSON_PRETTY_PRINT), ENT_QUOTES)
	. '</pre><p><a href="/csrf.php">Start again</a></p>',
);
