<?php

/**
 * Decidesk Proxy Vote Controller
 *
 * REST surface for proxy registration, listing, suspension and revocation.
 *
 * @category Controller
 * @package  OCA\Decidesk\Controller
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

namespace OCA\Decidesk\Controller;

use OCA\Decidesk\AppInfo\Application;
use OCA\Decidesk\Service\ProxyVoteService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * REST controller for proxy votes.
 *
 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-5.1
 * @spec openspec/changes/board-proxy-vote-authorization-guard/tasks.md#task-2
 */
class ProxyVoteController extends Controller {
	use GovernanceControllerTrait;

	/**
	 * Constructor.
	 *
	 * @param IRequest $request The HTTP request
	 * @param ProxyVoteService $proxyService Proxy service
	 * @param IUserSession $userSession User session
	 * @param IGroupManager $groupManager Group manager (for admin bypass on the authorization guard)
	 */
	public function __construct(
		IRequest $request,
		private readonly ProxyVoteService $proxyService,
		private readonly IUserSession $userSession,
		private readonly IGroupManager $groupManager,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Resolve the caller's UID for the authorization guard: null when the
	 * caller is a Nextcloud admin (admin bypass, mirroring
	 * `MotionCoauthorController`'s `$accessUid` convention), the UID otherwise.
	 *
	 * @return string|null
	 *
	 * @spec openspec/changes/board-proxy-vote-authorization-guard/tasks.md#task-2
	 */
	private function resolveCallerUid(): ?string {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return null;
		}

		$uid = $user->getUID();
		if ($this->groupManager->isAdmin($uid) === true) {
			return null;
		}

		return $uid;
	}//end resolveCallerUid()

	/**
	 * Map a service result to a JSONResponse, promoting authorization
	 * failures (service message prefixed `Forbidden:`) to `403 Forbidden`
	 * with a static message instead of the generic `422` that
	 * `respondFromResult()` would otherwise return.
	 *
	 * @param array<string, mixed> $result The service result
	 * @param string $payloadKey Key of the success-side payload
	 * @param int $successCode HTTP code to return on success
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/board-proxy-vote-authorization-guard/tasks.md#task-2
	 */
	private function respondFromAuthorizedResult(array $result, string $payloadKey, int $successCode = Http::STATUS_OK): JSONResponse {
		if (($result['success'] ?? false) === false
			&& stripos((string)($result['message'] ?? ''), 'Forbidden:') === 0
		) {
			return new JSONResponse(['message' => 'Forbidden.'], Http::STATUS_FORBIDDEN);
		}

		return $this->respondFromResult(result: $result, payloadKey: $payloadKey, successCode: $successCode);
	}//end respondFromAuthorizedResult()

	/**
	 * Register a proxy.
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-5.1
	 *
	 * @return JSONResponse
	 */
	#[NoAdminRequired]
	public function register(): JSONResponse {
		$auth = $this->requireUserOr401(session: $this->userSession);
		if ($auth !== null) {
			return $auth;
		}

		$meetingId = (string)$this->request->getParam('meetingId', '');
		$grantorId = (string)$this->request->getParam('grantorId', '');
		$holderId = (string)$this->request->getParam('holderId', '');
		if ($meetingId === '' || $grantorId === '' || $holderId === '') {
			return new JSONResponse(
				['message' => "Missing required parameter 'meetingId', 'grantorId' or 'holderId'."],
				Http::STATUS_UNPROCESSABLE_ENTITY
			);
		}

		$extra = [
			'scope' => (string)$this->request->getParam('scope', 'all-resolutions'),
			'expiresAt' => (string)$this->request->getParam('expiresAt', ''),
		];

		$callerUid = $this->resolveCallerUid();

		return $this->respondFromAuthorizedResult(
			result: $this->proxyService->register($meetingId, $grantorId, $holderId, $extra, $callerUid),
			payloadKey: 'proxy',
			successCode: Http::STATUS_CREATED
		);

	}//end register()

	/**
	 * List proxies for the requested meeting.
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-5.1
	 *
	 * @return JSONResponse
	 */
	#[NoAdminRequired]
	public function index(): JSONResponse {
		$auth = $this->requireUserOr401(session: $this->userSession);
		if ($auth !== null) {
			return $auth;
		}

		$meetingId = (string)$this->request->getParam('meetingId', '');
		if ($meetingId === '') {
			return new JSONResponse(
				['message' => "Missing required parameter 'meetingId'."],
				Http::STATUS_UNPROCESSABLE_ENTITY
			);
		}

		$status = (string)$this->request->getParam('status', '');
		$statusFilter = null;
		if ($status !== '') {
			$statusFilter = $status;
		}

		$result = $this->proxyService->forMeeting($meetingId, $statusFilter);

		return new JSONResponse(
			[
				'results' => $result['proxies'],
				'total' => $result['count'],
			]
		);

	}//end index()

	/**
	 * Suspend a proxy.
	 *
	 * @param string $id UUID of the proxy
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-5.1
	 *
	 * @return JSONResponse
	 */
	#[NoAdminRequired]
	public function suspend(string $id): JSONResponse {
		$auth = $this->requireUserOr401(session: $this->userSession);
		if ($auth !== null) {
			return $auth;
		}

		$actor = (string)$this->userSession->getUser()?->getUID();
		$callerUid = $this->resolveCallerUid();
		return $this->respondFromAuthorizedResult(
			result: $this->proxyService->suspend($id, $actor, $callerUid),
			payloadKey: 'proxy'
		);

	}//end suspend()

	/**
	 * Revoke a proxy.
	 *
	 * @param string $id UUID of the proxy
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-5.1
	 *
	 * @return JSONResponse
	 */
	#[NoAdminRequired]
	public function revoke(string $id): JSONResponse {
		$auth = $this->requireUserOr401(session: $this->userSession);
		if ($auth !== null) {
			return $auth;
		}

		$actor = (string)$this->userSession->getUser()?->getUID();
		$callerUid = $this->resolveCallerUid();
		return $this->respondFromAuthorizedResult(
			result: $this->proxyService->revoke($id, $actor, $callerUid),
			payloadKey: 'proxy'
		);

	}//end revoke()
}//end class
