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
     * @param IRequest           $request           HTTP request
     * @param EngagementService  $engagementService Engagement service
     * @param IUserSession       $userSession       Current user session
     * @param IGroupManager      $groupManager      Group manager for admin checks
     * @param ContainerInterface $container         DI container for ObjectService access
     *
     * @spec openspec/changes/p4-collaboration/tasks.md#task-8.2
     */
    public function __construct(
        IRequest $request,
        private readonly EngagementService $engagementService,
        private readonly IUserSession $userSession,
        private readonly IGroupManager $groupManager,
        private readonly ContainerInterface $container,
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
     * NC admins (chairs/secretaries operating the panel) may supply any UUID.
     * This prevents accountability record spoofing (OWASP A01:2021 — Broken Access Control).
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/p4-collaboration/tasks.md#task-8.2
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
        // Chairs/admins are allowed to record engagement for any participant.
        $callerUid = $user->getUID();
        if ($this->groupManager->isAdmin($callerUid) === false) {
            $callerParticipantId = $this->resolveParticipantUuid(nextcloudUid: $callerUid);
            if ($callerParticipantId === null || $callerParticipantId !== $participant) {
                return new JSONResponse(
                    ['message' => 'You may only record engagement for your own participant record.'],
                    Http::STATUS_FORBIDDEN
                );
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
