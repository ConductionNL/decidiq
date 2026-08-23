<?php

/**
 * Decidiq Notification Preference Controller
 *
 * REST controller for the current user's NotificationPreference object.
 *
 * @category Controller
 * @package  OCA\Decidiq\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/p4-collaboration/tasks.md#task-7.2
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2
declare(strict_types=1);

namespace OCA\Decidiq\Controller;

use OCA\Decidiq\AppInfo\Application;
use OCA\Decidiq\Service\NotificationPreferenceRequestValidator;
use OCA\Decidiq\Service\NotificationPreferenceService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Controller for own NotificationPreference (read/update).
 *
 * @spec openspec/changes/p4-collaboration/tasks.md#task-7.2
 */
class NotificationPreferenceController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param IRequest $request HTTP request
	 * @param NotificationPreferenceService $preferenceService Preference service
	 * @param IUserSession $userSession Current user session
	 * @param NotificationPreferenceRequestValidator $validator Update-payload validator
	 *
	 * @spec openspec/changes/p4-collaboration/tasks.md#task-7.2
	 */
	public function __construct(
		IRequest $request,
		private readonly NotificationPreferenceService $preferenceService,
		private readonly IUserSession $userSession,
		private readonly NotificationPreferenceRequestValidator $validator,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Read own notification preferences.
	 *
	 * GET /api/notification-preference
	 *
	 * Per-USER scoped: the person is derived exclusively from the session —
	 * the request can never name another user (no IDOR surface). The response
	 * is defaults-merged and includes `accountEmail` so the UI can show the
	 * Nextcloud default for governance communications.
	 *
	 * @return JSONResponse
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/specs/user-settings/spec.md
	 */
	public function show(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['message' => 'Unauthenticated.'], Http::STATUS_UNAUTHORIZED);
		}

		$pref = $this->preferenceService->getPreferenceWithDefaults($user->getUID());
		$pref['accountEmail'] = $user->getEMailAddress();

		return new JSONResponse($pref);
	}//end show()

	/**
	 * Update own notification preferences.
	 *
	 * PUT /api/notification-preference
	 *
	 * Per-USER scoped (session user only). Validates every new preference
	 * category: reminder-time whitelist, delegation period sanity (mandatory
	 * expiry, until >= from, ISO dates), governance e-mail format, urgent
	 * phone shape, and the communication-language whitelist. Unknown fields
	 * are ignored (field whitelisting), invalid values are rejected with 422.
	 *
	 * @return JSONResponse
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/specs/user-settings/spec.md
	 */
	public function update(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['message' => 'Unauthenticated.'], Http::STATUS_UNAUTHORIZED);
		}

		$changes = [];
		$error = $this->validator->collect(changes: $changes);
		if ($error !== null) {
			return new JSONResponse(['message' => $error], Http::STATUS_UNPROCESSABLE_ENTITY);
		}

		$pref = $this->preferenceService->updatePreference($user->getUID(), $changes);
		$pref['accountEmail'] = $user->getEMailAddress();
		return new JSONResponse($pref);
	}//end update()
}//end class
