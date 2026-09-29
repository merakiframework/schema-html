<?php
declare(strict_types=1);

namespace Meraki\Schema\Html;

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
	public function an_untouched_box_submitting_an_empty_string_reads_as_nothing(): void
	{
		$input = new Input(['name' => 'Jane', 'bio' => '', 'price' => ['amount' => '', 'currency' => 'AUD']]);

		$this->assertSame(
			['name' => 'Jane', 'bio' => null, 'price' => ['amount' => null, 'currency' => 'AUD']],
			$input->toArray(),
		);
	}

	/**
	 * Only a Boolean field means "checked" by `on`, and only the schema knows which fields
	 * those are — so the conversion belongs to the payload mapper, not here.
	 */
	#[Test]
	public function a_value_of_on_is_left_as_it_is(): void
	{
		$input = new Input(['subscribe' => 'on', 'switch' => 'on']);

		$this->assertSame(['subscribe' => 'on', 'switch' => 'on'], $input->toArray());
	}

	#[Test]
	public function already_normalised_data_passes_through_unchanged(): void
	{
		$data = ['name' => 'Jane', 'bio' => null, 'subscribe' => true, 'age' => 42];

		$this->assertSame($data, (new Input($data))->toArray());
		$this->assertSame($data, (new Input((new Input($data))->toArray()))->toArray());
	}

	#[Test]
	public function from_globals_merges_post_and_a_single_uploaded_file(): void
	{
		$_POST = ['full_name' => 'Jane', 'bio' => ''];
		$_FILES = [
			'resume' => [
				'name' => 'cv.pdf',
				'type' => 'application/pdf',
				'size' => 1024,
				'tmp_name' => '/tmp/php123',
				'error' => UPLOAD_ERR_OK,
			],
		];

		$this->assertSame([
			'full_name' => 'Jane',
			'bio' => null,
			'resume' => ['name' => 'cv.pdf', 'type' => 'application/pdf', 'size' => 1024],
		], Input::fromGlobals()->toArray());
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

		$data = Input::fromGlobals()->toArray();

		$this->assertSame([
			['name' => 'a.pdf', 'type' => 'application/pdf', 'size' => 10],
			['name' => 'b.pdf', 'type' => 'application/pdf', 'size' => 20],
		], $data['docs']);
		$this->assertArrayNotHasKey('avatar', $data);
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

		$this->assertSame([
			'full_name' => 'Jane',
			'bio' => null,
			'resume' => ['name' => 'cv.pdf', 'type' => 'application/pdf', 'size' => 2048],
		], Input::fromPsrRequest($request)->toArray());
	}
}
