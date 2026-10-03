<?php
declare(strict_types=1);

namespace Meraki\Schema\Html\Support;

use Meraki\Schema\Message\Mf2\PackValidator;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The tests' own wording keeps their assertions still while the published pack's wording moves.
 * The price is that it can fall behind the core, and a key it lacks renders as no message at all
 * — so this fails the moment the core reports something the fixtures say nothing about.
 */
#[CoversNothing]
final class FixturePackTest extends TestCase
{
	private const DIRECTORY = __DIR__ . '/../fixtures/lang';

	#[Test]
	public function the_fixture_pack_is_well_formed(): void
	{
		$this->assertSame([], (new PackValidator())->check(self::DIRECTORY));
	}

	#[Test]
	public function the_fixture_pack_covers_everything_the_core_reports(): void
	{
		$this->assertSame([], (new PackValidator())->missing(self::DIRECTORY)['en'] ?? []);
	}
}
