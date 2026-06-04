<?php
/**
 * Decidesk Board Voting Controller
 *
 * API endpoints for casting, tallying and closing board resolution votes.
 *
 * @category Controller
 * @package  OCA\Decidesk\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-4.2
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2.
declare(strict_types=1);

namespace OCA\Decidesk\Controller;

use OCA\Decidesk\AppInfo\Application;
use OCA\Decidesk\Service\BoardVotingService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Thin controller for board voting endpoints.
 *
 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-4.2
 */
class BoardVotingController extends Controller
{
    /**
     * Constructor.
     *
     * @param IRequest           $request       The request.
     * @param BoardVotingService $votingService The board voting service.
     * @param IUserSession       $userSession   The user session.
     * @param IGroupManager      $groupManager  The group manager.
     * @param IAppConfig         $appConfig     The app config.
     *
     * @return void
     *
     * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-4.2
     */
    public function __construct(
        IRequest $request,
        private readonly BoardVotingService $votingService,
        private readonly IUserSession $userSession,
        private readonly IGroupManager $groupManager,
        private readonly IAppConfig $appConfig,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);

    }//end __construct()

    /**
     * Resolve the current user UID, or null when anonymous.
     *
     * @return string|null
     */
    private function currentUid(): ?string
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return null;
        }

        return $user->getUID();

    }//end currentUid()

    /**
     * Require the caller to belong to the configured board chair group (or be admin).
     *
     * @return JSONResponse|null 403/401 response on failure, null on success.
     */
    private function requireChair(): ?JSONResponse
    {
        $uid = $this->currentUid();
        if ($uid === null) {
            return new JSONResponse(['message' => 'Unauthorized'], Http::STATUS_UNAUTHORIZED);
        }

        $chairGroup = $this->appConfig->getValueString('decidesk', 'board_chair_group', '');
        $authorized = $this->groupManager->isAdmin($uid);
        if ($chairGroup !== '') {
            $authorized = $this->groupManager->isInGroup($uid, $chairGroup);
        }

        if ($authorized === false) {
            return new JSONResponse(['message' => 'Board chair role required'], Http::STATUS_FORBIDDEN);
        }

        return null;

    }//end requireChair()

    /**
     * Cast a vote on a resolution.
     *
     * POST /api/board/resolutions/{id}/cast-vote
     *
     * @param string $id The resolution UUID.
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-4.2
     */
    #[NoAdminRequired]
    public function castVote(string $id): JSONResponse
    {
        $uid = $this->currentUid();
        if ($uid === null) {
            return new JSONResponse(['message' => 'Unauthorized'], Http::STATUS_UNAUTHORIZED);
        }

        $params        = $this->request->getParams();
        $boardMemberId = (string) ($params['boardMemberId'] ?? '');
        $vote          = (string) ($params['vote'] ?? '');
        $voteMethod    = (string) ($params['voteMethod'] ?? 'electronic');
        if ($boardMemberId === '' || $vote === '') {
            return new JSONResponse(['message' => 'boardMemberId and vote are required'], Http::STATUS_BAD_REQUEST);
        }

        try {
            $ballot = $this->votingService->castVote($id, $boardMemberId, $vote, $voteMethod, $uid);
            return new JSONResponse($ballot, Http::STATUS_CREATED);
        } catch (\RuntimeException $e) {
            return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }

    }//end castVote()

    /**
     * Close voting on a resolution (chair only) and compute adoption.
     *
     * POST /api/board/resolutions/{id}/close-vote
     *
     * @param string $id The resolution UUID.
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-4.2
     */
    #[NoAdminRequired]
    public function closeVote(string $id): JSONResponse
    {
        $guard = $this->requireChair();
        if ($guard !== null) {
            return $guard;
        }

        $totalSeats = (int) ($this->request->getParam('totalSeats', 0));
        if ($totalSeats <= 0) {
            return new JSONResponse(['message' => 'totalSeats is required'], Http::STATUS_BAD_REQUEST);
        }

        try {
            $outcome = $this->votingService->closeVote($id, $totalSeats, (string) $this->currentUid());
            return new JSONResponse($outcome, Http::STATUS_OK);
        } catch (\RuntimeException $e) {
            return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }

    }//end closeVote()

    /**
     * Return the running tally for an open vote (chair only).
     *
     * GET /api/board/resolutions/{id}/running-tally
     *
     * @param string $id The resolution UUID.
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-4.2
     */
    #[NoAdminRequired]
    public function runningTally(string $id): JSONResponse
    {
        $guard = $this->requireChair();
        if ($guard !== null) {
            return $guard;
        }

        $totalSeats = (int) ($this->request->getParam('totalSeats', 0));
        try {
            $tally = $this->votingService->computeResolutionAdoption($id, $totalSeats);
            return new JSONResponse($tally, Http::STATUS_OK);
        } catch (\RuntimeException $e) {
            return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }

    }//end runningTally()
}//end class
