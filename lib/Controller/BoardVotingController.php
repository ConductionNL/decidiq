<?php
/**
 * Decidesk Board Voting Controller
 *
 * Thin REST controller for casting board votes, closing a resolution vote and reading
 * the adoption tally. Scoped to the board secretary/admin operator (the chairman closes
 * the vote via the secretary console in this T1 backend).
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
use OCA\Decidesk\Service\QuorumVerificationService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;

/**
 * Board resolution voting REST endpoints.
 *
 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-4.2
 */
class BoardVotingController extends Controller
{
    /**
     * Constructor for BoardVotingController.
     *
     * @param IRequest                  $request            The request object.
     * @param BoardVotingService        $boardVotingService The board voting service.
     * @param QuorumVerificationService $quorumService      The quorum verification service.
     * @param IUserSession              $userSession        The user session.
     * @param IGroupManager             $groupManager       The group manager.
     * @param IAppConfig                $appConfig          The app config.
     * @param ContainerInterface        $container          DI container (lazy ObjectService).
     *
     * @return void
     *
     * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-4.2
     */
    public function __construct(
        IRequest $request,
        private readonly BoardVotingService $boardVotingService,
        private readonly QuorumVerificationService $quorumService,
        private readonly IUserSession $userSession,
        private readonly IGroupManager $groupManager,
        private readonly IAppConfig $appConfig,
        private readonly ContainerInterface $container,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);

    }//end __construct()

    /**
     * Require the caller to be a board secretary or system administrator.
     *
     * @return JSONResponse|null A 401/403 response on failure, null on success.
     *
     * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-4.5
     */
    private function requireBoardSecretary(): ?JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['message' => 'Unauthorized'], Http::STATUS_UNAUTHORIZED);
        }

        $uid            = $user->getUID();
        $secretaryGroup = $this->appConfig->getValueString('decidesk', 'board_secretary_group', '');
        $inGroup        = ($secretaryGroup !== '' && $this->groupManager->isInGroup($uid, $secretaryGroup) === true);

        if ($inGroup === false && $this->groupManager->isAdmin($uid) === false) {
            return new JSONResponse(['message' => 'Board secretary or administrator role required'], Http::STATUS_FORBIDDEN);
        }

        return null;

    }//end requireBoardSecretary()

    /**
     * Resolve OpenRegister ObjectService.
     *
     * @return object
     */
    private function objectService(): object
    {
        return $this->container->get('OCA\OpenRegister\Service\ObjectService');

    }//end objectService()

    /**
     * Cast a vote on a resolution.
     *
     * @param string $id The Resolution UUID.
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-4.2
     */
    #[NoAdminRequired]
    public function castVote(string $id): JSONResponse
    {
        $guard = $this->requireBoardSecretary();
        if ($guard !== null) {
            return $guard;
        }

        $resolutionEntity = $this->objectService()->find(id: $id, register: 'decidesk', schema: 'resolution');
        if ($resolutionEntity === null) {
            return new JSONResponse(['message' => 'Resolution not found'], Http::STATUS_NOT_FOUND);
        }

        $resolution = $resolutionEntity->jsonSerialize();
        if (in_array((string) ($resolution['status'] ?? 'proposed'), ['adopted', 'rejected', 'withdrawn'], true) === true) {
            return new JSONResponse(['message' => 'Voting is closed for this resolution'], Http::STATUS_FORBIDDEN);
        }

        // Validate the caster's attendance type counts towards the meeting quorum
        // before accepting the vote (proxy votes count as 'proxy', otherwise 'present').
        $meetingId      = (string) ($resolution['meeting-koppeling'] ?? '');
        $voteMethod     = (string) $this->request->getParam('voteMethod', 'electronic');
        $attendanceType = 'present';
        if ($voteMethod === 'proxy') {
            $attendanceType = 'proxy';
        }

        if ($meetingId !== ''
            && $this->quorumService->verifyAttendance(meetingId: $meetingId, participantType: $attendanceType) === false
        ) {
            return new JSONResponse(['message' => 'Voter attendance type does not count towards quorum'], Http::STATUS_FORBIDDEN);
        }

        $anonymized = ((string) ($resolution['vote-type'] ?? 'named') === 'anonymous');

        try {
            $vote = $this->boardVotingService->saveVote(
                voteData: [
                    'resolution-koppeling'   => $id,
                    'board-member-koppeling' => (string) $this->request->getParam('boardMember', ''),
                    'vote'                   => (string) $this->request->getParam('vote', ''),
                    'vote-method'            => (string) $this->request->getParam('voteMethod', 'electronic'),
                    'proxy-holder'           => $this->request->getParam('proxyHolder'),
                ],
                anonymized: $anonymized
            );
        } catch (\InvalidArgumentException $e) {
            return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }

        return new JSONResponse($vote, Http::STATUS_CREATED);

    }//end castVote()

    /**
     * Close the vote on a resolution and persist the computed adoption status.
     *
     * @param string $id The Resolution UUID.
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-4.2
     */
    #[NoAdminRequired]
    public function closeVote(string $id): JSONResponse
    {
        $guard = $this->requireBoardSecretary();
        if ($guard !== null) {
            return $guard;
        }

        $objectService    = $this->objectService();
        $resolutionEntity = $objectService->find(id: $id, register: 'decidesk', schema: 'resolution');
        if ($resolutionEntity === null) {
            return new JSONResponse(['message' => 'Resolution not found'], Http::STATUS_NOT_FOUND);
        }

        try {
            $adoption = $this->boardVotingService->computeResolutionAdoption(resolutionId: $id);
        } catch (\RuntimeException $e) {
            return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_NOT_FOUND);
        }

        $status = 'rejected';
        if ($adoption['adopted'] === true) {
            $status = 'adopted';
        }

        $resolution = $resolutionEntity->jsonSerialize();

        $resolution['status']        = $status;
        $resolution['adoption-date'] = gmdate('Y-m-d\TH:i:s\Z');
        unset($resolution['@self']);

        $saved = $objectService->saveObject(register: 'decidesk', schema: 'resolution', object: $resolution);

        return new JSONResponse(
            [
                'resolution' => $saved->jsonSerialize(),
                'tally'      => $adoption,
            ]
        );

    }//end closeVote()

    /**
     * Return the running adoption tally for a resolution.
     *
     * @param string $id The Resolution UUID.
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-4.2
     */
    #[NoAdminRequired]
    public function tally(string $id): JSONResponse
    {
        $guard = $this->requireBoardSecretary();
        if ($guard !== null) {
            return $guard;
        }

        try {
            $adoption = $this->boardVotingService->computeResolutionAdoption(resolutionId: $id);
        } catch (\RuntimeException $e) {
            return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_NOT_FOUND);
        }

        return new JSONResponse($adoption);

    }//end tally()
}//end class
