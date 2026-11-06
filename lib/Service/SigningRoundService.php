<?php

/**
 * Decidiq Signing Round Service
 *
 * Sends minutes, a meeting's decision list and motions to the external
 * signing service with their signers in the chosen order, and stores the
 * signed copy back in the record's files once the service reports it signed.
 *
 * The signers live on the record itself (`signers`, each with a participant
 * and an `order`); the round's state lives there too (`signingRequestId`,
 * `signingStatus`, `signedCopy`, `signedCopyHash`, `signedAt`), declared by
 * register fragment 97. The transport is IEIDASSignatureService, which goes
 * through integriq.
 *
 * @category Service
 * @package  OCA\Decidiq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/p2-minutes-and-decisions-core-t3/spec.md#requirement-req-ses-001-send-for-signature-in-a-chosen-order-and-store-the-signed-copy
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Service;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Service\FileService;
use Psr\Log\LoggerInterface;

/**
 * Send a record for signature in order and store the signed copy.
 *
 * @spec openspec/specs/p2-minutes-and-decisions-core-t3/spec.md#requirement-req-ses-001-send-for-signature-in-a-chosen-order-and-store-the-signed-copy
 */
class SigningRoundService {

	/**
	 * What can be signed, and the schema that holds it. A decision list is
	 * the meeting's list of decisions, so it is signed from the meeting.
	 *
	 * @var array<string, string>
	 */
	public const SUBJECTS = [
		'minutes' => 'minutes',
		'decision-list' => 'meeting',
		'motion' => 'decision',
	];

	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface $objectService The OpenRegister object service
	 * @param IEIDASSignatureService $signatureService The signing adapter (through integriq)
	 * @param FileService $fileService The OpenRegister file service
	 * @param LoggerInterface $logger Logger
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		private readonly IEIDASSignatureService $signatureService,
		private readonly FileService $fileService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The schema slug that holds a subject type, or null when it is not signable.
	 *
	 * @param string $subjectType minutes, decision-list or motion
	 *
	 * @return string|null
	 *
	 * @spec openspec/specs/p2-minutes-and-decisions-core-t3/spec.md#requirement-req-ses-001-send-for-signature-in-a-chosen-order-and-store-the-signed-copy
	 */
	public static function schemaFor(string $subjectType): ?string {
		return (self::SUBJECTS[$subjectType] ?? null);
	}//end schemaFor()

	/**
	 * The participant ids of a signer list, in signing order.
	 *
	 * Entries sort on `order`; an entry without one keeps its place after the
	 * ordered ones. A plain string entry is a participant id.
	 *
	 * @param array<int, mixed> $signers The record's signers
	 *
	 * @return array<int, string>
	 *
	 * @spec openspec/specs/p2-minutes-and-decisions-core-t3/spec.md#requirement-req-ses-001-send-for-signature-in-a-chosen-order-and-store-the-signed-copy
	 */
	public function orderedSigners(array $signers): array {
		$rows = [];
		foreach (array_values($signers) as $index => $entry) {
			$participant = $this->participantOf(entry: $entry);
			if ($participant === '') {
				continue;
			}

			$order = PHP_INT_MAX;
			if (is_array($entry) === true && is_numeric($entry['order'] ?? null) === true) {
				$order = (int)$entry['order'];
			}

			$rows[] = ['participant' => $participant, 'order' => $order, 'index' => $index];
		}

		usort(
			$rows,
			static fn (array $left, array $right): int => [$left['order'], $left['index']] <=> [$right['order'], $right['index']]
		);

		return array_values(array_unique(array_column($rows, 'participant')));
	}//end orderedSigners()

	/**
	 * Send a record to the signing service with its signers in order.
	 *
	 * @param string $subjectType minutes, decision-list or motion
	 * @param string $subjectId The record's UUID
	 *
	 * @return array{success: bool, message: string, requestId?: ?string, signingUrl?: ?string}
	 *
	 * @spec openspec/specs/p2-minutes-and-decisions-core-t3/spec.md#requirement-req-ses-001-send-for-signature-in-a-chosen-order-and-store-the-signed-copy
	 */
	public function send(string $subjectType, string $subjectId): array {
		$schema = self::schemaFor(subjectType: $subjectType);
		if ($schema === null) {
			return ['success' => false, 'message' => 'This record cannot be sent for signature.'];
		}

		$entity = $this->objectService->find(id: $subjectId, register: 'decidiq', schema: $schema);
		if ($entity === null) {
			return ['success' => false, 'message' => 'The record was not found.'];
		}

		$data = $this->dataOf(entity: $entity);
		$signers = $this->orderedSigners(signers: (array)($data['signers'] ?? []));
		if ($signers === []) {
			return ['success' => false, 'message' => 'Add at least one signer first.'];
		}

		$result = $this->signatureService->initializeSigningRequest($subjectId, $signers, $subjectType);
		if ($result['success'] === false) {
			return ['success' => false, 'message' => $result['message']];
		}

		$this->objectService->saveObject(
			object: array_merge(
				$data,
				[
					'signingRequestId' => (string)($result['requestId'] ?? ''),
					'signingStatus' => 'sent',
				]
			),
			register: 'decidiq',
			schema: $schema,
			uuid: $subjectId
		);

		return [
			'success' => true,
			'message' => 'Sent for signature.',
			'requestId' => $result['requestId'],
			'signingUrl' => $result['signingUrl'],
		];
	}//end send()

	/**
	 * Ask the signing service about a record that is out for signature and,
	 * once signed, store the signed copy in the record's files and link it.
	 *
	 * @param string $subjectType minutes, decision-list or motion
	 * @param string $subjectId The record's UUID
	 *
	 * @return array{status: string, message: string, signedCopy?: string}
	 *
	 * @spec openspec/specs/p2-minutes-and-decisions-core-t3/spec.md#requirement-req-ses-001-send-for-signature-in-a-chosen-order-and-store-the-signed-copy
	 */
	public function collect(string $subjectType, string $subjectId): array {
		$schema = self::schemaFor(subjectType: $subjectType);
		if ($schema === null) {
			return ['status' => 'failed', 'message' => 'This record cannot be sent for signature.'];
		}

		$entity = $this->objectService->find(id: $subjectId, register: 'decidiq', schema: $schema);
		if ($entity === null) {
			return ['status' => 'failed', 'message' => 'The record was not found.'];
		}

		$data = $this->dataOf(entity: $entity);
		$current = (string)($data['signingStatus'] ?? '');
		if ($current === 'signed') {
			return ['status' => 'signed', 'message' => 'Already signed.', 'signedCopy' => (string)($data['signedCopy'] ?? '')];
		}

		$requestId = (string)($data['signingRequestId'] ?? '');
		if ($requestId === '' || $current !== 'sent') {
			return ['status' => 'failed', 'message' => 'This record is not out for signature.'];
		}

		$result = $this->signatureService->fetchSigningResult($requestId);
		if ($result['status'] === 'failed') {
			$this->objectService->saveObject(
				object: array_merge($data, ['signingStatus' => 'failed']),
				register: 'decidiq',
				schema: $schema,
				uuid: $subjectId
			);
			return ['status' => 'failed', 'message' => $result['message']];
		}

		if ($result['status'] !== 'signed' || $result['document'] === null) {
			return ['status' => 'pending', 'message' => $result['message']];
		}

		$fileName = $this->fileName(proposed: (string)($result['fileName'] ?? ''), subjectType: $subjectType, subjectId: $subjectId);
		$this->fileService->addFile(objectEntity: $entity, fileName: $fileName, content: $result['document']);

		$now = gmdate('Y-m-d\TH:i:s\Z');
		$this->objectService->saveObject(
			object: array_merge(
				$data,
				[
					'signers' => $this->markSigned(signers: (array)($data['signers'] ?? []), signedAt: $now),
					'signingStatus' => 'signed',
					'signedCopy' => $fileName,
					'signedCopyHash' => hash('sha256', $result['document']),
					'signedAt' => $now,
				]
			),
			register: 'decidiq',
			schema: $schema,
			uuid: $subjectId
		);

		return ['status' => 'signed', 'message' => 'The signed copy is stored.', 'signedCopy' => $fileName];
	}//end collect()

	/**
	 * Collect every record that is out for signature. Run by the background
	 * job, so a signed copy is stored without anyone opening the record.
	 *
	 * @return integer The number of signed copies stored.
	 *
	 * @spec openspec/specs/p2-minutes-and-decisions-core-t3/spec.md#requirement-req-ses-001-send-for-signature-in-a-chosen-order-and-store-the-signed-copy
	 */
	public function collectAllSent(): int {
		$stored = 0;
		foreach (self::SUBJECTS as $subjectType => $schema) {
			try {
				$found = $this->objectService->findAll(
					config: ['filters' => ['register' => 'decidiq', 'schema' => $schema, 'signingStatus' => 'sent']],
					_rbac: false,
					_multitenancy: false
				);
			} catch (\Throwable $e) {
				$this->logger->warning('Decidiq: could not list records out for signature', ['schema' => $schema, 'exception' => $e->getMessage()]);
				continue;
			}

			foreach (($found['results'] ?? $found) as $item) {
				$uuid = $this->uuidOf(item: $item);
				if ($uuid === '') {
					continue;
				}

				try {
					if ($this->collect(subjectType: $subjectType, subjectId: $uuid)['status'] === 'signed') {
						$stored++;
					}
				} catch (\Throwable $e) {
					$this->logger->warning('Decidiq: collecting a signed copy failed', ['uuid' => $uuid, 'exception' => $e->getMessage()]);
				}
			}
		}//end foreach

		return $stored;
	}//end collectAllSent()

	/**
	 * Stamp every signer that has no signing time yet.
	 *
	 * @param array<int, mixed> $signers The record's signers
	 * @param string $signedAt When the round was signed
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function markSigned(array $signers, string $signedAt): array {
		$marked = [];
		foreach (array_values($signers) as $entry) {
			$row = ['participant' => $this->participantOf(entry: $entry)];
			if (is_array($entry) === true) {
				$row = array_merge($entry, $row);
			}

			if ($row['participant'] === '') {
				continue;
			}

			if (empty($row['signedAt']) === true) {
				$row['signedAt'] = $signedAt;
			}

			$marked[] = $row;
		}

		return $marked;
	}//end markSigned()

	/**
	 * The participant id of one signer entry.
	 *
	 * @param mixed $entry A signer entry: a participant id or an object
	 *
	 * @return string
	 */
	private function participantOf(mixed $entry): string {
		if (is_string($entry) === true) {
			return $entry;
		}

		if (is_array($entry) === true) {
			return (string)($entry['participant'] ?? ($entry['id'] ?? ($entry['uuid'] ?? '')));
		}

		return '';
	}//end participantOf()

	/**
	 * A safe file name for the signed copy.
	 *
	 * @param string $proposed The name the signing service sent, if any
	 * @param string $subjectType minutes, decision-list or motion
	 * @param string $subjectId The record's UUID
	 *
	 * @return string
	 */
	private function fileName(string $proposed, string $subjectType, string $subjectId): string {
		$name = trim((string)preg_replace('/[^A-Za-z0-9._-]+/', '-', basename($proposed)), '-.');
		if ($name === '') {
			$name = $subjectType . '-' . $subjectId . '-signed.pdf';
		}

		return $name;
	}//end fileName()

	/**
	 * The data of an OpenRegister object as an array.
	 *
	 * @param object $entity The object
	 *
	 * @return array<string, mixed>
	 */
	private function dataOf(object $entity): array {
		if (method_exists($entity, 'getObject') === true) {
			return (array)$entity->getObject();
		}

		if (method_exists($entity, 'jsonSerialize') === true) {
			return (array)$entity->jsonSerialize();
		}

		return [];
	}//end dataOf()

	/**
	 * The UUID of a listed object.
	 *
	 * @param mixed $item An ObjectEntity or a serialised object
	 *
	 * @return string
	 */
	private function uuidOf(mixed $item): string {
		if (is_object($item) === true && method_exists($item, 'getUuid') === true) {
			return (string)$item->getUuid();
		}

		if (is_array($item) === true) {
			return (string)($item['id'] ?? ($item['@self']['id'] ?? ''));
		}

		return '';
	}//end uuidOf()
}//end class
