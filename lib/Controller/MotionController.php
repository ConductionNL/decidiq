<?php
/**
 * Decidesk Motion Controller
 *
 * Thin REST controller for motion lifecycle, co-signature, and budget impact endpoints.
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
 * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-1
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2.
declare(strict_types=1);

namespace OCA\Decidesk\Controller;

use OCA\Decidesk\AppInfo\Application;
use OCA\Decidesk\Service\MotionService;
use OCA\Decidesk\Service\ParticipantResolver;
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
 * Thin controller for motion lifecycle and co-signature API endpoints.
 *
 * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-1.2
 */
class MotionController extends Controller
{
    /**
     * Constructor for MotionController.
     *
     * @param IRequest            $request             The request object
     * @param MotionService       $motionService       The motion service
     * @param IUserSession        $userSession         The user session
     * @param IGroupManager       $groupManager        The group manager
     * @param IAppConfig          $appConfig           The app config
     * @param ParticipantResolver $participantResolver Per-meeting participant/role resolver
     * @param ContainerInterface  $container           DI container (lazy-loads ObjectService)
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
        private readonly IAppConfig $appConfig,
        private readonly ParticipantResolver $participantResolver,
        private readonly ContainerInterface $container,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);

    }//end __construct()

    /**
     * Require the current user to hold the chair/secretary role on THIS motion's meeting.
     *
     * When $motionId is provided, resolves the linked meeting and checks via
     * ParticipantResolver::hasRole() that the caller holds a 'chair' or 'secretary'
     * Participant role in that specific meeting's governance body — preventing
     * cross-body privilege escalation in multi-council deployments.
     *
     * Falls back to the global chair_group / admin check only when $motionId is null
     * (backward-compatible for callers that cannot easily resolve a meeting).
     *
     * Returns a 403 JSONResponse when the check fails, null on success.
     *
     * @param string|null $motionId UUID of the motion to scope the role check (optional)
     *
     * @return JSONResponse|null
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-1.2
     */
    private function requireChairOrSecretary(?string $motionId=null): ?JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['message' => 'Unauthorized'], Http::STATUS_UNAUTHORIZED);
        }

        $uid = $user->getUID();

        // Per-meeting role check: resolve the meeting linked to this motion and verify
        // that the caller holds a chair or secretary role in that meeting's governance body.
        if ($motionId !== null) {
            $meetingId = $this->resolveMeetingIdFromMotion(motionId: $motionId);
            if ($meetingId !== null) {
                $authorized = $this->participantResolver->hasRole(
                    meetingId: $meetingId,
                    nextcloudUid: $uid,
                    roles: ['chair', 'secretary']
                );
                if ($authorized === false) {
                    return new JSONResponse(['message' => 'Chair or secretary role required for this meeting'], Http::STATUS_FORBIDDEN);
                }

                return null;
            }
        }

        // Fallback: global chair_group or system-admin check (no meeting context available).
        $chairGroup = $this->appConfig->getValueString('decidesk', 'chair_group', '');

        if ($chairGroup !== '') {
            $authorized = $this->groupManager->isInGroup($uid, $chairGroup);
        } else {
            $authorized = $this->groupManager->isAdmin($uid);
        }

        if ($authorized === false) {
            return new JSONResponse(['message' => 'Chair or secretary role required'], Http::STATUS_FORBIDDEN);
        }

        return null;

    }//end requireChairOrSecretary()

    /**
     * Require the current user to hold the CHAIR role (not secretary) on THIS motion's meeting.
     *
     * Setting the amendment voting order is the chair's prerogative
     * (motion-amendment spec), so the secretary does not suffice. Per-meeting
     * check via ParticipantResolver::hasRole(); when the meeting cannot be
     * resolved, falls back to the global chair_group/admin check. Fail closed:
     * any failure yields a 401/403.
     *
     * @param string|null $motionId UUID of the motion to scope the role check (optional)
     *
     * @spec openspec/specs/motion-amendment/spec.md
     *
     * @return JSONResponse|null A 403/401 response on failure, null when authorized
     */
    private function requireChair(?string $motionId=null): ?JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['message' => 'Unauthorized'], Http::STATUS_UNAUTHORIZED);
        }

        $uid = $user->getUID();

        if ($motionId !== null) {
            $meetingId = $this->resolveMeetingIdFromMotion(motionId: $motionId);
            if ($meetingId !== null) {
                $authorized = $this->participantResolver->hasRole(
                    meetingId: $meetingId,
                    nextcloudUid: $uid,
                    roles: ['chair']
                );
                if ($authorized === false) {
                    return new JSONResponse(['message' => 'Chair role required for this meeting'], Http::STATUS_FORBIDDEN);
                }

                return null;
            }
        }

        // Fallback: global chair_group or system-admin check (existing pattern).
        $chairGroup = $this->appConfig->getValueString('decidesk', 'chair_group', '');

        if ($chairGroup !== '') {
            $authorized = $this->groupManager->isInGroup($uid, $chairGroup);
        } else {
            $authorized = $this->groupManager->isAdmin($uid);
        }

        if ($authorized === false) {
            return new JSONResponse(['message' => 'Chair role required'], Http::STATUS_FORBIDDEN);
        }

        return null;

    }//end requireChair()

    /**
     * Resolve the meeting UUID linked to a motion.
     *
     * Honours BOTH link shapes: the flat `meeting` property (what the UI and
     * the Newman fixtures write) and the structured `relations` entry.
     * Previously only relations were read, so property-linked motions always
     * fell back to the global chair guard instead of the per-meeting check.
     *
     * @param string $motionId The motion UUID
     *
     * @spec openspec/specs/motion-amendment/spec.md
     *
     * @return string|null The meeting UUID or null if not found
     */
    private function resolveMeetingIdFromMotion(string $motionId): ?string
    {
        try {
            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
            $motionEntity  = $objectService->find(id: $motionId, register: 'decidesk', schema: 'motion');
            if ($motionEntity === null) {
                return null;
            }

            $motion = $motionEntity->jsonSerialize();

            // Flat meeting property (canonical UI shape).
            $meetingRef = ($motion['meeting'] ?? null);
            if (is_string($meetingRef) === true && $meetingRef !== '') {
                return $meetingRef;
            }

            if (is_array($meetingRef) === true && (($meetingRef['id'] ?? $meetingRef['uuid'] ?? '') !== '')) {
                return ($meetingRef['id'] ?? $meetingRef['uuid']);
            }

            foreach (($motion['relations'] ?? []) as $relation) {
                if (($relation['schema'] ?? '') === 'meeting') {
                    return ($relation['id'] ?? null);
                }
            }
        } catch (\Throwable) {
            // Silently fall through to global check.
        }

        return null;

    }//end resolveMeetingIdFromMotion()

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
    #[NoAdminRequired]
    public function transition(string $id): JSONResponse
    {
        $guard = $this->requireChairOrSecretary(motionId: $id);
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
    #[NoAdminRequired]
    public function coSignRequest(string $id): JSONResponse
    {
        $guard = $this->requireChairOrSecretary(motionId: $id);
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
    #[NoAdminRequired]
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
    #[NoAdminRequired]
    public function budgetImpact(string $id): JSONResponse
    {
        $guard = $this->requireChairOrSecretary(motionId: $id);
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
    #[NoAdminRequired]
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

    /**
     * Forward a motion to a target governance body.
     *
     * POST /api/motions/{id}/forward
     * Body: { "targetBodyId": "...", "justification": "..." }
     *
     * @param string $id The motion UUID
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/p2-motion-and-voting-core-t2/tasks.md#task-3
     *
     * @return JSONResponse
     */
    #[NoAdminRequired]
    public function forward(string $id): JSONResponse
    {
        $guard = $this->requireChairOrSecretary();
        if ($guard !== null) {
            return $guard;
        }

        $params        = $this->request->getParams();
        $targetBodyId  = ($params['targetBodyId'] ?? '');
        $justification = ($params['justification'] ?? '');
        $actorId       = ($this->userSession->getUser()?->getUID() ?? '');

        if ($targetBodyId === '' || $justification === '' || $actorId === '') {
            return new JSONResponse(['message' => 'targetBodyId, justification, and authentication required'], Http::STATUS_BAD_REQUEST);
        }

        try {
            $forwardedMotion = $this->motionService->forwardMotion(
                motionId: $id,
                targetBodyId: $targetBodyId,
                actorId: $actorId,
                justification: $justification,
            );
            return new JSONResponse($forwardedMotion);
        } catch (\RuntimeException $e) {
            return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }

    }//end forward()

    /**
     * Set the amendment voting order on a motion (chair only).
     *
     * POST /api/motions/{id}/amendment-order
     * Body: { "orderedAmendmentIds": ["uuid-first-voted", "uuid-second", ...] }
     *
     * Persists votingOrder 1..N on the motion's amendments in the supplied
     * order (motion-amendment spec — the chair sets the order, most
     * far-reaching first; VotingService enforces it when rounds are opened).
     * Guard: per-meeting CHAIR role, fail closed.
     *
     * @param string $id The motion UUID
     *
     * @NoAdminRequired
     *
     * @spec openspec/specs/motion-amendment/spec.md
     *
     * @return JSONResponse
     */
    #[NoAdminRequired]
    public function amendmentOrder(string $id): JSONResponse
    {
        $guard = $this->requireChair(motionId: $id);
        if ($guard !== null) {
            return $guard;
        }

        $params              = $this->request->getParams();
        $orderedAmendmentIds = ($params['orderedAmendmentIds'] ?? []);

        if (is_array($orderedAmendmentIds) === false || $orderedAmendmentIds === []) {
            return new JSONResponse(['message' => 'orderedAmendmentIds (non-empty array) is required'], Http::STATUS_BAD_REQUEST);
        }

        $orderedAmendmentIds = array_values(array_map('strval', $orderedAmendmentIds));
        $actorId             = ($this->userSession->getUser()?->getUID() ?? '');

        try {
            $updated = $this->motionService->setAmendmentVotingOrder(
                motionId: $id,
                orderedAmendmentIds: $orderedAmendmentIds,
                actorId: $actorId
            );
            return new JSONResponse(['success' => true, 'amendments' => $updated]);
        } catch (\InvalidArgumentException $e) {
            return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        } catch (\RuntimeException $e) {
            return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_NOT_FOUND);
        }

    }//end amendmentOrder()
}//end class
