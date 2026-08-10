<?php
/**
 * Decidesk Transcription Staff Guard
 *
 * Per-object staff (chair/secretary) authorization for the meeting-transcription
 * action endpoints. Extracted from TranscriptionController so the controller
 * keeps only its HTTP responsibility while the no-admin-idor invariant lives in
 * one auditable place.
 *
 * @category Service
 * @package  OCA\Decidesk\Service
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

namespace OCA\Decidesk\Service;

use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IUserSession;
use Throwable;

/**
 * Resolves whether the session user may act on a meeting, transcript or body.
 *
 * Every guard fails CLOSED for non-admins: when the subject cannot be resolved
 * to at least one meeting, access is denied with 403 rather than allowed.
 *
 * @spec openspec/specs/meeting-transcription/spec.md
 */
class TranscriptionStaffGuard
{

    /**
     * Meeting participant roles that count as transcription staff.
     *
     * @var list<string>
     */
    private const STAFF_ROLES = [
        'chair',
        'secretary',
    ];

    /**
     * Denial message used when the subject cannot be resolved at all.
     */
    private const UNRESOLVED_MESSAGE = 'Forbidden.';

    /**
     * Construct the guard.
     *
     * @param ObjectService       $objectService       OR object service (subject reads).
     * @param ParticipantResolver $participantResolver Meeting role resolution.
     * @param IUserSession        $userSession         Current user session.
     * @param IGroupManager       $groupManager        Group manager (admin check).
     *
     * @spec openspec/specs/meeting-transcription/spec.md
     */
    public function __construct(
        private readonly ObjectService $objectService,
        private readonly ParticipantResolver $participantResolver,
        private readonly IUserSession $userSession,
        private readonly IGroupManager $groupManager,
    ) {
    }//end __construct()

    /**
     * The UID of the session user, or an empty string when unauthenticated.
     *
     * @return string The Nextcloud UID.
     *
     * @spec openspec/specs/meeting-transcription/spec.md
     */
    public function currentUserId(): string
    {
        return (string) $this->userSession->getUser()?->getUID();

    }//end currentUserId()

    /**
     * Staff guard for a meeting id (chair/secretary or NC admin).
     *
     * @param string $meetingId Meeting UUID.
     *
     * @return JSONResponse|null Null when authorised; a 401/403 response otherwise.
     *
     * @spec openspec/specs/meeting-transcription/spec.md
     */
    public function forMeeting(string $meetingId): ?JSONResponse
    {
        return $this->authorize(
            resolveMeetings: static function () use ($meetingId): ?array {
                if ($meetingId === '') {
                    return null;
                }

                return [$meetingId];
            },
            roleMessage: 'Forbidden: chair or secretary role required.'
        );

    }//end forMeeting()

    /**
     * Staff guard for a transcript id — resolves its meeting then delegates.
     *
     * Fails CLOSED for non-admins: an unresolvable transcript/meeting yields 403.
     *
     * @param string $transcriptId Transcript UUID.
     *
     * @return JSONResponse|null Null when authorised; a 401/403 response otherwise.
     *
     * @spec openspec/specs/meeting-transcription/spec.md
     */
    public function forTranscript(string $transcriptId): ?JSONResponse
    {
        return $this->authorize(
            resolveMeetings: fn (): ?array => $this->meetingsOfTranscript(transcriptId: $transcriptId),
            roleMessage: 'Forbidden: chair or secretary role required.'
        );

    }//end forTranscript()

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
    public function forBody(string $bodyId): ?JSONResponse
    {
        return $this->authorize(
            resolveMeetings: function () use ($bodyId): ?array {
                if ($bodyId === '') {
                    return null;
                }

                return $this->meetingsOfBody(bodyId: $bodyId);
            },
            roleMessage: 'Forbidden: chair or secretary role required for this body.'
        );

    }//end forBody()

    /**
     * Shared authorization pipeline: authenticate, admit admins, then role-check.
     *
     * The meeting resolver is a callable so the (potentially expensive) subject
     * lookup only runs for non-admins, exactly as the inline guards did. It
     * returns null when the subject cannot be resolved at all, which denies with
     * the neutral message that never leaks existence.
     *
     * @param callable $resolveMeetings Lazily yields the candidate meeting ids, or null.
     * @param string   $roleMessage     Denial message when no staff role matches.
     *
     * @return JSONResponse|null Null when authorised; a 401/403 response otherwise.
     *
     * @spec openspec/specs/meeting-transcription/spec.md
     */
    private function authorize(callable $resolveMeetings, string $roleMessage): ?JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['message' => 'Unauthenticated.'], Http::STATUS_UNAUTHORIZED);
        }

        $userId = $user->getUID();
        if ($this->groupManager->isAdmin($userId) === true) {
            return null;
        }

        $meetingIds = $resolveMeetings();
        if ($meetingIds === null) {
            return new JSONResponse(['message' => self::UNRESOLVED_MESSAGE], Http::STATUS_FORBIDDEN);
        }

        foreach ($meetingIds as $meetingId) {
            if ($this->participantResolver->hasRole(meetingId: $meetingId, nextcloudUid: $userId, roles: self::STAFF_ROLES) === true) {
                return null;
            }
        }

        return new JSONResponse(['message' => $roleMessage], Http::STATUS_FORBIDDEN);

    }//end authorize()

    /**
     * Resolve the meeting a transcript belongs to.
     *
     * @param string $transcriptId Transcript UUID.
     *
     * @return list<string>|null The single owning meeting id, or null when unresolvable.
     *
     * @spec openspec/specs/meeting-transcription/spec.md
     */
    private function meetingsOfTranscript(string $transcriptId): ?array
    {
        $entity = $this->objectService->find(id: $transcriptId, register: 'decidesk', schema: 'transcript');
        if ($entity === null) {
            // Fail closed: do not leak existence to non-staff.
            return null;
        }

        $transcript = (array) $entity->jsonSerialize();
        $meetingId  = ($transcript['relations']['meeting'] ?? ($transcript['meeting'] ?? null));
        if (is_array($meetingId) === true) {
            $meetingId = ($meetingId['id'] ?? ($meetingId[0] ?? null));
        }

        if ($meetingId === null || $meetingId === '') {
            return null;
        }

        return [(string) $meetingId];

    }//end meetingsOfTranscript()

    /**
     * Resolve the meetings that belong to a governance body.
     *
     * @param string $bodyId Governance body UUID.
     *
     * @return list<string> The resolvable meeting ids (possibly empty).
     *
     * @spec openspec/specs/meeting-transcription/spec.md
     */
    private function meetingsOfBody(string $bodyId): array
    {
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
        } catch (Throwable) {
            return [];
        }

        $meetingIds = [];
        foreach ($entities as $entity) {
            $meeting = $this->toArray(entity: $entity);
            if ($meeting === null) {
                continue;
            }

            $meetingId = (string) ($meeting['id'] ?? ($meeting['@self']['id'] ?? ''));
            if ($meetingId !== '') {
                $meetingIds[] = $meetingId;
            }
        }

        return $meetingIds;

    }//end meetingsOfBody()

    /**
     * Normalise an OpenRegister result entry to a plain property array.
     *
     * @param mixed $entity An array payload or an entity exposing jsonSerialize().
     *
     * @return array<string, mixed>|null The property map, or null when unusable.
     *
     * @spec openspec/specs/meeting-transcription/spec.md
     */
    private function toArray(mixed $entity): ?array
    {
        if (is_array($entity) === true) {
            return $entity;
        }

        if (is_object($entity) === true && method_exists($entity, 'jsonSerialize') === true) {
            return (array) $entity->jsonSerialize();
        }

        return null;

    }//end toArray()
}//end class
