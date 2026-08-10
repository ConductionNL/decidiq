<?php
/**
 * Decidesk MCP Meeting Gate
 *
 * Single entry point for the "load a meeting and prove the caller may touch
 * it" step that every meeting-scoped MCP tool performs before doing work.
 *
 * @category Mcp
 * @package  OCA\Decidesk\Mcp
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
// SPDX-License-Identifier: EUPL-1.2
declare(strict_types=1);

namespace OCA\Decidesk\Mcp;

use OCA\Decidesk\Service\ParticipantResolver;
use OCP\IGroupManager;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Loads a meeting and enforces per-object authorisation for MCP tools.
 *
 * Extracted from DecideskToolProvider so the argument -> load -> not_found ->
 * authorise ladder is written once instead of three times, and so the
 * authorisation helpers stay a testable unit of their own.
 *
 * Auth design (OWASP A01:2021 / ADR-005):
 * - isChairOrAdmin() / isParticipantOrAdmin() return bool — they do NOT return
 *   true unconditionally and are NOT wrapped in catch(\Throwable).
 * - isAdmin() uses IGroupManager::isAdmin() (NC system admin) as the admin gate.
 *
 * @spec openspec/specs/mcp-tools/spec.md
 */
class McpMeetingGate
{

    /**
     * Resolves which meetings the caller may see when scope=all.
     *
     * @var McpMeetingScopeResolver
     */
    private readonly McpMeetingScopeResolver $scopeResolver;

    /**
     * Constructor for the McpMeetingGate.
     *
     * @param ContainerInterface   $container           DI container used to reach OpenRegister
     * @param IUserSession         $userSession         The current user session
     * @param IGroupManager        $groupManager        The group manager (for admin checks)
     * @param LoggerInterface      $logger              The PSR-3 logger
     * @param ParticipantResolver  $participantResolver Participant resolver for meeting-based access checks
     * @param McpSourceFormatter   $formatter           Builds the error envelopes
     * @param McpArgumentValidator $validator           Validates the meetingUuid argument
     *
     * @return void
     */
    public function __construct(
        private readonly ContainerInterface $container,
        private readonly IUserSession $userSession,
        private readonly IGroupManager $groupManager,
        LoggerInterface $logger,
        private readonly ParticipantResolver $participantResolver,
        private readonly McpSourceFormatter $formatter,
        private readonly McpArgumentValidator $validator,
    ) {
        $this->scopeResolver = new McpMeetingScopeResolver(
            container: $container,
            groupManager: $groupManager,
            logger: $logger
        );

    }//end __construct()

    /**
     * Nextcloud UID of the current caller, or an empty string when anonymous.
     *
     * @return string The caller UID.
     *
     * @spec openspec/specs/mcp-tools/spec.md
     */
    public function currentUserId(): string
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return '';
        }

        return $user->getUID();

    }//end currentUserId()

    /**
     * Return the set of meeting UUIDs the caller may see, or null for admins.
     *
     * @param string $userId Nextcloud UID of the caller
     *
     * @return array<string>|null Set of meeting UUIDs, or null for unrestricted admin.
     *
     * @spec openspec/specs/mcp-tools/spec.md
     */
    public function callerMeetingUuids(string $userId): ?array
    {
        return $this->scopeResolver->callerMeetingUuids(userId: $userId);

    }//end callerMeetingUuids()

    /**
     * Validate, load and authorise a meeting in one step.
     *
     * On success returns `['meeting' => array, 'userId' => string, 'uuid' => string]`.
     * On any failure returns `['error' => array]` carrying the MCP error envelope.
     * Checks run in the documented order: missing argument, malformed UUID,
     * not found, then per-object authorisation (design D4).
     *
     * @param mixed  $meetingUuid The raw meetingUuid argument
     * @param string $requirement Either "chair" or "participant"
     *
     * @return array<string, mixed> Either the resolved meeting or an error envelope.
     *
     * @spec openspec/specs/mcp-tools/spec.md
     */
    public function authorise(mixed $meetingUuid, string $requirement): array
    {
        $argumentError = $this->validator->validateMeetingUuid(meetingUuid: $meetingUuid);
        if ($argumentError !== null) {
            return ['error' => $argumentError];
        }

        $uuid    = (string) $meetingUuid;
        $meeting = $this->loadMeeting(meetingUuid: $uuid);
        if ($meeting === null) {
            return ['error' => $this->formatter->error(code: 'not_found', message: 'Meeting not found.')];
        }

        $userId = $this->currentUserId();
        if ($this->isGranted(requirement: $requirement, meetingUuid: $uuid, meeting: $meeting, userId: $userId) === false) {
            return [
                'error' => $this->formatter->error(
                    code: 'forbidden',
                    message: $this->denialMessage(requirement: $requirement)
                ),
            ];
        }

        return [
            'meeting' => $meeting,
            'userId'  => $userId,
            'uuid'    => $uuid,
        ];

    }//end authorise()

    /**
     * Fetch a meeting object from OpenRegister.
     *
     * @param string $meetingUuid The meeting UUID
     *
     * @return array<string, mixed>|null The meeting data, or null when absent.
     *
     * @spec openspec/specs/mcp-tools/spec.md
     */
    public function loadMeeting(string $meetingUuid): ?array
    {
        $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
        $meetingEntity = $objectService->find(id: $meetingUuid, register: 'decidesk', schema: 'meeting');
        if ($meetingEntity === null) {
            return null;
        }

        return $meetingEntity->jsonSerialize();

    }//end loadMeeting()

    /**
     * Check whether the calling user is the meeting chair or a system admin.
     *
     * Auth design (OWASP A01:2021 / ADR-005):
     * - The chair is identified by the 'chair' field in the meeting object.
     * - Admin is resolved via IGroupManager::isAdmin() (NC system admin group).
     * - This helper MUST actually run — it does not return true unconditionally.
     *
     * @param array<string, mixed> $meeting The meeting data array
     * @param string               $userId  The calling user ID
     *
     * @return bool True when the user is authorised.
     *
     * @spec openspec/specs/mcp-tools/spec.md
     */
    public function isChairOrAdmin(array $meeting, string $userId): bool
    {
        if ($userId === '') {
            return false;
        }

        if ($this->isAdmin(userId: $userId) === true) {
            return true;
        }

        $chairUserId = $meeting['chair'] ?? null;
        if ($chairUserId !== null && (string) $chairUserId === $userId) {
            return true;
        }

        return false;

    }//end isChairOrAdmin()

    /**
     * Check whether the calling user is a participant of the meeting or a system admin.
     *
     * Auth design (OWASP A01:2021 / ADR-005):
     * - Participation is resolved through the canonical schema path
     *   meeting -> governanceBody -> participants (ParticipantResolver), because
     *   the meeting object's own `participants` array is unreliable.
     * - Admin is resolved via IGroupManager::isAdmin() (NC system admin group).
     * - This helper MUST actually run — it does not return true unconditionally.
     *
     * @param string $meetingUuid The meeting UUID
     * @param string $userId      The calling user ID
     *
     * @return bool True when the user is authorised.
     *
     * @spec openspec/specs/mcp-tools/spec.md
     */
    public function isParticipantOrAdmin(string $meetingUuid, string $userId): bool
    {
        if ($userId === '') {
            return false;
        }

        if ($this->isAdmin(userId: $userId) === true) {
            return true;
        }

        return $this->participantResolver->isParticipant(
            meetingId: $meetingUuid,
            nextcloudUid: $userId,
        );

    }//end isParticipantOrAdmin()

    /**
     * Check whether the user is a Nextcloud system administrator.
     *
     * @param string $userId The Nextcloud user ID
     *
     * @return bool True when the user is a system admin.
     *
     * @spec openspec/specs/mcp-tools/spec.md
     */
    public function isAdmin(string $userId): bool
    {
        return $this->groupManager->isAdmin($userId);

    }//end isAdmin()

    /**
     * Dispatch to the authorisation helper the requirement names.
     *
     * @param string               $requirement Either "chair" or "participant"
     * @param string               $meetingUuid The meeting UUID
     * @param array<string, mixed> $meeting     The meeting data array
     * @param string               $userId      The calling user ID
     *
     * @return bool True when the user satisfies the requirement.
     *
     * @spec openspec/specs/mcp-tools/spec.md
     */
    private function isGranted(string $requirement, string $meetingUuid, array $meeting, string $userId): bool
    {
        return match ($requirement) {
            'chair' => $this->isChairOrAdmin(meeting: $meeting, userId: $userId),
            default => $this->isParticipantOrAdmin(meetingUuid: $meetingUuid, userId: $userId),
        };

    }//end isGranted()

    /**
     * The message returned when a requirement is not satisfied.
     *
     * @param string $requirement Either "chair" or "participant"
     *
     * @return string The denial message.
     *
     * @spec openspec/specs/mcp-tools/spec.md
     */
    private function denialMessage(string $requirement): string
    {
        return match ($requirement) {
            'chair' => 'Only the chair or an admin can start this meeting.',
            default => 'You are not a participant of this meeting.',
        };

    }//end denialMessage()
}//end class
