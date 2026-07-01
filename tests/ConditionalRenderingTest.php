<?php
declare(strict_types=1);

namespace Meraki\Schema\Html;

use Meraki\Schema\Facade;
use Meraki\Schema\Scope;
use Meraki\Schema\Html\Behaviour\HideOptionalFieldsResolvedByRules;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\CoversClass;

#[Group('html')]
#[CoversClass(FormRenderer::class)]
#[CoversClass(FormOptions::class)]
#[CoversClass(FieldRenderContext::class)]
#[CoversClass(HideOptionalFieldsResolvedByRules::class)]
final class ConditionalRenderingTest extends TestCase
{
	/**
	 * A contact form whose rules live in the schema: choosing "email" requires the
	 * email field and makes the phone field optional, and vice-versa.
	 */
	private function contactSchema(): Facade
	{
		$schema = new Facade('contact_us');
		$schema->addEnumField('contact_method', ['email', 'phone']);
		$schema->addEmailAddressField('email_address');
		$schema->addPhoneNumberField('phone_number');

		$schema->whenAllMatch(fn($r) => $r
			->whenEquals('#/fields/contact_method/value', 'email')
			->thenRequire('#/fields/email_address')
			->thenMakeOptional('#/fields/phone_number'));

		$schema->whenAllMatch(fn($r) => $r
			->whenEquals('#/fields/contact_method/value', 'phone')
			->thenRequire('#/fields/phone_number')
			->thenMakeOptional('#/fields/email_address'));

		return $schema;
	}

	#[Test]
	public function the_default_hook_hides_a_field_a_matched_rule_made_optional(): void
	{
		$schema = $this->contactSchema();
		$schema->input(['contact_method' => 'email']);

		$html = (new FormRenderer())->render($schema);

		$this->assertMatchesRegularExpression('/data-name="phone_number"\s+hidden/', $html);
		$this->assertDoesNotMatchRegularExpression('/data-name="email_address"\s+hidden/', $html);
	}

	#[Test]
	public function swapping_the_choice_swaps_which_field_is_hidden(): void
	{
		$schema = $this->contactSchema();
		$schema->input(['contact_method' => 'phone']);

		$html = (new FormRenderer())->render($schema);

		$this->assertMatchesRegularExpression('/data-name="email_address"\s+hidden/', $html);
		$this->assertDoesNotMatchRegularExpression('/data-name="phone_number"\s+hidden/', $html);
	}

	#[Test]
	public function the_default_hide_hook_can_be_turned_off(): void
	{
		$schema = $this->contactSchema();
		$schema->input(['contact_method' => 'email']);

		$options = (new FormOptions())->withoutBehaviour(HideOptionalFieldsResolvedByRules::class);
		$html = (new FormRenderer())->render($schema, $options);

		$this->assertDoesNotMatchRegularExpression('/data-name="phone_number"\s+hidden/', $html);
	}

	#[Test]
	public function a_field_made_optional_by_the_author_is_not_hidden(): void
	{
		$schema = $this->contactSchema();
		$schema->addBooleanField('newsletter')->makeOptional(); // baseline-optional, no rule targets it
		$schema->input(['contact_method' => 'email']);

		$html = (new FormRenderer())->render($schema);

		// phone_number is hidden by a matched rule; newsletter (author-optional) is not.
		$this->assertMatchesRegularExpression('/data-name="phone_number"\s+hidden/', $html);
		$this->assertDoesNotMatchRegularExpression('/data-name="newsletter"\s+hidden/', $html);
	}

	#[Test]
	public function a_behaviour_that_references_a_missing_field_throws(): void
	{
		$schema = $this->contactSchema();
		$schema->input(['contact_method' => 'email']);

		$behaviour = new class implements ConditionUiBehaviour {
			public function apply(FieldRenderContext $context): void
			{
				// Resolving a scope for a field the schema does not define must throw.
				(new Scope('#/fields/does_not_exist'))->resolve($context->schema);
			}
		};

		$options = (new FormOptions())->behaviours($behaviour);

		$this->expectException(\Throwable::class);
		(new FormRenderer())->render($schema, $options);
	}
}
