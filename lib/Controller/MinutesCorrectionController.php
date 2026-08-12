<?php

/**
 * Decidesk Minutes Correction Controller
 *
 * The correction-suggestion half of the Minutes API: any meeting participant
 * may suggest a correction while the minutes are in draft or review, and only a
 * chair/secretary (or NC admin) may accept or reject one.
 *
 * Split out of MinutesController, which had grown past the point where its
 * responsibilities were readable. Both controllers share ONE authorisation
 * implementation (MinutesAccessGuard) so the two access rules cannot drift.
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
 * @spec openspec/specs/resolution-minutes/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidesk\Controller;

use DateTimeImmutable;
use DateTimeInterface;
use Exception;
use OCA\Decidesk\AppInfo\Application;
use OCA\Decidesk\Service\MinutesAccessGuard;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Correction suggestions on a Minutes record.
 *
 * @spec openspec/specs/resolution-minutes/spec.md
 */
class MinutesCorrectionController extends Controller {
	/**
	 * Constructor for MinutesCorrectionController.
	 *
	 * @param IRequest $request The HTTP request
	 * @param MinutesAccessGuard $accessGuard Per-object minutes authorisation
	 * @param ObjectService $objectService OR object service
	 * @param IUserSession $userSession The current user session
	 *
	 * @return void
	 *
	 * @spec openspec/specs/resolution-minutes/spec.md
	 */
	public function __construct(
		IRequest $request,
		private readonly MinutesAccessGuard $accessGuard,
		private readonly ObjectService $objectService,
		private readonly IUserSession $userSession,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);

	}//end __construct()

	/**
	 * Submit a correction suggestion on a Minutes record.
	 *
	 * POST /api/minutes/{minutesId}/corrections
	 *
	 * Body: { "text": "<the suggested correction>" }
	 *
	 * The author is attributed server-side from the authenticated session —
	 * any client-sent author fields are ignored. Corrections are accepted
	 * while the lifecycle is draft or review only.
	 *
	 * Returns 200 with { corrections: [...] } on success.
	 * Returns 400 when the text is missing/empty.
	 * Returns 401 when not authenticated.
	 * Returns 403 when the caller is not a participant of the linked meeting.
	 * Returns 404 when the Minutes object is not found.
	 * Returns 409 when the lifecycle no longer accepts corrections.
	 *
	 * @param string $minutesId The UUID of the Minutes object
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/resolution-minutes/spec.md
	 */
	#[NoAdminRequired]
	public function addCorrection(string $minutesId): JSONResponse {
		$denied = $this->accessGuard->requireParticipant(minutesId: $minutesId);
		if ($denied !== null) {
			return $denied;
		}

		$text = $this->request->getParam('text');
		if (is_string($text) === false || trim($text) === '') {
			return new JSONResponse(
				['message' => 'A non-empty correction text is required.'],
				Http::STATUS_BAD_REQUEST
			);
		}

		try {
			// OpenRegister's find() THROWS DoesNotExistException for an unknown
			// id; it does not return null. DoesNotExistException extends
			// \Exception, so it was swallowed by the `catch (Exception)` at the
			// bottom of this method and answered 500 "Internal server error" —
			// while the `=== null` branch below, written to answer 404, could
			// never run. An ordinary "no such minutes" request produced a
			// server error.
			//
			// The null check is KEPT rather than replaced: find() is typed
			// `?ObjectEntity`, so null remains reachable in principle, and a
			// guard that costs nothing should not be removed on the strength of
			// current behaviour alone.
			$minutesEntity = $this->objectService->find(id: $minutesId, register: 'decidesk', schema: 'minutes');
			if ($minutesEntity === null) {
				return new JSONResponse(
					['message' => 'Minutes not found.'],
					Http::STATUS_NOT_FOUND
				);
			}

			$minutes = $minutesEntity->jsonSerialize();
			$lifecycle = ($minutes['lifecycle'] ?? 'draft');
			if (in_array($lifecycle, ['draft', 'review'], true) === false) {
				return new JSONResponse(
					['message' => 'Corrections can only be suggested while the minutes are in draft or review.'],
					Http::STATUS_CONFLICT
				);
			}

			$user = $this->userSession->getUser();

			$corrections = [];
			if (is_array($minutes['corrections'] ?? null) === true) {
				$corrections = $minutes['corrections'];
			}

			$corrections[] = [
				'id' => bin2hex(random_bytes(8)),
				'author' => $user->getUID(),
				'authorName' => $user->getDisplayName(),
				'text' => trim($text),
				'status' => 'proposed',
				'createdAt' => (new DateTimeImmutable())->format(DateTimeInterface::ATOM),
			];

			$minutes['corrections'] = $corrections;
			$this->objectService->saveObject(
				object: $minutes,
				register: 'decidesk',
				schema: 'minutes',
				uuid: $minutesId
			);

			return new JSONResponse(['corrections' => $corrections]);
		} catch (DoesNotExistException) {
			// Must precede the Exception arm: DoesNotExistException extends
			// \Exception, so an unknown id would otherwise be reported as a
			// server error rather than as the 404 it is.
			return new JSONResponse(
				['message' => 'Minutes not found.'],
				Http::STATUS_NOT_FOUND
			);
		} catch (Exception $e) {
			return new JSONResponse(
				['message' => 'Internal server error.'],
				Http::STATUS_INTERNAL_SERVER_ERROR
			);
		}//end try

	}//end addCorrection()

	/**
	 * Accept or reject a correction suggestion (chair/secretary only).
	 *
	 * PUT /api/minutes/{minutesId}/corrections/{correctionId}
	 *
	 * Body: { "status": "accepted" | "rejected" }
	 *
	 * Returns 200 with the updated correction on success.
	 * Returns 400 when the status value is invalid.
	 * Returns 401/403 per the chair/secretary guard.
	 * Returns 404 when the Minutes object or the correction is not found.
	 * Returns 409 when the correction was already resolved.
	 *
	 * @param string $minutesId The UUID of the Minutes object
	 * @param string $correctionId The correction identifier
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/resolution-minutes/spec.md
	 */
	#[NoAdminRequired]
	public function resolveCorrection(string $minutesId, string $correctionId): JSONResponse {
		$denied = $this->accessGuard->requireChairOrAdmin(minutesId: $minutesId);
		if ($denied !== null) {
			return $denied;
		}

		$status = $this->request->getParam('status');
		if (in_array($status, ['accepted', 'rejected'], true) === false) {
			return new JSONResponse(
				['message' => 'Status must be "accepted" or "rejected".'],
				Http::STATUS_BAD_REQUEST
			);
		}

		try {
			return $this->applyCorrectionResolution(
				minutesId: $minutesId,
				correctionId: $correctionId,
				status: $status
			);
		} catch (DoesNotExistException) {
			// Must precede the Exception arm: DoesNotExistException extends
			// \Exception, so an unknown id would otherwise be reported as a
			// server error rather than as the 404 it is.
			return new JSONResponse(
				['message' => 'Minutes not found.'],
				Http::STATUS_NOT_FOUND
			);
		} catch (Exception $e) {
			return new JSONResponse(
				['message' => 'Internal server error.'],
				Http::STATUS_INTERNAL_SERVER_ERROR
			);
		}//end try

	}//end resolveCorrection()

	/**
	 * Load the Minutes, resolve one correction and persist the result.
	 *
	 * Returns 404 when the Minutes or the correction is absent, 409 when the
	 * correction was already resolved, 200 with the updated correction on
	 * success.
	 *
	 * @param string $minutesId The UUID of the Minutes object
	 * @param string $correctionId The correction identifier
	 * @param string $status 'accepted' or 'rejected'
	 *
	 * @return JSONResponse
	 *
	 * @throws DoesNotExistException When OpenRegister cannot resolve the
	 *      Minutes uuid in any magic table. Note this is a SEPARATE path from
	 *      the `$minutesEntity === null` branch below: `ObjectService::find()`
	 *      returns null for some misses and raises for others, and both end as
	 *      a 404 — the null here, the throw in resolveCorrection(), which is
	 *      this method's only caller and already translates it.
	 * @throws Exception When `saveObject()` rejects the updated Minutes.
	 *      resolveCorrection() maps this to a 500. Declared rather than caught
	 *      here on purpose: this helper's job is to decide the outcome, and
	 *      moving the translation into it would put two catch sites on one
	 *      path and let them drift apart.
	 *
	 * @spec openspec/specs/resolution-minutes/spec.md
	 */
	private function applyCorrectionResolution(string $minutesId, string $correctionId, string $status): JSONResponse {
		$minutesEntity = $this->objectService->find(id: $minutesId, register: 'decidesk', schema: 'minutes');
		if ($minutesEntity === null) {
			return new JSONResponse(
				['message' => 'Minutes not found.'],
				Http::STATUS_NOT_FOUND
			);
		}

		$minutes = $minutesEntity->jsonSerialize();
		$corrections = ($minutes['corrections'] ?? null);
		if (is_array($corrections) === false) {
			$corrections = [];
		}

		$index = $this->findCorrectionIndex(corrections: $corrections, correctionId: $correctionId);
		if ($index === null) {
			return new JSONResponse(
				['message' => 'Correction not found.'],
				Http::STATUS_NOT_FOUND
			);
		}

		if (($corrections[$index]['status'] ?? 'proposed') !== 'proposed') {
			return new JSONResponse(
				['message' => 'This correction has already been resolved.'],
				Http::STATUS_CONFLICT
			);
		}

		$updated = $this->markCorrectionResolved(correction: $corrections[$index], status: $status);

		$corrections[$index] = $updated;
		$minutes['corrections'] = $corrections;

		$this->objectService->saveObject(
			object: $minutes,
			register: 'decidesk',
			schema: 'minutes',
			uuid: $minutesId
		);

		return new JSONResponse(['correction' => $updated]);
	}//end applyCorrectionResolution()

	/**
	 * Locate the array key of a correction by its identifier.
	 *
	 * @param array<mixed> $corrections The corrections list from the Minutes object
	 * @param string $correctionId The correction identifier to find
	 *
	 * @return int|string|null The array key, or null when no entry matches
	 *
	 * @spec openspec/specs/resolution-minutes/spec.md
	 */
	private function findCorrectionIndex(array $corrections, string $correctionId): int|string|null {
		foreach ($corrections as $index => $correction) {
			if (is_array($correction) === true && ($correction['id'] ?? '') === $correctionId) {
				return $index;
			}
		}

		return null;
	}//end findCorrectionIndex()

	/**
	 * Stamp the resolution outcome onto a correction, attributing it server-side.
	 *
	 * @param array<string, mixed> $correction The correction to stamp
	 * @param string $status 'accepted' or 'rejected'
	 *
	 * @return array<string, mixed> The stamped correction
	 *
	 * @spec openspec/specs/resolution-minutes/spec.md
	 */
	private function markCorrectionResolved(array $correction, string $status): array {
		$correction['status'] = $status;
		$correction['resolvedBy'] = $this->userSession->getUser()->getUID();
		$correction['resolvedAt'] = (new DateTimeImmutable())->format(DateTimeInterface::ATOM);

		return $correction;
	}//end markCorrectionResolved()
}//end class
