<?php

/**
 * Register to speak at a meeting, and moderate the requests.
 *
 * Any signed-in user may register; the secretariat staff of the meeting
 * registers past the deadline, approves, rejects and refers. Every moderation
 * is guarded on the request's own meeting, and a referral on its new meeting
 * as well.
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
 * @spec openspec/changes/inspreekrecht-plenair/specs/inspraak-register/spec.md#requirement-req-ins-004-registration-api-enforces-policy-and-closes-at-the-deadline
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Controller;

use Closure;
use OCA\Decidiq\Exception\SpeakingRequestRefusedException;
use OCA\Decidiq\Kernel\AppIdentity;
use OCA\Decidiq\Kernel\Contracts\SpeakingRequestServiceInterface;
use OCA\Decidiq\Kernel\Contracts\TranscriptionStaffGuardInterface;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Registration and moderation endpoints for speaking requests.
 */
class SpeakingRequestController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param IRequest                         $request  The request.
	 * @param SpeakingRequestServiceInterface  $requests The registration and moderation service.
	 * @param TranscriptionStaffGuardInterface $guard    Decides whether the user is staff of a meeting.
	 */
	public function __construct(
		IRequest $request,
		private readonly SpeakingRequestServiceInterface $requests,
		private readonly TranscriptionStaffGuardInterface $guard,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Register to speak. Registering past the deadline (override) is for the meeting's staff.
	 *
	 * @no-admin-idor-exempt Creates a new request and reads no existing object by a caller-supplied id beyond the meeting the policy checks; registering is open to every signed-in user by design (REQ-INS-004), and the late override is guarded on the meeting.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/inspreekrecht-plenair/specs/inspraak-register/spec.md#requirement-req-ins-004-registration-api-enforces-policy-and-closes-at-the-deadline
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 20, period: 3600)]
	public function create(): JSONResponse {
		$input    = [
			'meeting'        => $this->request->getParam('meeting', ''),
			'agendaItem'     => $this->request->getParam('agendaItem', ''),
			'governanceBody' => $this->request->getParam('governanceBody', ''),
			'topic'          => $this->request->getParam('topic', []),
			'contactDetails' => $this->request->getParam('contactDetails', []),
		];
		$override = filter_var($this->request->getParam('override', false), FILTER_VALIDATE_BOOLEAN);
		if ($override === true) {
			$denied = $this->guard->forMeeting(meetingId: (string)$input['meeting']);
			if ($denied !== null) {
				return $denied;
			}
		}

		return $this->answer(action: fn (): array => $this->requests->register(input: $input, override: $override), status: Http::STATUS_CREATED);
	}//end create()

	/**
	 * Approve a request, with an optional speaking time and order.
	 *
	 * @param string $id The speaking request.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/inspreekrecht-plenair/specs/inspraak-register/spec.md#requirement-req-ins-005-griffier-moderation-approve-reject-refer
	 */
	#[NoAdminRequired]
	public function approve(string $id): JSONResponse {
		$minutes  = $this->optionalInt(value: $this->request->getParam('allottedMinutes'));
		$position = $this->optionalInt(value: $this->request->getParam('position'));
		return $this->moderated(
			requestId: $id,
			action: fn (): array => $this->requests->approve(requestId: $id, minutes: $minutes, position: $position)
		);
	}//end approve()

	/**
	 * Reject a request with a reason.
	 *
	 * @param string $id The speaking request.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/inspreekrecht-plenair/specs/inspraak-register/spec.md#requirement-req-ins-005-griffier-moderation-approve-reject-refer
	 */
	#[NoAdminRequired]
	public function reject(string $id): JSONResponse {
		$reason = (string)$this->request->getParam('rejectionReason', '');
		return $this->moderated(requestId: $id, action: fn (): array => $this->requests->reject(requestId: $id, reason: $reason));
	}//end reject()

	/**
	 * Refer a request to another meeting, and optionally another agenda item.
	 *
	 * @param string $id The speaking request.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/inspreekrecht-plenair/specs/inspraak-register/spec.md#requirement-req-ins-005-griffier-moderation-approve-reject-refer
	 */
	#[NoAdminRequired]
	public function refer(string $id): JSONResponse {
		$meetingId = (string)$this->request->getParam('meeting', '');
		$itemId    = (string)$this->request->getParam('agendaItem', '');
		$denied    = $this->guard->forMeeting(meetingId: $meetingId);
		if ($denied !== null) {
			return $denied;
		}

		$agendaItemId = null;
		if ($itemId !== '') {
			$agendaItemId = $itemId;
		}

		return $this->moderated(
			requestId: $id,
			action: fn (): array => $this->requests->refer(requestId: $id, meetingId: $meetingId, agendaItemId: $agendaItemId)
		);
	}//end refer()

	/**
	 * A request parameter as an integer, or null when it is absent or empty.
	 *
	 * @param mixed $value The parameter.
	 *
	 * @return int|null
	 */
	private function optionalInt(mixed $value): ?int {
		if ($value === null || $value === '') {
			return null;
		}

		return (int)$value;
	}//end optionalInt()

	/**
	 * Run a moderation when the user is staff of the request's meeting.
	 *
	 * @param string  $requestId The speaking request.
	 * @param Closure $action    The moderation.
	 *
	 * @return JSONResponse
	 */
	private function moderated(string $requestId, Closure $action): JSONResponse {
		$meetingId = $this->requests->meetingOf(requestId: $requestId);
		if ($meetingId === '') {
			return new JSONResponse(['message' => 'Speaking request not found.'], Http::STATUS_NOT_FOUND);
		}

		$denied = $this->guard->forMeeting(meetingId: $meetingId);
		if ($denied !== null) {
			return $denied;
		}

		return $this->answer(action: $action);
	}//end moderated()

	/**
	 * The action's result, or its refusal with the refusal's status.
	 *
	 * @param Closure $action The action.
	 * @param int     $status The status on success.
	 *
	 * @return JSONResponse
	 */
	private function answer(Closure $action, int $status=Http::STATUS_OK): JSONResponse {
		try {
			return new JSONResponse($action(), $status);
		} catch (SpeakingRequestRefusedException $e) {
			return new JSONResponse(['message' => $e->getMessage()], $e->getStatus());
		}
	}//end answer()
}//end class
