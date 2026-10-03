<?php
declare(strict_types=1);

namespace Meraki\Schema\Html\Wizard;

use Meraki\Schema\Definition;
use Meraki\Schema\Html\Support\Forms;
use Meraki\Schema\Html\FormOptions;
use Meraki\Schema\Html\Signer;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\CoversClass;

#[Group('html')]
#[Group('wizard')]
#[Group('csrf')]
#[CoversClass(SignedHiddenFieldStore::class)]
#[CoversClass(StateTampered::class)]
#[CoversClass(Signer::class)]
#[CoversClass(Form::class)]
final class SignedStateTest extends TestCase
{
	private function store(): SignedHiddenFieldStore
	{
		return new SignedHiddenFieldStore(new Signer('state-secret'));
	}

	private function schema(): Definition
	{
		$schema = new Definition('signup');
		$schema->add(
			$schema->createNameField('name'),
			$schema->createEmailAddressField('email'),
			$schema->createEnumField('plan', ['free', 'pro']),
		);

		return $schema;
	}

	private function options(): FormOptions
	{
		$options = Forms::options();
		$options->group('Account', ['name']);
		$options->group('Contact', ['email']);
		$options->group('Plan', ['plan']);

		return $options;
	}

	private function form(?SignedHiddenFieldStore $store = null): Form
	{
		return new Form($this->schema(), $this->options(), $store ?? $this->store());
	}

	/**
	 * Advances past step 0 and returns the rendered step-1 HTML, which carries the
	 * signed step-0 answer.
	 */
	private function step1Html(): string
	{
		return $this->form()->handle(['name' => 'Alice', '__wizard' => ['step' => '0', 'action' => 'next']])->html;
	}

	private function extract(string $html, string $name): string
	{
		$pattern = '/name="' . preg_quote($name, '/') . '" value="([^"]*)"/';

		$this->assertMatchesRegularExpression($pattern, $html);
		preg_match($pattern, $html, $matches);

		return htmlspecialchars_decode($matches[1], ENT_QUOTES);
	}

	#[Test]
	public function it_emits_a_signature_and_a_carried_name_list_for_carried_state(): void
	{
		$html = $this->step1Html();

		$this->assertStringContainsString('<input type="hidden" name="name" value="Alice">', $html);
		$this->assertStringContainsString('name="__wizard[carried]"', $html);
		$this->assertStringContainsString('name="__wizard[sig]"', $html);
		$this->assertSame('["name"]', $this->extract($html, '__wizard[carried]'));
	}

	#[Test]
	public function it_accepts_untampered_carried_state(): void
	{
		$html = $this->step1Html();

		$result = $this->form()->handle([
			'name' => 'Alice',
			'email' => 'alice@example.com',
			'__wizard' => [
				'step' => '1',
				'action' => 'next',
				'carried' => $this->extract($html, '__wizard[carried]'),
				'sig' => $this->extract($html, '__wizard[sig]'),
			],
		]);

		$this->assertFalse($result->completed);
		$this->assertStringContainsString('name="__wizard[step]" value="2"', $result->html);
	}

	#[Test]
	public function it_rejects_an_edited_carried_value(): void
	{
		$html = $this->step1Html();

		$this->expectException(StateTampered::class);

		$this->form()->handle([
			'name' => 'Mallory',          // <- the signed step-0 answer, rewritten
			'email' => 'alice@example.com',
			'__wizard' => [
				'step' => '1',
				'action' => 'next',
				'carried' => $this->extract($html, '__wizard[carried]'),
				'sig' => $this->extract($html, '__wizard[sig]'),
			],
		]);
	}

	#[Test]
	public function it_rejects_carried_state_whose_signature_was_removed(): void
	{
		$html = $this->step1Html();

		$this->expectException(StateTampered::class);

		// Deleting both hidden inputs must not be a way to opt out of the check.
		$this->form()->handle([
			'name' => 'Mallory',
			'email' => 'alice@example.com',
			'__wizard' => ['step' => '1', 'action' => 'next', 'carried' => $this->extract($html, '__wizard[carried]')],
		]);
	}

	#[Test]
	public function it_rejects_a_carried_name_dropped_from_the_signed_list(): void
	{
		$html = $this->step1Html();

		$this->expectException(StateTampered::class);

		// Dropping the name from the list (and its value) must not let the old
		// signature stand for the smaller set.
		$this->form()->handle([
			'email' => 'alice@example.com',
			'__wizard' => [
				'step' => '1',
				'action' => 'next',
				'carried' => '[]',
				'sig' => $this->extract($html, '__wizard[sig]'),
			],
		]);
	}

	#[Test]
	public function it_rejects_an_editable_field_promoted_into_the_carried_set(): void
	{
		$html = $this->step1Html();

		$this->expectException(StateTampered::class);

		$this->form()->handle([
			'name' => 'Alice',
			'email' => 'alice@example.com',
			'__wizard' => [
				'step' => '1',
				'action' => 'next',
				'carried' => '["name","email"]',
				'sig' => $this->extract($html, '__wizard[sig]'),
			],
		]);
	}

	#[Test]
	public function it_rejects_state_signed_with_another_secret(): void
	{
		$html = $this->step1Html();

		$this->expectException(StateTampered::class);

		$other = new Form($this->schema(), $this->options(), new SignedHiddenFieldStore(new Signer('other-secret')));

		$other->handle([
			'name' => 'Alice',
			'email' => 'alice@example.com',
			'__wizard' => [
				'step' => '1',
				'action' => 'next',
				'carried' => $this->extract($html, '__wizard[carried]'),
				'sig' => $this->extract($html, '__wizard[sig]'),
			],
		]);
	}

	#[Test]
	public function the_first_step_needs_no_signature(): void
	{
		$result = $this->form()->handle(['name' => 'Alice', '__wizard' => ['step' => '0', 'action' => 'next']]);

		$this->assertFalse($result->completed);
		$this->assertStringContainsString('name="__wizard[step]" value="1"', $result->html);
	}

	#[Test]
	public function a_signed_wizard_completes_across_every_step(): void
	{
		$form = $this->form();

		$step1 = $form->handle(['name' => 'Alice', '__wizard' => ['step' => '0', 'action' => 'next']]);

		$step2 = $form->handle([
			'name' => 'Alice',
			'email' => 'alice@example.com',
			'__wizard' => [
				'step' => '1',
				'action' => 'next',
				'carried' => $this->extract($step1->html, '__wizard[carried]'),
				'sig' => $this->extract($step1->html, '__wizard[sig]'),
			],
		]);

		$done = $form->handle([
			'name' => 'Alice',
			'email' => 'alice@example.com',
			'plan' => 'pro',
			'__wizard' => [
				'step' => '2',
				'action' => 'submit',
				'carried' => $this->extract($step2->html, '__wizard[carried]'),
				'sig' => $this->extract($step2->html, '__wizard[sig]'),
			],
		]);

		$this->assertTrue($done->completed);
		$this->assertSame(['name' => 'Alice', 'email' => 'alice@example.com', 'plan' => 'pro'], $done->data);
	}
}
