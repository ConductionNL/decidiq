<?php

/**
 * Committee Step Agenda Listener Test
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/the-decision-as-a-walked-process/specs/decision-as-a-walked-process/spec.md (REQ-DWP-010)
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Listener;

use OCA\Decidiq\Kernel\Contracts\ListenerSchemaResolverInterface;
use OCA\Decidiq\Listener\CommitteeStepAgendaListener;
use OCA\Decidiq\Service\CommitteeAgendaPlacement;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Each save reaches the placement step it concerns, and a failure never breaks the save.
 *
 * @spec openspec/changes/the-decision-as-a-walked-process/specs/decision-as-a-walked-process/spec.md (REQ-DWP-010)
 */
class CommitteeStepAgendaListenerTest extends TestCase {

	/**
	 * The placement double.
	 *
	 * @var CommitteeAgendaPlacement&MockObject
	 */
	private CommitteeAgendaPlacement&MockObject $placement;

	/**
	 * Set up the placement double.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->placement = $this->createMock(originalClassName: CommitteeAgendaPlacement::class);

	}//end setUp()

	/**
	 * The listener for objects of one schema.
	 *
	 * @param string               $slug   The schema slug the resolver answers.
	 * @param LoggerInterface|null $logger The logger.
	 *
	 * @return CommitteeStepAgendaListener
	 */
	private function listener(string $slug, ?LoggerInterface $logger = null): CommitteeStepAgendaListener {
		$resolver = $this->createMock(originalClassName: ListenerSchemaResolverInterface::class);
		$resolver->method('schemaSlug')->willReturn($slug);

		return new CommitteeStepAgendaListener(
			placement: $this->placement,
			schemaResolver: $resolver,
			logger: ($logger ?? $this->createMock(originalClassName: LoggerInterface::class)),
		);

	}//end listener()

	/**
	 * An OpenRegister object entity, uuid obj-1.
	 *
	 * @param array<string, mixed> $payload The object.
	 *
	 * @return ObjectEntity
	 */
	private function entity(array $payload): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid('obj-1');
		$entity->setObject($payload);
		return $entity;

	}//end entity()

	/**
	 * A saved stage is offered for placement, with its uuid.
	 *
	 * @return void
	 */
	public function testASavedStageIsOfferedForPlacement(): void {
		$this->placement->expects($this->exactly(2))->method('placeStage')
			->with(['stepKind' => 'committee', 'id' => 'obj-1']);

		$listener = $this->listener(slug: 'decision-stage');
		$listener->handle(new ObjectCreatedEvent($this->entity(payload: ['stepKind' => 'committee'])));
		$listener->handle(new ObjectUpdatedEvent($this->entity(payload: ['stepKind' => 'committee']), $this->entity(payload: [])));

	}//end testASavedStageIsOfferedForPlacement()

	/**
	 * A created meeting takes the waiting stages; an updated one does not.
	 *
	 * @return void
	 */
	public function testOnlyACreatedMeetingTakesTheWaitingStages(): void {
		$this->placement->expects($this->once())->method('placePending')
			->with(['governanceBody' => 'college', 'id' => 'obj-1']);

		$listener = $this->listener(slug: 'meeting');
		$listener->handle(new ObjectCreatedEvent($this->entity(payload: ['governanceBody' => 'college'])));
		$listener->handle(new ObjectUpdatedEvent($this->entity(payload: ['governanceBody' => 'college']), $this->entity(payload: [])));

	}//end testOnlyACreatedMeetingTakesTheWaitingStages()

	/**
	 * A saved decision may close the committee stage it answers.
	 *
	 * @return void
	 */
	public function testASavedDecisionMayCloseItsStage(): void {
		$this->placement->expects($this->once())->method('closeFromDecision')
			->with(['agendaItem' => 'ai-9', 'outcome' => 'adopted', 'id' => 'obj-1']);
		$this->placement->expects($this->never())->method('placeStage');

		$this->listener(slug: 'decision')->handle(
			new ObjectUpdatedEvent($this->entity(payload: ['agendaItem' => 'ai-9', 'outcome' => 'adopted']), $this->entity(payload: []))
		);

	}//end testASavedDecisionMayCloseItsStage()

	/**
	 * Other events and failures never break the save; a failure is logged.
	 *
	 * @return void
	 */
	public function testOtherEventsAreIgnoredAndAFailureIsLogged(): void {
		$this->placement->method('placeStage')->willThrowException(new RuntimeException('register down'));
		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with(
			'Decidiq: a committee step was not placed on or closed from the agenda',
			['schema' => 'decision-stage', 'id' => 'obj-1', 'exception' => 'register down']
		);

		$listener = $this->listener(slug: 'decision-stage', logger: $logger);
		$listener->handle(new Event());
		$listener->handle(new ObjectCreatedEvent($this->entity(payload: [])));

	}//end testOtherEventsAreIgnoredAndAFailureIsLogged()
}//end class
