<?php
/**
 * Decidesk Engagement Controller
 *
 * REST controller for participant engagement capture during meetings.
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
 * @spec openspec/changes/p4-collaboration/tasks.md#task-8.2
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2.
declare(strict_types=1);

namespace OCA\Decidesk\Controller;

use OCA\Decidesk\AppInfo\Application;
use OCA\Decidesk\Service\EngagementService;
use OCA\Decidesk\Service\ParticipantResolver;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;

/**
 * Controller for capturing and querying engagement records.
 *
 * @spec openspec/changes/p4-collaboration/tasks.md#task-8.2
 */
class EngagementController extends Controller
{
    /**
     * Constructor.
     *
     * @param IRequest            $request             HTTP request
     * @param EngagementService   $engagementService   Engagement service
     * @param IUserSession        $userSession         Current user session
     * @param IGroupManager       $groupManager        Group manager for admin checks
     * @param ContainerInterface  $container           DI container for ObjectService access
     * @param ParticipantResolver $participantResolver Resolves meeting chair/secretary roles (meeting-efficiency)
     *
     * @spec openspec/changes/p4-collaboration/tasks.md#task-8.2
     * @spec openspec/specs/meeting-efficiency/spec.md
     */
    public function __construct(
        IRequest $request,
        private readonly EngagementService $engagementService,
        private readonly IUserSession $userSession,
        private readonly IGroupManager $groupManager,
        private readonly ContainerInterface $container,
        private readonly ParticipantResolver $participantResolver,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * Resolve the OpenRegister participant UUID for the given Nextcloud UID.
     *
     * Returns null when no participant record is linked to this user.
     *
     * @param string $nextcloudUid Nextcloud user ID
     *
     * @return string|null
     */
    private function resolveParticipantUuid(string $nextcloudUid): ?string
    {
        try {
            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
            $objectService->setRegister('decidesk');
            $objectService->setSchema('participant');
            $entities = $objectService->findAll(['filters' => ['nextcloudUserId' => $nextcloudUid]]);

            foreach ($entities as $participantEntity) {
                $participant = $participantEntity->jsonSerialize();
                return ($participant['uuid'] ?? $participant['id'] ?? null);
            }
        } catch (\Throwable $e) {
            // Non-fatal — service may be unavailable.
        }

        return null;

    }//end resolveParticipantUuid()

    /**
     * Capture an engagement event for a participant in a meeting.
     *
     * POST /api/engagement
     *
     * Body: { meeting, participant, eventType: speech|question|topic, ...eventData }
     *
     * The `participant` field is cross-checked against the authenticated session:
     * non-admin callers may only record engagement for their own participant UUID.
     * The exceptions that may record engagement for ANY participant in the meeting
     * (so the SpeakerQueuePanel operator can log every speech) are:
     *   - NC admins (the original p4 fallback); and
     *   - the meeting's chair or secretary (meeting-efficiency widening) — real
     *     Dutch chairs are never NC admins, so the panel was unusable for them.
     * This prevents accountability record spoofing (OWASP A01:2021 — Broken Access Control).
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/p4-collaboration/tasks.md#task-8.2
     * @spec openspec/specs/meeting-efficiency/spec.md
     */
    public function capture(): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['message' => 'Unauthenticated.'], Http::STATUS_UNAUTHORIZED);
        }

        $meeting     = (string) $this->request->getParam('meeting', '');
        $participant = (string) $this->request->getParam('participant', '');
        $eventType   = (string) $this->request->getParam('eventType', '');

        if ($meeting === '' || $participant === '' || $eventType === '') {
            return new JSONResponse(
                ['message' => 'Missing required fields: meeting, participant, eventType.'],
                Http::STATUS_UNPROCESSABLE_ENTITY
            );
        }

        // OWASP A01 — verify participant identity matches the authenticated session.
        // Admins, and the meeting's chair/secretary, may record engagement for any
        // participant; everyone else may only record for their own participant record.
        $callerUid = $user->getUID();
        if ($this->mayRecordForOthers(callerUid: $callerUid, meetingId: $meeting) === false) {
            $denied = $this->denyForeignParticipant(callerUid: $callerUid, participant: $participant);
            if ($denied !== null) {
                return $denied;
            }
        }

        $eventData = (array) ($this->request->getParam('eventData') ?? []);

        try {
            $record = $this->engagementService->captureEngagement(
                meetingId: $meeting,
                participant: $participant,
                eventType: $eventType,
                eventData: $eventData
            );
            return new JSONResponse($record);
        } catch (\InvalidArgumentException $e) {
            return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
        }

    }//end capture()

    /**
     * Decide whether the caller may record engagement for any participant.
     *
     * NC admins (the original p4 fallback) and the meeting's chair/secretary
     * (meeting-efficiency widening) may log every speech; everyone else is
     * restricted to their own participant record.
     *
     * @param string $callerUid Nextcloud UID of the authenticated caller
     * @param string $meetingId Meeting UUID the engagement belongs to
     *
     * @return bool True when the caller may record for other participants
     *
     * @spec openspec/changes/p4-collaboration/tasks.md#task-8.2
     * @spec openspec/specs/meeting-efficiency/spec.md
     */
    private function mayRecordForOthers(string $callerUid, string $meetingId): bool
    {
        if ($this->groupManager->isAdmin($callerUid) === true) {
            return true;
        }

        return ($this->participantResolver->hasRole(
            meetingId: $meetingId,
            nextcloudUid: $callerUid,
            roles: ['chair', 'secretary']
        ) === true);

    }//end mayRecordForOthers()

    /**
     * Refuse an unprivileged caller recording for someone else's participant record.
     *
     * OWASP A01:2021 — prevents accountability record spoofing.
     *
     * @param string $callerUid   Nextcloud UID of the authenticated caller
     * @param string $participant The participant UUID the caller is recording for
     *
     * @return JSONResponse|null A 403 response, or null when the record is the caller's own
     *
     * @spec openspec/changes/p4-collaboration/tasks.md#task-8.2
     * @spec openspec/specs/meeting-efficiency/spec.md
     */
    private function denyForeignParticipant(string $callerUid, string $participant): ?JSONResponse
    {
        $callerParticipantId = $this->resolveParticipantUuid(nextcloudUid: $callerUid);
        if ($callerParticipantId === null || $callerParticipantId !== $participant) {
            return new JSONResponse(
                ['message' => 'You may only record engagement for your own participant record.'],
                Http::STATUS_FORBIDDEN
            );
        }

        return null;

    }//end denyForeignParticipant()

    /**
     * List engagement records for a meeting.
     *
     * GET /api/engagement?meeting={meetingUid}
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/p4-collaboration/tasks.md#task-8.2
     */
    public function index(): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['message' => 'Unauthenticated.'], Http::STATUS_UNAUTHORIZED);
        }

        $meeting = (string) $this->request->getParam('meeting', '');
        if ($meeting === '') {
            return new JSONResponse(
                ['message' => 'Missing required query parameter: meeting.'],
                Http::STATUS_UNPROCESSABLE_ENTITY
            );
        }

        $records = $this->engagementService->findEngagementForMeeting($meeting);
        return new JSONResponse(['records' => $records]);

    }//end index()
}//end class
