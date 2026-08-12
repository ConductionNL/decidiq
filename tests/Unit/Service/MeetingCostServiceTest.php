<?php

/**
 * Unit tests for MeetingCostService (meeting-efficiency cost calculator).
 *
 * @category Test
 * @package  OCA\Decidesk\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/meeting-efficiency/spec.md
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Decidesk\Tests\Unit\Service;

use OCA\Decidesk\Service\MeetingCostService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for the pure cost formula and server-side cost resolution.
 *
 * @spec openspec/specs/meeting-efficiency/spec.md
 */
class MeetingCostServiceTest extends TestCase {

	/**
	 * Mock DI container.
	 *
	 * @var ContainerInterface&MockObject
	 */
	private ContainerInterface&MockObject $container;

	/**
	 * Mock logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Mock ObjectService.
	 *
	 * @var ObjectService&MockObject
	 */
	private ObjectService&MockObject $objectService;

	/**
	 * Service under test.
	 *
	 * @var MeetingCostService
	 */
	private MeetingCostService $service;

	/**
	 * Set up fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->container = $this->createMock(originalClassName: ContainerInterface::class);
		$this->logger = $this->createMock(originalClassName: LoggerInterface::class);
		$this->objectService = $this->createMock(originalClassName: ObjectService::class);

		$this->container->method('get')
			->with('OCA\OpenRegister\Service\ObjectService')
			->willReturn($this->objectService);

		$this->service = new MeetingCostService(
			container: $this->container,
			logger: $this->logger,
		);

	}//end setUp()

	/**
	 * The pure formula matches the spec worked example (45 min x 12 x EUR 75 = 675).
	 *
	 * @return void
	 */
	public function testComputeCostMatchesSpecWorkedExample(): void {
		self::assertSame(
			expected: 675.0,
			actual: $this->service->computeCost(elapsedSeconds: (45 * 60), attendeeCount: 12, hourlyRate: 75.0)
		);

	}//end testComputeCostMatchesSpecWorkedExample()

	/**
	 * The formula clamps negatives and zero inputs to 0.
	 *
	 * @return void
	 */
	public function testComputeCostClampsNegativesAndZeros(): void {
		self::assertSame(expected: 0.0, actual: $this->service->computeCost(elapsedSeconds: -100, attendeeCount: 12, hourlyRate: 75.0));
		self::assertSame(expected: 0.0, actual: $this->service->computeCost(elapsedSeconds: 2700, attendeeCount: 0, hourlyRate: 75.0));
		self::assertSame(expected: 0.0, actual: $this->service->computeCost(elapsedSeconds: 2700, attendeeCount: 12, hourlyRate: 0.0));

	}//end testComputeCostClampsNegativesAndZeros()

	/**
	 * The formula is linear and rounds to two decimals.
	 *
	 * @return void
	 */
	public function testComputeCostRoundsToTwoDecimals(): void {
		// 10 minutes x 10 attendees x EUR 60 = 100.0 exactly after rounding.
		self::assertSame(expected: 100.0, actual: $this->service->computeCost(elapsedSeconds: 600, attendeeCount: 10, hourlyRate: 60.0));

	}//end testComputeCostRoundsToTwoDecimals()

	/**
	 * calculateForMeeting returns null when the body has no hourlyRate
	 * (nothing should be persisted).
	 *
	 * @return void
	 */
	public function testCalculateForMeetingReturnsNullWithoutRate(): void {
		$meeting = [
			'openedAt' => '2026-01-01T09:00:00+00:00',
			'closedAt' => '2026-01-01T10:00:00+00:00',
			'governanceBody' => 'body-uuid',
		];

		$bodyEntity = $this->createMock(originalClassName: ObjectEntity::class);
		$bodyEntity->method('getObject')->willReturn(['id' => 'body-uuid']); // no hourlyRate

		$this->objectService->method('find')->willReturn($bodyEntity);

		self::assertNull(actual: $this->service->calculateForMeeting(meetingId: 'm-uuid', meeting: $meeting));

	}//end testCalculateForMeetingReturnsNullWithoutRate()

	/**
	 * calculateForMeeting resolves rate + window + attendees and computes the cost.
	 *
	 * @return void
	 */
	public function testCalculateForMeetingComputesCostFromStoredData(): void {
		$meeting = [
			'openedAt' => '2026-01-01T09:00:00+00:00',
			'closedAt' => '2026-01-01T09:45:00+00:00', // 45 minutes
			'governanceBody' => 'body-uuid',
		];

		$bodyEntity = $this->createMock(originalClassName: ObjectEntity::class);
		$bodyEntity->method('getObject')->willReturn(['id' => 'body-uuid', 'hourlyRate' => 75]);

		$this->objectService->method('find')->willReturn($bodyEntity);

		// 12 participants found.
		$participants = [];
		for ($i = 0; $i < 12; $i++) {
			$participants[] = ['id' => 'p' . $i];
		}

		$this->objectService->method('findAll')->willReturn($participants);

		self::assertSame(
			expected: 675.0,
			actual: $this->service->calculateForMeeting(meetingId: 'm-uuid', meeting: $meeting)
		);

	}//end testCalculateForMeetingComputesCostFromStoredData()
}//end class
