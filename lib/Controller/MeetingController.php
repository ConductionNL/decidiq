<?php
/**
 * Decidesk Meeting Controller
 *
 * Controller for meeting lifecycle transitions.
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
 * @spec openspec/changes/p2-meeting-management/tasks.md#task-2.1
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2.
declare(strict_types=1);

namespace OCA\Decidesk\Controller;

use OCA\Decidesk\AppInfo\Application;
use OCA\Decidesk\Exception\MissingObjectException;
use OCA\Decidesk\Service\MeetingService;
use OCA\Decidesk\Service\ParticipantResolver;
use OCA\Decidesk\Service\ProofPackageService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Controller for meeting lifecycle transitions.
 *
 * ## Access control design (OWASP A01 / ADR-005)
 *
 * Meeting CRUD (create/read/update/delete/list) is handled directly by the
 * frontend via OpenRegister's object API — this controller exposes only the
 * guarded lifecycle transition endpoint, which requires server-side domain
 * validation, quorum checks, and chair-only enforcement that cannot be
 * expressed as a simple data write.
 *
 * This controller uses OpenRegister ObjectService RBAC rather than
 * `IGroupManager::isAdmin()` for the following domain reason:
 *
 * In Dutch local government, the meeting chair and clerk are NOT Nextcloud
 * system administrators. `requireChairOrSecretary()` (which gates on
 * `IGroupManager::isAdmin()`) would incorrectly block all legitimate users.
 *
 * Access control is enforced at the OpenRegister layer:
 * - `ObjectService::find()` returns null if the caller lacks read permission → 422
 * - `ObjectService::saveObject()` throws if the caller lacks write permission → 422
 *
 * The meeting objects carry per-object ACLs in OpenRegister; only users with
 * clerk or chair permission on the specific meeting object can transition it.
 * This is the approved pattern per ADR-005 for resources governed by
 * OpenRegister's own RBAC instead of Nextcloud group membership.
 *
 * @spec openspec/changes/p2-meeting-management/tasks.md#task-2.1
 */
class MeetingController extends Controller
{
    /**
     * Constructor for MeetingController.
     *
     * @param IRequest            $request             The HTTP request
     * @param MeetingService      $meetingService      The meeting service
     * @param IUserSession        $userSession         The user session
     * @param IGroupManager       $groupManager        Group manager for the NC-admin fallback
     * @param ParticipantResolver $participantResolver Meeting-role resolver (chair/secretary gate)
     * @param ProofPackageService $proofPackageService Notarial proof package assembly
     *
     * @return void
     */
    public function __construct(
        IRequest $request,
        private readonly MeetingService $meetingService,
        private readonly IUserSession $userSession,
        private readonly IGroupManager $groupManager,
        private readonly ParticipantResolver $participantResolver,
        private readonly ProofPackageService $proofPackageService,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * Apply a lifecycle transition to a meeting.
     *
     * Access control: OpenRegister ObjectService RBAC (see class docblock).
     * The caller must be authenticated; write-level access to the meeting object
     * is enforced by ObjectService::saveObject() inside MeetingService::transition().
     *
     * Expects JSON body: { "action": "<schedule|open|pause|resume|adjourn|close>" }
     *
     * @param string $id UUID of the meeting
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/p2-meeting-management-core-t1/tasks.md#task-2.3
     *
     * @return JSONResponse HTTP 200 with updated meeting on success; 422 if transition is invalid
     */
    #[NoAdminRequired]
    public function lifecycle(string $id): JSONResponse
    {
        // Require authentication — anonymous callers are rejected before service call.
        if ($this->userSession->getUser() === null) {
            return new JSONResponse(['message' => 'Authentication required'], Http::STATUS_UNAUTHORIZED);
        }

        $action = $this->request->getParam('action', '');

        if (empty($action) === true) {
            return new JSONResponse(
                ['message' => "Missing required parameter 'action'."],
                Http::STATUS_UNPROCESSABLE_ENTITY
            );
        }

        $userId = $this->userSession->getUser()->getUID();
        $result = $this->meetingService->transition(meetingId: $id, action: $action, currentUserId: $userId);

        if ($result['success'] === false) {
            return new JSONResponse(
                ['message' => $result['message']],
                Http::STATUS_UNPROCESSABLE_ENTITY
            );
        }

        return new JSONResponse($result);

    }//end lifecycle()

    /**
     * Generate the notarial proof package for a meeting.
     *
     * POST /api/meetings/{id}/proof-package
     *
     * Assembles the decision evidence (convocation record, quorum snapshot,
     * votes tally, adopted decision texts) into a hash-sealed JSON + markdown
     * package stored in the meeting's Files folder.
     *
     * Access control: chair or secretary of the meeting (via
     * ParticipantResolver), with NC-admin fallback. Fails CLOSED — when the
     * roles cannot be resolved the request is denied.
     *
     * Returns 200 with { files, sha256, generatedAt } on success.
     * Returns 401 when not authenticated.
     * Returns 403 when the caller lacks the chair/secretary role.
     * Returns 404 when the meeting is not found.
     * Returns 503 when OpenRegister or the Files backend is unavailable.
     *
     * @param string $id UUID of the meeting
     *
     * @return JSONResponse
     *
     * @spec openspec/specs/resolution-minutes/spec.md
     */
    #[NoAdminRequired]
    public function proofPackage(string $id): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['message' => 'Authentication required'], Http::STATUS_UNAUTHORIZED);
        }

        $userId = $user->getUID();

        if ($this->groupManager->isAdmin($userId) === false
            && $this->participantResolver->hasRole(
                meetingId: $id,
                nextcloudUid: $userId,
                roles: ['chair', 'secretary'],
            ) === false
        ) {
            return new JSONResponse(
                ['message' => 'Forbidden: chair or secretary role required to generate a proof package.'],
                Http::STATUS_FORBIDDEN
            );
        }

        try {
            $result = $this->proofPackageService->assemble(
                meetingId: $id,
                generatedBy: $user->getDisplayName(),
            );
            return new JSONResponse($result);
        } catch (MissingObjectException $e) {
            return new JSONResponse(
                ['message' => $e->getMessage()],
                Http::STATUS_NOT_FOUND
            );
        } catch (\RuntimeException $e) {
            return new JSONResponse(
                ['message' => $e->getMessage()],
                Http::STATUS_SERVICE_UNAVAILABLE
            );
        }//end try

    }//end proofPackage()
}//end class
