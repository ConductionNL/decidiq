<?php
/**
 * Decidesk Publication Eligibility Service
 *
 * Server-side gates and the structural type deny-list governing which
 * governance objects may be published to the public surface.
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
 * @spec openspec/changes/publish-decisions-via-opencatalogi/specs/public-publication/spec.md
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2.
declare(strict_types=1);

namespace OCA\Decidesk\Service;

use OCA\Decidesk\Exception\AccessDeniedException;
use OCA\Decidesk\Exception\MissingObjectException;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Stateless service that decides whether a governance object is publishable.
 *
 * Two independent gates, evaluated in order:
 *   1. Structural type DENY-LIST — refused before any eligibility evaluation
 *      (board-governance family, votes/rounds, confidential resolutions,
 *      conflict declarations, audit logs, transcripts, recordings).
 *   2. Lifecycle/state eligibility per source type (decision / agenda / minutes).
 *
 * Sibling PR #73 (meeting-transcription) also introduces this file as the
 * deny-list home; the union of both deny-lists is intended — Transcript and
 * recording-file types are included here so the merge reconciles cleanly.
 *
 * @spec openspec/changes/publish-decisions-via-opencatalogi/specs/public-publication/spec.md
 */
class PublicationEligibilityService
{
    /**
     * Structural deny-list of object/schema types that are NEVER publishable.
     *
     * Matched case-insensitively against the schema slug AND the object's
     * declared type discriminators. Includes the meeting-transcription family
     * (Transcript, recording) so PR #73's deny-list unions cleanly at merge.
     *
     * @var string[]
     */
    public const DENY_TYPES = [
        'boardmeeting',
        'board-meeting',
        'boardminutes',
        'board-minutes',
        'boardmaterial',
        'board-material',
        'boardvote',
        'board-vote',
        'conflictofinterest',
        'conflict-of-interest',
        'boardauditlogentry',
        'audit-trail',
        'auditlogentry',
        'vote',
        'votinground',
        'voting-round',
        'transcript',
        'recording',
    ];

    /**
     * Constructor.
     *
     * @param ContainerInterface $container DI container (lazy ObjectService).
     * @param LoggerInterface    $logger    Logger.
     *
     * @spec openspec/changes/publish-decisions-via-opencatalogi/specs/public-publication/spec.md
     */
    public function __construct(
        private readonly ContainerInterface $container,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Determine whether a schema/type is on the structural deny-list.
     *
     * Evaluated BEFORE any lifecycle eligibility check — board-governance and
     * confidential outputs are structurally non-publishable regardless of state.
     *
     * @param string              $schema     Source schema slug (decision|meeting|minutes|...).
     * @param array<string,mixed> $objectData The object payload (for discriminators).
     *
     * @spec openspec/changes/publish-decisions-via-opencatalogi/specs/public-publication/spec.md
     *
     * @return bool True when publication must be structurally refused.
     */
    public function isDeniedType(string $schema, array $objectData): bool
    {
        $candidates = [strtolower($schema)];

        // Object-level discriminators that can flip an otherwise-allowed schema
        // into the deny-list (e.g. a confidential Resolution-type decision).
        foreach (['type', 'objectType', 'decisionType', 'meetingType'] as $key) {
            $value = ($objectData[$key] ?? null);
            if (is_string($value) === true && $value !== '') {
                $candidates[] = strtolower($value);
            }
        }

        foreach ($candidates as $candidate) {
            if (in_array($candidate, self::DENY_TYPES, true) === true) {
                return true;
            }
        }

        // Confidential Resolution: a resolution carrying a confidentiality
        // classification is never publishable (board confidentiality wins).
        $classification = strtolower((string) ($objectData['confidentiality'] ?? $objectData['classification'] ?? ''));
        if (($objectData['decisionType'] ?? '') === 'resolution'
            && in_array($classification, ['confidential', 'secret', 'restricted'], true) === true
        ) {
            return true;
        }

        return false;

    }//end isDeniedType()

    /**
     * Evaluate full publication eligibility for a source object.
     *
     * Runs the deny-list first, then the per-type lifecycle/state gate. Throws
     * on any failure so callers can surface a precise error; returns the
     * resolved object data on success.
     *
     * @param string $sourceType One of decision|agenda|minutes.
     * @param string $sourceId   UUID of the source object.
     *
     * @spec openspec/changes/publish-decisions-via-opencatalogi/specs/public-publication/spec.md
     *
     * @throws MissingObjectException When the source object does not exist.
     * @throws AccessDeniedException  When structurally denied or not yet eligible.
     *
     * @return array<string,mixed> The resolved source object data.
     */
    public function assertEligible(string $sourceType, string $sourceId): array
    {
        $schema = $this->schemaForType($sourceType);
        $data   = $this->loadObject($schema, $sourceId);

        if ($this->isDeniedType($schema, $data) === true) {
            throw new AccessDeniedException('This object type is not publishable.');
        }

        switch ($sourceType) {
            case 'decision':
                $this->assertDecisionEligible($data);
                break;
            case 'agenda':
                $this->assertAgendaEligible($data);
                break;
            case 'minutes':
                $this->assertMinutesEligible($data);
                break;
            default:
                throw new AccessDeniedException('Unknown publication source type: '.$sourceType);
        }

        return $data;

    }//end assertEligible()

    /**
     * Guard a direct client write to the flow-owned Decision publication fields.
     *
     * The publication state fields (isPublished/publishedAt) are derived outputs
     * of the publication flow. A client object-update that attempts to set them
     * to a value differing from the stored value is rejected — only the
     * publication flow may move them (decision-management delta).
     *
     * @param array<string,mixed> $stored   The currently-stored object data.
     * @param array<string,mixed> $incoming The incoming update payload.
     *
     * @spec openspec/changes/publish-decisions-via-opencatalogi/specs/decision-management/spec.md
     *
     * @throws AccessDeniedException When the incoming write changes a flow-owned field.
     *
     * @return void
     */
    public function guardDirectPublicationWrite(array $stored, array $incoming): void
    {
        foreach (['isPublished', 'publishedAt'] as $field) {
            if (array_key_exists($field, $incoming) === false) {
                continue;
            }

            $storedValue   = ($stored[$field] ?? null);
            $incomingValue = ($incoming[$field] ?? null);
            if ($incomingValue !== $storedValue) {
                throw new AccessDeniedException(
                    "The field '$field' is owned by the publication flow and cannot be written directly."
                );
            }
        }

    }//end guardDirectPublicationWrite()

    /**
     * Assert a decision is in a publishable lifecycle (decided|enacted).
     *
     * @param array<string,mixed> $data Decision object data.
     *
     * @spec openspec/changes/publish-decisions-via-opencatalogi/specs/public-publication/spec.md
     *
     * @throws AccessDeniedException When not eligible.
     *
     * @return void
     */
    private function assertDecisionEligible(array $data): void
    {
        $lifecycle = (string) ($data['lifecycle'] ?? '');
        if (in_array($lifecycle, ['decided', 'enacted'], true) === false) {
            throw new AccessDeniedException(
                'Only decisions in status "decided" or "enacted" are publishable.'
            );
        }

    }//end assertDecisionEligible()

    /**
     * Assert a meeting agenda is publishable (isPublic + convocation sent).
     *
     * @param array<string,mixed> $data Meeting object data.
     *
     * @spec openspec/changes/publish-decisions-via-opencatalogi/specs/public-publication/spec.md
     *
     * @throws AccessDeniedException When not eligible.
     *
     * @return void
     */
    private function assertAgendaEligible(array $data): void
    {
        if (($data['isPublic'] ?? false) !== true) {
            throw new AccessDeniedException(
                'Only agendas of meetings flagged isPublic are publishable.'
            );
        }

        $convocationSent = ($data['convocationSentAt'] ?? $data['convocationSent'] ?? null);
        if (empty($convocationSent) === true) {
            throw new AccessDeniedException(
                'The meeting convocation must have been sent before the agenda is publishable.'
            );
        }

    }//end assertAgendaEligible()

    /**
     * Assert a set of minutes is publishable (lifecycle approved).
     *
     * @param array<string,mixed> $data Minutes object data.
     *
     * @spec openspec/changes/publish-decisions-via-opencatalogi/specs/public-publication/spec.md
     *
     * @throws AccessDeniedException When not eligible.
     *
     * @return void
     */
    private function assertMinutesEligible(array $data): void
    {
        $lifecycle = (string) ($data['lifecycle'] ?? '');
        if (in_array($lifecycle, ['approved', 'signed', 'published'], true) === false) {
            throw new AccessDeniedException(
                'Only minutes in lifecycle "approved" (or later) are publishable.'
            );
        }

    }//end assertMinutesEligible()

    /**
     * Map a publication source type to its OR schema slug.
     *
     * @param string $sourceType One of decision|agenda|minutes.
     *
     * @spec openspec/changes/publish-decisions-via-opencatalogi/specs/public-publication/spec.md
     *
     * @return string The schema slug.
     */
    private function schemaForType(string $sourceType): string
    {
        return match ($sourceType) {
            'decision' => 'decision',
            'agenda'   => 'meeting',
            'minutes'  => 'minutes',
            default    => $sourceType,
        };

    }//end schemaForType()

    /**
     * Load a source object from OpenRegister and return its data array.
     *
     * @param string $schema Schema slug.
     * @param string $id     Object UUID.
     *
     * @spec openspec/changes/publish-decisions-via-opencatalogi/specs/public-publication/spec.md
     *
     * @throws MissingObjectException When the object does not exist.
     *
     * @return array<string,mixed> The object data.
     */
    private function loadObject(string $schema, string $id): array
    {
        try {
            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
            $entity        = $objectService->find(id: $id, register: 'decidesk', schema: $schema);
        } catch (\Throwable $e) {
            $this->logger->error('Decidesk publication: failed to load source object', ['exception' => $e->getMessage()]);
            throw new MissingObjectException('Source object could not be loaded.');
        }

        if ($entity === null) {
            throw new MissingObjectException('Source object not found: '.$id);
        }

        return $entity->jsonSerialize();

    }//end loadObject()
}//end class
