<?php

/**
 * Decidiq GuestInvitationController
 *
 * The organiser of an ad hoc meeting invites a guest from outside by email
 * (meeting-ad-hoc-with-guests, pla-20).
 *
 * @category Controller
 * @package  OCA\Decidiq\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/meeting-ad-hoc-with-guests/specs/meeting-management/spec.md#requirement-req-mah-001-an-organiser-runs-their-own-ad-hoc-meeting
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Decidiq\Controller;

use OCA\Decidiq\AppInfo\Application;
use OCA\Decidiq\Exception\GuestInvitationRefusedException;
use OCA\Decidiq\Service\GuestInvitationService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Guest invitations for ad hoc meetings.
 *
 * @spec openspec/changes/meeting-ad-hoc-with-guests/specs/meeting-management/spec.md#requirement-req-mah-001-an-organiser-runs-their-own-ad-hoc-meeting
 */
class GuestInvitationController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param IRequest               $request     The request
	 * @param GuestInvitationService $invitations The invitation service
	 * @param IUserSession           $userSession The user session
	 */
	public function __construct(
		IRequest $request,
		private readonly GuestInvitationService $invitations,
		private readonly IUserSession $userSession,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Invite a guest to the meeting: only its organiser or an administrator.
	 *
	 * @param string $id The meeting
	 *
	 * @return JSONResponse 201 with the guest, 403/404/422 when refused
	 *
	 * @spec openspec/changes/meeting-ad-hoc-with-guests/specs/meeting-management/spec.md#requirement-req-mah-001-an-organiser-runs-their-own-ad-hoc-meeting
	 */
	#[NoAdminRequired]
	public function invite(string $id): JSONResponse {
		$userId = ($this->userSession->getUser()?->getUID() ?? '');
		try {
			$this->invitations->requireOrganiserOf(meetingId: $id, userId: $userId);
			$result = $this->invitations->invite(
				meetingId: $id,
				name: (string)$this->request->getParam('name', ''),
				email: (string)$this->request->getParam('email', ''),
				userId: $userId
			);
		} catch (GuestInvitationRefusedException $e) {
			return new JSONResponse(['message' => $e->getMessage()], $e->getStatus());
		}

		return new JSONResponse($result, Http::STATUS_CREATED);
	}//end invite()
}//end class
