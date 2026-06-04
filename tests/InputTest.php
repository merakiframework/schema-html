<?php
declare(strict_types=1);

namespace Meraki\Schema\Html;

use Meraki\Schema\Facade;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\CoversClass;

#[Group('http')]
#[CoversClass(Input::class)]
final class InputTest extends TestCase
{
	protected function tearDown(): void
	{
		$_POST = [];
		$_FILES = [];
	}

	#[Test]
	public function empty_strings_become_null_and_checkboxes_become_true(): void
	{
		$input = new Input(['name' => 'Jane', 'bio' => '', 'subscribe' => 'on']);

		$this->assertSame('Jane', $input->get('name'));
		$this->assertNull($input->get('bio'));
		$this->assertTrue($input->get('subscribe'));
	}

	#[Test]
	public function presence_is_recoverable_even_for_empty_fields(): void
	{
		$input = new Input(['name' => 'Jane', 'bio' => '']);

		$this->assertTrue($input->has('name'));        // submitted with a value
		$this->assertTrue($input->has('bio'));         // submitted but empty
		$this->assertNull($input->get('bio'));
		$this->assertFalse($input->has('dob'));        // not submitted at all
		$this->assertSame('fallback', $input->get('missing', 'fallback'));
	}

	#[Test]
	public function nested_values_are_accessible_by_chained_get_array_and_object(): void
	{
		$input = new Input(['price' => ['currency' => 'AUD', 'amount' => '1500']]);

		$this->assertSame('1500', $input->get('price', [])->get('amount', '0.00'));
		$this->assertSame('AUD', $input['price']['currency']);
		$this->assertSame('1500', $input->price->amount);
		$this->assertSame('0.00', $input->get('nope', [])->get('amount', '0.00'));
	}

	#[Test]
	public function it_is_read_only(): void
	{
		$input = new Input(['a' => 'b']);

		$this->expectException(\LogicException::class);

		$input['a'] = 'c';
	}

	#[Test]
	public function from_globals_merges_post_and_a_single_uploaded_file(): void
	{
		$_POST = ['full_name' => 'Jane', 'bio' => '', 'subscribe' => 'on'];
		$_FILES = ['resume' => [
			'name' => 'cv.pdf',
			'type' => 'application/pdf',
			'size' => 1024,
			'tmp_name' => '/tmp/php123',
			'error' => UPLOAD_ERR_OK,
		]];

		$input = Input::fromGlobals();

		$this->assertSame('Jane', $input->get('full_name'));
		$this->assertNull($input->get('bio'));
		$this->assertTrue($input->get('subscribe'));
		$this->assertSame(
			['name' => 'cv.pdf', 'type' => 'application/pdf', 'size' => 1024],
			$input->get('resume')->toArray(),
		);
	}

	#[Test]
	public function from_globals_normalizes_multiple_files_and_skips_empty_uploads(): void
	{
		$_FILES = [
			'docs' => [
				'name' => ['a.pdf', 'b.pdf'],
				'type' => ['application/pdf', 'application/pdf'],
				'size' => [10, 20],
				'tmp_name' => ['/tmp/a', '/tmp/b'],
				'error' => [UPLOAD_ERR_OK, UPLOAD_ERR_OK],
			],
			'avatar' => [
				'name' => '',
				'type' => '',
				'size' => 0,
				'tmp_name' => '',
				'error' => UPLOAD_ERR_NO_FILE,
			],
		];

		$input = Input::fromGlobals();

		$this->assertSame([
			['name' => 'a.pdf', 'type' => 'application/pdf', 'size' => 10],
			['name' => 'b.pdf', 'type' => 'application/pdf', 'size' => 20],
		], $input->get('docs')->toArray());
		$this->assertFalse($input->has('avatar'));
	}

	#[Test]
	public function it_builds_from_a_psr7_request_merging_parsed_body_and_uploads(): void
	{
		$file = $this->createMock(UploadedFileInterface::class);
		$file->method('getError')->willReturn(UPLOAD_ERR_OK);
		$file->method('getClientFilename')->willReturn('cv.pdf');
		$file->method('getClientMediaType')->willReturn('application/pdf');
		$file->method('getSize')->willReturn(2048);

		$request = $this->createMock(ServerRequestInterface::class);
		$request->method('getParsedBody')->willReturn(['full_name' => 'Jane', 'bio' => '']);
		$request->method('getUploadedFiles')->willReturn(['resume' => $file]);

		$input = Input::fromPsrRequest($request);

		$this->assertSame('Jane', $input->get('full_name'));
		$this->assertNull($input->get('bio'));
		$this->assertSame(
			['name' => 'cv.pdf', 'type' => 'application/pdf', 'size' => 2048],
			$input->get('resume')->toArray(),
		);
	}

	#[Test]
	public function its_array_form_feeds_schema_validation(): void
	{
		$schema = new Facade('checkout');
		$schema->addMoneyField('price', ['AUD' => 2])->minOf('AUD', '0.00');
		$schema->addBooleanField('subscribe')->makeOptional();
		$schema->addEmailAddressField('email')->makeOptional();

		$input = new Input([
			'price' => ['currency' => 'AUD', 'amount' => '1500'],
			'subscribe' => 'on',
			'email' => '',
		]);

		$result = $schema->validate($input->toArray());

		$this->assertFalse($result->failed());
	}
}
