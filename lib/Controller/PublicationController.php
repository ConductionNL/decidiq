<?php
/**
 * Decidesk Publication Controller
 *
 * Action endpoints for the public-publication flow: publish, withdraw, and
 * rectify. Plain CRUD on PublicationRecord/PublicationPayload stays on the OR
 * object API per ADR-022 — this controller exposes ACTIONS only.
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
 * @spec openspec/specs/public-publication/spec.md
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2.
declare(strict_types=1);

namespace OCA\Decidesk\Controller;

use OCA\Decidesk\AppInfo\Application;
use OCA\Decidesk\Exception\AccessDeniedException;
use OCA\Decidesk\Exception\MissingObjectException;
use OCA\Decidesk\Service\ParticipantResolver;
use OCA\Decidesk\Service\PublicationService;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Controller for publish / withdraw / rectify actions.
 *
 * Every method carries a per-object staff RBAC guard (no-admin-idor): a
 * non-admin caller must hold a chair/secretary role on the meeting linked to
 * the targeted governance object. Unauthenticated => 401, unauthorised => 403.
 *
 * @spec openspec/specs/public-publication/spec.md
 */
class PublicationController extends Controller
{
    /**
     * Constructor.
     *
     * @param IRequest            $request             The HTTP request.
     * @param PublicationService  $publicationService  The publication orchestrator.
     * @param ObjectService       $objectService       OR object service.
     * @param ParticipantResolver $participantResolver Role resolution for the staff guard.
     * @param IUserSession        $userSession         Current user session.
     * @param IGroupManager       $groupManager        Group manager for admin checks.
     *
     * @spec openspec/specs/public-publication/spec.md
     */
    public function __construct(
        IRequest $request,
        private readonly PublicationService $publicationService,
        private readonly ObjectService $objectService,
        private readonly ParticipantResolver $participantResolver,
        private readonly IUserSession $userSession,
        private readonly IGroupManager $groupManager,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * Publish an eligible decision / agenda / minutes object.
     *
     * POST /api/publications
     * Body: { sourceType: decision|agenda|minutes, sourceId: <uuid> }
     *
     * @spec openspec/specs/public-publication/spec.md
     *
     * @return JSONResponse
     */
    #[NoAdminRequired]
    public function publish(): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['message' => 'Authentication required.'], Http::STATUS_UNAUTHORIZED);
        }

        $sourceType = (string) $this->request->getParam('sourceType', '');
        $sourceId   = (string) $this->request->getParam('sourceId', '');
        if (in_array($sourceType, ['decision', 'agenda', 'minutes'], true) === false || $sourceId === '') {
            return new JSONResponse(['message' => 'sourceType (decision|agenda|minutes) and sourceId are required.'], Http::STATUS_BAD_REQUEST);
        }

        $denied = $this->requireStaffForSource(sourceType: $sourceType, sourceId: $sourceId);
        if ($denied !== null) {
            return $denied;
        }

        try {
            $result = $this->publicationService->publish($sourceType, $sourceId, $user->getUID());
            return new JSONResponse($result, Http::STATUS_CREATED);
        } catch (AccessDeniedException $e) {
            return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
        } catch (MissingObjectException $e) {
            return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_NOT_FOUND);
        } catch (\Throwable $e) {
            return new JSONResponse(['message' => 'Internal server error.'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }

    }//end publish()

    /**
     * Withdraw a publication with a mandatory reason.
     *
     * POST /api/publications/{recordId}/withdraw
     * Body: { reason: <string> }
     *
     * @param string $recordId UUID of the PublicationRecord.
     *
     * @spec openspec/specs/public-publication/spec.md
     *
     * @return JSONResponse
     */
    #[NoAdminRequired]
    public function withdraw(string $recordId): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['message' => 'Authentication required.'], Http::STATUS_UNAUTHORIZED);
        }

        $denied = $this->requireStaffForRecord(recordId: $recordId);
        if ($denied !== null) {
            return $denied;
        }

        $reason = (string) $this->request->getParam('reason', '');
        if (trim($reason) === '') {
            return new JSONResponse(['message' => 'A withdraw reason is required.'], Http::STATUS_UNPROCESSABLE_ENTITY);
        }

        try {
            $result = $this->publicationService->withdraw($recordId, $user->getUID(), $reason);
            return new JSONResponse($result, Http::STATUS_OK);
        } catch (MissingObjectException $e) {
            return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_NOT_FOUND);
        } catch (\InvalidArgumentException $e) {
            return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
        } catch (\Throwable $e) {
            return new JSONResponse(['message' => 'Internal server error.'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }

    }//end withdraw()

    /**
     * Rectify a publication: publish a corrected version, withdraw the old one.
     *
     * POST /api/publications/{recordId}/rectify
     * Body: { reason?: <string> }
     *
     * @param string $recordId UUID of the PublicationRecord to rectify.
     *
     * @spec openspec/specs/public-publication/spec.md
     *
     * @return JSONResponse
     */
    #[NoAdminRequired]
    public function rectify(string $recordId): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['message' => 'Authentication required.'], Http::STATUS_UNAUTHORIZED);
        }

        $denied = $this->requireStaffForRecord(recordId: $recordId);
        if ($denied !== null) {
            return $denied;
        }

        $reason = (string) $this->request->getParam('reason', '');

        try {
            $result = $this->publicationService->rectify($recordId, $user->getUID(), $reason);
            return new JSONResponse($result, Http::STATUS_CREATED);
        } catch (AccessDeniedException $e) {
            return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
        } catch (MissingObjectException $e) {
            return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_NOT_FOUND);
        } catch (\Throwable $e) {
            return new JSONResponse(['message' => 'Internal server error.'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }

    }//end rectify()

    /**
     * Per-object staff guard keyed by a publication source object.
     *
     * Admin passes. Otherwise the caller must hold a chair/secretary role on the
     * meeting linked to the source object (decision/agenda/minutes). Fails CLOSED.
     *
     * @param string $sourceType One of decision|agenda|minutes.
     * @param string $sourceId   UUID of the source object.
     *
     * @spec openspec/specs/public-publication/spec.md
     *
     * @return JSONResponse|null Null when authorised; a 403 otherwise.
     */
    private function requireStaffForSource(string $sourceType, string $sourceId): ?JSONResponse
    {
        $userId = $this->userSession->getUser()->getUID();
        if ($this->groupManager->isAdmin($userId) === true) {
            return null;
        }

        $meetingId = $this->resolveMeetingId(sourceType: $sourceType, sourceId: $sourceId);
        if ($meetingId !== null
            && $this->participantResolver->hasRole(meetingId: $meetingId, nextcloudUid: $userId, roles: ['chair', 'secretary']) === true
        ) {
            return null;
        }

        return new JSONResponse(
            ['message' => 'Forbidden: governance-body authority (chair or secretary) required to publish.'],
            Http::STATUS_FORBIDDEN
        );

    }//end requireStaffForSource()

    /**
     * Per-object staff guard keyed by an existing PublicationRecord.
     *
     * @param string $recordId UUID of the PublicationRecord.
     *
     * @spec openspec/specs/public-publication/spec.md
     *
     * @return JSONResponse|null Null when authorised; a 403/404 otherwise.
     */
    private function requireStaffForRecord(string $recordId): ?JSONResponse
    {
        $userId = $this->userSession->getUser()->getUID();
        if ($this->groupManager->isAdmin($userId) === true) {
            return null;
        }

        $record = $this->objectService->find(id: $recordId, register: 'decidesk', schema: 'publication-record');
        if ($record === null) {
            return new JSONResponse(['message' => 'Publication record not found.'], Http::STATUS_NOT_FOUND);
        }

        $data       = $record->jsonSerialize();
        $sourceType = (string) ($data['sourceType'] ?? '');
        $sourceId   = (string) ($data['sourceObject'] ?? '');

        $meetingId = $this->resolveMeetingId(sourceType: $sourceType, sourceId: $sourceId);
        if ($meetingId !== null
            && $this->participantResolver->hasRole(meetingId: $meetingId, nextcloudUid: $userId, roles: ['chair', 'secretary']) === true
        ) {
            return null;
        }

        return new JSONResponse(
            ['message' => 'Forbidden: governance-body authority (chair or secretary) required.'],
            Http::STATUS_FORBIDDEN
        );

    }//end requireStaffForRecord()

    /**
     * Resolve the meeting UUID a source object is associated with.
     *
     * For agenda the source IS a meeting; for minutes/decision it is resolved
     * from the relations map.
     *
     * @param string $sourceType One of decision|agenda|minutes.
     * @param string $sourceId   UUID of the source object.
     *
     * @spec openspec/specs/public-publication/spec.md
     *
     * @return string|null
     */
    private function resolveMeetingId(string $sourceType, string $sourceId): ?string
    {
        if ($sourceType === 'agenda') {
            return $sourceId;
        }

        $schema = 'decision';
        if ($sourceType === 'minutes') {
            $schema = 'minutes';
        }

        $entity = $this->objectService->find(id: $sourceId, register: 'decidesk', schema: $schema);
        if ($entity === null) {
            return null;
        }

        $data            = $entity->jsonSerialize();
        $meetingRelation = ($data['relations']['meeting'] ?? $data['relations']['Meeting'] ?? $data['meeting'] ?? null);
        if (is_array($meetingRelation) === true) {
            $meetingRelation = ($meetingRelation['id'] ?? ($meetingRelation[0] ?? null));
        }

        if (is_string($meetingRelation) === true && $meetingRelation !== '') {
            return $meetingRelation;
        }

        return null;

    }//end resolveMeetingId()
}//end class
