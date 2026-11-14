<?php

/**
 * Unit tests for the StageLapseRun value object.
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service;

use DateTimeImmutable;
use DateTimeZone;
use OCA\Decidiq\Service\StageLapseRun;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Decidiq\Service\StageLapseRun
 */
class StageLapseRunTest extends TestCase {

	public function testReadsUuidAndActorFromTheStage(): void {
		$run = new StageLapseRun(
			stage: ['id' => 'stage-1', 'assignedPerson' => 'alice'],
			now: new DateTimeImmutable('2026-03-01T10:00:00+00:00')
		);

		$this->assertSame('stage-1', $run->uuid());
		$this->assertSame('alice', $run->actor());

	}//end testReadsUuidAndActorFromTheStage()

	public function testMissingFieldsReadAsEmptyStrings(): void {
		$run = new StageLapseRun(stage: [], now: new DateTimeImmutable('2026-03-01T10:00:00+00:00'));

		$this->assertSame('', $run->uuid());
		$this->assertSame('', $run->actor());

	}//end testMissingFieldsReadAsEmptyStrings()

	public function testNonStringIdsAreCastToString(): void {
		$run = new StageLapseRun(stage: ['id' => 42], now: new DateTimeImmutable('2026-03-01T10:00:00+00:00'));

		$this->assertSame('42', $run->uuid());

	}//end testNonStringIdsAreCastToString()

	public function testStampedAtIsAtomInTheClockOwnZone(): void {
		$now = new DateTimeImmutable('2026-03-01 10:15:30', new DateTimeZone('Europe/Amsterdam'));
		$run = new StageLapseRun(stage: [], now: $now);

		$this->assertSame('2026-03-01T10:15:30+01:00', $run->stampedAt());

	}//end testStampedAtIsAtomInTheClockOwnZone()
}//end class
