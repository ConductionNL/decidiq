<?php

/**
 * The HTTP status each refusal exception answers with, and what it chains.
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\Exception
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Exception;


use OCA\Decidiq\Exception\BroadcastRefusedException;
use OCA\Decidiq\Exception\CaseSystemException;
use OCA\Decidiq\Exception\ExportBundleException;
use OCA\Decidiq\Exception\GuestInvitationRefusedException;
use OCA\Decidiq\Exception\PaperSummaryRefusedException;
use OCA\Decidiq\Exception\SubstitutionRefusedException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Each refusal answers with its own default status unless told otherwise.
 *
 * @covers \OCA\Decidiq\Exception\BroadcastRefusedException
 * @covers \OCA\Decidiq\Exception\CaseSystemException
 * @covers \OCA\Decidiq\Exception\ExportBundleException
 * @covers \OCA\Decidiq\Exception\GuestInvitationRefusedException
 * @covers \OCA\Decidiq\Exception\PaperSummaryRefusedException
 * @covers \OCA\Decidiq\Exception\SubstitutionRefusedException
 */
class StatusRefusalExceptionsTest extends TestCase {

	/**
	 * The default status of each refusal.
	 *
	 * @return void
	 */
	public function testEachRefusalHasItsDefaultStatus(): void {
		self::assertSame(409, (new BroadcastRefusedException(message: 'm'))->getStatus());
		self::assertSame(422, (new CaseSystemException(message: 'm'))->getStatus());
		self::assertSame(422, (new ExportBundleException(message: 'm'))->getStatus());
		self::assertSame(422, (new GuestInvitationRefusedException(message: 'm'))->getStatus());
		self::assertSame(403, (new PaperSummaryRefusedException(message: 'm'))->getStatus());
	}//end testEachRefusalHasItsDefaultStatus()

	/**
	 * A given status is answered instead, and the message is kept.
	 *
	 * @return void
	 */
	public function testAGivenStatusIsAnswered(): void {
		$refusals = [
			new BroadcastRefusedException(message: 'Broadcast', status: 404),
			new CaseSystemException(message: 'Case system', status: 502),
			new ExportBundleException(message: 'Export', status: 404),
			new GuestInvitationRefusedException(message: 'Guest', status: 403),
			new PaperSummaryRefusedException(message: 'Paper', status: 404),
			new SubstitutionRefusedException(message: 'Substitution', status: 409),
		];

		self::assertSame([404, 502, 404, 403, 404, 409], array_map(static fn ($e): int => $e->getStatus(), $refusals));
		self::assertSame(
			['Broadcast', 'Case system', 'Export', 'Guest', 'Paper', 'Substitution'],
			array_map(static fn ($e): string => $e->getMessage(), $refusals)
		);
		foreach ($refusals as $refusal) {
			self::assertInstanceOf(RuntimeException::class, $refusal);
		}
	}//end testAGivenStatusIsAnswered()

	/**
	 * The case system and export refusals carry their status as the code, and chain the cause.
	 *
	 * @return void
	 */
	public function testTheChainingRefusalsCarryCodeAndCause(): void {
		$cause = new RuntimeException('upstream down');

		$case = new CaseSystemException(message: 'Case system unavailable', status: 503, previous: $cause);
		$export = new ExportBundleException(message: 'Bundle failed', status: 500, previous: $cause);

		self::assertSame(503, $case->getCode());
		self::assertSame($cause, $case->getPrevious());
		self::assertSame(500, $export->getCode());
		self::assertSame($cause, $export->getPrevious());
		self::assertSame(422, (new CaseSystemException(message: 'm'))->getCode());
		self::assertNull((new ExportBundleException(message: 'm'))->getPrevious());
	}//end testTheChainingRefusalsCarryCodeAndCause()

	/**
	 * The other refusals keep code zero, whatever their status.
	 *
	 * @return void
	 */
	public function testTheOtherRefusalsKeepCodeZero(): void {
		self::assertSame(0, (new BroadcastRefusedException(message: 'm', status: 404))->getCode());
		self::assertSame(0, (new GuestInvitationRefusedException(message: 'm'))->getCode());
		self::assertSame(0, (new PaperSummaryRefusedException(message: 'm'))->getCode());
		self::assertSame(0, (new SubstitutionRefusedException(message: 'm', status: 409))->getCode());
	}//end testTheOtherRefusalsKeepCodeZero()
}//end class
