<?php
/**
 * Decidesk Board Vote Controller
 *
 * REST endpoints for casting and tallying BoardVotes.
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
 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-phase3-board-vote-controller
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2.
declare(strict_types=1);

namespace OCA\Decidesk\Controller;

use OCA\Decidesk\AppInfo\Application;
use OCA\Decidesk\Service\BoardVoteService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * REST controller for the BoardVote entity.
 *
 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-phase3-board-vote-controller
 */
class BoardVoteController extends Controller
{
    use BoardPortalControllerTrait;


    /**
     * Constructor for BoardVoteController.
     *
     * @param IRequest         $request         The HTTP request
     * @param BoardVoteService $voteService     The vote service
     * @param IUserSession     $userSession     The user session
     */
    public function __construct(
        IRequest $request,
        private readonly BoardVoteService $voteService,
        private readonly IUserSession $userSession,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()


    /**
     * Cast a vote.
     *
     * @param string $resolutionId UUID of the resolution
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-phase3-board-vote-controller
     *
     * @return JSONResponse
     */
    #[NoAdminRequired]
    public function cast(string $resolutionId): JSONResponse
    {
        $auth = $this->requireUserOr401($this->userSession);
        if ($auth !== null) {
            return $auth;
        }

        $boardMemberId = (string) $this->request->getParam('boardMemberId', '');
        $vote          = (string) $this->request->getParam('vote', '');
        if ($boardMemberId === '' || $vote === '') {
            return new JSONResponse(
                ['message' => "Missing required parameter 'boardMemberId' or 'vote'."],
                Http::STATUS_UNPROCESSABLE_ENTITY
            );
        }

        $extra = [
            'voteMethod'           => (string) $this->request->getParam('voteMethod', 'electronic'),
            'agendaItemKoppeling'  => (string) $this->request->getParam('agendaItemKoppeling', ''),
            'anonymized'           => (bool) $this->request->getParam('anonymized', false),
            'proxyHolder'          => (string) $this->request->getParam('proxyHolder', ''),
        ];

        return $this->respondFromResult(
            $this->voteService->cast($resolutionId, $boardMemberId, $vote, $extra),
            'vote',
            Http::STATUS_CREATED
        );

    }//end cast()


    /**
     * Return the running tally for a resolution.
     *
     * @param string $resolutionId UUID of the resolution
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-phase3-board-vote-controller
     *
     * @return JSONResponse
     */
    #[NoAdminRequired]
    public function tally(string $resolutionId): JSONResponse
    {
        $auth = $this->requireUserOr401($this->userSession);
        if ($auth !== null) {
            return $auth;
        }

        $result = $this->voteService->tally($resolutionId);
        if ($result['success'] === false) {
            return new JSONResponse(['message' => 'Failed to compute tally.'], Http::STATUS_SERVICE_UNAVAILABLE);
        }

        return new JSONResponse(
            [
                'tally' => $result['tally'],
                'cast'  => $result['cast'],
                'total' => $result['total'],
            ]
        );

    }//end tally()


    /**
     * Return the raw vote rows for a resolution.
     *
     * @param string $resolutionId UUID of the resolution
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-phase3-board-vote-controller
     *
     * @return JSONResponse
     */
    #[NoAdminRequired]
    public function audit(string $resolutionId): JSONResponse
    {
        $auth = $this->requireUserOr401($this->userSession);
        if ($auth !== null) {
            return $auth;
        }

        $result = $this->voteService->audit($resolutionId);
        if ($result['success'] === false) {
            return new JSONResponse(['message' => 'Failed to read votes.'], Http::STATUS_SERVICE_UNAVAILABLE);
        }

        return new JSONResponse(
            [
                'results' => $result['votes'],
                'total'   => count($result['votes']),
            ]
        );

    }//end audit()


}//end class
