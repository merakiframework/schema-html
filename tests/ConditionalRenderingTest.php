<?php
declare(strict_types=1);

namespace Meraki\Schema\Html;

use Meraki\Schema\Definition;
use Meraki\Schema\Exception\UnknownField;
use Meraki\Schema\Html\Behaviour\HideOptionalFieldsResolvedByRules;
use Meraki\Schema\Html\Presentation\RuleEffects;
use Meraki\Schema\Html\Support\Forms;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\CoversClass;

#[Group('html')]
#[CoversClass(FormRenderer::class)]
#[CoversClass(FormOptions::class)]
#[CoversClass(FieldRenderContext::class)]
#[CoversClass(RuleEffects::class)]
#[CoversClass(HideOptionalFieldsResolvedByRules::class)]
final class ConditionalRenderingTest extends TestCase
{
	/**
	 * A contact form whose rules live in the schema: choosing "email" requires the
	 * email field and makes the phone field optional, and vice-versa.
	 */
	private function contactSchema(): Definition
	{
		$schema = new Definition('contact_us');
		$method = $schema->createEnumField('contact_method', ['email', 'phone']);
		$email = $schema->createEmailAddressField('email_address');
		$phone = $schema->createPhoneNumberField('phone_number', ['AU']);

		$schema->add($method, $email, $phone);
		$schema->addRules(
			$method->when()->equals('email')->then($email->makeRequired(), $phone->makeOptional()),
			$method->when()->equals('phone')->then($phone->makeRequired(), $email->makeOptional()),
		);

		return $schema;
	}

	private function render(Definition $schema, string $method, ?FormOptions $options = null): string
	{
		return (new FormRenderer())->render(
			$schema,
			$options ?? Forms::options(),
			$schema->resolve((object) ['contact_method' => $method]),
		);
	}

	#[Test]
	public function the_default_hook_hides_a_field_a_rule_made_optional(): void
	{
		$html = $this->render($this->contactSchema(), 'email');

		$this->assertMatchesRegularExpression('/data-name="phone_number"\s+hidden/', $html);
		$this->assertDoesNotMatchRegularExpression('/data-name="email_address"\s+hidden/', $html);
	}

	#[Test]
	public function swapping_the_choice_swaps_which_field_is_hidden(): void
	{
		$html = $this->render($this->contactSchema(), 'phone');

		$this->assertMatchesRegularExpression('/data-name="email_address"\s+hidden/', $html);
		$this->assertDoesNotMatchRegularExpression('/data-name="phone_number"\s+hidden/', $html);
	}

	#[Test]
	public function the_default_hide_hook_can_be_turned_off(): void
	{
		$options = Forms::options()->withoutBehaviour(HideOptionalFieldsResolvedByRules::class);

		$html = $this->render($this->contactSchema(), 'email', $options);

		$this->assertDoesNotMatchRegularExpression('/data-name="phone_number"\s+hidden/', $html);
	}

	/** `required` comes from the field as the rules left it on this request, not as authored. */
	#[Test]
	public function required_follows_what_the_rules_did(): void
	{
		$options = Forms::options()->withoutBehaviour(HideOptionalFieldsResolvedByRules::class);

		$html = $this->render($this->contactSchema(), 'email', $options);

		$this->assertMatchesRegularExpression('/name="email_address"[^>]*required/', $html);
		$this->assertDoesNotMatchRegularExpression('/name="phone_number\[number\]"[^>]*required/', $html);
	}

	#[Test]
	public function a_field_made_optional_by_the_author_is_not_hidden(): void
	{
		$schema = $this->contactSchema();
		$schema->add($schema->createBooleanField('newsletter')->makeOptional()); // author-optional, no rule targets it

		$html = $this->render($schema, 'email');

		// phone_number is hidden by a matched rule; newsletter (author-optional) is not.
		$this->assertMatchesRegularExpression('/data-name="phone_number"\s+hidden/', $html);
		$this->assertDoesNotMatchRegularExpression('/data-name="newsletter"\s+hidden/', $html);
	}

	/**
	 * Making an already-optional field optional changes nothing, so a rule records nothing for
	 * it — but a rule discarding the field's input is reason enough not to ask for it.
	 */
	#[Test]
	public function a_field_whose_input_a_rule_ignores_is_hidden_even_when_already_optional(): void
	{
		$schema = new Definition('booking');
		$whoFor = $schema->createEnumField('who_for', ['myself', 'someone_else']);
		$participant = $schema->createTextField('participant')->makeOptional();
		$schema->add($whoFor, $participant);
		$schema->addRule($whoFor->when()->notEquals('someone_else')->then($participant->makeOptional())->thenIgnore($participant));

		$myself = (new FormRenderer())->render($schema, Forms::options(), $schema->resolve((object) ['who_for' => 'myself']));
		$other = (new FormRenderer())->render($schema, Forms::options(), $schema->resolve((object) ['who_for' => 'someone_else']));

		$this->assertMatchesRegularExpression('/data-name="participant"\s+hidden/', $myself);
		$this->assertDoesNotMatchRegularExpression('/data-name="participant"\s+hidden/', $other);
	}

	#[Test]
	public function an_else_branch_that_makes_a_field_optional_hides_it_too(): void
	{
		$schema = new Definition('insurance');
		$parcel = $schema->createEnumField('parcel', ['small', 'large']);
		$insurance = $schema->createBooleanField('insurance');
		$schema->add($parcel, $insurance);
		$schema->addRule(
			$parcel->when()->equals('large')
				->then($insurance->makeRequired()->mustBeAccepted())
				->else($insurance->makeOptional()),
		);

		$small = (new FormRenderer())->render($schema, Forms::options(), $schema->resolve((object) ['parcel' => 'small']));
		$large = (new FormRenderer())->render($schema, Forms::options(), $schema->resolve((object) ['parcel' => 'large']));

		$this->assertMatchesRegularExpression('/data-name="insurance"\s+hidden/', $small);
		$this->assertDoesNotMatchRegularExpression('/data-name="insurance"\s+hidden/', $large);
		// once it must be ticked, the box is required
		$this->assertMatchesRegularExpression('/type="checkbox"[^>]*required/', $large);
	}

	#[Test]
	public function a_behaviour_can_see_what_the_rules_did(): void
	{
		$seen = new \ArrayObject();
		$behaviour = new class ($seen) implements ConditionUiBehaviour {
			/** @param \ArrayObject<string, bool> $seen */
			public function __construct(private readonly \ArrayObject $seen) {}

			public function apply(FieldRenderContext $context): void
			{
				$this->seen[(string) $context->field->name] = $context->madeOptionalByMatchedRule;
			}
		};

		$this->render($this->contactSchema(), 'email', Forms::options()->behaviours($behaviour));

		$this->assertSame(
			['contact_method' => false, 'email_address' => false, 'phone_number' => true],
			$seen->getArrayCopy(),
		);
	}

	#[Test]
	public function a_behaviour_that_references_a_missing_field_throws(): void
	{
		$behaviour = new class implements ConditionUiBehaviour {
			public function apply(FieldRenderContext $context): void
			{
				$context->schema->fields->getByName('does_not_exist');
			}
		};

		$this->expectException(UnknownField::class);

		$this->render($this->contactSchema(), 'email', Forms::options()->behaviours($behaviour));
	}
}
