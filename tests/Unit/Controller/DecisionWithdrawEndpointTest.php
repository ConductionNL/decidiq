<?php

/**
 * decision#withdraw: an administrator withdraws a decision through the guarded
 * withdraw flow; anybody else, or nobody, withdraws nothing, and a refusal by
 * the flow answers 422 with its message (REQ-DWP-005).
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
 * @spec openspec/changes/the-decision-as-a-walked-process/specs/decision-as-a-walked-process/spec.md (REQ-DWP-005)
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Controller;

use OCA\Decidiq\Controller\DecisionController;
use OCA\Decidiq\Service\DecisionLifecycleService;
use OCA\Decidiq\Service\DecisionWithdrawFlow;
use OCP\AppFramework\Http;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Decidiq\Controller\DecisionController::withdraw
 * @uses   \OCA\Decidiq\Controller\DecisionController::__construct
 * @uses   \OCA\Decidiq\Service\DecisionPublicationService
 */
class DecisionWithdrawEndpointTest extends TestCase {

	private IGroupManager&MockObject $groups;

	private IUserSession&MockObject $session;

	private DecisionWithdrawFlow&MockObject $flow;

	protected function setUp(): void {
		$this->groups = $this->createMock(IGroupManager::class);
		$this->session = $this->createMock(IUserSession::class);
		$this->flow = $this->createMock(DecisionWithdrawFlow::class);
	}

	/**
	 * Sign in as the given user, or as nobody.
	 *
	 * @param string|null $uid   The user, or null for no session.
	 * @param bool        $admin Whether the user is an administrator.
	 *
	 * @return void
	 */
	private function signIn(?string $uid, bool $admin): void {
		$user = null;
		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
		}

		$this->session->method('getUser')->willReturn($user);
		$this->groups->method('isAdmin')->willReturn($admin);
	}

	/**
	 * The controller, with request parameters.
	 *
	 * @param array<string, string> $params The body.
	 *
	 * @return DecisionController
	 */
	private function controller(array $params): DecisionController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(static fn (string $key, $default = null) => ($params[$key] ?? $default));

		return new DecisionController(
			request: $request,
			container: $this->createMock(ContainerInterface::class),
			userSession: $this->session,
			groupManager: $this->groups,
			logger: $this->createMock(LoggerInterface::class),
			lifecycleService: $this->createMock(DecisionLifecycleService::class),
			withdrawFlow: $this->flow,
		);
	}

	public function testAnAdministratorWithdrawsThroughTheFlow(): void {
		$this->signIn(uid: 'admin', admin: true);
		$this->flow->expects($this->once())->method('withdraw')
			->with('d1', 'belanghebbende', 'Aanvraag ingetrokken', 'admin')
			->willReturn(['success' => true, 'decision' => ['id' => 'd1', 'lifecycle' => 'withdrawn', 'outcome' => 'granted'], 'message' => 'ok']);

		$response = $this->controller(['withdrawnBy' => 'belanghebbende', 'reason' => 'Aanvraag ingetrokken'])->withdraw('d1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('withdrawn', $response->getData()['decision']['lifecycle']);
		$this->assertSame('granted', $response->getData()['decision']['outcome']);
	}

	public function testARefusalByTheFlowIs422WithItsMessage(): void {
		$this->signIn(uid: 'admin', admin: true);
		$this->flow->method('withdraw')->willReturn(['success' => false, 'decision' => null, 'message' => 'A withdrawal has to say who withdrew it: bestuursorgaan or belanghebbende.']);

		$response = $this->controller([])->withdraw('d1');

		$this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
		$this->assertStringContainsString('bestuursorgaan or belanghebbende', $response->getData()['message']);
	}

	public function testANonAdministratorWithdrawsNothing(): void {
		$this->signIn(uid: 'member', admin: false);
		$this->flow->expects($this->never())->method('withdraw');

		$this->assertSame(Http::STATUS_FORBIDDEN, $this->controller(['withdrawnBy' => 'bestuursorgaan'])->withdraw('d1')->getStatus());
	}

	public function testNoSessionWithdrawsNothing(): void {
		$this->signIn(uid: null, admin: false);
		$this->flow->expects($this->never())->method('withdraw');

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $this->controller(['withdrawnBy' => 'bestuursorgaan'])->withdraw('d1')->getStatus());
	}
}
