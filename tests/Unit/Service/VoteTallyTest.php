<?php

/**
 * Unit tests for VoteTally.
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\Service
 *
 * @spec openspec/specs/voting-system/spec.md
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service;

use OCA\Decidiq\Service\VoteTally;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the vote tally and its base under each abstention handling.
 *
 * @covers \OCA\Decidiq\Service\VoteTally
 *
 * @spec openspec/specs/voting-system/spec.md
 */
class VoteTallyTest extends TestCase {
	public function testTotalIncludesAbstentions(): void {
		$tally = new VoteTally(5, 3);
		$tally->abstentions = 2;

		$this->assertSame(10, $tally->total());
	}

	public function testAbstentionsDefaultToZero(): void {
		$this->assertSame(8, (new VoteTally(5, 3))->total());
	}

	public function testCountedAbstentionsAreInTheBase(): void {
		$tally = new VoteTally(5, 3);
		$tally->abstentions = 2;

		$this->assertSame(10, $tally->base('count'));
	}

	public function testOtherwiseAbstentionsAreLeftOutOfTheBase(): void {
		$tally = new VoteTally(5, 3);
		$tally->abstentions = 2;

		$this->assertSame(8, $tally->base('exclude'));
		$this->assertSame(8, $tally->base(''));
	}
}
