<?php
/**
 * Decidesk MCP Abstract Tool Handler
 *
 * Shared base for the per-tool MCP handlers extracted out of
 * DecideskToolProvider. Owns argument validation, per-object authorisation,
 * meeting loading, deep-link building and source-list shaping, so that each
 * concrete handler contains only the logic specific to its own tool.
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

use OCA\Decidesk\Mcp\McpMeetingScopeResolver;
use OCA\Decidesk\Service\ParticipantResolver;
use OCP\IGroupManager;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Base class for a single decidesk MCP tool handler.
 *
 * Auth design (OWASP A01:2021 / ADR-005):
 * - Per-object authorisation runs inside handle(), AFTER argument validation
 *   but BEFORE business logic. Every helper invoked MUST actually run.
 * - requireChairOrAdmin() / requireParticipantOrAdmin() return bool — they do
 *   NOT return true unconditionally and are NOT wrapped in catch(\Throwable).
 * - isAdmin() uses IGroupManager::isAdmin() (NC system admin) as the admin gate.
 *
 * @spec openspec/specs/mcp-tools/spec.md
 */
abstract class AbstractToolHandler
{

    /**
     * Maximum number of source descriptors per tool result (REQ-DMCP-006).
     *
     * @var int
     */
    protected const SOURCES_CAP = 20;

    /**
     * Resolves which meetings the caller may see when scope=all.
     *
     * @var McpMeetingScopeResolver
     */
    protected readonly McpMeetingScopeResolver $scopeResolver;

    /**
     * Constructor for the shared MCP tool handler base.
     *
     * @param IUserSession        $userSession         The current user session
     * @param IGroupManager       $groupManager        The group manager (for admin checks)
     * @param ContainerInterface  $container           The DI container (for ObjectService)
     * @param LoggerInterface     $logger              The PSR-3 logger
     * @param ParticipantResolver $participantResolver Participant resolver for meeting-based access checks
     *
     * @spec openspec/specs/mcp-tools/spec.md
     */
    public function __construct(
        protected readonly IUserSession $userSession,
        protected readonly IGroupManager $groupManager,
        protected readonly ContainerInterface $container,
        protected readonly LoggerInterface $logger,
        protected readonly ParticipantResolver $participantResolver,
    ) {
        $this->scopeResolver = new McpMeetingScopeResolver(
            container: $container,
            groupManager: $groupManager,
            logger: $logger
        );

    }//end __construct()

    /**
     * Execute the tool this handler implements.
     *
     * @param array<string, mixed> $args Tool arguments from the LLM call
     *
     * @return array<string, mixed>
     *
     * @spec openspec/specs/mcp-tools/spec.md
     */
    abstract public function handle(array $args): array;

    /**
     * Resolve the OpenRegister ObjectService from the DI container.
     *
     * @return object The OpenRegister ObjectService.
     */
    protected function objectService(): object
    {
        return $this->container->get('OCA\OpenRegister\Service\ObjectService');

    }//end objectService()

    /**
     * Return the calling user's Nextcloud UID, or an empty string when anonymous.
     *
     * @return string The current user id.
     */
    protected function currentUserId(): string
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return '';
        }

        return $user->getUID();

    }//end currentUserId()

    /**
     * Validate that a string is a syntactically valid UUID (8-4-4-4-12 hex).
     *
     * @param string $candidate The candidate string to validate
     *
     * @return bool True when the string is UUID-shaped.
     *
     * @spec openspec/specs/mcp-tools/spec.md
     */
    protected function isValidUuid(string $candidate): bool
    {
        return (bool) preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
            $candidate
        );

    }//end isValidUuid()

    /**
     * Validate the meetingUuid argument shared by three tools.
     *
     * @param mixed $meetingUuid The raw meetingUuid argument
     *
     * @return array<string, mixed>|null An error envelope, or null when valid.
     *
     * @spec openspec/specs/mcp-tools/spec.md
     */
    protected function validateMeetingUuid(mixed $meetingUuid): ?array
    {
        if ($meetingUuid === null || $meetingUuid === '') {
            return $this->invalidArguments(message: 'Required argument meetingUuid is missing.');
        }

        if ($this->isValidUuid(candidate: (string) $meetingUuid) === false) {
            return $this->invalidArguments(
                message: "Invalid UUID format for meetingUuid: '{$meetingUuid}'."
            );
        }

        return null;

    }//end validateMeetingUuid()

    /**
     * Load a meeting object by UUID.
     *
     * @param string $meetingUuid The meeting UUID
     *
     * @return array<string, mixed>|null The meeting data, or null when not found.
     *
     * @spec openspec/specs/mcp-tools/spec.md
     */
    protected function loadMeeting(string $meetingUuid): ?array
    {
        $meetingEntity = $this->objectService()->find(
            id: $meetingUuid,
            register: 'decidesk',
            schema: 'meeting'
        );

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
    protected function requireChairOrAdmin(array $meeting, string $userId): bool
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

    }//end requireChairOrAdmin()

    /**
     * Check whether the calling user is a participant of the meeting or a system admin.
     *
     * Auth design (OWASP A01:2021 / ADR-005):
     * - Participants are resolved through the canonical schema path
     *   meeting -> governanceBody -> participants (ParticipantResolver), because
     *   the meeting object's own `participants` array is unreliable and
     *   `@self.relations.meeting` does not exist on the participant schema.
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
    protected function requireParticipantOrAdmin(string $meetingUuid, string $userId): bool
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

    }//end requireParticipantOrAdmin()

    /**
     * Check whether the user is a Nextcloud system administrator.
     *
     * @param string $userId The Nextcloud user ID
     *
     * @return bool True when the user is a system admin.
     *
     * @spec openspec/specs/mcp-tools/spec.md
     */
    protected function isAdmin(string $userId): bool
    {
        return $this->groupManager->isAdmin($userId);

    }//end isAdmin()

    /**
     * Build a structured error envelope.
     *
     * @param string $error   The machine-readable error code
     * @param string $message The human-readable message
     *
     * @return array<string, mixed>
     *
     * @spec openspec/specs/mcp-tools/spec.md
     */
    protected function errorResult(string $error, string $message): array
    {
        return [
            'isError' => true,
            'error'   => $error,
            'message' => $message,
        ];

    }//end errorResult()

    /**
     * Build an invalid_arguments error envelope.
     *
     * @param string $message The human-readable message
     *
     * @return array<string, mixed>
     *
     * @spec openspec/specs/mcp-tools/spec.md
     */
    protected function invalidArguments(string $message): array
    {
        return $this->errorResult(error: 'invalid_arguments', message: $message);

    }//end invalidArguments()

    /**
     * Build the canonical "meeting not found" envelope.
     *
     * @return array<string, mixed>
     *
     * @spec openspec/specs/mcp-tools/spec.md
     */
    protected function meetingNotFound(): array
    {
        return $this->errorResult(error: 'not_found', message: 'Meeting not found.');

    }//end meetingNotFound()

    /**
     * Build the canonical "not a participant" envelope.
     *
     * @return array<string, mixed>
     *
     * @spec openspec/specs/mcp-tools/spec.md
     */
    protected function notAParticipant(): array
    {
        return $this->errorResult(
            error: 'forbidden',
            message: 'You are not a participant of this meeting.'
        );

    }//end notAParticipant()

    /**
     * Log a handler failure and return the canonical internal_error envelope.
     *
     * @param string               $tool    The tool name, used in the log line
     * @param string               $message The user-facing message
     * @param \Throwable           $error   The caught throwable
     * @param array<string, mixed> $context Extra log context
     *
     * @return array<string, mixed>
     *
     * @spec openspec/specs/mcp-tools/spec.md
     */
    protected function internalError(string $tool, string $message, \Throwable $error, array $context=[]): array
    {
        $this->logger->error(
            "Decidesk MCP: {$tool} failed",
            array_merge($context, ['exception' => $error->getMessage()])
        );

        return $this->errorResult(error: 'internal_error', message: $message);

    }//end internalError()

    /**
     * Build a deep link URL for a decidesk resource.
     *
     * @param string $type One of: meeting, agendaItem, decision, actionItem
     * @param string $uuid The object UUID
     *
     * @return string The deep link path, e.g. /apps/decidesk/meetings/<uuid>.
     *
     * @spec openspec/specs/mcp-tools/spec.md
     */
    protected function buildDeepLink(string $type, string $uuid): string
    {
        $paths = [
            'meeting'    => '/apps/decidesk/meetings',
            'agendaItem' => '/apps/decidesk/agenda-items',
            'decision'   => '/apps/decidesk/decisions',
            'actionItem' => '/apps/decidesk/action-items',
        ];

        $base = $paths[$type] ?? "/apps/decidesk/{$type}s";
        return "{$base}/{$uuid}";

    }//end buildDeepLink()

    /**
     * Build a single source descriptor.
     *
     * The wire `type` is always the app-namespaced form "decidesk.<kind>" and the
     * deep link is always built from the same <kind>, so both stay in lockstep.
     *
     * @param string $kind  The resource kind: meeting, agendaItem, decision or actionItem
     * @param string $uuid  The object UUID
     * @param string $label The human-readable label
     *
     * @return array<string, mixed>
     *
     * @spec openspec/specs/mcp-tools/spec.md
     */
    protected function makeSource(string $kind, string $uuid, string $label): array
    {
        return [
            'type'  => "decidesk.{$kind}",
            'uuid'  => $uuid,
            'url'   => $this->buildDeepLink(type: $kind, uuid: $uuid),
            'label' => $label,
        ];

    }//end makeSource()

    /**
     * Pick the first present label field from an object, falling back to a default.
     *
     * @param array<string, mixed> $item     The normalised object array
     * @param array<int, string>   $keys     The candidate label keys, in priority order
     * @param string               $fallback The fallback label
     *
     * @return string The resolved label.
     */
    protected function pickLabel(array $item, array $keys, string $fallback): string
    {
        foreach ($keys as $key) {
            if (isset($item[$key]) === true) {
                return (string) $item[$key];
            }
        }

        return $fallback;

    }//end pickLabel()

    /**
     * Append the sources array (and truncation markers) to a result payload.
     *
     * Key order is preserved: 'sources' is appended after the caller's own
     * keys, followed by the truncation markers when the cap was exceeded.
     *
     * @param array<string, mixed>             $result  The result payload built by the handler
     * @param array<int, array<string, mixed>> $sources The full sources array
     *
     * @return array<string, mixed> The result with sources applied.
     *
     * @spec openspec/specs/mcp-tools/spec.md
     */
    protected function withSources(array $result, array $sources): array
    {
        $truncated = $this->truncateSources(sources: $sources);

        $result['sources'] = $truncated['truncated'];

        if ($truncated['didTruncate'] === true) {
            $result['sourcesTruncated']  = true;
            $result['sourcesTotalCount'] = $truncated['totalCount'];
        }

        return $result;

    }//end withSources()

    /**
     * Truncate a sources array to at most SOURCES_CAP elements.
     *
     * Returns a structure with:
     * - truncated:   the (possibly capped) sources array
     * - totalCount:  the original count before truncation
     * - didTruncate: bool — true when the array was capped
     *
     * @param array<int, array<string, mixed>> $sources The full sources array
     *
     * @return array{truncated: array<int, array<string, mixed>>, totalCount: int, didTruncate: bool}
     *
     * @spec openspec/specs/mcp-tools/spec.md
     */
    protected function truncateSources(array $sources): array
    {
        $totalCount  = count($sources);
        $didTruncate = ($totalCount > self::SOURCES_CAP);
        $truncated   = $sources;
        if ($didTruncate === true) {
            $truncated = array_slice(array: $sources, offset: 0, length: self::SOURCES_CAP);
        }

        return [
            'truncated'   => $truncated,
            'totalCount'  => $totalCount,
            'didTruncate' => $didTruncate,
        ];

    }//end truncateSources()

    /**
     * Normalise an OpenRegister object to a plain PHP array.
     *
     * @param mixed $item Raw item from ObjectService
     *
     * @return array<string, mixed>
     */
    protected function toArray(mixed $item): array
    {
        if (is_array(value: $item) === true) {
            return $item;
        }

        if (is_object(value: $item) === true && method_exists($item, 'getObject') === true) {
            return $item->getObject();
        }

        if (is_object(value: $item) === true && method_exists($item, 'jsonSerialize') === true) {
            return $item->jsonSerialize();
        }

        return (array) $item;

    }//end toArray()

    /**
     * Extract the UUID from a normalised object array.
     *
     * Checks multiple common field names to handle different OR object shapes.
     *
     * @param array<string, mixed> $item The normalised object array
     *
     * @return string The UUID, or empty string when not found.
     */
    protected function extractUuid(array $item): string
    {
        $uuid = $item['uuid'] ?? $item['id'] ?? ($item['@self']['uuid'] ?? ($item['@self']['id'] ?? ''));
        return (string) $uuid;

    }//end extractUuid()
}//end class
