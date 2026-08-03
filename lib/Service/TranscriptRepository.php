<?php

/**
 * Decidesk Transcript Repository
 *
 * All OpenRegister / Files access the transcription pipeline needs: fetching
 * the meeting, the transcript and its agenda items, persisting a transcript,
 * and resolving a source file node. Keeping this here leaves
 * TranscriptionService with the pipeline itself.
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

use OCA\Decidesk\Exception\MissingObjectException;
use Psr\Container\ContainerInterface;
use RuntimeException;

/**
 * Object + file access for the meeting-transcription pipeline.
 *
 * Every read goes through OpenRegister's ObjectService, so per-object RBAC
 * applies unchanged; a missing (or unreadable) object surfaces as a
 * MissingObjectException, never as a silent empty result.
 *
 * @spec openspec/specs/meeting-transcription/spec.md
 */
class TranscriptRepository
{
    /**
     * Constructor.
     *
     * @param ContainerInterface $container DI container (lazy-loads OpenRegister services).
     *
     * @spec openspec/specs/meeting-transcription/spec.md
     */
    public function __construct(
        private readonly ContainerInterface $container,
    ) {
    }//end __construct()

    /**
     * Fetch a meeting object by id (throws when absent).
     *
     * @param string $meetingId Meeting UUID.
     *
     * @return array<string,mixed> The meeting object.
     *
     * @throws MissingObjectException When not found.
     *
     * @spec openspec/specs/meeting-transcription/spec.md
     */
    public function fetchMeeting(string $meetingId): array
    {
        $objectService = $this->getObjectService();
        $entity        = $objectService->find(id: $meetingId, register: 'decidesk', schema: 'meeting');
        if ($entity === null) {
            throw new MissingObjectException(message: sprintf('Meeting "%s" not found.', $meetingId));
        }

        return (array) $entity->jsonSerialize();

    }//end fetchMeeting()

    /**
     * Fetch a transcript object by id (throws when absent).
     *
     * @param string $transcriptId Transcript UUID.
     *
     * @return array<string,mixed> The transcript object.
     *
     * @throws MissingObjectException When not found.
     *
     * @spec openspec/specs/meeting-transcription/spec.md
     */
    public function fetchTranscript(string $transcriptId): array
    {
        $objectService = $this->getObjectService();
        $entity        = $objectService->find(id: $transcriptId, register: 'decidesk', schema: 'transcript');
        if ($entity === null) {
            throw new MissingObjectException(message: sprintf('Transcript "%s" not found.', $transcriptId));
        }

        return (array) $entity->jsonSerialize();

    }//end fetchTranscript()

    /**
     * Fetch the agenda items linked to a meeting.
     *
     * @param string $meetingId Meeting UUID.
     *
     * @return array<int,array<string,mixed>> Agenda item objects.
     *
     * @spec openspec/specs/meeting-transcription/spec.md
     */
    public function fetchAgendaItems(string $meetingId): array
    {
        $objectService = $this->getObjectService();
        $entities      = $objectService->findAll(
            [
                'register' => 'decidesk',
                'schema'   => 'agenda-item',
                'filters'  => [
                    'register'           => 'decidesk',
                    'schema'             => 'agenda-item',
                    '_relations.meeting' => $meetingId,
                ],
            ]
        );

        $items = [];
        foreach ($entities as $entity) {
            if (is_array($entity) === true) {
                $items[] = $entity;
                continue;
            }

            if (method_exists($entity, 'getObject') === true) {
                $items[] = $entity->getObject();
                continue;
            }

            if (method_exists($entity, 'jsonSerialize') === true) {
                $items[] = (array) $entity->jsonSerialize();
            }
        }//end foreach

        return $items;

    }//end fetchAgendaItems()

    /**
     * Persist a Transcript object via the OpenRegister object API.
     *
     * @param array<string,mixed> $transcript The Transcript object.
     *
     * @return array<string,mixed> The persisted object.
     *
     * @spec openspec/specs/meeting-transcription/spec.md
     */
    public function saveTranscript(array $transcript): array
    {
        $objectService = $this->getObjectService();

        $saved = $objectService->saveObject(
            object: $transcript,
            register: 'decidesk',
            schema: 'transcript',
            uuid: $this->transcriptId(transcript: $transcript)
        );

        if (is_array($saved) === true) {
            return $saved;
        }

        if (is_object($saved) === true && method_exists($saved, 'jsonSerialize') === true) {
            return (array) $saved->jsonSerialize();
        }

        if (is_object($saved) === true && method_exists($saved, 'getObject') === true) {
            return (array) $saved->getObject();
        }

        return $transcript;

    }//end saveTranscript()

    /**
     * Extract the transcript object UUID (id or @self.id), or null.
     *
     * @param array<string,mixed> $transcript The Transcript object.
     *
     * @return string|null The UUID.
     *
     * @spec openspec/specs/meeting-transcription/spec.md
     */
    public function transcriptId(array $transcript): ?string
    {
        $id = ($transcript['id'] ?? ($transcript['@self']['id'] ?? null));
        if ($id === null || $id === '') {
            return null;
        }

        return (string) $id;

    }//end transcriptId()

    /**
     * Resolve the linked meeting UUID from a Transcript object.
     *
     * @param array<string,mixed> $transcript The Transcript object.
     *
     * @return string|null The meeting UUID, or null when not linked.
     *
     * @spec openspec/specs/meeting-transcription/spec.md
     */
    public function resolveMeetingId(array $transcript): ?string
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
     * Resolve the source File node from a Files path.
     *
     * @param string $path Path of the source file in the meeting folder.
     *
     * @return \OCP\Files\File The resolved file node.
     *
     * @throws RuntimeException When the file cannot be resolved.
     *
     * @spec openspec/specs/meeting-transcription/spec.md
     */
    public function resolveSourceNode(string $path): \OCP\Files\File
    {
        if ($path === '') {
            throw new RuntimeException('Transcript has no source file path.');
        }

        $fileService = $this->container->get('OCA\OpenRegister\Service\FileService');
        $dir         = dirname($path);
        $base        = basename($path);
        $folderNode  = $fileService->createFolder($dir);
        $node        = $folderNode->get($base);

        if (($node instanceof \OCP\Files\File) === false) {
            throw new RuntimeException('Source path is not a file: '.$path);
        }

        return $node;

    }//end resolveSourceNode()

    /**
     * Lazy-load the OpenRegister ObjectService.
     *
     * @return object The ObjectService instance.
     *
     * @throws RuntimeException When OpenRegister is not installed.
     *
     * @spec openspec/specs/meeting-transcription/spec.md
     */
    private function getObjectService(): object
    {
        try {
            return $this->container->get('OCA\OpenRegister\Service\ObjectService');
        } catch (\Throwable $e) {
            throw new RuntimeException('OpenRegister ObjectService is not available.', 0, $e);
        }

    }//end getObjectService()
}//end class
