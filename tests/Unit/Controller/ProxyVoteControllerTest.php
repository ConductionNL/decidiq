<?php

/**
 * Unit tests for ProxyVoteController.
 *
 * @category Test
 * @package  OCA\Decidesk\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-5.1
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidesk\Tests\Unit\Controller;

use OCA\Decidesk\Controller\ProxyVoteController;
use OCA\Decidesk\Service\ProxyVoteService;
use OCP\AppFramework\Http;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * Tests for ProxyVoteController.
 *
 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-5.1
 */
class ProxyVoteControllerTest extends TestCase {
	/**
	 * Build a controller wired to the supplied service and params.
	 *
	 * @param ProxyVoteService $service Service double
	 * @param array<string, mixed> $requestParams Params returned by IRequest
	 * @param bool $authenticated Whether session has a user
	 * @param bool $isAdmin Whether the authenticated user is a Nextcloud admin
	 *
	 * @return ProxyVoteController
	 */
	private function makeController(
		ProxyVoteService $service,
		array $requestParams = [],
		bool $authenticated = true,
		bool $isAdmin = false,
	): ProxyVoteController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn($requestParams);
		$request->method('getParam')->willReturnCallback(
			static function (string $key, mixed $default = null) use ($requestParams): mixed {
				return ($requestParams[$key] ?? $default);
			}
		);

		$session = $this->createMock(IUserSession::class);
		if ($authenticated === true) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn('alice');
			$session->method('getUser')->willReturn($user);
		} else {
			$session->method('getUser')->willReturn(null);
		}

		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('isAdmin')->willReturn($isAdmin);

		return new ProxyVoteController($request, $service, $session, $groupManager);
	}//end makeController()

	/**
	 * register requires authentication.
	 *
	 * @return void
	 */
	public function testRegisterRequiresAuth(): void {
		$service = $this->createMock(ProxyVoteService::class);
		$controller = $this->makeController($service, authenticated: false);

		$response = $controller->register();
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());

	}//end testRegisterRequiresAuth()

	/**
	 * register rejects missing fields.
	 *
	 * @return void
	 */
	public function testRegisterRejectsMissingFields(): void {
		$service = $this->createMock(ProxyVoteService::class);
		$controller = $this->makeController($service, requestParams: ['meetingId' => 'm-1']);

		$response = $controller->register();
		$this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());

	}//end testRegisterRejectsMissingFields()

	/**
	 * register returns 201 on success.
	 *
	 * @return void
	 */
	public function testRegisterReturns201OnSuccess(): void {
		$service = $this->createMock(ProxyVoteService::class);
		$service->expects($this->once())->method('register')
			->with('m-1', 'g-1', 'h-1', $this->anything(), 'alice')
			->willReturn(['success' => true, 'proxy' => ['id' => 'p-1'], 'message' => 'ok']);

		$controller = $this->makeController(
			$service,
			requestParams: ['meetingId' => 'm-1', 'grantorId' => 'g-1', 'holderId' => 'h-1']
		);

		$response = $controller->register();
		$this->assertSame(Http::STATUS_CREATED, $response->getStatus());

	}//end testRegisterReturns201OnSuccess()

	/**
	 * register forwards a null callerUid for admin callers (admin bypass).
	 *
	 * @return void
	 */
	public function testRegisterForwardsNullCallerUidForAdmin(): void {
		$service = $this->createMock(ProxyVoteService::class);
		$service->expects($this->once())->method('register')
			->with('m-1', 'g-1', 'h-1', $this->anything(), null)
			->willReturn(['success' => true, 'proxy' => ['id' => 'p-1'], 'message' => 'ok']);

		$controller = $this->makeController(
			$service,
			requestParams: ['meetingId' => 'm-1', 'grantorId' => 'g-1', 'holderId' => 'h-1'],
			isAdmin: true
		);

		$response = $controller->register();
		$this->assertSame(Http::STATUS_CREATED, $response->getStatus());

	}//end testRegisterForwardsNullCallerUidForAdmin()

	/**
	 * register() maps a Forbidden: service message to 403, not the generic 422.
	 *
	 * @spec openspec/changes/board-proxy-vote-authorization-guard/specs/board-proxy-voting/spec.md#requirement-req-bpv-001-only-the-grantor-or-an-authorized-official-may-register-a-proxy
	 *
	 * @return void
	 */
	public function testRegisterMapsForbiddenMessageTo403(): void {
		$service = $this->createMock(ProxyVoteService::class);
		$service->method('register')->willReturn(
			[
				'success' => false,
				'proxy' => null,
				'message' => 'Forbidden: only the grantor, a chair/clerk of the meeting, or an admin may register this proxy.',
			]
		);

		$controller = $this->makeController(
			$service,
			requestParams: ['meetingId' => 'm-1', 'grantorId' => 'g-1', 'holderId' => 'h-1']
		);

		$response = $controller->register();
		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());

	}//end testRegisterMapsForbiddenMessageTo403()

	/**
	 * index requires meetingId.
	 *
	 * @return void
	 */
	public function testIndexRequiresMeetingId(): void {
		$service = $this->createMock(ProxyVoteService::class);
		$controller = $this->makeController($service);

		$response = $controller->index();
		$this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());

	}//end testIndexRequiresMeetingId()

	/**
	 * index returns results from the service.
	 *
	 * @return void
	 */
	public function testIndexReturnsResults(): void {
		$service = $this->createMock(ProxyVoteService::class);
		$service->method('isAuthorizedToList')->with('m-1', 'alice')->willReturn(true);
		$service->expects($this->once())->method('forMeeting')
			->with('m-1', 'active')
			->willReturn(['success' => true, 'proxies' => [['id' => 'p-1']], 'count' => 1]);

		$controller = $this->makeController(
			$service,
			requestParams: ['meetingId' => 'm-1', 'status' => 'active']
		);

		$response = $controller->index();
		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$data = $response->getData();
		$this->assertSame(1, $data['total']);

	}//end testIndexReturnsResults()

	/**
	 * A caller who is NOT a participant of the requested meeting is refused
	 * 403 and the proxy register is never read.
	 *
	 * `meetingId` is a request parameter, so without this check any
	 * authenticated user could enumerate meetings and read who handed their
	 * vote to whom (REQ-MPA-008 scopes the register view to "a participant
	 * with access"). The `never()` on forMeeting is the point: a guard that
	 * lets the read happen and filters afterwards is not a guard.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/member-proxy-authorization/specs/member-proxy-authorization/spec.md#requirement-req-mpa-008-per-meeting-proxy-register-attachable-to-the-minutes
	 */
	public function testIndexRefusesNonParticipantWith403(): void {
		$service = $this->createMock(ProxyVoteService::class);
		$service->expects($this->once())->method('isAuthorizedToList')
			->with('m-1', 'alice')->willReturn(false);
		$service->expects($this->never())->method('forMeeting');

		$controller = $this->makeController($service, requestParams: ['meetingId' => 'm-1']);

		$response = $controller->index();
		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());

	}//end testIndexRefusesNonParticipantWith403()

	/**
	 * A participant of the meeting still gets the register — the guard denies
	 * the outsider WITHOUT denying the legitimate caller.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/member-proxy-authorization/specs/member-proxy-authorization/spec.md#requirement-req-mpa-008-per-meeting-proxy-register-attachable-to-the-minutes
	 */
	public function testIndexAllowsParticipant(): void {
		$service = $this->createMock(ProxyVoteService::class);
		$service->expects($this->once())->method('isAuthorizedToList')
			->with('m-1', 'alice')->willReturn(true);
		$service->expects($this->once())->method('forMeeting')
			->with('m-1', null)
			->willReturn(['success' => true, 'proxies' => [['id' => 'p-1']], 'count' => 1]);

		$controller = $this->makeController($service, requestParams: ['meetingId' => 'm-1']);

		$response = $controller->index();
		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(1, $response->getData()['total']);

	}//end testIndexAllowsParticipant()

	/**
	 * An NC admin bypasses the participation check entirely: `resolveCallerUid()`
	 * answers null for an admin, so `isAuthorizedToList()` is never consulted
	 * and the register is returned.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/member-proxy-authorization/specs/member-proxy-authorization/spec.md#requirement-req-mpa-008-per-meeting-proxy-register-attachable-to-the-minutes
	 */
	public function testIndexAllowsAdminWithoutParticipationCheck(): void {
		$service = $this->createMock(ProxyVoteService::class);
		$service->expects($this->never())->method('isAuthorizedToList');
		$service->expects($this->once())->method('forMeeting')
			->with('m-1', null)
			->willReturn(['success' => true, 'proxies' => [], 'count' => 0]);

		$controller = $this->makeController(
			$service,
			requestParams: ['meetingId' => 'm-1'],
			isAdmin: true
		);

		$this->assertSame(Http::STATUS_OK, $controller->index()->getStatus());

	}//end testIndexAllowsAdminWithoutParticipationCheck()

	/**
	 * suspend delegates to the service and returns 200 on success.
	 *
	 * @return void
	 */
	public function testSuspendDelegates(): void {
		$service = $this->createMock(ProxyVoteService::class);
		$service->expects($this->once())->method('suspend')
			->with('p-1', 'alice', 'alice')
			->willReturn(['success' => true, 'proxy' => ['id' => 'p-1', 'proxyStatus' => 'suspended'], 'message' => 'ok']);

		$controller = $this->makeController($service);
		$response = $controller->suspend('p-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());

	}//end testSuspendDelegates()

	/**
	 * suspend() maps a Forbidden: service message to 403 (unrelated-caller IDOR guard).
	 *
	 * @spec openspec/changes/board-proxy-vote-authorization-guard/specs/board-proxy-voting/spec.md#requirement-req-bpv-002-only-a-party-to-the-proxy-or-an-authorized-official-may-suspend-or-revoke-it
	 *
	 * @return void
	 */
	public function testSuspendMapsForbiddenMessageTo403(): void {
		$service = $this->createMock(ProxyVoteService::class);
		$service->method('suspend')->willReturn(
			[
				'success' => false,
				'proxy' => null,
				'message' => "Forbidden: only the proxy's grantor, its holder, a chair/clerk of the meeting, or an admin may change its status.",
			]
		);

		$controller = $this->makeController($service);
		$response = $controller->suspend('p-1');

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());

	}//end testSuspendMapsForbiddenMessageTo403()

	/**
	 * revoke delegates to the service and returns 200 on success.
	 *
	 * @return void
	 */
	public function testRevokeDelegates(): void {
		$service = $this->createMock(ProxyVoteService::class);
		$service->expects($this->once())->method('revoke')
			->with('p-1', 'alice', 'alice')
			->willReturn(['success' => true, 'proxy' => ['id' => 'p-1', 'proxyStatus' => 'revoked'], 'message' => 'ok']);

		$controller = $this->makeController($service);
		$response = $controller->revoke('p-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());

	}//end testRevokeDelegates()
}//end class
