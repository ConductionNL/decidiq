<?php

/**
 * Which version of a governing document is in force on a date, and the save rules of a version.
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
 * @spec openspec/changes/governing-documents-register
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Service;

use DateTimeImmutable;
use OCA\Decidiq\Exception\GoverningDocumentVersionRefusedException;
use OCA\Decidiq\Kernel\Contracts\GoverningDocumentConsolidationServiceInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCP\AppFramework\Db\DoesNotExistException;
use Throwable;

/**
 * In-force resolution over a document's version chain (REQ-GDR-004), the
 * activation-ordering guard, and the conditional trace rule (REQ-GDR-002).
 *
 * A version is SEALED once it reached `in-effect`: it is then `in-effect`,
 * `replaced` or `lapsed`. In-force resolution takes the sealed version with
 * the latest effective date on or before the date; that version is in force
 * unless it is `lapsed` without an expiry date, or its expiry date is on or
 * before the date. A document that is itself `lapsed` has no version in force.
 *
 * @spec openspec/changes/governing-documents-register
 */
class GoverningDocumentConsolidationService implements GoverningDocumentConsolidationServiceInterface {

	/**
	 * The document schema.
	 *
	 * @var string
	 */
	public const DOCUMENT_SCHEMA = 'governing-document';

	/**
	 * The version schema.
	 *
	 * @var string
	 */
	public const VERSION_SCHEMA = 'governing-document-versie';

	/**
	 * The states of a sealed version.
	 *
	 * @var string[]
	 */
	public const SEALED_STATES = ['in-effect', 'replaced', 'lapsed'];

	/**
	 * The fields of a version that a seal freezes (REQ-GDR-003).
	 *
	 * @var string[]
	 */
	public const SEALED_FIELDS = ['document', 'versionNumber', 'determinedBy', 'effectiveDate', 'deedDate', 'notary'];

	/**
	 * The sealed fields that hold a date.
	 *
	 * @var string[]
	 */
	private const DATE_FIELDS = ['effectiveDate', 'deedDate'];

	/**
	 * The sealed fields that hold a reference.
	 *
	 * @var string[]
	 */
	private const REFERENCE_FIELDS = ['document', 'determinedBy'];

	/**
	 * The decision schema, whose citations point at a document.
	 *
	 * @var string
	 */
	public const DECISION_SCHEMA = 'decision';

	/**
	 * The most citing decisions one read lists.
	 *
	 * @var int
	 */
	private const CITED_BY_LIMIT = 200;

	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface $objectService Reads documents and versions.
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
	) {
	}//end __construct()

	/**
	 * The governing document, read with the caller's own rights.
	 *
	 * @param string $documentId The document uuid.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/changes/governing-documents-register
	 */
	public function readableDocument(string $documentId): ?array {
		try {
			$entity = $this->objectService->find(id: $documentId, register: 'decidiq', schema: self::DOCUMENT_SCHEMA, _rbac: true);
		} catch (DoesNotExistException) {
			return null;
		}

		if ($entity === null) {
			return null;
		}

		return (array)$entity->jsonSerialize();
	}//end readableDocument()

	/**
	 * The version of a document in force on a date, read with the caller's own rights.
	 *
	 * @param string            $documentId The document uuid.
	 * @param DateTimeImmutable $date       The date.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/changes/governing-documents-register
	 */
	public function inForceOn(string $documentId, DateTimeImmutable $date): ?array {
		$document = $this->readableDocument(documentId: $documentId);
		if ($document === null || ($document['status'] ?? null) === 'lapsed') {
			return null;
		}

		return self::resolveInForce(versions: $this->versionsOf(documentId: $documentId, rbac: true), date: $date->format('Y-m-d'));
	}//end inForceOn()

	/**
	 * Refuse a version the register may not hold.
	 *
	 * Reads the document's other versions without the caller's rights: the
	 * rules are about the whole chain, whoever may see it.
	 *
	 * @param array<string, mixed> $version The version about to be saved.
	 *
	 * @return void
	 *
	 * @throws GoverningDocumentVersionRefusedException When it is refused.
	 *
	 * @spec openspec/changes/governing-documents-register
	 */
	public function assertAcceptable(array $version): void {
		$documentId = self::reference(value: ($version['document'] ?? null));
		if ($documentId === '') {
			return;
		}

		try {
			$siblings = $this->versionsOf(documentId: $documentId, rbac: false);
		} catch (Throwable $e) {
			throw new GoverningDocumentVersionRefusedException(
				message: 'The version was not saved: the other versions of its document could not be read to check it. Try again in a moment.',
				code: 0,
				previous: $e
			);
		}

		$ownId = self::idOf(row: $version);
		$others = array_values(
			array_filter(
				$siblings,
				static fn (array $sibling): bool => $ownId === '' || self::idOf(row: $sibling) !== $ownId
			)
		);

		self::assertTraced(others: $others, version: $version);
		self::assertUniqueNumber(others: $others, version: $version);
		self::assertActivationOrder(others: $others, version: $version);
	}//end assertAcceptable()

	/**
	 * Refuse an edit of a sealed version's frozen fields (REQ-GDR-003).
	 *
	 * @param array<string, mixed> $before The version as stored.
	 * @param array<string, mixed> $after  The version about to be saved.
	 *
	 * @return void
	 *
	 * @throws GoverningDocumentVersionRefusedException When it is refused.
	 *
	 * @spec openspec/changes/governing-documents-register
	 */
	public function assertEditable(array $before, array $after): void {
		self::assertSealedUnchanged(before: $before, after: $after);
	}//end assertEditable()

	/**
	 * Refuse a change to a frozen field of a version that is already sealed.
	 *
	 * The state may still move on (in effect to replaced or lapsed) and an
	 * expiry date or a note may still be set; a correction is a new version.
	 *
	 * @param array<string, mixed> $before The version as stored.
	 * @param array<string, mixed> $after  The version about to be saved.
	 *
	 * @return void
	 *
	 * @throws GoverningDocumentVersionRefusedException When it is refused.
	 *
	 * @spec openspec/changes/governing-documents-register
	 */
	public static function assertSealedUnchanged(array $before, array $after): void {
		if (self::isSealed(version: $before) === false) {
			return;
		}

		foreach (self::SEALED_FIELDS as $field) {
			if (self::comparable(field: $field, value: ($before[$field] ?? null)) !== self::comparable(field: $field, value: ($after[$field] ?? null))) {
				throw new GoverningDocumentVersionRefusedException(
					message: 'The version was not saved: version ' . (string)($before['versionNumber'] ?? '?')
						. ' is in effect and sealed, so its ' . $field . ' cannot change. Record the correction as a new version.'
				);
			}
		}
	}//end assertSealedUnchanged()

	/**
	 * The version in force on a date, among a document's versions.
	 *
	 * @param array<int, array<string, mixed>> $versions The document's versions.
	 * @param string                           $date     The date, Y-m-d.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/changes/governing-documents-register
	 */
	public static function resolveInForce(array $versions, string $date): ?array {
		$latest = null;
		foreach ($versions as $version) {
			$effective = self::date(value: ($version['effectiveDate'] ?? null));
			if (self::isSealed(version: $version) === false || $effective === '' || $effective > $date) {
				continue;
			}

			if ($latest === null || $effective > self::date(value: $latest['effectiveDate'])) {
				$latest = $version;
			}
		}

		if ($latest === null) {
			return null;
		}

		$expiry = self::date(value: ($latest['expiryDate'] ?? null));
		if ($expiry !== '' && $expiry <= $date) {
			return null;
		}

		if ($expiry === '' && ($latest['status'] ?? null) === 'lapsed') {
			return null;
		}

		return $latest;
	}//end resolveInForce()

	/**
	 * Refuse an amendment version without the decision that enacted it.
	 *
	 * Only a document's first, constitutive version may omit it.
	 *
	 * @param array<int, array<string, mixed>> $others  The document's other versions.
	 * @param array<string, mixed>             $version The version about to be saved.
	 *
	 * @return void
	 *
	 * @throws GoverningDocumentVersionRefusedException When it is refused.
	 *
	 * @spec openspec/changes/governing-documents-register
	 */
	public static function assertTraced(array $others, array $version): void {
		if (self::reference(value: ($version['determinedBy'] ?? null)) !== '') {
			return;
		}

		foreach ($others as $other) {
			if (self::isSealed(version: $other) === true) {
				throw new GoverningDocumentVersionRefusedException(
					message: 'The version was not saved: this document already has a version in effect, '
						. 'so an amendment must name the decision that enacted it (determinedBy).'
				);
			}
		}
	}//end assertTraced()

	/**
	 * Refuse a version number the document already has.
	 *
	 * @param array<int, array<string, mixed>> $others  The document's other versions.
	 * @param array<string, mixed>             $version The version about to be saved.
	 *
	 * @return void
	 *
	 * @throws GoverningDocumentVersionRefusedException When it is refused.
	 *
	 * @spec openspec/changes/governing-documents-register
	 */
	public static function assertUniqueNumber(array $others, array $version): void {
		$number = ($version['versionNumber'] ?? null);
		if (is_numeric($number) === false) {
			return;
		}

		foreach ($others as $other) {
			if (is_numeric($other['versionNumber'] ?? null) === true && (int)$other['versionNumber'] === (int)$number) {
				throw new GoverningDocumentVersionRefusedException(
					message: 'The version was not saved: this document already has a version ' . (int)$number . '. Version numbers are unique per document.'
				);
			}
		}
	}//end assertUniqueNumber()

	/**
	 * The decisions citing a document, read with the caller's own rights
	 * (REQ-GDR-005): each with the articles it cites and the pinned version.
	 *
	 * @param string $documentId The document uuid.
	 *
	 * @return list<array<string, mixed>>
	 *
	 * @spec openspec/changes/governing-documents-register
	 */
	public function citingDecisions(string $documentId): array {
		$this->objectService->setRegister('decidiq');
		$this->objectService->setSchema(self::DOCUMENT_SCHEMA);
		$page = $this->objectService->getObjectUsedBy(
			objectId: $documentId,
			query: ['_limit' => self::CITED_BY_LIMIT],
			_rbac: true,
			_multitenancy: true
		);

		$decisions = [];
		foreach (self::rowsOf(page: $page) as $row) {
			$citations = self::citationsOf(decision: $row, documentId: $documentId);
			if ($citations === []) {
				continue;
			}

			$decisions[] = [
				'id' => self::idOf(row: $row),
				'title' => (string)($row['title'] ?? ($row['name'] ?? '')),
				'decisionDate' => ($row['decisionDate'] ?? null),
				'articles' => array_values(array_filter(array_column($citations, 'article'), static fn (mixed $a): bool => is_string($a) && trim($a) !== '')),
				'versions' => array_values(array_filter(array_map(static fn (array $c): string => self::reference(value: ($c['version'] ?? null)), $citations))),
			];
		}

		return $decisions;
	}//end citingDecisions()

	/**
	 * The citations of one document a decision carries.
	 *
	 * @param array<string, mixed> $decision   The decision.
	 * @param string               $documentId The document uuid.
	 *
	 * @return list<array<string, mixed>>
	 *
	 * @spec openspec/changes/governing-documents-register
	 */
	public static function citationsOf(array $decision, string $documentId): array {
		$cited = ($decision['citesGoverningDocuments'] ?? null);
		if ($documentId === '' || is_array($cited) === false) {
			return [];
		}

		$matching = [];
		foreach ($cited as $citation) {
			if (is_array($citation) === true && self::reference(value: ($citation['document'] ?? null)) === $documentId) {
				$matching[] = $citation;
			}
		}

		return $matching;
	}//end citationsOf()

	/**
	 * The public view of a document (REQ-GDR-007), or null when it is not
	 * public: not marked public, lapsed, or without a version in effect today.
	 *
	 * Only the citation title, type, the governing body's name and the version
	 * in force today (its number, dates and consolidated text) are in it; a
	 * draft or adopted version, the notes and the notary never are.
	 *
	 * @param string            $documentId The document uuid.
	 * @param DateTimeImmutable $today      The day the view answers for.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/changes/governing-documents-register
	 */
	public function publicView(string $documentId, DateTimeImmutable $today): ?array {
		$document = $this->systemRead(id: $documentId, schema: self::DOCUMENT_SCHEMA);
		if ($document === null || ($document['isPublic'] ?? false) !== true || ($document['status'] ?? null) === 'lapsed') {
			return null;
		}

		$version = self::resolveInForce(versions: $this->versionsOf(documentId: $documentId, rbac: false), date: $today->format('Y-m-d'));
		if ($version === null) {
			return null;
		}

		$withFiles = $this->systemRead(id: self::idOf(row: $version), schema: self::VERSION_SCHEMA, files: true);
		$body = $this->systemRead(id: self::reference(value: ($document['governingBody'] ?? null)), schema: 'governance-body');

		return self::publicPayload(
			document: $document,
			version: $version,
			bodyName: (string)($body['name'] ?? ($body['title'] ?? '')),
			files: (array)($withFiles['@self']['files'] ?? [])
		);
	}//end publicView()

	/**
	 * The public payload of a document and its version in force, built only
	 * from the fields that may be public.
	 *
	 * @param array<string, mixed>     $document The document.
	 * @param array<string, mixed>     $version  The version in force.
	 * @param string                   $bodyName The governing body's name.
	 * @param array<int|string, mixed> $files    The version's files.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/governing-documents-register
	 */
	public static function publicPayload(array $document, array $version, string $bodyName, array $files): array {
		$texts = [];
		foreach ($files as $file) {
			if (is_array($file) === false) {
				continue;
			}

			$texts[] = [
				'title' => (string)($file['title'] ?? ($file['name'] ?? ($file['filename'] ?? ''))),
				'type' => ($file['type'] ?? ($file['mimeType'] ?? null)),
				'accessUrl' => ($file['accessUrl'] ?? null),
				'downloadUrl' => ($file['downloadUrl'] ?? null),
			];
		}

		return [
			'id' => self::idOf(row: $document),
			'citationTitle' => (string)($document['citationTitle'] ?? ''),
			'type' => ($document['type'] ?? null),
			'governingBody' => $bodyName,
			'currentVersion' => [
				'versionNumber' => ($version['versionNumber'] ?? null),
				'effectiveDate' => self::date(value: ($version['effectiveDate'] ?? null)),
				'expiryDate' => self::dateOrNull(value: ($version['expiryDate'] ?? null)),
				'consolidatedText' => $texts,
			],
		];
	}//end publicPayload()

	/**
	 * Write the effective date of a version just put in effect onto its
	 * document, so the register lists it without resolving versions
	 * (REQ-GDR-006). Any other version is left alone.
	 *
	 * @param array<string, mixed> $version The version just saved.
	 *
	 * @return bool Whether the document was updated.
	 *
	 * @spec openspec/changes/governing-documents-register
	 */
	public function recordCurrentEffectiveDate(array $version): bool {
		$effective = self::date(value: ($version['effectiveDate'] ?? null));
		$documentId = self::reference(value: ($version['document'] ?? null));
		if (($version['status'] ?? null) !== 'in-effect' || $effective === '' || $documentId === '') {
			return false;
		}

		$document = $this->systemRead(id: $documentId, schema: self::DOCUMENT_SCHEMA);
		if ($document === null || self::date(value: ($document['currentEffectiveDate'] ?? null)) === $effective) {
			return false;
		}

		$document['currentEffectiveDate'] = $effective;
		unset($document['@self']);
		$this->objectService->saveObject(
			object: $document,
			register: 'decidiq',
			schema: self::DOCUMENT_SCHEMA,
			uuid: $documentId,
			_rbac: false,
			_multitenancy: false
		);

		return true;
	}//end recordCurrentEffectiveDate()

	/**
	 * Refuse sealing a version that does not take effect after the latest sealed one.
	 *
	 * Applies when the version is saved in the sealed `in-effect` state.
	 *
	 * @param array<int, array<string, mixed>> $others  The document's other versions.
	 * @param array<string, mixed>             $version The version about to be saved.
	 *
	 * @return void
	 *
	 * @throws GoverningDocumentVersionRefusedException When it is refused.
	 *
	 * @spec openspec/changes/governing-documents-register
	 */
	public static function assertActivationOrder(array $others, array $version): void {
		if (($version['status'] ?? null) !== 'in-effect') {
			return;
		}

		$effective = self::date(value: ($version['effectiveDate'] ?? null));
		if ($effective === '') {
			throw new GoverningDocumentVersionRefusedException(
				message: 'The version was not put in effect: it has no effective date (effectiveDate).'
			);
		}

		foreach ($others as $other) {
			$sealed = self::date(value: ($other['effectiveDate'] ?? null));
			if (self::isSealed(version: $other) === true && $sealed !== '' && $effective <= $sealed) {
				throw new GoverningDocumentVersionRefusedException(
					message: 'The version was not put in effect: its effective date ' . $effective . ' is not after ' . $sealed
						. ', the effective date of version ' . (string)($other['versionNumber'] ?? '?') . ' already in effect.'
				);
			}
		}
	}//end assertActivationOrder()

	/**
	 * A document's versions.
	 *
	 * @param string $documentId The document uuid.
	 * @param bool   $rbac       Whether to read with the caller's rights.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function versionsOf(string $documentId, bool $rbac): array {
		$rows = $this->objectService->findAll(
			config: [
				'filters' => [
					'register' => 'decidiq',
					'schema' => self::VERSION_SCHEMA,
					'document' => $documentId,
				],
			],
			_rbac: $rbac,
			_multitenancy: $rbac
		);

		$versions = [];
		foreach (($rows['results'] ?? $rows) as $row) {
			$data = $row;
			if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
				$data = $row->jsonSerialize();
			}

			if (is_array($data) === true && self::reference(value: ($data['document'] ?? null)) === $documentId) {
				$versions[] = $data;
			}
		}

		return $versions;
	}//end versionsOf()

	/**
	 * An object read without the caller's rights, or null.
	 *
	 * @param string $id     The uuid.
	 * @param string $schema The schema slug.
	 * @param bool   $files  Whether to read its files too.
	 *
	 * @return array<string, mixed>|null
	 */
	private function systemRead(string $id, string $schema, bool $files = false): ?array {
		if ($id === '') {
			return null;
		}

		try {
			$entity = $this->objectService->find(id: $id, files: $files, register: 'decidiq', schema: $schema, _rbac: false, _multitenancy: false);
		} catch (DoesNotExistException) {
			return null;
		}

		if ($entity === null) {
			return null;
		}

		return (array)$entity->jsonSerialize();
	}//end systemRead()

	/**
	 * The rows of a list answer, as arrays.
	 *
	 * @param array<mixed> $page The answer: a page with `results`, or a list.
	 *
	 * @return list<array<string, mixed>>
	 */
	private static function rowsOf(array $page): array {
		$rows = [];
		foreach (($page['results'] ?? $page) as $row) {
			$data = $row;
			if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
				$data = $row->jsonSerialize();
			}

			if (is_array($data) === true) {
				$rows[] = $data;
			}
		}

		return $rows;
	}//end rowsOf()

	/**
	 * Whether a version is sealed.
	 *
	 * @param array<string, mixed> $version The version.
	 *
	 * @return bool
	 */
	private static function isSealed(array $version): bool {
		return in_array(($version['status'] ?? null), self::SEALED_STATES, true);
	}//end isSealed()

	/**
	 * A stored date as Y-m-d, or '' when there is none.
	 *
	 * @param mixed $value The stored date.
	 *
	 * @return string
	 */
	private static function date(mixed $value): string {
		if (is_string($value) === false || strlen($value) < 10) {
			return '';
		}

		return substr($value, 0, 10);
	}//end date()

	/**
	 * A sealed field's value in a form two saves can be compared in.
	 *
	 * @param string $field The field.
	 * @param mixed  $value The stored value.
	 *
	 * @return string
	 */
	private static function comparable(string $field, mixed $value): string {
		if (in_array($field, self::REFERENCE_FIELDS, true) === true) {
			return self::reference(value: $value);
		}

		if (in_array($field, self::DATE_FIELDS, true) === true) {
			return self::date(value: $value);
		}

		if ($value === null || is_scalar($value) === false) {
			return '';
		}

		return trim((string)$value);
	}//end comparable()

	/**
	 * A stored date as Y-m-d, or null when there is none.
	 *
	 * @param mixed $value The stored date.
	 *
	 * @return string|null
	 */
	private static function dateOrNull(mixed $value): ?string {
		$date = self::date(value: $value);
		if ($date === '') {
			return null;
		}

		return $date;
	}//end dateOrNull()

	/**
	 * A row's own id.
	 *
	 * @param array<string, mixed> $row The row.
	 *
	 * @return string The id, or '' when it has none yet.
	 */
	private static function idOf(array $row): string {
		return self::reference(value: ($row['id'] ?? ($row['uuid'] ?? ($row['@self']['id'] ?? null))));
	}//end idOf()

	/**
	 * A reference as a uuid string: a plain id, or an object carrying one.
	 *
	 * @param mixed $value The stored reference.
	 *
	 * @return string The id, or '' when there is none.
	 */
	private static function reference(mixed $value): string {
		if (is_array($value) === true) {
			$value = ($value['id'] ?? ($value['uuid'] ?? ($value['@self']['id'] ?? null)));
		}

		if (is_string($value) === true) {
			return $value;
		}

		if (is_int($value) === true) {
			return (string)$value;
		}

		return '';
	}//end reference()
}//end class
