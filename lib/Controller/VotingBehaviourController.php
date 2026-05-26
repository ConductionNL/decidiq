<?php

/**
 * Decidesk Voting Behaviour Controller
 *
 * REST API endpoint for voting behaviour statistics.
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
 * @spec openspec/changes/p2-motion-and-voting-core-t2/tasks.md#task-1
 */

declare(strict_types=1);

namespace OCA\Decidesk\Controller;

use OCA\Decidesk\AppInfo\Application;
use OCA\Decidesk\Service\VotingBehaviourService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Thin controller for voting behaviour API endpoint.
 *
 * @spec openspec/changes/p2-motion-and-voting-core-t2/tasks.md#task-1
 */
class VotingBehaviourController extends Controller
{
    /**
     * Constructor for VotingBehaviourController.
     *
     * @param IRequest               $request          The request object
     * @param VotingBehaviourService $behaviourService The voting behaviour service
     * @param IUserSession           $userSession      The user session
     * @param IGroupManager          $groupManager     The group manager
     *
     * @return void
     *
     * @spec openspec/changes/p2-motion-and-voting-core-t2/tasks.md#task-1
     */
    public function __construct(
        IRequest $request,
        private readonly VotingBehaviourService $behaviourService,
        private readonly IUserSession $userSession,
        private readonly IGroupManager $groupManager,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);

    }//end __construct()

    /**
     * Get voting behaviour statistics for a participant.
     *
     * Requires authentication. Current user may only access own stats unless they
     * hold chair, secretary, or admin role.
     *
     * @param string $participantId    The participant UUID
     * @param string $governanceBodyId The governance body UUID (required in query params)
     *
     * @return JSONResponse The statistics array or error
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/p2-motion-and-voting-core-t2/tasks.md#task-1
     */
    #[NoAdminRequired]
    public function getStats(string $participantId, string $governanceBodyId=''): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['message' => 'Unauthorized'], Http::STATUS_UNAUTHORIZED);
        }

        $uid = $user->getUID();

        // Authorization: user may access own stats or must hold admin rights.
        // Allow if this is the user's own stats, otherwise require admin.
        $isOwnStats  = ($participantId === $uid);
        $canViewOther = $isOwnStats || $this->groupManager->isAdmin($uid);

        if ($canViewOther === false) {
            return new JSONResponse(['message' => 'Forbidden'], Http::STATUS_FORBIDDEN);
        }

        if ($governanceBodyId === '') {
            return new JSONResponse(['message' => 'governanceBodyId required'], Http::STATUS_BAD_REQUEST);
        }

        $stats = $this->behaviourService->getStats(
            participantId: $participantId,
            governanceBodyId: $governanceBodyId,
        );

        return new JSONResponse($stats);

    }//end getStats()
}//end class
