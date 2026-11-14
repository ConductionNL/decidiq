<?php

/**
 * Decidiq Publication Controller
 *
 * Action endpoints for the public-publication flow: publish, withdraw, and
 * rectify. Plain CRUD on PublicationRecord/PublicationPayload stays on the OR
 * object API per ADR-022 — this controller exposes ACTIONS only.
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
 * @spec openspec/specs/public-publication/spec.md
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2
declare(strict_types=1);

namespace OCA\Decidiq\Controller;

use OCA\Decidiq\Exception\AccessDeniedException;
use OCA\Decidiq\Exception\AccessibilityGateException;
use OCA\Decidiq\Exception\ConfidentialityUnreadableException;
use OCA\Decidiq\Exception\MissingObjectException;
use OCA\Decidiq\Kernel\AppIdentity;
use OCA\Decidiq\Kernel\Contracts\PublicationServiceInterface;
use OCA\Decidiq\Kernel\Contracts\PublicationStaffGuardInterface;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Controller for publish / withdraw / rectify actions.
 *
 * Every method carries a per-object staff RBAC guard (no-admin-idor): a
 * non-admin caller must hold a chair/secretary role on the meeting linked to
 * the targeted governance object. Unauthenticated => 401, unauthorised => 403.
 *
 * @spec openspec/specs/public-publication/spec.md
 */
class PublicationController extends Controller {

	/**
	 * The source types a publish request may name.
	 *
	 * @var array<int, string>
	 */
	private const SOURCE_TYPES = ['decision', 'agenda', 'minutes', 'activity'];

	/**
	 * Publish and rectify failures with a status of their own, checked in order.
	 *
	 * @var array<class-string<\Throwable>, int>
	 */
	private const PUBLISH_FAILURE_STATUS = [
		AccessDeniedException::class => Http::STATUS_UNPROCESSABLE_ENTITY,
		MissingObjectException::class => Http::STATUS_NOT_FOUND,
		ConfidentialityUnreadableException::class => Http::STATUS_SERVICE_UNAVAILABLE,
	];

	/**
	 * Withdraw failures with a status of their own, checked in order.
	 *
	 * @var array<class-string<\Throwable>, int>
	 */
	private const WITHDRAW_FAILURE_STATUS = [
		MissingObjectException::class => Http::STATUS_NOT_FOUND,
		\InvalidArgumentException::class => Http::STATUS_UNPROCESSABLE_ENTITY,
	];
	/**
	 * Constructor.
	 *
	 * @param IRequest $request The HTTP request.
	 * @param PublicationServiceInterface $publicationService The publication orchestrator.
	 * @param PublicationStaffGuardInterface $staffGuard Per-object staff authority.
	 *
	 * @spec openspec/specs/public-publication/spec.md
	 */
	public function __construct(
		IRequest $request,
		private readonly PublicationServiceInterface $publicationService,
		private readonly PublicationStaffGuardInterface $staffGuard,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Publish an eligible decision / agenda / minutes object.
	 *
	 * POST /api/publications
	 * Body: { sourceType: decision|agenda|minutes|activity, sourceId: <uuid> }
	 *
	 * An activity is a public meeting on the residents' calendar.
	 *
	 * @spec openspec/specs/public-publication/spec.md
	 * @spec openspec/specs/activity-calendar/spec.md#requirement-req-acal-004-staff-publish-a-public-meeting-to-the-residents-calendar
	 *
	 * @return JSONResponse
	 */
	#[NoAdminRequired]
	public function publish(): JSONResponse {
		$userId = $this->staffGuard->currentUid();
		if ($userId === null) {
			return $this->authenticationRequired();
		}

		$source = $this->requestedSource();
		if ($source === null) {
			return new JSONResponse(['message' => 'sourceType (decision|agenda|minutes|activity) and sourceId are required.'], Http::STATUS_BAD_REQUEST);
		}

		$denied = $this->requireStaffForSource(sourceType: $source->type, sourceId: $source->id);
		if ($denied !== null) {
			return $denied;
		}

		return $this->publishResponse(source: $source, userId: $userId);
	}//end publish()

	/**
	 * The source the request names, or null when its type or id is not acceptable.
	 *
	 * @return PublicationSource|null
	 *
	 * @spec openspec/specs/public-publication/spec.md
	 */
	private function requestedSource(): ?PublicationSource {
		$sourceType = (string)$this->request->getParam('sourceType', '');
		$sourceId = (string)$this->request->getParam('sourceId', '');
		if (in_array($sourceType, self::SOURCE_TYPES, true) === false || $sourceId === '') {
			return null;
		}

		return new PublicationSource(type: $sourceType, id: $sourceId);
	}//end requestedSource()

	/**
	 * Publish the source and answer 201, or the failure's status.
	 *
	 * @param PublicationSource $source The validated source.
	 * @param string            $userId The publishing staff member.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/public-publication/spec.md
	 */
	private function publishResponse(PublicationSource $source, string $userId): JSONResponse {
		try {
			$result = $this->publicationService->publish(
				$source->type,
				$source->id,
				$userId,
				accessibilityAcknowledged: filter_var($this->request->getParam('accessibilityAcknowledged', false), FILTER_VALIDATE_BOOLEAN),
				accessibilityOverride: $this->accessibilityOverrideReason()
			);
			return new JSONResponse($result, Http::STATUS_CREATED);
		} catch (AccessibilityGateException $e) {
			return new JSONResponse($e->payload(), Http::STATUS_CONFLICT);
		} catch (\Throwable $e) {
			return $this->failureResponse(failure: $e, statuses: self::PUBLISH_FAILURE_STATUS);
		}
	}//end publishResponse()

	/**
	 * The override reason the request gives for publishing past the accessibility gate, or null.
	 *
	 * Accepts `accessibilityOverride: {reason}` and a bare string.
	 *
	 * @return string|null
	 *
	 * @spec openspec/changes/document-accessibility-check/specs/document-accessibility-check/spec.md#requirement-req-003-publication-gate-on-failing-documents-with-recorded-override
	 */
	private function accessibilityOverrideReason(): ?string {
		$override = $this->request->getParam('accessibilityOverride');
		if (is_array($override) === true) {
			$override = ($override['reason'] ?? null);
		}

		if (is_string($override) === false) {
			return null;
		}

		return $override;
	}//end accessibilityOverrideReason()

	/**
	 * Withdraw a publication with a mandatory reason.
	 *
	 * POST /api/publications/{recordId}/withdraw
	 * Body: { reason: <string> }
	 *
	 * @param string $recordId UUID of the PublicationRecord.
	 *
	 * @spec openspec/specs/public-publication/spec.md
	 *
	 * @return JSONResponse
	 */
	#[NoAdminRequired]
	public function withdraw(string $recordId): JSONResponse {
		$userId = $this->staffGuard->currentUid();
		if ($userId === null) {
			return $this->authenticationRequired();
		}

		$denied = $this->requireStaffForRecord(recordId: $recordId);
		if ($denied !== null) {
			return $denied;
		}

		$reason = (string)$this->request->getParam('reason', '');
		if (trim($reason) === '') {
			return new JSONResponse(['message' => 'A withdraw reason is required.'], Http::STATUS_UNPROCESSABLE_ENTITY);
		}

		return $this->withdrawResponse(change: new PublicationRecordChange(recordId: $recordId, userId: $userId, reason: $reason));
	}//end withdraw()

	/**
	 * Withdraw the record and answer 200, or the failure's status.
	 *
	 * @param PublicationRecordChange $change The validated withdraw request.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/public-publication/spec.md
	 */
	private function withdrawResponse(PublicationRecordChange $change): JSONResponse {
		try {
			$result = $this->publicationService->withdraw($change->recordId, $change->userId, $change->reason);
			return new JSONResponse($result, Http::STATUS_OK);
		} catch (\Throwable $e) {
			return $this->failureResponse(failure: $e, statuses: self::WITHDRAW_FAILURE_STATUS);
		}
	}//end withdrawResponse()

	/**
	 * Rectify a publication: publish a corrected version, withdraw the old one.
	 *
	 * POST /api/publications/{recordId}/rectify
	 * Body: { reason?: <string> }
	 *
	 * @param string $recordId UUID of the PublicationRecord to rectify.
	 *
	 * @spec openspec/specs/public-publication/spec.md
	 *
	 * @return JSONResponse
	 */
	#[NoAdminRequired]
	public function rectify(string $recordId): JSONResponse {
		$userId = $this->staffGuard->currentUid();
		if ($userId === null) {
			return $this->authenticationRequired();
		}

		$denied = $this->requireStaffForRecord(recordId: $recordId);
		if ($denied !== null) {
			return $denied;
		}

		$reason = (string)$this->request->getParam('reason', '');

		return $this->rectifyResponse(change: new PublicationRecordChange(recordId: $recordId, userId: $userId, reason: $reason));
	}//end rectify()

	/**
	 * Rectify the record and answer 201, or the failure's status.
	 *
	 * @param PublicationRecordChange $change The rectify request.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/public-publication/spec.md
	 */
	private function rectifyResponse(PublicationRecordChange $change): JSONResponse {
		try {
			$result = $this->publicationService->rectify($change->recordId, $change->userId, $change->reason);
			return new JSONResponse($result, Http::STATUS_CREATED);
		} catch (\Throwable $e) {
			return $this->failureResponse(failure: $e, statuses: self::PUBLISH_FAILURE_STATUS);
		}
	}//end rectifyResponse()

	/**
	 * The answer for a failed service call: the first matching failure class's
	 * status with the failure's message, else a 500 that leaks no detail.
	 *
	 * @param \Throwable                         $failure  The failure.
	 * @param array<class-string<\Throwable>, int> $statuses Statuses by failure class, checked in order.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/public-publication/spec.md
	 */
	private function failureResponse(\Throwable $failure, array $statuses): JSONResponse {
		foreach ($statuses as $class => $status) {
			if ($failure instanceof $class) {
				return new JSONResponse(['message' => $failure->getMessage()], $status);
			}
		}

		return new JSONResponse(['message' => 'Internal server error.'], Http::STATUS_INTERNAL_SERVER_ERROR);
	}//end failureResponse()

	/**
	 * The 401 answer for a request without a session.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/public-publication/spec.md
	 */
	private function authenticationRequired(): JSONResponse {
		return new JSONResponse(['message' => 'Authentication required.'], Http::STATUS_UNAUTHORIZED);
	}//end authenticationRequired()

	/**
	 * Per-object staff guard keyed by a publication source object.
	 *
	 * Admin passes. Otherwise the caller must hold a chair/secretary role on the
	 * meeting linked to the source object (decision/agenda/minutes). Fails CLOSED.
	 *
	 * @param string $sourceType One of decision|agenda|minutes.
	 * @param string $sourceId UUID of the source object.
	 *
	 * @spec openspec/specs/public-publication/spec.md
	 *
	 * @return JSONResponse|null Null when authorised; a 403 otherwise.
	 */
	private function requireStaffForSource(string $sourceType, string $sourceId): ?JSONResponse {
		$outcome = $this->staffGuard->checkSource(sourceType: $sourceType, sourceId: $sourceId);
		if ($outcome === PublicationStaffGuardInterface::ALLOWED) {
			return null;
		}

		return new JSONResponse(
			['message' => 'Forbidden: governance-body authority (chair or secretary) required to publish.'],
			Http::STATUS_FORBIDDEN
		);

	}//end requireStaffForSource()

	/**
	 * Per-object staff guard keyed by an existing PublicationRecord.
	 *
	 * @param string $recordId UUID of the PublicationRecord.
	 *
	 * @spec openspec/specs/public-publication/spec.md
	 *
	 * @return JSONResponse|null Null when authorised; a 403/404 otherwise.
	 */
	private function requireStaffForRecord(string $recordId): ?JSONResponse {
		$outcome = $this->staffGuard->checkRecord(recordId: $recordId);
		if ($outcome === PublicationStaffGuardInterface::ALLOWED) {
			return null;
		}

		if ($outcome === PublicationStaffGuardInterface::RECORD_NOT_FOUND) {
			return new JSONResponse(['message' => 'Publication record not found.'], Http::STATUS_NOT_FOUND);
		}

		return new JSONResponse(
			['message' => 'Forbidden: governance-body authority (chair or secretary) required.'],
			Http::STATUS_FORBIDDEN
		);

	}//end requireStaffForRecord()
}//end class
