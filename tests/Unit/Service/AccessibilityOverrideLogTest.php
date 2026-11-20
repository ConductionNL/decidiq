<?php

/**
 * Tests for AccessibilityOverrideLog.
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/document-accessibility-check/specs/document-accessibility-check/spec.md#requirement-req-005-aggregate-accessibility-report-per-body-and-period
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service;

use OCA\Decidiq\Service\AccessibilityOverrideLog;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * One countable record per override, carrying the body the aggregate report splits by.
 *
 * @covers \OCA\Decidiq\Service\AccessibilityOverrideLog
 *
 * @spec openspec/changes/document-accessibility-check/specs/document-accessibility-check/spec.md#requirement-req-005-aggregate-accessibility-report-per-body-and-period
 */
class AccessibilityOverrideLogTest extends TestCase {

	private ObjectServiceInterface&MockObject $objectService;
	private LoggerInterface&MockObject $logger;
	private AccessibilityOverrideLog $log;

	/**
	 * Set up the mocks.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->objectService = $this->createMock(ObjectServiceInterface::class);
		$this->logger        = $this->createMock(LoggerInterface::class);
		$this->log           = new AccessibilityOverrideLog(objectService: $this->objectService, logger: $this->logger);
	}//end setUp()

	/**
	 * An override.
	 *
	 * @return array<string, mixed>
	 */
	private static function override(): array {
		return ['reason' => 'Wettelijk termijngebonden', 'actor' => 'bram', 'overriddenAt' => '2026-06-02T10:00:00+00:00', 'reports' => ['r-1', 'r-2']];
	}//end override()

	/**
	 * The record takes the body and source of the first report naming them.
	 *
	 * @return void
	 */
	public function testTheRecordTakesTheFirstBodyAndSource(): void {
		$record = $this->log->recordOf(
			override: self::override(),
			reports: [
				['id' => 'r-1', 'governanceBody' => '', 'sourceObject' => 'item-1', 'sourceSchema' => 'agenda-item'],
				['id' => 'r-2', 'governanceBody' => 'raad', 'sourceObject' => 'item-2', 'sourceSchema' => 'agenda-item'],
			]
		);

		self::assertSame(
			[
				'reason' => 'Wettelijk termijngebonden',
				'actor' => 'bram',
				'overriddenAt' => '2026-06-02T10:00:00+00:00',
				'reports' => ['r-1', 'r-2'],
				'documentCount' => 2,
				'governanceBody' => 'raad',
				'sourceObject' => 'item-1',
				'sourceSchema' => 'agenda-item',
			],
			$record
		);
	}//end testTheRecordTakesTheFirstBodyAndSource()

	/**
	 * Without a body on any report the record carries none, rather than an empty one.
	 *
	 * @return void
	 */
	public function testNoBodyIsLeftOut(): void {
		$record = $this->log->recordOf(override: ['reason' => 'x'], reports: []);

		self::assertSame(['reason' => 'x', 'actor' => '', 'overriddenAt' => '', 'reports' => [], 'documentCount' => 0], $record);
	}//end testNoBodyIsLeftOut()

	/**
	 * The record is stored as an accessibility-override in the decidiq register.
	 *
	 * @return void
	 */
	public function testRecordStoresTheOverride(): void {
		$reports = [['id' => 'r-1', 'governanceBody' => 'raad', 'sourceObject' => 'meeting-1', 'sourceSchema' => 'meeting']];
		$this->objectService->expects(self::once())->method('saveObject')->with(
			$this->log->recordOf(override: self::override(), reports: $reports),
			self::callback(callback: static fn (ObjectSaveOptions $options): bool => $options->target->register === 'decidiq'
				&& $options->target->schema === 'accessibility-override' && $options->uuid === null),
			false,
			false
		);
		$this->logger->expects(self::never())->method('warning');

		self::assertTrue($this->log->record(override: self::override(), reports: $reports));
	}//end testRecordStoresTheOverride()

	/**
	 * A failed write is logged and reported, never thrown.
	 *
	 * @return void
	 */
	public function testAFailedWriteIsLoggedNotThrown(): void {
		$this->objectService->method('saveObject')->willThrowException(new RuntimeException('down'));
		$this->logger->expects(self::once())->method('warning')->with(
			self::stringContains('could not be logged'),
			['sourceObject' => 'meeting-1', 'error' => 'down']
		);

		self::assertFalse($this->log->record(override: self::override(), reports: [['sourceObject' => 'meeting-1']]));
	}//end testAFailedWriteIsLoggedNotThrown()
}//end class
