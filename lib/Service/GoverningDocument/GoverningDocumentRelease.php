<?php

/**
 * Decidiq Governing Document Release
 *
 * Lets an approved governing document take effect on the date it was given:
 * once its planned effective date has arrived it gets that date as its
 * effective date. A document that is not approved yet is left alone and
 * reported as overdue.
 *
 * @category Service
 * @package  OCA\Decidiq\Service\GoverningDocument
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/the-decision-as-a-walked-process/specs/decision-as-a-walked-process/spec.md#requirement-req-dwp-006-a-document-takes-effect-on-a-date-it-was-given
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2
declare(strict_types=1);

namespace OCA\Decidiq\Service\GoverningDocument;

use DateTimeImmutable;
use OCA\Decidiq\Service\RegisterObjectStore;
use OCP\AppFramework\Utility\ITimeFactory;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The daily release of governing documents whose planned date has arrived.
 *
 * @spec openspec/changes/the-decision-as-a-walked-process/specs/decision-as-a-walked-process/spec.md#requirement-req-dwp-006-a-document-takes-effect-on-a-date-it-was-given
 */
class GoverningDocumentRelease {

	/**
	 * The schema slug of a governing document.
	 *
	 * @var string
	 */
	public const SCHEMA = 'governing-document';

	/**
	 * The statuses of a document that was approved: adopted, or already in force or in effect.
	 *
	 * @var list<string>
	 */
	public const APPROVED = ['adopted', 'in-force', 'in-effect'];

	/**
	 * Constructor.
	 *
	 * @param RegisterObjectStore $store  Reads and patches the documents.
	 * @param ITimeFactory        $time   The clock.
	 * @param LoggerInterface     $logger The logger.
	 *
	 * @spec openspec/changes/the-decision-as-a-walked-process/specs/decision-as-a-walked-process/spec.md#requirement-req-dwp-006-a-document-takes-effect-on-a-date-it-was-given
	 */
	public function __construct(
		private readonly RegisterObjectStore $store,
		private readonly ITimeFactory $time,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * What a document is due for today: `release`, `overdue`, or null for nothing.
	 *
	 * Due once its planned date is today or earlier and it has no effective
	 * date yet; a lapsed document is never due. Approved, it is released;
	 * not approved, it is overdue.
	 *
	 * @param array<string, mixed> $document The document.
	 * @param string               $today    Today as Y-m-d.
	 *
	 * @return string|null
	 *
	 * @spec openspec/changes/the-decision-as-a-walked-process/specs/decision-as-a-walked-process/spec.md#requirement-req-dwp-006-a-document-takes-effect-on-a-date-it-was-given
	 */
	public function dueFor(array $document, string $today): ?string {
		if (self::isPending(document: $document, today: $today) === false) {
			return null;
		}

		if (in_array(($document['status'] ?? null), self::APPROVED, true) === true) {
			return 'release';
		}

		return 'overdue';
	}//end dueFor()

	/**
	 * Whether a document's planned date has come while it has no effective
	 * date yet and has not lapsed.
	 *
	 * @param array<string,mixed> $document The governing document.
	 * @param string              $today    Today as Y-m-d.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/the-decision-as-a-walked-process/specs/decision-as-a-walked-process/spec.md#requirement-req-dwp-006-a-document-takes-effect-on-a-date-it-was-given
	 */
	private static function isPending(array $document, string $today): bool {
		$planned = self::day(value: ($document['plannedEffectiveDate'] ?? null));

		return $planned !== null && $planned <= $today
			&& self::day(value: ($document['effectiveDate'] ?? null)) === null
			&& ($document['status'] ?? null) !== 'lapsed';
	}//end isPending()

	/**
	 * Release every approved document whose planned date has arrived.
	 *
	 * Each release sets `effectiveDate` to the planned date, through
	 * OpenRegister, so the document's own audit trail records it. A document
	 * that cannot be saved is logged and left for the next run.
	 *
	 * @param DateTimeImmutable|null $now The moment of the run; the clock when null.
	 *
	 * @return array{released: list<string>, overdue: list<string>, failed: list<string>} The document ids.
	 *
	 * @spec openspec/changes/the-decision-as-a-walked-process/specs/decision-as-a-walked-process/spec.md#requirement-req-dwp-006-a-document-takes-effect-on-a-date-it-was-given
	 */
	public function sweep(?DateTimeImmutable $now=null): array {
		$today  = ($now ?? DateTimeImmutable::createFromMutable($this->time->getDateTime()))->format('Y-m-d');
		$result = ['released' => [], 'overdue' => [], 'failed' => []];
		foreach ($this->store->findAll(schema: self::SCHEMA, filters: []) as $document) {
			$id  = (string)($document['id'] ?? ($document['@self']['id'] ?? ''));
			$due = $this->dueFor(document: $document, today: $today);
			if ($id === '' || $due === null) {
				continue;
			}

			$outcome = 'overdue';
			if ($due === 'release') {
				$outcome = $this->release(id: $id, planned: (string)self::day(value: $document['plannedEffectiveDate']));
			}

			$result[$outcome][] = $id;
		}

		return $result;
	}//end sweep()

	/**
	 * Give one document its planned date as its effective date.
	 *
	 * @param string $id      The document uuid.
	 * @param string $planned The planned effective date (Y-m-d).
	 *
	 * @return string `released`, or `failed` when the save was refused (logged).
	 *
	 * @spec openspec/changes/the-decision-as-a-walked-process/specs/decision-as-a-walked-process/spec.md#requirement-req-dwp-006-a-document-takes-effect-on-a-date-it-was-given
	 */
	private function release(string $id, string $planned): string {
		try {
			$this->store->patch(schema: self::SCHEMA, data: ['effectiveDate' => $planned], uuid: $id);
		} catch (Throwable $e) {
			$this->logger->error(
				'Decidiq: a governing document could not take effect on its planned date',
				['document' => $id, 'plannedEffectiveDate' => $planned, 'reason' => $e->getMessage()]
			);
			return 'failed';
		}

		$this->logger->info(
			'Decidiq: a governing document took effect on its planned date',
			['document' => $id, 'effectiveDate' => $planned]
		);
		return 'released';
	}//end release()

	/**
	 * A date value as Y-m-d, or null when there is none or it cannot be read.
	 *
	 * @param mixed $value The stored value.
	 *
	 * @return string|null
	 */
	private static function day(mixed $value): ?string {
		if (is_string($value) === false || trim($value) === '') {
			return null;
		}

		if (preg_match('/^\d{4}-\d{2}-\d{2}/', trim($value), $match) !== 1) {
			return null;
		}

		return $match[0];
	}//end day()
}//end class
