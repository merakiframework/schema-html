<?php
declare(strict_types=1);

namespace Meraki\Schema\Html\Request;

use Meraki\Schema\Facade;
use Meraki\Schema\Html\Input;
use Meraki\Schema\Html\SettledValues;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\CoversClass;
use stdClass;

#[Group('http')]
#[CoversClass(PayloadMapper::class)]
#[CoversClass(SettledValues::class)]
#[CoversClass(SequentialRowKeys::class)]
final class PayloadMapperTest extends TestCase
{
	private function map(Facade $schema, array $wire): object
	{
		return (new PayloadMapper())->map($schema, new Input($wire));
	}

	#[Test]
	public function a_structured_fields_parts_become_a_record(): void
	{
		$schema = new Facade('checkout');
		$schema->add($schema->createMoneyField('price', ['AUD', 'NZD']));

		$payload = $this->map($schema, ['price' => ['currency' => 'AUD', 'amount' => '12.50']]);

		$this->assertEquals((object) ['currency' => 'AUD', 'amount' => '12.50'], $payload->price);
	}

	#[Test]
	public function a_settled_part_the_form_left_out_is_filled_back_in(): void
	{
		$schema = new Facade('checkout');
		$schema->add(
			$schema->createAddressField('billing', ['AU']),
			$schema->createMoneyField('price', ['AUD']),
			$schema->createPhoneNumberField('mobile', ['AU']),
		);

		$payload = $this->map($schema, [
			'billing' => ['line1' => '1 King St', 'locality' => 'Brisbane'],
			'price' => ['amount' => '12.50'],
			'mobile' => ['number' => '0412 345 678'],
		]);

		$this->assertSame('AU', $payload->billing->country);
		$this->assertSame('AUD', $payload->price->currency);
		$this->assertSame('AU', $payload->mobile->country);
	}

	#[Test]
	public function a_submitted_settled_part_is_left_for_the_core_to_judge(): void
	{
		$schema = new Facade('checkout');
		$schema->add($schema->createAddressField('billing', ['AU']));

		$payload = $this->map($schema, ['billing' => ['line1' => '1 King St', 'country' => 'NZ']]);

		$this->assertSame('NZ', $payload->billing->country);
	}

	/**
	 * An address that is all country and no street was never filled in; saying it is missing
	 * beats saying it has no street.
	 */
	#[Test]
	public function a_record_with_nothing_but_settled_parts_is_not_submitted(): void
	{
		$schema = new Facade('checkout');
		$schema->add(
			$schema->createAddressField('billing', ['AU']),
			$schema->createMoneyField('price', ['AUD']),
		);

		$payload = $this->map($schema, [
			'billing' => ['line1' => '', 'locality' => '', 'country' => 'AU'],
			'price' => ['amount' => '', 'currency' => 'AUD'],
		]);

		$this->assertEquals(new stdClass(), $payload);
		$this->assertTrue($schema->validate($payload)->forField('billing')?->wasMissing());
	}

	#[Test]
	public function a_collection_becomes_named_rows_of_records(): void
	{
		$schema = new Facade('booking');
		$schema->add($schema->createCollectionField(
			'lessons',
			$schema->createDateField('date'),
			$schema->createTimeField('time'),
		));

		$payload = $this->map($schema, ['lessons' => [
			'row1' => ['date' => '2026-01-01', 'time' => '10:00'],
			'row2' => ['date' => '2026-01-02', 'time' => '11:00'],
		]]);

		$this->assertEquals([
			'row1' => (object) ['date' => '2026-01-01', 'time' => '10:00'],
			'row2' => (object) ['date' => '2026-01-02', 'time' => '11:00'],
		], $payload->lessons);
		$this->assertFalse($schema->validate($payload)->anyFailed());
	}

	/** The spare row a form always offers arrives empty; the core would take it as intentional. */
	#[Test]
	public function blank_rows_are_dropped(): void
	{
		$schema = new Facade('booking');
		$schema->add($schema->createCollectionField(
			'lessons',
			$schema->createDateField('date'),
			$schema->createBooleanField('paid'),
		));

		$payload = $this->map($schema, ['lessons' => [
			'row1' => ['date' => '2026-01-01', 'paid' => 'on'],
			'row2' => ['date' => '', 'paid' => '0'], // the spare row, with its checkbox's sentinel
		]]);

		$this->assertSame(['row1'], array_keys($payload->lessons));
		$this->assertEquals((object) ['date' => '2026-01-01', 'paid' => true], $payload->lessons['row1']);
	}

	#[Test]
	public function a_positional_list_is_passed_on_for_the_core_to_refuse(): void
	{
		$schema = new Facade('booking');
		$schema->add($schema->createCollectionField('lessons', $schema->createDateField('date')));

		$payload = $this->map($schema, ['lessons' => [['date' => '2026-01-01']]]);

		$this->assertSame([0], array_keys($payload->lessons));
		$this->assertTrue($schema->validate($payload)->forField('lessons')?->wasUnreadable());
	}

	#[Test]
	public function a_checkbox_reads_as_true_when_ticked_and_false_from_its_sentinel(): void
	{
		$schema = new Facade('prefs');
		$schema->add(
			$schema->createBooleanField('ticked'),
			$schema->createBooleanField('unticked'),
			$schema->createBooleanField('normalised'),
			$schema->createBooleanField('absent')->makeOptional(),
		);

		$payload = $this->map($schema, ['ticked' => 'on', 'unticked' => '0', 'normalised' => true]);

		$this->assertTrue($payload->ticked);
		$this->assertFalse($payload->unticked);
		$this->assertTrue($payload->normalised);
		$this->assertObjectNotHasProperty('absent', $payload);
		$this->assertFalse($schema->validate($payload)->anyFailed());
	}

	#[Test]
	public function a_value_of_on_is_only_a_checkbox_to_a_boolean(): void
	{
		$schema = new Facade('prefs');
		$schema->add($schema->createEnumField('switch', ['on', 'off']));

		$payload = $this->map($schema, ['switch' => 'on']);

		$this->assertSame('on', $payload->switch);
	}

	#[Test]
	public function anything_the_schema_does_not_have_is_left_out(): void
	{
		$schema = new Facade('signup');
		$schema->add($schema->createNameField('name'));

		$payload = $this->map($schema, ['name' => 'Jane', '_method' => 'put', 'admin' => '1']);

		$this->assertEquals((object) ['name' => 'Jane'], $payload);
	}

	#[Test]
	public function an_uploaded_file_becomes_the_record_a_file_field_reads(): void
	{
		$schema = new Facade('upload');
		$schema->add($schema->createFileField('resume'));

		$payload = $this->map($schema, ['resume' => ['name' => 'cv.pdf', 'type' => 'application/pdf', 'size' => 1024]]);

		$this->assertFalse($schema->validate($payload)->anyFailed());
	}

	#[Test]
	public function rows_are_named_one_past_the_highest_name_in_use(): void
	{
		$keys = new SequentialRowKeys();

		$this->assertSame('row1', $keys->next([]));
		$this->assertSame('row4', $keys->next(['row1', 'row3', 'other']));
		$this->assertSame('line2', (new SequentialRowKeys('line'))->next(['line1']));
	}

	#[Test]
	public function a_row_name_prefix_must_be_able_to_start_a_name(): void
	{
		$this->expectException(\InvalidArgumentException::class);

		new SequentialRowKeys('1st');
	}
}
