<?php

/**
 * Decidiq Filinq Signing Request
 *
 * Builds the body decidiq posts to filinq's `POST api/signing/requests`
 * route (filinq `SigningController::createRequest`), in the field names
 * filinq's `SigningService::createRequest()` reads: `documentFileId` (the
 * Nextcloud file id of the PDF that is signed), `documentName`, `signers`
 * and `signatureLevel`. Kept apart from EIDASSignatureService, which owns
 * the transport through integriq.
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
use RuntimeException;

/**
 * Compose a filinq signing request for a minutes record or a decision list.
 *
 * @spec openspec/specs/p2-minutes-and-decisions-core-t3/spec.md#requirement-req-ses-001-send-for-signature-in-a-chosen-order-and-store-the-signed-copy
 */
class FilinqSigningRequest {

	/**
	 * Filinq's route that creates a signing request, relative to the
	 * `docudesk-signing` source's location (filinq's app root).
	 *
	 * @var string
	 */
	public const ENDPOINT = '/api/signing/requests';

	/**
	 * Construct the builder.
	 *
	 * @param ObjectServiceInterface $objectService The OpenRegister object service
	 * @param MeetingFolderService   $folders       Finds the PDF in the meeting's Files folder
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		private readonly MeetingFolderService $folders,
	) {
	}//end __construct()

	/**
	 * The request body filinq's `SigningService::createRequest()` reads.
	 *
	 * @param string        $subjectType minutes, decision-list or motion
	 * @param string        $subjectId   The record's UUID
	 * @param array<string> $signatories Participant (or Person) UUIDs, in signing order
	 *
	 * @throws RuntimeException When the record has no PDF to sign.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/specs/p2-minutes-and-decisions-core-t3/spec.md#requirement-req-ses-001-send-for-signature-in-a-chosen-order-and-store-the-signed-copy
	 */
	public function payload(string $subjectType, string $subjectId, array $signatories): array {
		$file = $this->document(subjectType: $subjectType, subjectId: $subjectId);

		return [
			'documentFileId' => $file->getId(),
			'documentName' => $file->getName(),
			'signers' => $this->signers(signatories: $signatories),
			'signingOrder' => 'sequential',
			'signatureLevel' => 'QES',
		];
	}//end payload()

	/**
	 * The PDF that is signed: the latest generated PDF of the minutes, or the
	 * meeting's decision list.
	 *
	 * @param string $subjectType minutes, decision-list or motion
	 * @param string $subjectId   The record's UUID
	 *
	 * @throws RuntimeException When there is no such PDF.
	 *
	 * @return \OCP\Files\File
	 */
	private function document(string $subjectType, string $subjectId): \OCP\Files\File {
		$file = null;
		if ($subjectType === 'minutes') {
			$minutes = $this->record(schema: 'minutes', uuid: $subjectId);
			$path    = $this->latestPdfPath(minutes: ($minutes ?? []));
			if ($path !== null) {
				$file = $this->folders->fileAt(path: $path);
			}
		} elseif ($subjectType === 'decision-list') {
			$meeting = $this->record(schema: 'meeting', uuid: $subjectId);
			if ($meeting !== null) {
				$file = $this->folders->meetingFile(
					meeting: (['id' => $subjectId] + $meeting),
					subfolder: 'Minutes',
					fileName: DecisionListService::BASE_NAME . '.pdf'
				);
			}
		}

		if ($file === null) {
			throw new RuntimeException('No PDF of this ' . $subjectType . ' to sign was found. Generate the PDF first.');
		}

		return $file;
	}//end document()

	/**
	 * The path of the most recent PDF generated for the minutes.
	 *
	 * @param array<string, mixed> $minutes The minutes data
	 *
	 * @return string|null
	 */
	private function latestPdfPath(array $minutes): ?string {
		$path = null;
		foreach ((array)($minutes['generatedDocuments'] ?? []) as $document) {
			if (is_array($document) === true && ($document['format'] ?? '') === 'pdf' && (string)($document['path'] ?? '') !== '') {
				$path = (string)$document['path'];
			}
		}

		return $path;
	}//end latestPdfPath()

	/**
	 * The signers in filinq's shape, in signing order.
	 *
	 * @param array<string> $signatories Participant (or Person) UUIDs, in signing order
	 *
	 * @return array<int, array{name: string, email: string, order: int}>
	 */
	private function signers(array $signatories): array {
		$signers = [];
		foreach (array_values($signatories) as $index => $uuid) {
			$uuid = (string)$uuid;
			$who  = ($this->record(schema: 'participant', uuid: $uuid) ?? $this->record(schema: 'person', uuid: $uuid) ?? []);

			$signers[] = [
				'name' => (string)($who['displayName'] ?? ($who['name'] ?? $uuid)),
				'email' => (string)($who['email'] ?? ''),
				'order' => ($index + 1),
			];
		}

		return $signers;
	}//end signers()

	/**
	 * Read one decidiq object in system context, or null when it is not there.
	 *
	 * @param string $schema The schema slug
	 * @param string $uuid   The object's UUID
	 *
	 * @return array<string, mixed>|null
	 */
	private function record(string $schema, string $uuid): ?array {
		try {
			$entity = $this->objectService->find(id: $uuid, register: 'decidiq', schema: $schema, _rbac: false, _multitenancy: false);
		} catch (\Throwable) {
			return null;
		}

		if (is_object($entity) === false || method_exists($entity, 'getObject') === false) {
			return null;
		}

		return $entity->getObject();
	}//end record()
}//end class
