<?php

/**
 * Unit tests for AnalyticsController.
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\Controller
 *
 * @spec openspec/changes/archive/2026-06-10-migrate-engagement-analytics-to-analytics-leaf/tasks.md#task-3.2
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Controller;

use OCA\Decidiq\Controller\AnalyticsController;
use OCA\Decidiq\Service\ActionItemAnalyticsService;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the personal action item analytics endpoint.
 *
 * @covers \OCA\Decidiq\Controller\AnalyticsController
 *
 * @spec openspec/changes/archive/2026-06-10-migrate-engagement-analytics-to-analytics-leaf/tasks.md#task-3.2
 */
class AnalyticsControllerTest extends TestCase {
	public function testReturnsTheItemsOfTheSignedInUser(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$service = $this->createMock(ActionItemAnalyticsService::class);
		$service->expects($this->once())->method('getMyItems')->with('alice')->willReturn(['open' => 2]);

		$response = (new AnalyticsController($this->createMock(IRequest::class), $service, $session))->getMyItems();

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(['open' => 2], $response->getData());
	}

	public function testRefusesAnAnonymousCaller(): void {
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn(null);
		$service = $this->createMock(ActionItemAnalyticsService::class);
		$service->expects($this->never())->method('getMyItems');

		$response = (new AnalyticsController($this->createMock(IRequest::class), $service, $session))->getMyItems();

		$this->assertSame(401, $response->getStatus());
		$this->assertSame(['error' => 'Not authenticated'], $response->getData());
	}
}
