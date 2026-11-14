<?php

/**
 * Unit tests for DecisionAgendaClearanceGuard.
 *
 * @category Tests
 * @package  OCA\Decidiq\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Listener;

use OCA\Decidiq\Kernel\Contracts\ApprovalRouteServiceInterface;
use OCA\Decidiq\Listener\DecisionAgendaClearanceGuard;
use OCA\Decidiq\Service\SubjectClearanceService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * A proposal with an open approval step is not placed on the agenda.
 *
 * @spec openspec/changes/routes-send-proposal-along-route/specs/approval-routes/spec.md#requirement-req-rspr-001-a-proposal-is-sent-along-a-route-from-its-page
 */
class DecisionAgendaClearanceGuardTest extends TestCase {

	/**
	 * The route service mock.
	 *
	 * @var ApprovalRouteServiceInterface&MockObject
	 */
	private ApprovalRouteServiceInterface&MockObject $routes;

	/**
	 * The guard under test, with the real clearance service.
	 *
	 * @var DecisionAgendaClearanceGuard
	 */
	private DecisionAgendaClearanceGuard $guard;

	/**
	 * Set up.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->routes = $this->createMock(ApprovalRouteServiceInterface::class);
		$this->guard = new DecisionAgendaClearanceGuard(
			routes: $this->routes,
			clearance: new SubjectClearanceService(),
			logger: $this->createMock(LoggerInterface::class),
		);
	}//end setUp()

	/**
	 * A decision entity.
	 *
	 * @param array<string,mixed> $payload The stored payload.
	 *
	 * @return ObjectEntity
	 */
	private function decision(array $payload): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid('dec-1');
		$entity->setObject($payload);
		return $entity;
	}//end decision()

	/**
	 * The Parkeerbeleid route, waiting on its Wethouder step.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private static function openRoute(): array {
		return [[
			'id' => 'route-1',
			'name' => 'Wethouder then Secretaris',
			'required' => true,
			'stages' => [
				['sequence' => 1, 'label' => 'Wethouder', 'assignedPerson' => 'wethouder', 'status' => 'pending'],
				['sequence' => 2, 'label' => 'Secretaris', 'assignedPerson' => 'secretaris', 'status' => 'waiting'],
			],
		]];
	}//end openRoute()

	/**
	 * Placing a proposal with an open step on an agenda item is refused, naming the step.
	 *
	 * @return void
	 */
	public function testPlacingAProposalWithAnOpenStepIsRefusedNamingTheStep(): void {
		$this->routes->expects(self::once())->method('routesWithStagesFor')->with('dec-1')->willReturn(self::openRoute());

		$event = new ObjectUpdatingEvent(
			$this->decision(payload: ['title' => 'Parkeerbeleid', 'agendaItem' => 'item-4']),
			$this->decision(payload: ['title' => 'Parkeerbeleid'])
		);
		$this->guard->handle($event);

		self::assertTrue($event->isPropagationStopped());
		self::assertSame(
			['message' => 'This proposal cannot go on the agenda before it is approved. Still open: Wethouder of Wethouder then Secretaris.'],
			$event->getErrors()
		);
	}//end testPlacingAProposalWithAnOpenStepIsRefusedNamingTheStep()

	/**
	 * A cleared proposal, or one never sent along a route, is placed.
	 *
	 * @return void
	 */
	public function testAClearedProposalIsPlaced(): void {
		$done = self::openRoute();
		$done[0]['stages'][0]['status'] = 'approved';
		$done[0]['stages'][1]['status'] = 'approved';
		$this->routes->method('routesWithStagesFor')->willReturnOnConsecutiveCalls($done, []);

		foreach (['approved route', 'no route'] as $case) {
			$event = new ObjectUpdatingEvent(
				$this->decision(payload: ['agendaItem' => ['id' => 'item-4']]),
				$this->decision(payload: [])
			);
			$this->guard->handle($event);
			self::assertFalse($event->isPropagationStopped(), $case);
			self::assertSame([], $event->getErrors(), $case);
		}
	}//end testAClearedProposalIsPlaced()

	/**
	 * Saving without moving it onto another item, or taking it off the agenda, is not checked.
	 *
	 * @return void
	 */
	public function testOnlyANewPlacementIsChecked(): void {
		$this->routes->expects(self::never())->method('routesWithStagesFor');

		$same = new ObjectUpdatingEvent(
			$this->decision(payload: ['agendaItem' => 'item-4', 'title' => 'renamed']),
			$this->decision(payload: ['agendaItem' => 'item-4'])
		);
		$off = new ObjectUpdatingEvent($this->decision(payload: ['agendaItem' => '']), $this->decision(payload: ['agendaItem' => 'item-4']));
		$created = new ObjectCreatingEvent($this->decision(payload: ['agendaItem' => 'item-4']));
		$other = new ObjectUpdatingEvent(
			$this->decision(payload: ['_schemaSlug' => 'meeting', 'agendaItem' => 'item-4']),
			$this->decision(payload: [])
		);
		foreach ([$same, $off, $created, $other] as $event) {
			$this->guard->handle($event);
			self::assertSame([], $event->getErrors());
		}
	}//end testOnlyANewPlacementIsChecked()

	/**
	 * A placement set through modified data is checked too.
	 *
	 * @return void
	 */
	public function testAPlacementInModifiedDataIsChecked(): void {
		$this->routes->method('routesWithStagesFor')->willReturn(self::openRoute());

		$event = new ObjectUpdatingEvent($this->decision(payload: []), $this->decision(payload: []));
		$event->setModifiedData(['agendaItem' => 'item-4']);
		$this->guard->handle($event);

		self::assertTrue($event->isPropagationStopped());
	}//end testAPlacementInModifiedDataIsChecked()

	/**
	 * A check that fails refuses the placement.
	 *
	 * @return void
	 */
	public function testAFailedCheckRefuses(): void {
		$this->routes->method('routesWithStagesFor')->willThrowException(new RuntimeException('store down'));

		$event = new ObjectUpdatingEvent($this->decision(payload: ['agendaItem' => 'item-4']), $this->decision(payload: []));
		$this->guard->handle($event);

		self::assertSame(['message' => DecisionAgendaClearanceGuard::UNCHECKED_MESSAGE], $event->getErrors());
	}//end testAFailedCheckRefuses()

	/**
	 * A step without a name is named by its number.
	 *
	 * @return void
	 */
	public function testAStepWithoutANameIsNamedByItsNumber(): void {
		self::assertSame(
			'This proposal cannot go on the agenda before it is approved. Still open: step 2.',
			DecisionAgendaClearanceGuard::message(waitingOn: [['stage' => 2, 'stageName' => '', 'routeName' => '']])
		);
	}//end testAStepWithoutANameIsNamedByItsNumber()
}//end class
