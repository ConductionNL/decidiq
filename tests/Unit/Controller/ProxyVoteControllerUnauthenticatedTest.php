<?php

/**
 * Unit tests for ProxyVoteController without a session.
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/archive/2026-06-12-board-meeting-resolutions/tasks.md#task-5.1
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Controller;

use OCA\Decidiq\Controller\GovernanceControllerTrait;
use OCA\Decidiq\Controller\ProxyVoteController;
use OCA\Decidiq\Kernel\Contracts\ProxyVoteServiceInterface;
use OCP\AppFramework\Http;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * index(), suspend() and revoke() refuse a caller without a session.
 */
#[CoversClass(ProxyVoteController::class)]
#[UsesClass(GovernanceControllerTrait::class)]
final class ProxyVoteControllerUnauthenticatedTest extends TestCase {

	/**
	 * Every listed action answers 401 and never reaches the proxy service.
	 *
	 * @return void
	 */
	public function testListSuspendAndRevokeRefuseAnUnauthenticatedCaller(): void {
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn(null);

		$proxyService = $this->createMock(ProxyVoteServiceInterface::class);
		foreach (['forMeeting', 'isAuthorizedToList', 'suspend', 'revoke'] as $method) {
			$proxyService->expects($this->never())->method($method);
		}

		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->expects($this->never())->method('isAdmin');

		$controller = new ProxyVoteController(
			request: $this->createMock(IRequest::class),
			proxyService: $proxyService,
			userSession: $session,
			groupManager: $groupManager,
		);

		foreach ([$controller->index(), $controller->suspend('proxy-1'), $controller->revoke('proxy-1')] as $response) {
			self::assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
			self::assertSame(['message' => 'Authentication required.'], $response->getData());
		}

	}//end testListSuspendAndRevokeRefuseAnUnauthenticatedCaller()
}//end class
