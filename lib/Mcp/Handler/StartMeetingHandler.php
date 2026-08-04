<?php
/**
 * Decidesk MCP Handler — decidesk.startMeeting
 *
 * Transitions a scheduled meeting to opened (in-progress). Only the meeting
 * chair or a Nextcloud system administrator may perform the transition.
 *
 * @category Mcp
 * @package  OCA\Decidesk\Mcp\Handler
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/mcp-tools/spec.md
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2.
declare(strict_types=1);

namespace OCA\Decidesk\Mcp\Handler;

use DateTimeImmutable;
use DateTimeInterface;
use OCA\Decidesk\Service\MeetingService;
use OCA\Decidesk\Service\ParticipantResolver;
use OCP\IGroupManager;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Handler for the decidesk.startMeeting MCP tool.
 *
 * @spec openspec/specs/mcp-tools/spec.md
 */
class StartMeetingHandler extends AbstractToolHandler
{
    /**
     * Constructor for the startMeeting handler.
     *
     * @param IUserSession        $userSession         The current user session
     * @param IGroupManager       $groupManager        The group manager (for admin checks)
     * @param ContainerInterface  $container           The DI container (for ObjectService)
     * @param LoggerInterface     $logger              The PSR-3 logger
     * @param ParticipantResolver $participantResolver Participant resolver for meeting-based access checks
     * @param MeetingService      $meetingService      The meeting service owning the lifecycle transitions
     *
     * @spec openspec/specs/mcp-tools/spec.md
     */
    public function __construct(
        IUserSession $userSession,
        IGroupManager $groupManager,
        ContainerInterface $container,
        LoggerInterface $logger,
        ParticipantResolver $participantResolver,
        private readonly MeetingService $meetingService,
    ) {
        parent::__construct(
            userSession: $userSession,
            groupManager: $groupManager,
            container: $container,
            logger: $logger,
            participantResolver: $participantResolver
        );

    }//end __construct()

    /**
     * Handle decidesk.startMeeting.
     *
     * Argument validation runs BEFORE authorisation, which runs BEFORE the
     * state guard, which runs BEFORE the transition (design D4).
     *
     * @param array<string, mixed> $args Tool arguments
     *
     * @return array<string, mixed>
     *
     * @spec openspec/specs/mcp-tools/spec.md
     */
    public function handle(array $args): array
    {
        $rawUuid = $args['meetingUuid'] ?? null;

        $invalid = $this->validateMeetingUuid(meetingUuid: $rawUuid);
        if ($invalid !== null) {
            return $invalid;
        }

        $meetingUuid = (string) $rawUuid;

        $meeting = $this->loadMeeting(meetingUuid: $meetingUuid);
        if ($meeting === null) {
            return $this->meetingNotFound();
        }

        $currentUserId  = $this->currentUserId();
        $isChairOrAdmin = $this->requireChairOrAdmin(meeting: $meeting, userId: $currentUserId);
        if ($isChairOrAdmin === false) {
            return $this->errorResult(
                error: 'forbidden',
                message: 'Only the chair or an admin can start this meeting.'
            );
        }

        // State guard: only scheduled meetings can be opened (REQ-DMCP-005).
        $stateError = $this->stateError(meeting: $meeting);
        if ($stateError !== null) {
            return $stateError;
        }

        return $this->open(meetingUuid: $meetingUuid, meeting: $meeting, userId: $currentUserId);

    }//end handle()

    /**
     * Check the meeting lifecycle state guard.
     *
     * @param array<string, mixed> $meeting The loaded meeting object
     *
     * @return array<string, mixed>|null An invalid_state envelope, or null when the meeting may be opened.
     *
     * @spec openspec/specs/mcp-tools/spec.md
     */
    private function stateError(array $meeting): ?array
    {
        $lifecycle = $meeting['lifecycle'] ?? 'draft';
        if ($lifecycle === 'scheduled') {
            return null;
        }

        $stateLabel = $lifecycle;
        if ($lifecycle === 'opened') {
            $stateLabel = 'in progress';
        }

        return $this->errorResult(
            error: 'invalid_state',
            message: "Meeting is already {$stateLabel}."
        );

    }//end stateError()

    /**
     * Run the open transition and build the success payload.
     *
     * @param string               $meetingUuid The validated meeting UUID
     * @param array<string, mixed> $meeting     The loaded meeting object
     * @param string               $userId      The calling user id
     *
     * @return array<string, mixed>
     *
     * @spec openspec/specs/mcp-tools/spec.md
     */
    private function open(string $meetingUuid, array $meeting, string $userId): array
    {
        $result = $this->meetingService->transition(
            meetingId: $meetingUuid,
            action: 'open',
            currentUserId: $userId,
        );

        if ($result['success'] === false) {
            return $this->errorResult(error: 'internal_error', message: $result['message']);
        }

        $startedAt = (new DateTimeImmutable())->format(DateTimeInterface::ATOM);

        return [
            'success'     => true,
            'started'     => true,
            'meetingUuid' => $meetingUuid,
            'startedAt'   => $startedAt,
            'sources'     => [
                $this->makeSource(
                    kind: 'meeting',
                    uuid: $meetingUuid,
                    label: $this->pickLabel(item: $meeting, keys: ['title'], fallback: 'Meeting')
                ),
            ],
        ];

    }//end open()
}//end class
