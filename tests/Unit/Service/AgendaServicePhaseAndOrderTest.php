<?php

/**
 * Unit tests for AgendaService: the default BOB phase and the notice
 * suppression while a reorder writes its items.
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
 *
 * @spec openspec/changes/p2-agenda-management/tasks.md#task-1.1
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service;

use OCA\Decidiq\Service\AgendaNotices;
use OCA\Decidiq\Service\AgendaService;
use OCA\Decidiq\Service\NotificationPreferenceService;
use OCA\Decidiq\Service\ParticipantResolver;
use OCA\Decidiq\Service\PublicationEventRecorder;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\L10N\IFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for the agenda phase and order paths.
 *
 * @covers \OCA\Decidiq\Service\AgendaService
 * @uses   \OCA\Decidiq\Service\AgendaRules
 * @uses   \OCA\Decidiq\Service\AgendaNotices
 */
class AgendaServicePhaseAndOrderTest extends TestCase {

	/**
	 * The object service double.
	 *
	 * @var ObjectServiceInterface&MockObject
	 */
	private ObjectServiceInterface&MockObject $objects;

	/**
	 * The logger double.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * The service under test.
	 *
	 * @var AgendaService
	 */
	private AgendaService $service;

	/**
	 * Build the service over doubles.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->objects = $this->createMock(ObjectServiceInterface::class);
		$this->logger  = $this->createMock(LoggerInterface::class);
		$this->service = new AgendaService(
			objectService: $this->objects,
			notices: new AgendaNotices(preferences: $this->createMock(NotificationPreferenceService::class), l10nFactory: $this->createMock(IFactory::class), logger: $this->logger),
			logger: $this->logger,
			participantResolver: $this->createMock(ParticipantResolver::class),
			eventRecorder: $this->createMock(PublicationEventRecorder::class),
		);
	}//end setUp()

	/**
	 * An item without a status is in beeldvorming and moves to oordeelsvorming,
	 * and the log names both phases.
	 *
	 * @return void
	 */
	public function testAnItemWithoutStatusAdvancesFromBeeldvorming(): void {
		$item = $this->createMock(ObjectEntity::class);
		$item->method('getObject')->willReturn(['title' => 'No status']);
		$this->objects->method('find')->willReturn($item);
		$this->objects->expects($this->once())->method('patchObject')->with(
			objectId: 'ai-1',
			data: ['phase' => 'oordeelsvorming'],
			register: 'decidiq',
			schema: 'agenda-item',
		);
		$this->logger->expects($this->once())->method('info')->with(
			'BOB phase advanced for agenda item {id}: {from} to {to}',
			['id' => 'ai-1', 'from' => 'beeldvorming', 'to' => 'oordeelsvorming']
		);

		$this->service->advanceBobPhase('ai-1');
	}//end testAnItemWithoutStatusAdvancesFromBeeldvorming()

	/**
	 * The declared phase wins over a legacy status, and the next phase is
	 * written to `phase` alone (REQ-LIV-010).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/agenda-item-phase-is-declared/specs/agenda-live-management/spec.md#requirement-req-liv-010-the-agenda-item-declares-its-phase
	 */
	public function testTheDeclaredPhaseWinsOverALegacyStatus(): void {
		$item = $this->createMock(ObjectEntity::class);
		$item->method('getObject')->willReturn(['itemType' => 'decision', 'phase' => 'oordeelsvorming', 'status' => 'beeldvorming']);
		$this->objects->method('find')->willReturn($item);
		$this->objects->expects($this->once())->method('patchObject')->with(
			objectId: 'ai-1',
			data: ['phase' => 'besluitvorming'],
			register: 'decidiq',
			schema: 'agenda-item',
		);

		$this->service->advanceBobPhase('ai-1');
	}//end testTheDeclaredPhaseWinsOverALegacyStatus()

	/**
	 * An item from before the upgrade, whose phase is still in `status`,
	 * advances from that phase.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/agenda-item-phase-is-declared/specs/agenda-live-management/spec.md#requirement-req-liv-010-the-agenda-item-declares-its-phase
	 */
	public function testALegacyStatusPhaseStillAdvances(): void {
		$item = $this->createMock(ObjectEntity::class);
		$item->method('getObject')->willReturn(['itemType' => 'decision', 'status' => 'besluitvorming']);
		$this->objects->method('find')->willReturn($item);
		$this->objects->expects($this->once())->method('patchObject')->with(
			objectId: 'ai-1',
			data: ['phase' => 'completed'],
			register: 'decidiq',
			schema: 'agenda-item',
		);

		$this->service->advanceBobPhase('ai-1');
	}//end testALegacyStatusPhaseStillAdvances()

	/**
	 * A completed item cannot be advanced, and nothing is written.
	 *
	 * @return void
	 */
	public function testACompletedPhaseIsFinal(): void {
		$item = $this->createMock(ObjectEntity::class);
		$item->method('getObject')->willReturn(['itemType' => 'decision', 'phase' => 'completed']);
		$this->objects->method('find')->willReturn($item);
		$this->objects->expects($this->never())->method('patchObject');

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage("already at final phase 'completed'");
		$this->service->advanceBobPhase('ai-1');
	}//end testACompletedPhaseIsFinal()

	/**
	 * While a reorder writes its items the service says it is suppressing item notices, and not after.
	 *
	 * @return void
	 */
	public function testReorderSuppressesItemNoticesWhileItWrites(): void {
		$this->objects->method('findAll')->willReturn([['id' => 'ai-1'], ['id' => 'ai-2']]);
		$seen = [];
		$this->objects->method('patchObject')->willReturnCallback(
			function () use (&$seen): ObjectEntity {
				$seen[] = $this->service->isSuppressingItemNotices();
				return $this->createMock(ObjectEntity::class);
			}
		);

		$this->service->reorderItems('m-1', ['ai-2', 'ai-1']);

		self::assertSame([true, true], $seen);
		self::assertFalse($this->service->isSuppressingItemNotices());
	}//end testReorderSuppressesItemNoticesWhileItWrites()
}//end class
