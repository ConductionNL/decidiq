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

	/**
	 * Group manager deciding who is an administrator.
	 *
	 * @var IGroupManager&MockObject
	 */
	private IGroupManager&MockObject $groups;

	/**
	 * The user session.
	 *
	 * @var IUserSession&MockObject
	 */
	private IUserSession&MockObject $session;

	/**
	 * The guarded withdraw flow.
	 *
	 * @var DecisionWithdrawFlow&MockObject
	 */
	private DecisionWithdrawFlow&MockObject $flow;

	/**
	 * Fresh mocks for every test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->groups = $this->createMock(originalClassName: IGroupManager::class);
		$this->session = $this->createMock(originalClassName: IUserSession::class);
		$this->flow = $this->createMock(originalClassName: DecisionWithdrawFlow::class);
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
			$user = $this->createMock(originalClassName: IUser::class);
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
		$request = $this->createMock(originalClassName: IRequest::class);
		$request->method('getParam')->willReturnCallback(static fn (string $key, $default = null) => ($params[$key] ?? $default));

		$container = $this->createMock(originalClassName: ContainerInterface::class);
		$container->method('get')->willReturn(value: $this->flow);

		return new DecisionController(
			request: $request,
			container: $container,
			userSession: $this->session,
			groupManager: $this->groups,
			logger: $this->createMock(originalClassName: LoggerInterface::class),
			lifecycleService: $this->createMock(originalClassName: DecisionLifecycleService::class),
		);
	}

	/**
	 * An administrator withdraws through the flow and gets the withdrawn decision back.
	 *
	 * @return void
	 */
	public function testAnAdministratorWithdrawsThroughTheFlow(): void {
		$this->signIn(uid: 'admin', admin: true);
		$this->flow->expects($this->once())->method('withdraw')
			->with('d1', 'belanghebbende', 'Aanvraag ingetrokken', 'admin')
			->willReturn(['success' => true, 'decision' => ['id' => 'd1', 'lifecycle' => 'withdrawn', 'outcome' => 'granted'], 'message' => 'ok']);

		$response = $this->controller(params: ['withdrawnBy' => 'belanghebbende', 'reason' => 'Aanvraag ingetrokken'])->withdraw('d1');

		$this->assertSame(expected: Http::STATUS_OK, actual: $response->getStatus());
		$this->assertSame(expected: 'withdrawn', actual: $response->getData()['decision']['lifecycle']);
		$this->assertSame(expected: 'granted', actual: $response->getData()['decision']['outcome']);
	}

	/**
	 * A refusal by the flow answers 422 with its message.
	 *
	 * @return void
	 */
	public function testARefusalByTheFlowIs422WithItsMessage(): void {
		$this->signIn(uid: 'admin', admin: true);
		$refusal = 'A withdrawal has to say who withdrew it: bestuursorgaan or belanghebbende.';
		$this->flow->method('withdraw')->willReturn(['success' => false, 'decision' => null, 'message' => $refusal]);

		$response = $this->controller(params: [])->withdraw('d1');

		$this->assertSame(expected: Http::STATUS_UNPROCESSABLE_ENTITY, actual: $response->getStatus());
		$this->assertStringContainsString(needle: 'bestuursorgaan or belanghebbende', haystack: $response->getData()['message']);
	}

	/**
	 * A signed-in non-administrator is refused before the flow runs.
	 *
	 * @return void
	 */
	public function testANonAdministratorWithdrawsNothing(): void {
		$this->signIn(uid: 'member', admin: false);
		$this->flow->expects($this->never())->method('withdraw');

		$response = $this->controller(params: ['withdrawnBy' => 'bestuursorgaan'])->withdraw('d1');
		$this->assertSame(expected: Http::STATUS_FORBIDDEN, actual: $response->getStatus());
	}

	/**
	 * Without a session nothing is withdrawn.
	 *
	 * @return void
	 */
	public function testNoSessionWithdrawsNothing(): void {
		$this->signIn(uid: null, admin: false);
		$this->flow->expects($this->never())->method('withdraw');

		$response = $this->controller(params: ['withdrawnBy' => 'bestuursorgaan'])->withdraw('d1');
		$this->assertSame(expected: Http::STATUS_UNAUTHORIZED, actual: $response->getStatus());
	}
}
