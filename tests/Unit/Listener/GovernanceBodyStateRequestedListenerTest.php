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

use OCA\Decidiq\AppInfo\Registrar\CrossAppEventRegistrar;
use OCA\Decidiq\Event\GovernanceBodyStateRequestedEvent;
use OCA\Decidiq\Listener\GovernanceBodyStateRequestedListener;
use OCA\Decidiq\Service\GovernanceBodyQueryService;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * REQ-GBE-007: the read seam answers in process, and tells "could not read"
 * apart from "not there".
 */
class GovernanceBodyStateRequestedListenerTest extends TestCase {

	public function testAFoundBodyIsWrittenOntoTheEvent(): void {
		$query = $this->createMock(GovernanceBodyQueryService::class);
		$query->expects($this->once())->method('lookup')
			->with('dossiq', 'cmte-1', '')
			->willReturn(['id' => 'gb-1', 'active' => false, 'members' => []]);

		$event = new GovernanceBodyStateRequestedEvent('dossiq', 'cmte-1');
		(new GovernanceBodyStateRequestedListener($query, $this->createMock(LoggerInterface::class)))->handle($event);

		$this->assertTrue($event->isHandled());
		$this->assertTrue($event->isFound());
		$this->assertSame('gb-1', $event->getGovernanceBody()['id']);
		$this->assertFalse($event->getGovernanceBody()['active']);

	}//end testAFoundBodyIsWrittenOntoTheEvent()

	public function testAMissIsHandledButNotFound(): void {
		$query = $this->createMock(GovernanceBodyQueryService::class);
		$query->method('lookup')->willReturn(null);

		$event = new GovernanceBodyStateRequestedEvent('dossiq', '', 'gb-404');
		(new GovernanceBodyStateRequestedListener($query, $this->createMock(LoggerInterface::class)))->handle($event);

		$this->assertTrue($event->isHandled());
		$this->assertFalse($event->isFound());
		$this->assertNull($event->getGovernanceBody());

	}//end testAMissIsHandledButNotFound()

	public function testAFailedReadLeavesTheEventUnhandledAndThrowsNothing(): void {
		$query = $this->createMock(GovernanceBodyQueryService::class);
		$query->method('lookup')->willThrowException(new RuntimeException('OpenRegister unavailable'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('error');

		$event = new GovernanceBodyStateRequestedEvent('dossiq', 'cmte-1');
		(new GovernanceBodyStateRequestedListener($query, $logger))->handle($event);

		$this->assertFalse($event->isHandled());
		$this->assertFalse($event->isFound());

	}//end testAFailedReadLeavesTheEventUnhandledAndThrowsNothing()

	public function testAnotherEventIsIgnored(): void {
		$query = $this->createMock(GovernanceBodyQueryService::class);
		$query->expects($this->never())->method('lookup');

		(new GovernanceBodyStateRequestedListener($query, $this->createMock(LoggerInterface::class)))->handle(new Event());

	}//end testAnotherEventIsIgnored()

	public function testTheRegistrarWiresTheListener(): void {
		$wired = [];
		$context = $this->createMock(IRegistrationContext::class);
		$context->method('registerEventListener')->willReturnCallback(
			static function (string $event, string $listener) use (&$wired): void {
				$wired[$event] = $listener;
			}
		);

		(new CrossAppEventRegistrar())->register($context);

		$this->assertSame(GovernanceBodyStateRequestedListener::class, ($wired[GovernanceBodyStateRequestedEvent::class] ?? null));

	}//end testTheRegistrarWiresTheListener()
}//end class
