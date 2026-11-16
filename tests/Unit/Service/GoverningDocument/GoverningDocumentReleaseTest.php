<?php

/**
 * Tests for GoverningDocumentRelease.
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\Service\GoverningDocument
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/the-decision-as-a-walked-process/specs/decision-as-a-walked-process/spec.md#requirement-req-dwp-006-a-document-takes-effect-on-a-date-it-was-given
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service\GoverningDocument;

use DateTime;
use DateTimeImmutable;
use OCA\Decidiq\Service\GoverningDocument\GoverningDocumentRelease;
use OCA\Decidiq\Service\RegisterObjectStore;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The release on a frozen clock: approved documents take effect on their date, others are overdue.
 *
 * @covers \OCA\Decidiq\Service\GoverningDocument\GoverningDocumentRelease
 *
 * @spec openspec/changes/the-decision-as-a-walked-process/specs/decision-as-a-walked-process/spec.md#requirement-req-dwp-006-a-document-takes-effect-on-a-date-it-was-given
 */
class GoverningDocumentReleaseTest extends TestCase {

	private RegisterObjectStore&MockObject $store;
	private LoggerInterface&MockObject $logger;
	private GoverningDocumentRelease $release;

	/**
	 * A release on a clock frozen at 1 January 2027, 06:00.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->store  = $this->createMock(RegisterObjectStore::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new DateTime('2027-01-01T06:00:00+01:00'));
		$this->release = new GoverningDocumentRelease(store: $this->store, time: $time, logger: $this->logger);
	}//end setUp()

	/**
	 * A verordening adopted for 1 January takes effect on the job of 1 January.
	 *
	 * @return void
	 */
	public function testAnApprovedDocumentTakesEffectOnItsDate(): void {
		$this->store->method('findAll')->with(schema: 'governing-document', filters: [])->willReturn([
			['id' => 'verordening', 'status' => 'adopted', 'plannedEffectiveDate' => '2027-01-01'],
		]);
		$this->store->expects(self::once())->method('patch')->with(
			schema: 'governing-document',
			data: ['effectiveDate' => '2027-01-01'],
			uuid: 'verordening'
		)->willReturn([]);

		self::assertSame(['released' => ['verordening'], 'overdue' => [], 'failed' => []], $this->release->sweep());
	}//end testAnApprovedDocumentTakesEffectOnItsDate()

	/**
	 * A document with yesterday's date and no approval stays as it is and is overdue.
	 *
	 * @return void
	 */
	public function testAnUnapprovedDocumentIsLeftAloneAndOverdue(): void {
		$this->store->method('findAll')->willReturn([
			['id' => 'concept', 'status' => 'in-preparation', 'plannedEffectiveDate' => '2026-12-31'],
		]);
		$this->store->expects(self::never())->method('patch');

		self::assertSame(['released' => [], 'overdue' => ['concept'], 'failed' => []], $this->release->sweep());
	}//end testAnUnapprovedDocumentIsLeftAloneAndOverdue()

	/**
	 * Nothing is due before its date, twice, without a date, or once lapsed.
	 *
	 * @return void
	 */
	public function testWhatIsNotDue(): void {
		$today = '2027-01-01';
		self::assertNull($this->release->dueFor(document: ['status' => 'adopted', 'plannedEffectiveDate' => '2027-01-02'], today: $today));
		self::assertNull($this->release->dueFor(document: ['status' => 'adopted', 'plannedEffectiveDate' => '2026-12-01', 'effectiveDate' => '2026-12-01'], today: $today));
		self::assertNull($this->release->dueFor(document: ['status' => 'adopted'], today: $today));
		self::assertNull($this->release->dueFor(document: ['status' => 'adopted', 'plannedEffectiveDate' => 'soon'], today: $today));
		self::assertNull($this->release->dueFor(document: ['status' => 'lapsed', 'plannedEffectiveDate' => '2026-12-01'], today: $today));
		self::assertSame('release', $this->release->dueFor(document: ['status' => 'in-force', 'plannedEffectiveDate' => '2026-12-01T00:00:00+00:00'], today: $today));
		self::assertSame('overdue', $this->release->dueFor(document: ['plannedEffectiveDate' => '2027-01-01'], today: $today));
	}//end testWhatIsNotDue()

	/**
	 * The run's own moment wins over the clock, and the id may sit in @self.
	 *
	 * @return void
	 */
	public function testTheRunMomentAndTheSelfId(): void {
		$this->store->method('findAll')->willReturn([
			['@self' => ['id' => 'reglement'], 'status' => 'adopted', 'plannedEffectiveDate' => '2027-03-01'],
		]);
		$this->store->expects(self::once())->method('patch')->with(
			schema: 'governing-document',
			data: ['effectiveDate' => '2027-03-01'],
			uuid: 'reglement'
		)->willReturn([]);

		self::assertSame(['reglement'], $this->release->sweep(now: new DateTimeImmutable('2027-03-01'))['released']);
	}//end testTheRunMomentAndTheSelfId()

	/**
	 * A document that cannot be saved is logged and left for the next run.
	 *
	 * @return void
	 */
	public function testAFailedSaveIsLoggedAndLeft(): void {
		$this->store->method('findAll')->willReturn([
			['id' => 'verordening', 'status' => 'adopted', 'plannedEffectiveDate' => '2027-01-01'],
		]);
		$this->store->method('patch')->willThrowException(new RuntimeException('down'));
		$this->logger->expects(self::once())->method('error')->with(
			self::stringContains('could not take effect'),
			['document' => 'verordening', 'plannedEffectiveDate' => '2027-01-01', 'reason' => 'down']
		);

		self::assertSame(['released' => [], 'overdue' => [], 'failed' => ['verordening']], $this->release->sweep());
	}//end testAFailedSaveIsLoggedAndLeft()
}//end class
