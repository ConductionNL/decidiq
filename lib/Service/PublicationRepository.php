<?php

/**
 * Decidesk Publication Repository
 *
 * Owns every OpenRegister object read/write of the publication flow:
 * persisting PublicationPayload and PublicationRecord objects, loading a
 * record, setting the withdraw-side `depublicationDate` predicate, and
 * stamping the source object's published state. Splitting this persistence
 * responsibility out of PublicationService keeps the orchestrator focused on
 * the flow itself.
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
 * @spec openspec/specs/public-publication/spec.md
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2
declare(strict_types=1);

namespace OCA\Decidesk\Service;

use OCA\Decidesk\Exception\MissingObjectException;
use Psr\Log\LoggerInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;

/**
 * OpenRegister persistence gateway for the publication flow.
 *
 * Every write goes through the ordinary OR object API on register-owned
 * schemas — there is no magic predicate and no app-local storage. The
 * best-effort writes (depublication date, source published stamp) degrade
 * gracefully with a logged warning, exactly as they did inline.
 *
 * @spec openspec/specs/public-publication/spec.md
 */
class PublicationRepository {
	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container DI container (lazy ObjectService).
	 * @param LoggerInterface $logger Logger.
	 *
	 * @spec openspec/specs/public-publication/spec.md
	 */
	public function __construct(
		private readonly LoggerInterface $logger,
		private readonly ObjectServiceInterface $objectService,
	) {
	}//end __construct()

	/**
	 * Persist a derived payload as an immutable PublicationPayload object.
	 *
	 * @param array<string,mixed> $payload The allow-list payload.
	 *
	 * @spec openspec/specs/public-publication/spec.md
	 *
	 * @return string The created payload object UUID.
	 */
	public function persistPayload(array $payload): string {
		$saved = $this->objectService()->saveObject(object: $payload, register: 'decidesk', schema: 'publication-payload');

		return $this->extractId(saved: $saved);
	}//end persistPayload()

	/**
	 * Persist (create or update) a PublicationRecord object.
	 *
	 * @param array<string,mixed> $record The record data.
	 * @param string|null $uuid Existing UUID for an update, null to create.
	 *
	 * @spec openspec/specs/public-publication/spec.md
	 *
	 * @return string The record object UUID.
	 */
	public function persistRecord(array $record, ?string $uuid = null): string {
		if ($uuid !== null) {
			$this->objectService()->saveObject(object: $record, register: 'decidesk', schema: 'publication-record', uuid: $uuid);
			return $uuid;
		}

		$saved = $this->objectService()->saveObject(object: $record, register: 'decidesk', schema: 'publication-record');

		return $this->extractId(saved: $saved);
	}//end persistRecord()

	/**
	 * Load a PublicationRecord by UUID.
	 *
	 * @param string $recordId The record UUID.
	 *
	 * @spec openspec/specs/public-publication/spec.md
	 *
	 * @throws MissingObjectException When the record does not exist.
	 *
	 * @return array<string,mixed> The record data.
	 */
	public function loadRecord(string $recordId): array {
		$entity = $this->objectService()->find(id: $recordId, register: 'decidesk', schema: 'publication-record');
		if ($entity === null) {
			throw new MissingObjectException(message: 'Publication record not found: ' . $recordId);
		}

		return $entity->jsonSerialize();
	}//end loadRecord()

	/**
	 * Set `depublicationDate` on a payload object so OR's public-group RBAC rule
	 * stops returning it — the withdraw side of the published-predicate.
	 *
	 * This is a normal field write on a register-owned object via the standard
	 * OR object API. There is no magic-mapper limitation: PublicationPayload is
	 * a register-owned schema on the ordinary RBAC save path.
	 *
	 * @param string $payloadId UUID of the PublicationPayload object.
	 * @param string $timestamp The depublication timestamp (ATOM).
	 *
	 * @spec openspec/specs/public-publication/spec.md
	 *
	 * @return void
	 */
	public function setDepublicationDate(string $payloadId, string $timestamp): void {
		try {
			$objectService = $this->objectService();
			$entity = $objectService->find(id: $payloadId, register: 'decidesk', schema: 'publication-payload');
			if ($entity === null) {
				return;
			}

			$data = $entity->jsonSerialize();
			$data['depublicationDate'] = $timestamp;

			$objectService->saveObject(
				object: $data,
				register: 'decidesk',
				schema: 'publication-payload',
				uuid: $payloadId,
			);
		} catch (\Throwable $e) {
			$this->logger->warning('Decidesk publication: failed to set depublicationDate on payload', ['exception' => $e->getMessage()]);
		}//end try

	}//end setDepublicationDate()

	/**
	 * Mark (or unmark) the source object's published state in the same write,
	 * routed through the eligibility guard so the value is flow-owned.
	 *
	 * A non-null `$publishedAt` publishes the source; null resets it to
	 * internal.
	 *
	 * @param string $sourceType One of decision|agenda|minutes.
	 * @param string $sourceId UUID of the source object.
	 * @param array<string,mixed>|null $source Resolved source data (re-fetched when null).
	 * @param string|null $publishedAt Publication timestamp, or null to unpublish.
	 *
	 * @spec openspec/specs/decision-management/spec.md
	 *
	 * @return void
	 */
	public function markSourcePublished(string $sourceType, string $sourceId, ?array $source, ?string $publishedAt): void {
		if ($sourceType !== 'decision') {
			// Only the Decision schema carries the isPublished/publishedAt fields.
			return;
		}

		try {
			$objectService = $this->objectService();
			if ($source === null) {
				$entity = $objectService->find(id: $sourceId, register: 'decidesk', schema: 'decision');
				if ($entity === null) {
					return;
				}

				$source = $entity->jsonSerialize();
			}

			$isPublished = 'internal';
			if ($publishedAt !== null) {
				$isPublished = 'public';
			}

			$source['isPublished'] = $isPublished;
			$source['publishedAt'] = $publishedAt;

			$objectService->saveObject(object: $source, register: 'decidesk', schema: 'decision', uuid: $sourceId);
		} catch (\Throwable $e) {
			$this->logger->warning('Decidesk publication: failed to stamp source published state', ['exception' => $e->getMessage()]);
		}//end try

	}//end markSourcePublished()

	/**
	 * Lazily resolve the OpenRegister ObjectService.
	 *
	 * @spec openspec/specs/public-publication/spec.md
	 *
	 * @return object The ObjectService instance.
	 */
	private function objectService(): object {
		return $this->objectService;
	}//end objectService()

	/**
	 * Extract a UUID from an ObjectService save result (object or array).
	 *
	 * @param mixed $saved The save result.
	 *
	 * @spec openspec/specs/public-publication/spec.md
	 *
	 * @return string The UUID, or '' when it cannot be determined.
	 */
	private function extractId(mixed $saved): string {
		if (is_object($saved) === true) {
			return $this->extractObjectId(saved: $saved);
		}

		if (is_array($saved) === true) {
			return $this->stringId(id: ($saved['id'] ?? $saved['uuid'] ?? ($saved['@self']['id'] ?? null)));
		}

		return '';
	}//end extractId()

	/**
	 * Extract a UUID from an ObjectEntity-like save result.
	 *
	 * @param object $saved The save result.
	 *
	 * @spec openspec/specs/public-publication/spec.md
	 *
	 * @return string The UUID, or '' when it cannot be determined.
	 */
	private function extractObjectId(object $saved): string {
		if (method_exists($saved, 'getUuid') === true) {
			$uuid = $this->stringId(id: $saved->getUuid());
			if ($uuid !== '') {
				return $uuid;
			}
		}

		if (method_exists($saved, 'jsonSerialize') === false) {
			return '';
		}

		$data = $saved->jsonSerialize();

		return $this->stringId(id: ($data['id'] ?? $data['uuid'] ?? ($data['@self']['id'] ?? null)));
	}//end extractObjectId()

	/**
	 * Normalise a candidate id to a non-empty string, or ''.
	 *
	 * @param mixed $id The candidate id.
	 *
	 * @spec openspec/specs/public-publication/spec.md
	 *
	 * @return string The id when it is a string, '' otherwise.
	 */
	private function stringId(mixed $id): string {
		if (is_string($id) === true) {
			return $id;
		}

		return '';
	}//end stringId()
}//end class
