<?php

/**
 * Decidiq Citizen Advice Controller
 *
 * Lets the griffie open and close the advisory vote residents give on a
 * motion (participation-citizen-advisory-vote-on-motions, issue #1418).
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
 * @spec openspec/changes/participation-citizen-advisory-vote-on-motions/specs/citizen-participation/spec.md#requirement-req-cav-001-the-griffie-opens-and-closes-an-advisory-vote-on-a-motion
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Controller;

use OCA\Decidiq\AppInfo\Application;
use OCA\Decidiq\Exception\NotFoundException;
use OCA\Decidiq\Exception\ParticipationValidationException;
use OCA\Decidiq\Service\CitizenAdviceService;
use OCA\Decidiq\Service\MotionService;
use OCA\Decidiq\Service\ParticipantResolver;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Open and close a residents' advisory vote on a motion.
 *
 * Who may: an admin, a member of the secretariat group, or the chair or
 * secretary of the motion's own meeting. Anyone else gets 403 before the
 * motion is read.
 *
 * @spec openspec/changes/participation-citizen-advisory-vote-on-motions/specs/citizen-participation/spec.md#requirement-req-cav-001-the-griffie-opens-and-closes-an-advisory-vote-on-a-motion
 */
class CitizenAdviceController extends Controller {

	/**
	 * The group of the griffie (the secretariat).
	 *
	 * @var string
	 */
	public const SECRETARIAT_GROUP = 'decidiq-secretariat';

	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request.
	 * @param CitizenAdviceService $citizenAdviceService Opens, closes and counts the advisory vote.
	 * @param MotionService $motionService Resolves the motion's meeting.
	 * @param ParticipantResolver $participantResolver Resolves roles in a meeting.
	 * @param IUserSession $userSession The user session.
	 * @param IGroupManager $groupManager The group manager.
	 * @param LoggerInterface $logger The logger.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/participation-citizen-advisory-vote-on-motions/specs/citizen-participation/spec.md#requirement-req-cav-001-the-griffie-opens-and-closes-an-advisory-vote-on-a-motion
	 */
	public function __construct(
		IRequest $request,
		private readonly CitizenAdviceService $citizenAdviceService,
		private readonly MotionService $motionService,
		private readonly ParticipantResolver $participantResolver,
		private readonly IUserSession $userSession,
		private readonly IGroupManager $groupManager,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Open the advisory vote on a motion.
	 *
	 * POST /api/motions/{id}/citizen-advice/open
	 *
	 * @param string $id The motion's uuid.
	 *
	 * @return JSONResponse 200 with the motion, 403, 404, or 422 with the reason.
	 *
	 * @spec openspec/changes/participation-citizen-advisory-vote-on-motions/specs/citizen-participation/spec.md#requirement-req-cav-001-the-griffie-opens-and-closes-an-advisory-vote-on-a-motion
	 */
	#[NoAdminRequired]
	public function open(string $id): JSONResponse {
		$denied = $this->requireMotionManager(motionId: $id);
		if ($denied !== null) {
			return $denied;
		}

		return $this->respond(operation: fn (): array => $this->citizenAdviceService->open(motionId: $id));
	}//end open()

	/**
	 * Close the advisory vote on a motion and store its counts.
	 *
	 * POST /api/motions/{id}/citizen-advice/close
	 *
	 * @param string $id The motion's uuid.
	 *
	 * @return JSONResponse 200 with the motion, 403, 404, or 422 with the reason.
	 *
	 * @spec openspec/changes/participation-citizen-advisory-vote-on-motions/specs/citizen-participation/spec.md#requirement-req-cav-003-the-advisory-result-shows-apart-from-the-councils-vote
	 */
	#[NoAdminRequired]
	public function close(string $id): JSONResponse {
		$denied = $this->requireMotionManager(motionId: $id);
		if ($denied !== null) {
			return $denied;
		}

		return $this->respond(operation: fn (): array => $this->citizenAdviceService->close(motionId: $id));
	}//end close()

	/**
	 * Refuse anyone who is not an admin, in the secretariat group, or the
	 * chair or secretary of this motion's meeting.
	 *
	 * @param string $motionId The motion's uuid.
	 *
	 * @return JSONResponse|null A 401 or 403, or null when the caller may proceed.
	 *
	 * @spec openspec/changes/participation-citizen-advisory-vote-on-motions/specs/citizen-participation/spec.md#requirement-req-cav-001-the-griffie-opens-and-closes-an-advisory-vote-on-a-motion
	 */
	private function requireMotionManager(string $motionId): ?JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['message' => 'Unauthorized'], Http::STATUS_UNAUTHORIZED);
		}

		$uid = $user->getUID();
		if ($this->groupManager->isAdmin($uid) === true
			|| $this->groupManager->isInGroup($uid, self::SECRETARIAT_GROUP) === true
		) {
			return null;
		}

		$meetingId = $this->motionService->resolveMeetingId(motionId: $motionId);
		if ($meetingId !== null
			&& $this->participantResolver->hasRole(meetingId: $meetingId, nextcloudUid: $uid, roles: ['chair', 'secretary']) === true
		) {
			return null;
		}

		return new JSONResponse(
			['message' => 'Only the secretariat, or the chair or secretary of this motion\'s meeting, can open or close the advisory vote'],
			Http::STATUS_FORBIDDEN
		);
	}//end requireMotionManager()

	/**
	 * Run an open or close and map its outcome onto a response.
	 *
	 * @param callable(): array<string, mixed> $operation The operation.
	 *
	 * @return JSONResponse 200, 404, 422 or 500.
	 *
	 * @spec openspec/changes/participation-citizen-advisory-vote-on-motions/specs/citizen-participation/spec.md#requirement-req-cav-001-the-griffie-opens-and-closes-an-advisory-vote-on-a-motion
	 */
	private function respond(callable $operation): JSONResponse {
		try {
			return new JSONResponse(['motion' => $operation()]);
		} catch (NotFoundException $e) {
			return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_NOT_FOUND);
		} catch (ParticipationValidationException $e) {
			return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
		} catch (\Throwable $e) {
			$this->logger->error('Decidiq: opening or closing the advisory vote failed', ['exception' => $e->getMessage()]);
			return new JSONResponse(['message' => 'The advisory vote could not be changed'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}//end respond()
}//end class
