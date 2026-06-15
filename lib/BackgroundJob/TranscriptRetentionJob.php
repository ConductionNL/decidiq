<?php
/**
 * Decidesk Transcript Retention Background Job
 *
 * Daily job that enforces the per-body retention policy on meeting recordings
 * and raw transcripts after the meeting's minutes are approved.
 *
 * @category BackgroundJob
 * @package  OCA\Decidesk\BackgroundJob
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/meeting-transcription-ai-minutes/specs/meeting-transcription/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidesk\BackgroundJob;

use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Daily retention sweep over `done` Transcripts whose meeting's minutes are
 * approved beyond the body's retention window.
 *
 * Policy resolution (per governance body): `keep` (no deletion),
 * `delete-recording` (remove the source recording only), `delete-both` (remove
 * the recording and the raw transcript file). Default `delete-both` / 30 days.
 * Each deletion is recorded in the meeting's audit trail and the Transcript's
 * retentionState is advanced (active → recording-deleted → purged). Pure
 * derivation over stored data; safe to re-run (idempotent on already-purged
 * transcripts).
 *
 * @spec openspec/changes/meeting-transcription-ai-minutes/specs/meeting-transcription/spec.md
 */
class TranscriptRetentionJob extends TimedJob
{

    /**
     * Run interval: 24 hours.
     */
    private const INTERVAL_SECONDS = 86400;

    /**
     * Default retention window in days when the body has no explicit policy.
     */
    private const DEFAULT_DAYS = 30;

    /**
     * Default retention policy when the body has no explicit policy.
     */
    private const DEFAULT_POLICY = 'delete-both';

    /**
     * Constructor.
     *
     * @param ITimeFactory       $time      NC time factory (injected by TimedJob).
     * @param ContainerInterface $container DI container (lazy OR services).
     * @param LoggerInterface    $logger    The logger.
     *
     * @spec openspec/changes/meeting-transcription-ai-minutes/specs/meeting-transcription/spec.md
     */
    public function __construct(
        ITimeFactory $time,
        private readonly ContainerInterface $container,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct(time: $time);
        $this->setInterval(seconds: self::INTERVAL_SECONDS);

    }//end __construct()


    /**
     * Execute the retention sweep.
     *
     * @param mixed $argument Unused; required by TimedJob.
     *
     * @return void
     *
     * @spec openspec/changes/meeting-transcription-ai-minutes/specs/meeting-transcription/spec.md
     */
    protected function run(mixed $argument): void
    {
        try {
            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
        } catch (\Throwable $e) {
            $this->logger->warning(
                'Decidesk TranscriptRetentionJob: OpenRegister unavailable, skipping.',
                ['exception' => $e->getMessage()]
            );
            return;
        }

        $now        = new \DateTimeImmutable();
        $transcripts = $this->fetchActiveDoneTranscripts(objectService: $objectService);

        foreach ($transcripts as $transcript) {
            try {
                $this->enforceForTranscript(objectService: $objectService, transcript: $transcript, now: $now);
            } catch (\Throwable $e) {
                $this->logger->error(
                    'Decidesk TranscriptRetentionJob: enforcement failed for a transcript',
                    ['exception' => $e->getMessage()]
                );
            }
        }

    }//end run()


    /**
     * Enforce the retention policy for one Transcript (pure, testable).
     *
     * Exposed (public) so it can be unit-tested with a mocked ObjectService and
     * a fixed `now`. Resolves the meeting + body policy, checks the
     * approval-age window, deletes files per policy, advances retentionState,
     * and appends a meeting audit-trail entry on any deletion.
     *
     * @param object              $objectService The OR ObjectService.
     * @param array<string,mixed> $transcript    The Transcript object.
     * @param \DateTimeImmutable  $now           Current time (injectable for tests).
     *
     * @return string The resulting retention state.
     *
     * @spec openspec/changes/meeting-transcription-ai-minutes/specs/meeting-transcription/spec.md
     */
    public function enforceForTranscript(object $objectService, array $transcript, \DateTimeImmutable $now): string
    {
        $currentState = (string) ($transcript['retentionState'] ?? 'active');
        if ($currentState === 'purged') {
            return 'purged';
        }

        $meetingId = $this->resolveMeetingId(transcript: $transcript);
        if ($meetingId === null) {
            return $currentState;
        }

        $meeting = $this->fetchObject(objectService: $objectService, id: $meetingId, schema: 'meeting');
        if ($meeting === null) {
            return $currentState;
        }

        $approvedAt = $this->resolveMinutesApprovedAt(objectService: $objectService, meetingId: $meetingId);
        if ($approvedAt === null) {
            // Minutes not approved yet — retention window has not started.
            return $currentState;
        }

        [$policy, $days] = $this->resolveBodyPolicy(objectService: $objectService, meeting: $meeting);
        if ($policy === 'keep') {
            return $currentState;
        }

        $threshold = $approvedAt->modify('+'.$days.' days');
        if ($now < $threshold) {
            return $currentState;
        }

        $deleted = [];

        // Delete the source recording (both policies).
        $recordingPath = (string) ($transcript['sourceFilePath'] ?? '');
        if ($recordingPath !== '' && $currentState === 'active') {
            if ($this->deleteFile(path: $recordingPath) === true) {
                $deleted[]                     = $recordingPath;
                $transcript['sourceFilePath']  = '';
                $currentState                  = 'recording-deleted';
            }
        }

        // delete-both also removes the raw transcript text file.
        if ($policy === 'delete-both') {
            $transcriptPath = (string) ($transcript['transcriptFilePath'] ?? '');
            if ($transcriptPath !== '') {
                if ($this->deleteFile(path: $transcriptPath) === true) {
                    $deleted[]                        = $transcriptPath;
                    $transcript['transcriptFilePath'] = '';
                }
            }

            $currentState = 'purged';
        }

        if ($deleted === []) {
            return $currentState;
        }

        $transcript['retentionState'] = $currentState;
        $objectService->saveObject(
            object: $transcript,
            register: 'decidesk',
            schema: 'transcript',
            uuid: $this->objectId(object: $transcript)
        );

        $this->appendAudit(meetingId: $meetingId, deleted: $deleted, policy: $policy);

        return $currentState;

    }//end enforceForTranscript()


    /**
     * Resolve the per-body retention policy and window (days).
     *
     * @param object              $objectService The OR ObjectService.
     * @param array<string,mixed> $meeting       The meeting object.
     *
     * @return array{0:string,1:int} [policy, days].
     *
     * @spec openspec/changes/meeting-transcription-ai-minutes/specs/meeting-transcription/spec.md
     */
    private function resolveBodyPolicy(object $objectService, array $meeting): array
    {
        $bodyId = ($meeting['governanceBody'] ?? ($meeting['relations']['GovernanceBody'][0] ?? ($meeting['relations']['governanceBody'] ?? null)));
        if (is_array($bodyId) === true) {
            $bodyId = ($bodyId['id'] ?? ($bodyId[0] ?? null));
        }

        if ($bodyId === null || $bodyId === '') {
            return [self::DEFAULT_POLICY, self::DEFAULT_DAYS];
        }

        $body = $this->fetchObject(objectService: $objectService, id: (string) $bodyId, schema: 'governance-body');
        if ($body === null) {
            return [self::DEFAULT_POLICY, self::DEFAULT_DAYS];
        }

        $policy = (string) ($body['transcriptRetentionPolicy'] ?? self::DEFAULT_POLICY);
        if (in_array($policy, ['keep', 'delete-recording', 'delete-both'], true) === false) {
            $policy = self::DEFAULT_POLICY;
        }

        $days = (int) ($body['transcriptRetentionDays'] ?? self::DEFAULT_DAYS);
        if ($days < 0) {
            $days = self::DEFAULT_DAYS;
        }

        return [$policy, $days];

    }//end resolveBodyPolicy()


    /**
     * Resolve the approval timestamp of the meeting's approved minutes.
     *
     * @param object $objectService The OR ObjectService.
     * @param string $meetingId     Meeting UUID.
     *
     * @return \DateTimeImmutable|null The approval time, or null when not approved.
     *
     * @spec openspec/changes/meeting-transcription-ai-minutes/specs/meeting-transcription/spec.md
     */
    private function resolveMinutesApprovedAt(object $objectService, string $meetingId): ?\DateTimeImmutable
    {
        $entities = $objectService->findAll(
            [
                'register' => 'decidesk',
                'schema'   => 'minutes',
                'filters'  => [
                    'register'           => 'decidesk',
                    'schema'             => 'minutes',
                    '_relations.meeting' => $meetingId,
                ],
            ]
        );

        foreach ($entities as $entity) {
            $minutes = $this->toArray(entity: $entity);
            $lifecycle = (string) ($minutes['lifecycle'] ?? '');
            if (in_array($lifecycle, ['approved', 'signed', 'published'], true) === false) {
                continue;
            }

            $approvedAt = (string) ($minutes['approvedAt'] ?? '');
            if ($approvedAt === '') {
                continue;
            }

            try {
                return new \DateTimeImmutable($approvedAt);
            } catch (\Throwable) {
                continue;
            }
        }

        return null;

    }//end resolveMinutesApprovedAt()


    /**
     * Fetch all active/recording-deleted `done` transcripts.
     *
     * @param object $objectService The OR ObjectService.
     *
     * @return array<int,array<string,mixed>> Transcript objects.
     *
     * @spec openspec/changes/meeting-transcription-ai-minutes/specs/meeting-transcription/spec.md
     */
    private function fetchActiveDoneTranscripts(object $objectService): array
    {
        $entities = $objectService->findAll(
            [
                'register' => 'decidesk',
                'schema'   => 'transcript',
                'filters'  => [
                    'register' => 'decidesk',
                    'schema'   => 'transcript',
                    'status'   => 'done',
                ],
            ]
        );

        $result = [];
        foreach ($entities as $entity) {
            $result[] = $this->toArray(entity: $entity);
        }

        return $result;

    }//end fetchActiveDoneTranscripts()


    /**
     * Delete a file by Files path via the OR FileService (fail-soft).
     *
     * @param string $path The file path.
     *
     * @return bool True when the file was deleted.
     *
     * @spec openspec/changes/meeting-transcription-ai-minutes/specs/meeting-transcription/spec.md
     */
    private function deleteFile(string $path): bool
    {
        try {
            $fileService = $this->container->get('OCA\OpenRegister\Service\FileService');
            $folderNode  = $fileService->createFolder(dirname($path));
            $node        = $folderNode->get(basename($path));
            $node->delete();
            return true;
        } catch (\Throwable $e) {
            $this->logger->warning(
                'Decidesk TranscriptRetentionJob: file delete failed',
                ['path' => $path, 'error' => $e->getMessage()]
            );
            return false;
        }

    }//end deleteFile()


    /**
     * Append a retention deletion entry to the meeting's audit trail (fail-soft).
     *
     * @param string   $meetingId The meeting UUID.
     * @param string[] $deleted   Paths deleted.
     * @param string   $policy    The applied policy.
     *
     * @return void
     *
     * @spec openspec/changes/meeting-transcription-ai-minutes/specs/meeting-transcription/spec.md
     */
    private function appendAudit(string $meetingId, array $deleted, string $policy): void
    {
        try {
            $auditLog = $this->container->get(\OCA\Decidesk\Service\AuditLogService::class);
            $auditLog->append(
                'system:retention',
                'transcript.retention.purge',
                [$meetingId],
                ['policy' => $policy, 'deletedFiles' => $deleted]
            );
        } catch (\Throwable $e) {
            $this->logger->warning(
                'Decidesk TranscriptRetentionJob: audit append failed',
                ['meetingId' => $meetingId, 'error' => $e->getMessage()]
            );
        }

    }//end appendAudit()


    /**
     * Fetch a single object as an array (or null).
     *
     * @param object $objectService The OR ObjectService.
     * @param string $id            Object UUID.
     * @param string $schema        Schema slug.
     *
     * @return array<string,mixed>|null The object data.
     *
     * @spec openspec/changes/meeting-transcription-ai-minutes/specs/meeting-transcription/spec.md
     */
    private function fetchObject(object $objectService, string $id, string $schema): ?array
    {
        try {
            $entity = $objectService->find(id: $id, register: 'decidesk', schema: $schema);
        } catch (\Throwable) {
            return null;
        }

        if ($entity === null) {
            return null;
        }

        return $this->toArray(entity: $entity);

    }//end fetchObject()


    /**
     * Normalise an OR entity (object or array) to an array.
     *
     * @param mixed $entity The entity.
     *
     * @return array<string,mixed> The object data.
     *
     * @spec openspec/changes/meeting-transcription-ai-minutes/specs/meeting-transcription/spec.md
     */
    private function toArray(mixed $entity): array
    {
        if (is_array($entity) === true) {
            return $entity;
        }

        if (is_object($entity) === true && method_exists($entity, 'jsonSerialize') === true) {
            return (array) $entity->jsonSerialize();
        }

        if (is_object($entity) === true && method_exists($entity, 'getObject') === true) {
            return (array) $entity->getObject();
        }

        return [];

    }//end toArray()


    /**
     * Resolve the linked meeting UUID from a Transcript object.
     *
     * @param array<string,mixed> $transcript The Transcript object.
     *
     * @return string|null The meeting UUID.
     *
     * @spec openspec/changes/meeting-transcription-ai-minutes/specs/meeting-transcription/spec.md
     */
    private function resolveMeetingId(array $transcript): ?string
    {
        $relation = ($transcript['relations']['meeting'] ?? ($transcript['meeting'] ?? null));
        if (is_array($relation) === true) {
            $relation = ($relation['id'] ?? ($relation[0] ?? null));
        }

        if ($relation === null || $relation === '') {
            return null;
        }

        return (string) $relation;

    }//end resolveMeetingId()


    /**
     * Extract an object UUID (id or @self.id).
     *
     * @param array<string,mixed> $object The object.
     *
     * @return string|null The UUID.
     *
     * @spec openspec/changes/meeting-transcription-ai-minutes/specs/meeting-transcription/spec.md
     */
    private function objectId(array $object): ?string
    {
        $id = ($object['id'] ?? ($object['@self']['id'] ?? null));
        if ($id === null || $id === '') {
            return null;
        }

        return (string) $id;

    }//end objectId()
}//end class
