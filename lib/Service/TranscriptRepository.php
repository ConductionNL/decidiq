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
class TranscriptRepository {
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
	public function fetchMeeting(string $meetingId): array {
		return $this->fetchObject(
			id: $meetingId,
			schema: 'meeting',
			absentMessage: sprintf('Meeting "%s" not found.', $meetingId)
		);

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
	public function fetchTranscript(string $transcriptId): array {
		return $this->fetchObject(
			id: $transcriptId,
			schema: 'transcript',
			absentMessage: sprintf('Transcript "%s" not found.', $transcriptId)
		);

	}//end fetchTranscript()

	/**
	 * Fetch one decidesk object by id, or raise MissingObjectException.
	 *
	 * THE SEAM THIS REPAIRS. This repository is the place the transcription
	 * surface converts "absent object" into the app's own
	 * MissingObjectException, which every TranscriptionController action
	 * already catches and renders as 404. It was written as
	 * `if ($entity === null) throw …` — but OpenRegister's find() THROWS
	 * DoesNotExistException for an unknown id and never returns null, so the
	 * conversion never happened. DoesNotExistException escaped the repository,
	 * the service and the controller, and Nextcloud answered 500 with a stack
	 * trace. Every `catch (MissingObjectException) → 404` on the transcription
	 * endpoints was therefore unreachable for the case it exists to serve.
	 *
	 * The null branch is KEPT as well as the catch: find() is typed
	 * `?ObjectEntity`, so null stays reachable in principle, and both answers
	 * mean the same thing to the caller.
	 *
	 * Only DoesNotExistException is converted. Any other failure — a broken
	 * register, an OpenRegister outage — still propagates, because turning
	 * that into "not found" would tell the caller, and monitoring, that the
	 * data is absent when the data layer is simply down.
	 *
	 * @param string $id The object UUID.
	 * @param string $schema The decidesk schema slug.
	 * @param string $absentMessage Message for the MissingObjectException.
	 *
	 * @return array<string,mixed> The object.
	 *
	 * @throws MissingObjectException When the object does not exist.
	 *
	 * @spec openspec/specs/meeting-transcription/spec.md
	 */
	private function fetchObject(string $id, string $schema, string $absentMessage): array {
		$objectService = $this->getObjectService();

		try {
			$entity = $objectService->find(id: $id, register: 'decidesk', schema: $schema);
		} catch (\OCP\AppFramework\Db\DoesNotExistException) {
			throw new MissingObjectException(message: $absentMessage);
		}

		if ($entity === null) {
			throw new MissingObjectException(message: $absentMessage);
		}

		return (array)$entity->jsonSerialize();
	}//end fetchObject()

	/**
	 * Fetch the agenda items linked to a meeting.
	 *
	 * @param string $meetingId Meeting UUID.
	 *
	 * @return array<int,array<string,mixed>> Agenda item objects.
	 *
	 * @spec openspec/specs/meeting-transcription/spec.md
	 */
	public function fetchAgendaItems(string $meetingId): array {
		$objectService = $this->getObjectService();
		$entities = $objectService->findAll(
			[
				'register' => 'decidesk',
				'schema' => 'agenda-item',
				'filters' => [
					'register' => 'decidesk',
					'schema' => 'agenda-item',
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
				$items[] = (array)$entity->jsonSerialize();
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
	public function saveTranscript(array $transcript): array {
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
			return (array)$saved->jsonSerialize();
		}

		if (is_object($saved) === true && method_exists($saved, 'getObject') === true) {
			return (array)$saved->getObject();
		}

		return $transcript;
	}//end saveTranscript()

	/**
	 * Write the transcript/recording retention policy onto a governance body.
	 *
	 * Lives here rather than in TranscriptionController because a controller
	 * doing plain object CRUD is the thing ADR-022 exists to stop — and,
	 * concretely, because the controller's own copy of this lookup carried the
	 * dead-null-branch defect that fetchObject() above repairs. One lookup, one
	 * conversion, one place to get it right.
	 *
	 * Reads through fetchObject(), so an unknown body id raises
	 * MissingObjectException — which TranscriptionController already renders as
	 * 404 for every other action.
	 *
	 * @param string $bodyId The governance body UUID.
	 * @param string $policy One of keep|delete-recording|delete-both.
	 * @param int $days Retention window in days.
	 *
	 * @return void
	 *
	 * @throws MissingObjectException When the governance body does not exist.
	 *
	 * @spec openspec/specs/meeting-transcription/spec.md
	 */
	public function saveRetentionPolicy(string $bodyId, string $policy, int $days): void {
		$body = $this->fetchObject(
			id: $bodyId,
			schema: 'governance-body',
			absentMessage: sprintf('Governance body "%s" not found.', $bodyId)
		);

		$body['transcriptRetentionPolicy'] = $policy;
		$body['transcriptRetentionDays'] = $days;

		$this->getObjectService()->saveObject(
			object: $body,
			register: 'decidesk',
			schema: 'governance-body',
			uuid: $bodyId
		);

	}//end saveRetentionPolicy()

	/**
	 * Extract the transcript object UUID (id or @self.id), or null.
	 *
	 * @param array<string,mixed> $transcript The Transcript object.
	 *
	 * @return string|null The UUID.
	 *
	 * @spec openspec/specs/meeting-transcription/spec.md
	 */
	public function transcriptId(array $transcript): ?string {
		$id = ($transcript['id'] ?? ($transcript['@self']['id'] ?? null));
		if ($id === null || $id === '') {
			return null;
		}

		return (string)$id;
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
	public function resolveMeetingId(array $transcript): ?string {
		$relation = ($transcript['relations']['meeting'] ?? ($transcript['meeting'] ?? null));
		if (is_array($relation) === true) {
			$relation = ($relation['id'] ?? ($relation[0] ?? null));
		}

		if ($relation === null || $relation === '') {
			return null;
		}

		return (string)$relation;
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
	public function resolveSourceNode(string $path): \OCP\Files\File {
		if ($path === '') {
			throw new RuntimeException('Transcript has no source file path.');
		}

		$fileService = $this->container->get('OCA\OpenRegister\Service\FileService');
		$dir = dirname($path);
		$base = basename($path);
		$folderNode = $fileService->createFolder($dir);
		$node = $folderNode->get($base);

		if (($node instanceof \OCP\Files\File) === false) {
			throw new RuntimeException('Source path is not a file: ' . $path);
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
	private function getObjectService(): object {
		try {
			return $this->container->get('OCA\OpenRegister\Service\ObjectService');
		} catch (\Throwable $e) {
			throw new RuntimeException('OpenRegister ObjectService is not available.', 0, $e);
		}

	}//end getObjectService()
}//end class
