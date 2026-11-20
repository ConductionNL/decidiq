<?php

/**
 * Releases the documents whose embargo moment has passed to the wider membership.
 *
 * @category BackgroundJob
 * @package  OCA\Decidiq\BackgroundJob
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/embargo-geheimhouding/specs/embargo-geheimhouding/spec.md#requirement-req-emb-005-member-facing-embargo-with-scheduled-timed-release
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Decidiq\BackgroundJob;

use DateTimeImmutable;
use OCA\Decidiq\Service\SystemObjectRows;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindTarget;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The member-side embargo release: a field flip, not a time-switched read rule.
 *
 * A document under embargo carries `embargoActive: true` and the moment it
 * ends in `embargoUntil`. Read rules key off the field, because a group's
 * read rule cannot compare a moment per object, so the wider membership gets
 * the document when this sweep writes `embargoActive: false`, at most one run
 * interval after `embargoUntil`. The write goes through OpenRegister, so the
 * document's audit trail records it and the declared release notification
 * fires on the change, never before it. Only documents still under embargo
 * are read. A document whose `embargoUntil` is missing or does not read as a
 * moment stays under embargo: releasing it would open it on a guess.
 *
 * @spec openspec/changes/embargo-geheimhouding/specs/embargo-geheimhouding/spec.md#requirement-req-emb-005-member-facing-embargo-with-scheduled-timed-release
 */
class DocumentEmbargoSweep {

	/**
	 * The register the documents live in.
	 *
	 * @var string
	 */
	private const REGISTER = 'decidiq';

	/**
	 * The schema slug of a document.
	 *
	 * @var string
	 */
	public const SCHEMA = 'digital-document';

	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface $objectService Reads and writes the documents.
	 * @param LoggerInterface        $logger        Logs a release that failed.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/embargo-geheimhouding/specs/embargo-geheimhouding/spec.md#requirement-req-emb-005-member-facing-embargo-with-scheduled-timed-release
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * One run: release every document whose embargo moment is at or before now.
	 *
	 * @param DateTimeImmutable $now The moment of the run.
	 *
	 * @return array{released: int, failed: int} How many were released, and how many writes were refused.
	 *
	 * @spec openspec/changes/embargo-geheimhouding/specs/embargo-geheimhouding/spec.md#requirement-req-emb-005-member-facing-embargo-with-scheduled-timed-release
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) SystemObjectRows::read is the shared stateless system read the other sweeps use.
	 */
	public function sweep(DateTimeImmutable $now): array {
		$result = ['released' => 0, 'failed' => 0];
		$rows   = SystemObjectRows::read(
			objectService: $this->objectService,
			logger: $this->logger,
			filters: ['schema' => self::SCHEMA, 'embargoActive' => 'true'],
			warning: 'Decidiq: documents under embargo could not be read for the release'
		);
		foreach ($rows as $document) {
			if ($this->isDue(document: $document, now: $now) === false) {
				continue;
			}

			$outcome = $this->release(document: $document);
			$result[$outcome]++;
		}

		return $result;
	}//end sweep()

	/**
	 * Whether a document is still under embargo and its moment has come.
	 *
	 * @param array<string, mixed> $document The document.
	 * @param DateTimeImmutable    $now      The moment of the run.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/embargo-geheimhouding/specs/embargo-geheimhouding/spec.md#requirement-req-emb-005-member-facing-embargo-with-scheduled-timed-release
	 */
	public function isDue(array $document, DateTimeImmutable $now): bool {
		if (($document['embargoActive'] ?? false) !== true) {
			return false;
		}

		$until = $this->moment(value: ($document['embargoUntil'] ?? null));

		return $until !== null && $until <= $now;
	}//end isDue()

	/**
	 * Write `embargoActive: false` on one document, carrying every other field.
	 *
	 * @param array<string, mixed> $document The document as read.
	 *
	 * @return string `released`, or `failed` when the write was refused (logged).
	 */
	private function release(array $document): string {
		$id = (string)($document['id'] ?? ($document['uuid'] ?? ($document['@self']['id'] ?? '')));
		if ($id === '') {
			return 'failed';
		}

		try {
			$this->objectService->saveObject(
				object: array_merge($document, ['embargoActive' => false]),
				options: new ObjectSaveOptions(
					target: new ObjectFindTarget(register: self::REGISTER, schema: self::SCHEMA),
					uuid: $id
				),
				_rbac: false,
				_multitenancy: false
			);
		} catch (Throwable $e) {
			$this->logger->error(
				'Decidiq: a document could not be released from its embargo',
				['document' => $id, 'embargoUntil' => ($document['embargoUntil'] ?? null), 'reason' => $e->getMessage()]
			);
			return 'failed';
		}

		return 'released';
	}//end release()

	/**
	 * A stored moment, or null when there is none or it does not read as one.
	 *
	 * @param mixed $value The stored value.
	 *
	 * @return DateTimeImmutable|null
	 */
	private function moment(mixed $value): ?DateTimeImmutable {
		// Only a stored date or moment: DateTimeImmutable also reads words such
		// as "next week", relative to the run, which would release on a guess.
		if (is_string($value) === false || preg_match('/^\d{4}-\d{2}-\d{2}/', trim($value)) !== 1) {
			return null;
		}

		try {
			return new DateTimeImmutable(trim($value));
		} catch (Throwable $e) {
			return null;
		}
	}//end moment()
}//end class
