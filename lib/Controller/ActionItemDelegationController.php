<?php
/**
 * Decidesk Action-Item Delegation Controller
 *
 * REST controller for action-item delegation endpoints. Generic CRUD for the
 * action-item schema is delegated to OpenRegister's object API; this
 * controller adds the reassign + reclaim endpoints which enforce the
 * assignee/delegator authorisation rules on the canonical action-item object
 * (replacing the retired Task/Delegation endpoints).
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
 * @spec openspec/changes/migrate-action-items-to-deck-leaf/tasks.md#task-2
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2.
declare(strict_types=1);

namespace OCA\Decidesk\Controller;

use OCA\Decidesk\AppInfo\Application;
use OCA\Decidesk\Service\ActionItemDelegationService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Controller for action-item delegation / reclaim endpoints.
 *
 * @spec openspec/changes/migrate-action-items-to-deck-leaf/tasks.md#task-2
 */
class ActionItemDelegationController extends Controller
{
    /**
     * Constructor.
     *
     * @param IRequest                    $request           HTTP request
     * @param ActionItemDelegationService $delegationService Delegation service
     * @param IUserSession                $userSession       Current user session
     * @param IGroupManager               $groupManager      Group manager for admin checks
     *
     * @spec openspec/changes/migrate-action-items-to-deck-leaf/tasks.md#task-2
     */
    public function __construct(
        IRequest $request,
        private readonly ActionItemDelegationService $delegationService,
        private readonly IUserSession $userSession,
        private readonly IGroupManager $groupManager,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * Reassign an action item to a substitute.
     *
     * POST /api/action-items/{id}/reassign
     *
     * @param string $id Action-item UUID
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/migrate-action-items-to-deck-leaf/tasks.md#task-2.1
     */
    public function reassign(string $id): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['message' => 'Unauthenticated.'], Http::STATUS_UNAUTHORIZED);
        }

        $substitute = (string) $this->request->getParam('substitute', '');
        if (trim($substitute) === '') {
            return new JSONResponse(
                ['message' => 'Missing required field: substitute.'],
                Http::STATUS_UNPROCESSABLE_ENTITY
            );
        }

        $substituteUntil = $this->request->getParam('substituteUntil');
        if ($substituteUntil !== null) {
            $substituteUntil = (string) $substituteUntil;
        }

        // Admins bypass the ownership check; regular users are checked inside the
        // service against current assignee/delegator (OWASP A01).
        $callerUid   = $user->getUID();
        $ownerFilter = null;
        if ($this->groupManager->isAdmin($callerUid) === false) {
            $ownerFilter = $callerUid;
        }

        try {
            $item = $this->delegationService->reassign($id, $substitute, $ownerFilter, $substituteUntil);
            return new JSONResponse($item);
        } catch (\InvalidArgumentException $e) {
            $msg = $e->getMessage();
            if (str_contains($msg, 'may reassign') === true) {
                return new JSONResponse(['message' => $msg], Http::STATUS_FORBIDDEN);
            }

            return new JSONResponse(['message' => $msg], Http::STATUS_UNPROCESSABLE_ENTITY);
        } catch (\RuntimeException $e) {
            return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_NOT_FOUND);
        }//end try

    }//end reassign()

    /**
     * Reclaim an action item: only the original delegator may invoke this.
     *
     * POST /api/action-items/{id}/reclaim
     *
     * @param string $id Action-item UUID
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/migrate-action-items-to-deck-leaf/tasks.md#task-2.3
     */
    public function reclaim(string $id): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['message' => 'Unauthenticated.'], Http::STATUS_UNAUTHORIZED);
        }

        try {
            $item = $this->delegationService->reclaim($id, $user->getUID());
            return new JSONResponse($item);
        } catch (\InvalidArgumentException $e) {
            $msg = $e->getMessage();
            if (str_contains($msg, 'cannot be reclaimed') === true) {
                return new JSONResponse(['message' => $msg], Http::STATUS_UNPROCESSABLE_ENTITY);
            }

            return new JSONResponse(['message' => $msg], Http::STATUS_FORBIDDEN);
        } catch (\RuntimeException $e) {
            return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_NOT_FOUND);
        }//end try

    }//end reclaim()
}//end class
