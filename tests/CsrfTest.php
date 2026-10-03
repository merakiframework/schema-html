<?php
declare(strict_types=1);

namespace Meraki\Schema\Html;

use Meraki\Schema\Definition;
use Meraki\Schema\Html\Csrf\Guard;
use Meraki\Schema\Html\Csrf\SignedToken;
use Meraki\Schema\Html\Csrf\SynchroniserToken;
use Meraki\Schema\Html\Csrf\TokenMismatch;
use Meraki\Schema\Html\Csrf\TokenProvider;
use Meraki\Schema\Html\Support\Forms;
use Meraki\Schema\Html\Wizard\InMemoryStorage;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\CoversClass;
use InvalidArgumentException;

#[Group('html')]
#[Group('csrf')]
#[CoversClass(Guard::class)]
#[CoversClass(Signer::class)]
#[CoversClass(SignedToken::class)]
#[CoversClass(SynchroniserToken::class)]
#[CoversClass(TokenMismatch::class)]
#[CoversClass(FormOptions::class)]
#[CoversClass(FormRenderer::class)]
final class CsrfTest extends TestCase
{
	private function schema(): Definition
	{
		$schema = new Definition('signup');
		$schema->add($schema->createNameField('name'));

		return $schema;
	}

	private function synchroniser(): SynchroniserToken
	{
		return new SynchroniserToken(new InMemoryStorage());
	}

	private function signed(string $secret = 'app-secret', string $binding = 'session-abc', int $ttl = 7200): SignedToken
	{
		return new SignedToken(new Signer($secret), $binding, $ttl);
	}

	#[Test]
	public function it_renders_no_token_input_by_default(): void
	{
		$html = (new FormRenderer())->render($this->schema(), Forms::options());

		$this->assertStringNotContainsString('_token', $html);
	}

	#[Test]
	public function it_renders_a_hidden_token_input_when_protection_is_enabled(): void
	{
		$provider = $this->synchroniser();
		$options = Forms::options()->withCsrfProtection($provider);

		$html = (new FormRenderer())->render($this->schema(), $options);

		$this->assertStringContainsString(
			'<input type="hidden" name="_token" value="' . $provider->issue() . '">',
			$html,
		);
	}

	#[Test]
	public function it_uses_the_configured_field_name(): void
	{
		$options = Forms::options()->withCsrfProtection($this->synchroniser(), 'csrf_token');

		$html = (new FormRenderer())->render($this->schema(), $options);

		$this->assertStringContainsString('name="csrf_token"', $html);
		$this->assertStringNotContainsString('name="_token"', $html);
	}

	#[Test]
	public function it_omits_the_token_from_get_forms(): void
	{
		$options = Forms::options()->getFrom('/search')->withCsrfProtection($this->synchroniser());

		$html = (new FormRenderer())->render($this->schema(), $options);

		$this->assertStringNotContainsString('_token', $html);
	}

	#[Test]
	public function it_still_protects_non_post_methods_that_tunnel_through_post(): void
	{
		$options = Forms::options()->putTo('/account')->withCsrfProtection($this->synchroniser());

		$html = (new FormRenderer())->render($this->schema(), $options);

		$this->assertStringContainsString('name="_method" value="put"', $html);
		$this->assertStringContainsString('name="_token"', $html);
	}

	#[Test]
	public function the_synchroniser_token_reuses_the_stored_value_across_renders(): void
	{
		$provider = $this->synchroniser();

		$first = $provider->issue();
		$second = $provider->issue();

		// Rotating per render would invalidate any other open tab and break the wizard.
		$this->assertSame($first, $second);
		$this->assertTrue($provider->accepts($first));
	}

	#[Test]
	public function the_synchroniser_token_rejects_a_missing_or_altered_token(): void
	{
		$provider = $this->synchroniser();
		$token = $provider->issue();

		$this->assertFalse($provider->accepts(null));
		$this->assertFalse($provider->accepts(''));
		$this->assertFalse($provider->accepts(strrev($token)));
		$this->assertFalse($provider->accepts(substr($token, 0, -1)));
	}

	#[Test]
	public function the_synchroniser_token_rejects_everything_before_one_is_issued(): void
	{
		$this->assertFalse($this->synchroniser()->accepts('anything'));
	}

	#[Test]
	public function rotating_the_synchroniser_token_invalidates_the_previous_one(): void
	{
		$provider = $this->synchroniser();
		$old = $provider->issue();

		$new = $provider->rotate();

		$this->assertNotSame($old, $new);
		$this->assertFalse($provider->accepts($old));
		$this->assertTrue($provider->accepts($new));
	}

	#[Test]
	public function it_accepts_a_signed_token_and_rejects_one_signed_with_another_secret(): void
	{
		$token = $this->signed(secret: 'app-secret')->issue();

		$this->assertTrue($this->signed(secret: 'app-secret')->accepts($token));
		$this->assertFalse($this->signed(secret: 'other-secret')->accepts($token));
	}

	#[Test]
	public function it_rejects_a_signed_token_bound_to_a_different_session(): void
	{
		// The whole point of the binding: a token the attacker legitimately obtained
		// for their own session must not work against the victim's.
		$attackers = $this->signed(binding: 'session-attacker')->issue();

		$this->assertFalse($this->signed(binding: 'session-victim')->accepts($attackers));
	}

	#[Test]
	public function it_rejects_an_expired_signed_token(): void
	{
		$token = $this->signed(ttl: 1)->issue();

		$this->assertTrue($this->signed(ttl: 1)->accepts($token));

		// Re-sign an already-elapsed expiry rather than sleeping.
		$expired = $this->expiredTokenFor('app-secret', 'session-abc');

		$this->assertFalse($this->signed()->accepts($expired));
	}

	#[Test]
	public function it_rejects_malformed_signed_tokens(): void
	{
		$provider = $this->signed();

		$this->assertFalse($provider->accepts(null));
		$this->assertFalse($provider->accepts(''));
		$this->assertFalse($provider->accepts('no-separator'));
		$this->assertFalse($provider->accepts('.'));
		$this->assertFalse($provider->accepts('notbase64.deadbeef'));
	}

	#[Test]
	public function signed_tokens_differ_per_issue_but_all_remain_acceptable(): void
	{
		$provider = $this->signed();

		$first = $provider->issue();
		$second = $provider->issue();

		$this->assertNotSame($first, $second);
		$this->assertTrue($provider->accepts($first));
		$this->assertTrue($provider->accepts($second));
	}

	#[Test]
	public function an_unbound_signed_token_is_refused_at_construction(): void
	{
		$this->expectException(InvalidArgumentException::class);

		new SignedToken(new Signer('app-secret'), '');
	}

	#[Test]
	public function an_empty_signing_secret_is_refused(): void
	{
		$this->expectException(InvalidArgumentException::class);

		new Signer('');
	}

	#[Test]
	public function the_guard_verifies_a_request_and_throws_on_mismatch(): void
	{
		$provider = $this->synchroniser();
		$guard = new Guard($provider);
		$token = $provider->issue();

		$this->assertTrue($guard->accepts(['_token' => $token]));
		$guard->verify(['_token' => $token]);

		$this->assertFalse($guard->accepts([]));
		$this->assertFalse($guard->accepts(['_token' => ['array-not-string']]));

		$this->expectException(TokenMismatch::class);
		$guard->verify(['_token' => 'wrong']);
	}

	#[Test]
	public function a_host_can_supply_its_own_token_provider(): void
	{
		$provider = new class implements TokenProvider {
			public function issue(): string
			{
				return 'framework-issued';
			}

			public function accepts(?string $token): bool
			{
				return $token === 'framework-issued';
			}
		};

		$options = Forms::options()->withCsrfProtection($provider);
		$html = (new FormRenderer())->render($this->schema(), $options);

		$this->assertStringContainsString('name="_token" value="framework-issued"', $html);
		$this->assertTrue($options->csrf->accepts(['_token' => 'framework-issued']));
	}

	#[Test]
	public function a_token_cannot_break_out_of_the_value_attribute(): void
	{
		$provider = new class implements TokenProvider {
			public function issue(): string
			{
				return '"><script>alert(1)</script>';
			}

			public function accepts(?string $token): bool
			{
				return true;
			}
		};

		$html = (new FormRenderer())->render($this->schema(), Forms::options()->withCsrfProtection($provider));

		$this->assertStringNotContainsString('<script>', $html);
		$this->assertStringContainsString('&quot;&gt;&lt;script&gt;', $html);
	}

	/**
	 * A structurally valid token whose expiry has already passed, built the same way
	 * SignedToken builds one.
	 */
	private function expiredTokenFor(string $secret, string $binding): string
	{
		$payload = rtrim(strtr(base64_encode((string) json_encode([
			'exp' => time() - 1,
			'n' => 'deadbeefdeadbeef',
		])), '+/', '-_'), '=');

		return $payload . '.' . (new Signer($secret))->sign($payload . '|' . $binding);
	}
}
