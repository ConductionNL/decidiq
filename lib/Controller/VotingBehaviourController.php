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

use InvalidArgumentException;
use OCA\Decidesk\AppInfo\Application;
use OCA\Decidesk\Service\VotingBehaviourService;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
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
     * @param ObjectService          $objectService    OpenRegister object service for participant lookup
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
        private readonly ObjectService $objectService,
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
     * @throws \Throwable When OpenRegister fails for a reason other than an
     *                    unknown id (those are translated to 404/400 below)
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

        $canViewOther = $this->ownsParticipant(participantId: $participantId, uid: $uid)
            || $this->groupManager->isAdmin($uid);

        if ($canViewOther === false) {
            return new JSONResponse(['message' => 'Forbidden'], Http::STATUS_FORBIDDEN);
        }

        if ($governanceBodyId === '') {
            return new JSONResponse(['message' => 'governanceBodyId required'], Http::STATUS_BAD_REQUEST);
        }

        // The service reaches OpenRegister for rounds and votes, so the same
        // "unknown id throws" contract applies to the governance body. Translate
        // it to the status the caller is owed instead of letting it surface as
        // a 500; anything else still propagates.
        try {
            $stats = $this->behaviourService->getStats(
                participantId: $participantId,
                governanceBodyId: $governanceBodyId,
            );
        } catch (DoesNotExistException) {
            return new JSONResponse(
                ['message' => 'Governance body or participant not found.'],
                Http::STATUS_NOT_FOUND
            );
        } catch (InvalidArgumentException $e) {
            return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }

        return new JSONResponse($stats);

    }//end getStats()

    /**
     * Whether a Nextcloud user owns the given participant record.
     *
     * `participantId` is an OpenRegister UUID and `$uid` is a Nextcloud user id
     * — different namespaces that must never be compared directly. Ownership is
     * resolved by loading the participant and reading its `nextcloudUserId`.
     *
     * OpenRegister's `find()` THROWS `DoesNotExistException` for an unknown id;
     * it does not return null. The caller's old `!== null` branch was therefore
     * unreachable for the case it was written to handle, and an unknown
     * participantId escaped as an uncaught exception — a 500 on what is an
     * ordinary "no such participant" request. (Same defect class as
     * ParticipantResolver::resolveGovernanceBodyId, fixed in #425.)
     *
     * The catch is narrowed to `DoesNotExistException` so every other failure —
     * a broken register, an OpenRegister outage — still propagates rather than
     * being silently recoloured as "not your stats".
     *
     * An absent participant answers false, so the caller returns 403 rather
     * than 404. That is deliberate and fail-CLOSED: a distinct 404 would let
     * any authenticated user enumerate which participant UUIDs exist, and an id
     * that does not exist cannot be "own" under any reading.
     *
     * @param string $participantId The participant UUID
     * @param string $uid           The authenticated Nextcloud user id
     *
     * @spec openspec/changes/p2-motion-and-voting-core-t2/tasks.md#task-1
     *
     * @return bool True when the participant belongs to this user
     */
    private function ownsParticipant(string $participantId, string $uid): bool
    {
        try {
            $participantEntity = $this->objectService->find($participantId, [], false, 'decidesk', 'participant');
        } catch (DoesNotExistException) {
            return false;
        }

        if ($participantEntity === null) {
            return false;
        }

        $participant     = $participantEntity->jsonSerialize();
        $nextcloudUserId = ($participant['nextcloudUserId'] ?? ($participant['owner'] ?? null));

        return ($nextcloudUserId !== null && $nextcloudUserId === $uid);

    }//end ownsParticipant()
}//end class
