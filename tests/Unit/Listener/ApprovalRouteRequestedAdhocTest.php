<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category  Test
 * @package   OCA\Decidiq\Tests\Unit\Listener
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 * @link      https://github.com/ConductionNL/decidiq
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Listener;

use OCA\Decidiq\Event\ApprovalRouteRequestedEvent;
use OCA\Decidiq\Listener\ApprovalRouteRequestedListener;
use OCA\Decidiq\Service\ApprovalRouteCommandService;
use OCA\Decidiq\Service\ApprovalRouteService;
use OCA\Decidiq\Service\HeldRouteRequest;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * A route request that names people and a subject is held ad hoc, without a
 * template; one that names people without a subject still goes the template way.
 *
 * @covers \OCA\Decidiq\Listener\ApprovalRouteRequestedListener
 * @uses   \OCA\Decidiq\Event\ApprovalRouteRequestedEvent
 * @uses   \OCA\Decidiq\Service\HeldRouteRequest
 */
class ApprovalRouteRequestedAdhocTest extends TestCase {

	/**
	 * A route request.
	 *
	 * @param string             $name    The route name.
	 * @param string             $subject The subject.
	 * @param array<int, string> $actors  The named people.
	 *
	 * @return ApprovalRouteRequestedEvent
	 */
	private function event(string $name, string $subject, array $actors): ApprovalRouteRequestedEvent {
		return new ApprovalRouteRequestedEvent(
			'dossiq',
			'pr-7',
			$name,
			[],
			'',
			'',
			false,
			$subject,
			'proposal',
			'admin',
			'corr-7',
			$actors,
			'2026-12-01'
		);
	}//end event()

	/**
	 * Named people and a subject: the engine holds the route, the event says
	 * created with no route id, and the template service is not asked.
	 *
	 * @return void
	 */
	public function testNamedPeopleAndASubjectAreHeldAdHoc(): void {
		$seen = null;
		$engine = $this->getMockBuilder(ApprovalRouteService::class)->disableOriginalConstructor()->onlyMethods(['holdFor'])->getMock();
		$engine->expects($this->once())->method('holdFor')->willReturnCallback(
			static function (HeldRouteRequest $request) use (&$seen): array {
				$seen = $request;
				return [['id' => 's-1'], ['id' => 's-2']];
			}
		);
		$commands = $this->createMock(ApprovalRouteCommandService::class);
		$commands->expects($this->never())->method('holdRoute');
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('info')->with(
			'Decidiq: held an ad-hoc approval route from named people',
			['sourceApp' => 'dossiq', 'externalReference' => 'pr-7', 'stageCount' => 2]
		);

		$event = $this->event(name: 'Juridische toets', subject: 'voorstel-7', actors: ['alice', 'bob']);
		(new ApprovalRouteRequestedListener($commands, $logger, $engine))->handle($event);

		$this->assertSame(['voorstel-7', 'proposal', ['alice', 'bob'], 'Juridische toets'], [$seen->subject, $seen->subjectSchema, $seen->actors, $seen->name]);
		$this->assertSame('2026-12-01', $seen->deadline());
		$this->assertNull($seen->askSubstituteAfter());
		$this->assertSame(['', true, 2, true], [$event->getRouteId(), $event->isCreated(), $event->getStageCount(), $event->isHandled()]);
	}//end testNamedPeopleAndASubjectAreHeldAdHoc()

	/**
	 * An ad-hoc route without a name is called "Review".
	 *
	 * @return void
	 */
	public function testAnUnnamedAdHocRouteIsCalledReview(): void {
		$names = [];
		$engine = $this->getMockBuilder(ApprovalRouteService::class)->disableOriginalConstructor()->onlyMethods(['holdFor'])->getMock();
		$engine->method('holdFor')->willReturnCallback(
			static function (HeldRouteRequest $request) use (&$names): array {
				$names[] = $request->name;
				return [];
			}
		);

		$event = $this->event(name: '', subject: 'voorstel-7', actors: ['alice']);
		(new ApprovalRouteRequestedListener($this->createMock(ApprovalRouteCommandService::class), $this->createMock(LoggerInterface::class), $engine))->handle($event);

		$this->assertSame(['Review'], $names);
		$this->assertSame(0, $event->getStageCount());
	}//end testAnUnnamedAdHocRouteIsCalledReview()

	/**
	 * Named people without a subject go the template way.
	 *
	 * @return void
	 */
	public function testNamedPeopleWithoutASubjectUseTheTemplate(): void {
		$engine = $this->getMockBuilder(ApprovalRouteService::class)->disableOriginalConstructor()->onlyMethods(['holdFor'])->getMock();
		$engine->expects($this->never())->method('holdFor');
		$commands = $this->createMock(ApprovalRouteCommandService::class);
		$commands->expects($this->once())->method('holdRoute')->willReturn(['id' => 'ar-9', 'created' => false, 'stageCount' => 1]);

		$event = $this->event(name: 'Paraaf', subject: '', actors: ['alice']);
		(new ApprovalRouteRequestedListener($commands, $this->createMock(LoggerInterface::class), $engine))->handle($event);

		$this->assertSame(['ar-9', false, 1, true], [$event->getRouteId(), $event->isCreated(), $event->getStageCount(), $event->isHandled()]);
	}//end testNamedPeopleWithoutASubjectUseTheTemplate()

	/**
	 * An engine refusal is logged and leaves the event unhandled.
	 *
	 * @return void
	 */
	public function testARefusedAdHocRouteIsLoggedAndLeftUnhandled(): void {
		$failure = new RuntimeException('A route held from named people needs at least one person.');
		$engine = $this->getMockBuilder(ApprovalRouteService::class)->disableOriginalConstructor()->onlyMethods(['holdFor'])->getMock();
		$engine->method('holdFor')->willThrowException($failure);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('error')->with(
			'Decidiq: ApprovalRouteRequestedEvent not handled',
			['sourceApp' => 'dossiq', 'externalReference' => 'pr-7', 'exception' => $failure]
		);
		$logger->expects($this->never())->method('info');

		$event = $this->event(name: 'Paraaf', subject: 'voorstel-7', actors: ['alice']);
		(new ApprovalRouteRequestedListener($this->createMock(ApprovalRouteCommandService::class), $logger, $engine))->handle($event);

		$this->assertFalse($event->isHandled());
	}//end testARefusedAdHocRouteIsLoggedAndLeftUnhandled()

	/**
	 * Any other event is ignored.
	 *
	 * @return void
	 */
	public function testAnotherEventIsIgnored(): void {
		$engine = $this->getMockBuilder(ApprovalRouteService::class)->disableOriginalConstructor()->onlyMethods(['holdFor'])->getMock();
		$engine->expects($this->never())->method('holdFor');
		$commands = $this->createMock(ApprovalRouteCommandService::class);
		$commands->expects($this->never())->method('holdRoute');

		(new ApprovalRouteRequestedListener($commands, $this->createMock(LoggerInterface::class), $engine))->handle(new Event());
	}//end testAnotherEventIsIgnored()
}//end class
