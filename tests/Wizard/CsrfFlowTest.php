<?php
declare(strict_types=1);

namespace Meraki\Schema\Html\Wizard;

use Meraki\Schema\Facade;
use Meraki\Schema\Html\Support\Forms;
use Meraki\Schema\Html\Csrf\SynchroniserToken;
use Meraki\Schema\Html\Csrf\TokenMismatch;
use Meraki\Schema\Html\FormOptions;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\CoversClass;

#[Group('html')]
#[Group('wizard')]
#[Group('csrf')]
#[CoversClass(Form::class)]
#[CoversClass(Renderer::class)]
#[CoversClass(HiddenFieldStore::class)]
final class CsrfFlowTest extends TestCase
{
	private SynchroniserToken $provider;

	protected function setUp(): void
	{
		$this->provider = new SynchroniserToken(new InMemoryStorage());
	}

	private function schema(): Facade
	{
		$schema = new Facade('signup');
		$schema->add(
			$schema->createNameField('name'),
			$schema->createEmailAddressField('email'),
		);

		return $schema;
	}

	private function options(): FormOptions
	{
		$options = Forms::options();
		$options->group('Account', ['name']);
		$options->group('Contact', ['email']);
		$options->withCsrfProtection($this->provider);

		return $options;
	}

	private function form(): Form
	{
		return new Form($this->schema(), $this->options(), new HiddenFieldStore());
	}

	#[Test]
	public function the_first_step_carries_a_token(): void
	{
		$html = $this->form()->start();

		$this->assertStringContainsString('name="_token" value="' . $this->provider->issue() . '"', $html);
	}

	#[Test]
	public function it_rejects_a_step_submission_with_a_bad_token(): void
	{
		$this->expectException(TokenMismatch::class);

		$this->form()->handle([
			'name' => 'Alice',
			'_token' => 'forged',
			'__wizard' => ['step' => '0', 'action' => 'next'],
		]);
	}

	#[Test]
	public function it_rejects_a_step_submission_with_no_token_at_all(): void
	{
		$this->expectException(TokenMismatch::class);

		$this->form()->handle(['name' => 'Alice', '__wizard' => ['step' => '0', 'action' => 'next']]);
	}

	#[Test]
	public function it_does_not_carry_the_token_into_wizard_state(): void
	{
		$result = $this->form()->handle([
			'name' => 'Alice',
			'_token' => $this->provider->issue(),
			'__wizard' => ['step' => '0', 'action' => 'next'],
		]);

		$this->assertFalse($result->completed);
		$this->assertStringContainsString('name="__wizard[step]" value="1"', $result->html);

		// Exactly one token input: the fresh one from the form shell. A second, stale
		// copy carried forward as state would shadow it and break the next step.
		$this->assertSame(1, substr_count($result->html, 'name="_token"'));
		$this->assertStringContainsString('<input type="hidden" name="name" value="Alice">', $result->html);
	}

	#[Test]
	public function the_token_never_reaches_the_completed_data(): void
	{
		$token = $this->provider->issue();
		$form = $this->form();

		$result = $form->handle([
			'name' => 'Alice',
			'email' => 'alice@example.com',
			'_token' => $token,
			'__wizard' => ['step' => '1', 'action' => 'submit'],
		]);

		$this->assertTrue($result->completed);
		$this->assertArrayNotHasKey('_token', $result->data);
		$this->assertSame(['name' => 'Alice', 'email' => 'alice@example.com'], $result->data);
	}

	#[Test]
	public function a_protected_wizard_still_completes_across_every_step(): void
	{
		$token = $this->provider->issue();
		$form = $this->form();

		$step1 = $form->handle([
			'name' => 'Alice',
			'_token' => $token,
			'__wizard' => ['step' => '0', 'action' => 'next'],
		]);
		$this->assertFalse($step1->completed);

		$done = $form->handle([
			'name' => 'Alice',
			'email' => 'alice@example.com',
			'_token' => $token,
			'__wizard' => ['step' => '1', 'action' => 'submit'],
		]);

		$this->assertTrue($done->completed);
		$this->assertSame(['name' => 'Alice', 'email' => 'alice@example.com'], $done->data);
	}

	#[Test]
	public function an_unprotected_wizard_is_unaffected(): void
	{
		$options = Forms::options();
		$options->group('Account', ['name']);
		$options->group('Contact', ['email']);

		$form = new Form($this->schema(), $options, new HiddenFieldStore());
		$result = $form->handle(['name' => 'Alice', '__wizard' => ['step' => '0', 'action' => 'next']]);

		$this->assertFalse($result->completed);
		$this->assertStringNotContainsString('_token', $result->html);
	}
}
