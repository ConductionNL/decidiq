<?php
/**
 * Decidesk Transcription Controller
 *
 * Action endpoints for meeting transcription: source listing, attach+consent,
 * transcribe (async submit), re-align, generate-draft, and retention-config.
 * Plain CRUD on Transcript objects stays on the OpenRegister object API
 * (ADR-022 / redundant-controller gate).
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
 * @spec openspec/specs/meeting-transcription/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidesk\Controller;

use OCA\Decidesk\AppInfo\Application;
use OCA\Decidesk\BackgroundJob\TranscriptionJob;
use OCA\Decidesk\Exception\MissingObjectException;
use OCA\Decidesk\Service\MinutesDraftService;
use OCA\Decidesk\Service\ParticipantResolver;
use OCA\Decidesk\Service\TranscriptionService;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\BackgroundJob\IJobList;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Controller for meeting-transcription actions.
 *
 * Every #[NoAdminRequired] action that operates on an object id carries a
 * per-object staff (chair/secretary) authorization guard resolved through the
 * meeting's participant records — the no-admin-idor invariant. The guards fail
 * CLOSED for non-admins when the meeting cannot be resolved.
 *
 * @spec openspec/specs/meeting-transcription/spec.md
 */
class TranscriptionController extends Controller
{
    /**
     * Constructor.
     *
     * @param IRequest             $request              The HTTP request.
     * @param TranscriptionService $transcriptionService Transcription orchestration.
     * @param MinutesDraftService  $minutesDraftService  AI draft generation.
     * @param ObjectService        $objectService        OR object service (guards + reads).
     * @param ParticipantResolver  $participantResolver  Meeting role resolution.
     * @param IJobList             $jobList              Background job queue.
     * @param IUserSession         $userSession          Current user session.
     * @param IGroupManager        $groupManager         Group manager (admin check).
     *
     * @spec openspec/specs/meeting-transcription/spec.md
     */
    public function __construct(
        IRequest $request,
        private readonly TranscriptionService $transcriptionService,
        private readonly MinutesDraftService $minutesDraftService,
        private readonly ObjectService $objectService,
        private readonly ParticipantResolver $participantResolver,
        private readonly IJobList $jobList,
        private readonly IUserSession $userSession,
        private readonly IGroupManager $groupManager,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);

    }//end __construct()

    /**
     * List candidate transcription sources for a meeting + provider availability.
     *
     * GET /api/meetings/{meetingId}/transcription/sources
     *
     * @param string $meetingId Meeting UUID.
     *
     * @return JSONResponse
     *
     * @spec openspec/specs/meeting-transcription/spec.md
     */
    #[NoAdminRequired]
    public function sources(string $meetingId): JSONResponse
    {
        $denied = $this->requireStaffForMeeting(meetingId: $meetingId);
        if ($denied !== null) {
            return $denied;
        }

        try {
            $sources = $this->transcriptionService->listSources(meetingId: $meetingId);
        } catch (MissingObjectException $e) {
            return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_NOT_FOUND);
        }

        return new JSONResponse(
            [
                'sources'           => $sources,
                'providerAvailable' => $this->transcriptionService->isProviderAvailable(),
                'aiAvailable'       => $this->minutesDraftService->isProviderAvailable(),
            ]
        );

    }//end sources()

    /**
     * Attach a source to a meeting and record consent.
     *
     * POST /api/meetings/{meetingId}/transcription/attach
     * Body: { sourceType, sourcePath, consent: true, language? }
     *
     * @param string $meetingId Meeting UUID.
     *
     * @return JSONResponse
     *
     * @spec openspec/specs/meeting-transcription/spec.md
     */
    #[NoAdminRequired]
    public function attach(string $meetingId): JSONResponse
    {
        $denied = $this->requireStaffForMeeting(meetingId: $meetingId);
        if ($denied !== null) {
            return $denied;
        }

        $consentGiven = $this->request->getParam('consent');
        if ($consentGiven !== true && $consentGiven !== 'true' && $consentGiven !== 1 && $consentGiven !== '1') {
            return new JSONResponse(
                ['message' => 'Consent confirmation is required before attaching a recording source.'],
                Http::STATUS_UNPROCESSABLE_ENTITY
            );
        }

        $sourceType  = (string) $this->request->getParam('sourceType', '');
        $sourcePath  = (string) $this->request->getParam('sourcePath', '');
        $language    = (string) $this->request->getParam('language', '');
        $confirmedBy = (string) $this->userSession->getUser()?->getUID();

        try {
            $transcript = $this->transcriptionService->attach(
                meetingId: $meetingId,
                sourceType: $sourceType,
                sourcePath: $sourcePath,
                confirmedBy: $confirmedBy,
                language: $language
            );
        } catch (MissingObjectException $e) {
            return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_NOT_FOUND);
        } catch (\InvalidArgumentException $e) {
            return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
        }

        return new JSONResponse($transcript, Http::STATUS_CREATED);

    }//end attach()

    /**
     * Submit a Transcript for asynchronous transcription.
     *
     * POST /api/transcripts/{transcriptId}/transcribe
     *
     * Enforces the consent precondition and provider availability server-side,
     * then enqueues the TranscriptionJob. Refuses (422) without consent and
     * reports unavailable (503) without a SpeechToText provider.
     *
     * @param string $transcriptId Transcript UUID.
     *
     * @return JSONResponse
     *
     * @spec openspec/specs/meeting-transcription/spec.md
     */
    #[NoAdminRequired]
    public function transcribe(string $transcriptId): JSONResponse
    {
        $denied = $this->requireStaffForTranscript(transcriptId: $transcriptId);
        if ($denied !== null) {
            return $denied;
        }

        try {
            $this->transcriptionService->submit(transcriptId: $transcriptId);
        } catch (MissingObjectException $e) {
            return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_NOT_FOUND);
        } catch (\DomainException $e) {
            $code   = (int) $e->getCode();
            $status = Http::STATUS_UNPROCESSABLE_ENTITY;
            if ($code === 503) {
                $status = Http::STATUS_SERVICE_UNAVAILABLE;
            }

            return new JSONResponse(['message' => $e->getMessage()], $status);
        }

        $this->jobList->add(TranscriptionJob::class, ['transcriptId' => $transcriptId]);

        return new JSONResponse(['status' => 'queued']);

    }//end transcribe()

    /**
     * Re-run agenda alignment for a Transcript (no re-transcription).
     *
     * POST /api/transcripts/{transcriptId}/re-align
     *
     * @param string $transcriptId Transcript UUID.
     *
     * @return JSONResponse
     *
     * @spec openspec/specs/meeting-transcription/spec.md
     */
    #[NoAdminRequired]
    public function realign(string $transcriptId): JSONResponse
    {
        $denied = $this->requireStaffForTranscript(transcriptId: $transcriptId);
        if ($denied !== null) {
            return $denied;
        }

        try {
            $transcript = $this->transcriptionService->align(transcriptId: $transcriptId);
        } catch (MissingObjectException $e) {
            return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_NOT_FOUND);
        }

        return new JSONResponse($transcript);

    }//end realign()

    /**
     * Generate an AI-assisted draft from a Transcript.
     *
     * POST /api/transcripts/{transcriptId}/generate-draft
     *
     * Returns the draft structure for the resolution-minutes editor. The draft
     * is never an approved/published Minutes object. Hidden (503) when no AI
     * provider is available.
     *
     * @param string $transcriptId Transcript UUID.
     *
     * @return JSONResponse
     *
     * @spec openspec/specs/meeting-transcription/spec.md
     */
    #[NoAdminRequired]
    public function generateDraft(string $transcriptId): JSONResponse
    {
        $denied = $this->requireStaffForTranscript(transcriptId: $transcriptId);
        if ($denied !== null) {
            return $denied;
        }

        try {
            $draft = $this->minutesDraftService->generate(transcriptId: $transcriptId);
        } catch (MissingObjectException $e) {
            return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_NOT_FOUND);
        } catch (\DomainException $e) {
            return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_SERVICE_UNAVAILABLE);
        }

        return new JSONResponse($draft);

    }//end generateDraft()

    /**
     * Configure the per-body transcript retention policy.
     *
     * PUT /api/governance-bodies/{bodyId}/retention-config
     * Body: { policy: keep|delete-recording|delete-both, days: int }
     *
     * Body retention configuration is staff-scoped; non-admins must hold a
     * chair/secretary role on a meeting of this body (guarded below).
     *
     * @param string $bodyId Governance body UUID.
     *
     * @return JSONResponse
     *
     * @spec openspec/specs/meeting-transcription/spec.md
     */
    #[NoAdminRequired]
    public function retentionConfig(string $bodyId): JSONResponse
    {
        $denied = $this->requireStaffForBody(bodyId: $bodyId);
        if ($denied !== null) {
            return $denied;
        }

        $policy = (string) $this->request->getParam('policy', 'delete-both');
        if (in_array($policy, ['keep', 'delete-recording', 'delete-both'], true) === false) {
            return new JSONResponse(['message' => 'Invalid retention policy.'], Http::STATUS_UNPROCESSABLE_ENTITY);
        }

        $days = (int) $this->request->getParam('days', 30);
        if ($days < 0) {
            return new JSONResponse(['message' => 'Retention days must be zero or positive.'], Http::STATUS_UNPROCESSABLE_ENTITY);
        }

        $entity = $this->objectService->find(id: $bodyId, register: 'decidesk', schema: 'governance-body');
        if ($entity === null) {
            return new JSONResponse(['message' => 'Governance body not found.'], Http::STATUS_NOT_FOUND);
        }

        $body = (array) $entity->jsonSerialize();
        $body['transcriptRetentionPolicy'] = $policy;
        $body['transcriptRetentionDays']   = $days;

        $this->objectService->saveObject(
            object: $body,
            register: 'decidesk',
            schema: 'governance-body',
            uuid: $bodyId
        );

        return new JSONResponse(['policy' => $policy, 'days' => $days]);

    }//end retentionConfig()

    /**
     * Staff guard for a meeting id (chair/secretary or NC admin).
     *
     * @param string $meetingId Meeting UUID.
     *
     * @return JSONResponse|null Null when authorised; a 401/403 response otherwise.
     *
     * @spec openspec/specs/meeting-transcription/spec.md
     */
    private function requireStaffForMeeting(string $meetingId): ?JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['message' => 'Unauthenticated.'], Http::STATUS_UNAUTHORIZED);
        }

        $userId = $user->getUID();
        if ($this->groupManager->isAdmin($userId) === true) {
            return null;
        }

        if ($meetingId === '') {
            return new JSONResponse(['message' => 'Forbidden.'], Http::STATUS_FORBIDDEN);
        }

        if ($this->participantResolver->hasRole(meetingId: $meetingId, nextcloudUid: $userId, roles: ['chair', 'secretary']) === true) {
            return null;
        }

        return new JSONResponse(
            ['message' => 'Forbidden: chair or secretary role required.'],
            Http::STATUS_FORBIDDEN
        );

    }//end requireStaffForMeeting()

    /**
     * Staff guard for a transcript id — resolves its meeting then delegates.
     *
     * Fails CLOSED for non-admins: an unresolvable transcript/meeting yields 403.
     *
     * @param string $transcriptId Transcript UUID.
     *
     * @return JSONResponse|null Null when authorised; a 401/403/404 response otherwise.
     *
     * @spec openspec/specs/meeting-transcription/spec.md
     */
    private function requireStaffForTranscript(string $transcriptId): ?JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['message' => 'Unauthenticated.'], Http::STATUS_UNAUTHORIZED);
        }

        $userId = $user->getUID();
        if ($this->groupManager->isAdmin($userId) === true) {
            return null;
        }

        $entity = $this->objectService->find(id: $transcriptId, register: 'decidesk', schema: 'transcript');
        if ($entity === null) {
            // Fail closed: do not leak existence to non-staff.
            return new JSONResponse(['message' => 'Forbidden.'], Http::STATUS_FORBIDDEN);
        }

        $transcript = (array) $entity->jsonSerialize();
        $meetingId  = ($transcript['relations']['meeting'] ?? ($transcript['meeting'] ?? null));
        if (is_array($meetingId) === true) {
            $meetingId = ($meetingId['id'] ?? ($meetingId[0] ?? null));
        }

        if ($meetingId === null || $meetingId === '') {
            return new JSONResponse(['message' => 'Forbidden.'], Http::STATUS_FORBIDDEN);
        }

        if ($this->participantResolver->hasRole(meetingId: (string) $meetingId, nextcloudUid: $userId, roles: ['chair', 'secretary']) === true) {
            return null;
        }

        return new JSONResponse(
            ['message' => 'Forbidden: chair or secretary role required.'],
            Http::STATUS_FORBIDDEN
        );

    }//end requireStaffForTranscript()

    /**
     * Staff guard for a governance body id.
     *
     * Non-admins must hold a chair/secretary role on at least one meeting of the
     * body. Fails CLOSED: when no such meeting can be resolved, access is denied.
     *
     * @param string $bodyId Governance body UUID.
     *
     * @return JSONResponse|null Null when authorised; a 401/403 response otherwise.
     *
     * @spec openspec/specs/meeting-transcription/spec.md
     */
    private function requireStaffForBody(string $bodyId): ?JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['message' => 'Unauthenticated.'], Http::STATUS_UNAUTHORIZED);
        }

        $userId = $user->getUID();
        if ($this->groupManager->isAdmin($userId) === true) {
            return null;
        }

        if ($bodyId === '') {
            return new JSONResponse(['message' => 'Forbidden.'], Http::STATUS_FORBIDDEN);
        }

        // Resolve meetings of this body and require a staff role on one of them.
        try {
            $entities = $this->objectService->findAll(
                [
                    'register' => 'decidesk',
                    'schema'   => 'meeting',
                    'filters'  => [
                        'register'                  => 'decidesk',
                        'schema'                    => 'meeting',
                        '_relations.GovernanceBody' => $bodyId,
                    ],
                ]
            );
        } catch (\Throwable) {
            $entities = [];
        }

        foreach ($entities as $entity) {
            if (is_array($entity) === true) {
                $meeting = $entity;
            } else if (method_exists($entity, 'jsonSerialize') === true) {
                $meeting = (array) $entity->jsonSerialize();
            } else {
                continue;
            }

            $meetingId = (string) ($meeting['id'] ?? ($meeting['@self']['id'] ?? ''));
            if ($meetingId === '') {
                continue;
            }

            if ($this->participantResolver->hasRole(meetingId: $meetingId, nextcloudUid: $userId, roles: ['chair', 'secretary']) === true) {
                return null;
            }
        }

        return new JSONResponse(
            ['message' => 'Forbidden: chair or secretary role required for this body.'],
            Http::STATUS_FORBIDDEN
        );

    }//end requireStaffForBody()
}//end class
