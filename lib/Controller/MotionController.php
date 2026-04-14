<?php

/**
 * Decidesk Motion Controller
 *
 * Thin REST controller for motion lifecycle, co-signature, and budget impact endpoints.
 *
 * @category Controller
 * @package  OCA\Decidesk\Controller
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-1
 */

declare(strict_types=1);

namespace OCA\Decidesk\Controller;

use OCA\Decidesk\AppInfo\Application;
use OCA\Decidesk\Service\MotionService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Thin controller for motion lifecycle and co-signature API endpoints.
 *
 * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-1.2
 */
class MotionController extends Controller
{
    /**
     * Constructor for MotionController.
     *
     * @param IRequest      $request       The request object
     * @param MotionService $motionService The motion service
     * @param IUserSession  $userSession   The user session
     * @param IGroupManager $groupManager  The group manager
     *
     * @return void
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-1.2
     */
    public function __construct(
        IRequest $request,
        private readonly MotionService $motionService,
        private readonly IUserSession $userSession,
        private readonly IGroupManager $groupManager,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);

    }//end __construct()

    /**
     * Require the current user to be an admin (chair/secretary equivalent).
     *
     * Returns a 403 JSONResponse when the check fails, null on success.
     *
     * @return JSONResponse|null
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-1.2
     */
    private function requireChairOrSecretary(): ?JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null || $this->groupManager->isAdmin($user->getUID()) === false) {
            return new JSONResponse(['message' => 'Chair or secretary role required'], Http::STATUS_FORBIDDEN);
        }

        return null;

    }//end requireChairOrSecretary()

    /**
     * Transition the lifecycle state of a Motion.
     *
     * POST /api/motions/{id}/transition
     * Body: { "newState": "debating", "actorId": "uid" }
     *
     * @param string $id The motion UUID
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-1.2
     *
     * @return JSONResponse
     */
    public function transition(string $id): JSONResponse
    {
        $guard = $this->requireChairOrSecretary();
        if ($guard !== null) {
            return $guard;
        }

        $params   = $this->request->getParams();
        $newState = ($params['newState'] ?? '');
        $actorId  = ($this->userSession->getUser()?->getUID() ?? '');

        try {
            $this->motionService->transitionLifecycle(
                objectId: $id,
                objectType: 'motion',
                newState: $newState,
                actorId: $actorId
            );
            return new JSONResponse(['success' => true, 'newState' => $newState]);
        } catch (\InvalidArgumentException $e) {
            return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        } catch (\RuntimeException $e) {
            return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_NOT_FOUND);
        }

    }//end transition()

    /**
     * Request co-signature from one or more participants for a Motion.
     *
     * POST /api/motions/{id}/co-sign-request
     * Body: { "participantIds": ["uid1", "uid2"] }
     *
     * @param string $id The motion UUID
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-1.2
     *
     * @return JSONResponse
     */
    public function coSignRequest(string $id): JSONResponse
    {
        $guard = $this->requireChairOrSecretary();
        if ($guard !== null) {
            return $guard;
        }

        $params         = $this->request->getParams();
        $participantIds = ($params['participantIds'] ?? []);

        if (empty($participantIds) === true) {
            return new JSONResponse(['message' => 'participantIds is required'], Http::STATUS_BAD_REQUEST);
        }

        try {
            $this->motionService->requestCoSignature(motionId: $id, participantIds: $participantIds);
            return new JSONResponse(['success' => true]);
        } catch (\RuntimeException $e) {
            return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_NOT_FOUND);
        }

    }//end coSignRequest()

    /**
     * Confirm co-signature on a Motion.
     *
     * POST /api/motions/{id}/co-sign-confirm
     * Body: { "displayName": "A. de Vries" }
     *
     * @param string $id The motion UUID
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-1.2
     *
     * @return JSONResponse
     */
    public function coSignConfirm(string $id): JSONResponse
    {
        // Always derive identity from the authenticated session — never trust client-supplied displayName.
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['message' => 'Unauthenticated'], Http::STATUS_UNAUTHORIZED);
        }

        $uid         = $user->getUID();
        $displayName = $user->getDisplayName();

        if ($displayName === '') {
            return new JSONResponse(['message' => 'displayName is required'], Http::STATUS_BAD_REQUEST);
        }

        // Verify that this user was explicitly invited to co-sign (OWASP A01 — Broken Access Control).
        if ($this->motionService->isPendingCoSigner(motionId: $id, nextcloudUid: $uid) === false) {
            return new JSONResponse(['message' => 'U bent niet uitgenodigd om deze motie mede te ondertekenen'], Http::STATUS_FORBIDDEN);
        }

        try {
            $this->motionService->addCoSigner(motionId: $id, participantDisplayName: $displayName);
            return new JSONResponse(['success' => true]);
        } catch (\RuntimeException $e) {
            return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_NOT_FOUND);
        }

    }//end coSignConfirm()

    /**
     * Store budget impact details on a Motion.
     *
     * POST /api/motions/{id}/budget-impact
     * Body: { "budgetLine": "string", "amountDelta": float, "rationale": "string" }
     *
     * @param string $id The motion UUID
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-1.2
     *
     * @return JSONResponse
     */
    public function budgetImpact(string $id): JSONResponse
    {
        $guard = $this->requireChairOrSecretary();
        if ($guard !== null) {
            return $guard;
        }

        $params      = $this->request->getParams();
        $budgetLine  = ($params['budgetLine'] ?? '');
        $amountDelta = (float) ($params['amountDelta'] ?? 0.0);
        $rationale   = ($params['rationale'] ?? '');

        try {
            $this->motionService->saveBudgetImpact(
                motionId: $id,
                budgetLine: $budgetLine,
                amountDelta: $amountDelta,
                rationale: $rationale
            );
            return new JSONResponse(['success' => true]);
        } catch (\RuntimeException $e) {
            return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_NOT_FOUND);
        }

    }//end budgetImpact()

    /**
     * Transition the lifecycle state of an Amendment.
     *
     * POST /api/amendments/{id}/transition
     * Body: { "newState": "debating" }
     *
     * @param string $id The amendment UUID
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-1.2
     *
     * @return JSONResponse
     */
    public function amendmentTransition(string $id): JSONResponse
    {
        $guard = $this->requireChairOrSecretary();
        if ($guard !== null) {
            return $guard;
        }

        $params   = $this->request->getParams();
        $newState = ($params['newState'] ?? '');
        $actorId  = ($this->userSession->getUser()?->getUID() ?? '');

        try {
            $this->motionService->transitionLifecycle(
                objectId: $id,
                objectType: 'amendment',
                newState: $newState,
                actorId: $actorId
            );
            return new JSONResponse(['success' => true, 'newState' => $newState]);
        } catch (\InvalidArgumentException $e) {
            return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        } catch (\RuntimeException $e) {
            return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_NOT_FOUND);
        }

    }//end amendmentTransition()
}//end class
