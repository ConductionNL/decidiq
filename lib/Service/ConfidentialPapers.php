<?php

/**
 * Confidential papers for a named portal reader
 *
 * The papers of a non-public agenda item as the portal lists them to a reader
 * the griffie named, and the audit entry written before the portal hands one
 * out (portal-voting-record-and-confidential-papers, REQ-PSH-003, REQ-PSH-004).
 *
 * @category  Service
 * @package   OCA\Decidiq\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/decidiq
 *
 * @spec openspec/changes/portal-voting-record-and-confidential-papers/specs/portal-contribution/spec.md#requirement-req-psh-003-confidential-papers-reach-only-a-named-reader-at-the-declared-trust-level
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Service;

use OCA\Decidiq\Kernel\AppIdentity;
use OCA\Decidiq\Kernel\Contracts\AuditLogServiceInterface;
use OCA\Decidiq\Kernel\Contracts\ConfidentialPapersInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Lists the papers of a non-public item and logs every portal opening first.
 *
 * Who may see the item is the portal's scoped read (`authorisedPersons`
 * against the reader's `decidiq.personId` claim) at the declared trust; this
 * class only answers for an item that is still confidential, so lifting the
 * secrecy empties the list at once.
 *
 * @category Service
 * @package  OCA\Decidiq\Service
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/decidiq
 *
 * @spec openspec/changes/portal-voting-record-and-confidential-papers/specs/portal-contribution/spec.md#requirement-req-psh-003-confidential-papers-reach-only-a-named-reader-at-the-declared-trust-level
 */
class ConfidentialPapers implements ConfidentialPapersInterface {

	/**
	 * The register every object lives in.
	 */
	private const REGISTER = 'decidiq';

	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface   $objectService Reads the item, its papers and the reader's person.
	 * @param AuditLogServiceInterface $auditLog      The hash-chained audit log.
	 * @param IAppConfig               $appConfig     Holds `portal_confidential_min_trust`.
	 * @param LoggerInterface          $logger        Records a failed read or audit write.
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		private readonly AuditLogServiceInterface $auditLog,
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The trust a portal session needs: `substantial` unset, `high` for `high` and for any unknown value.
	 *
	 * @return string `substantial` or `high`.
	 *
	 * @spec openspec/changes/portal-voting-record-and-confidential-papers/specs/portal-contribution/spec.md#requirement-req-psh-003-confidential-papers-reach-only-a-named-reader-at-the-declared-trust-level
	 */
	public function minTrust(): string {
		return self::trustOf(value: $this->appConfig->getValueString(AppIdentity::APP_ID, self::MIN_TRUST_KEY, ''));
	}//end minTrust()

	/**
	 * The trust a stored setting names: '' is `substantial`, anything but `substantial` is `high`.
	 *
	 * @param string $value The stored value.
	 *
	 * @return string `substantial` or `high`.
	 *
	 * @spec openspec/changes/portal-voting-record-and-confidential-papers/specs/portal-contribution/spec.md#requirement-req-psh-003-confidential-papers-reach-only-a-named-reader-at-the-declared-trust-level
	 */
	public static function trustOf(string $value): string {
		$value = strtolower(trim($value));
		if ($value === '' || $value === 'substantial') {
			return 'substantial';
		}

		return 'high';
	}//end trustOf()

	/**
	 * The papers of a non-public agenda item, [] for a public or unknown one.
	 *
	 * @param string $itemId The agenda item id, uuid or slug.
	 *
	 * @return list<array<string, mixed>> Portal document entries.
	 *
	 * @spec openspec/changes/portal-voting-record-and-confidential-papers/specs/portal-contribution/spec.md#requirement-req-psh-003-confidential-papers-reach-only-a-named-reader-at-the-declared-trust-level
	 */
	public function papers(string $itemId): array {
		$item = $this->confidentialItem(itemId: $itemId);
		if ($item === null) {
			return [];
		}

		$entries = [];
		foreach ($this->papersOf(item: $item) as $paper) {
			$entry = self::entry(paper: $paper);
			if ($entry !== null) {
				$entries[] = $entry;
			}
		}

		return $entries;
	}//end papers()

	/**
	 * Append the `material-access` entry for one portal opening; true only when it was written.
	 *
	 * @param string               $itemId     The agenda item id.
	 * @param string               $documentId The paper id.
	 * @param array<string, mixed> $subject    The portal session.
	 *
	 * @return bool Whether the portal may stream the paper.
	 *
	 * @spec openspec/changes/portal-voting-record-and-confidential-papers/specs/portal-contribution/spec.md#requirement-req-psh-004-every-portal-opening-of-a-confidential-paper-is-in-decidiqs-audit-log-first
	 */
	public function opened(string $itemId, string $documentId, array $subject): bool {
		$item = $this->confidentialItem(itemId: $itemId);
		if ($item === null || $documentId === '') {
			return false;
		}

		$paper = null;
		foreach ($this->papersOf(item: $item) as $candidate) {
			if (in_array($documentId, self::idsOf(row: $candidate), true) === true) {
				$paper = $candidate;
				break;
			}
		}

		if ($paper === null) {
			return false;
		}

		$subjectRef = (string)($subject['subjectRef'] ?? '');
		try {
			$result = $this->auditLog->append(
				actor: 'portal:' . $subjectRef,
				action: 'material-access',
				objectUids: [self::idOf(row: $item), self::idOf(row: $paper)],
				payload: [
					'kind' => 'portal-confidential-paper',
					'channel' => 'portal',
					'trust' => (string)($subject['trust'] ?? ''),
					'identityType' => (string)($subject['identityType'] ?? ''),
					'subjectRef' => $subjectRef,
					'person' => $this->personOf(subjectRef: $subjectRef),
					'agendaItem' => self::idOf(row: $item),
					'document' => self::idOf(row: $paper),
				]
			);
		} catch (Throwable $e) {
			$this->logger->error('Decidiq: the portal opening of a confidential paper could not be logged', ['exception' => $e->getMessage()]);
			return false;
		}

		return ($result['success'] ?? false) === true;
	}//end opened()

	/**
	 * The agenda item when it exists and is not public, else null.
	 *
	 * @param string $itemId The id, uuid or slug.
	 *
	 * @return array<string, mixed>|null
	 */
	private function confidentialItem(string $itemId): ?array {
		if ($itemId === '') {
			return null;
		}

		foreach ($this->rows(schema: 'agenda-item', filters: []) as $item) {
			if (in_array($itemId, self::idsOf(row: $item), true) === true) {
				if (($item['public'] ?? true) !== false) {
					return null;
				}

				return $item;
			}
		}

		return null;
	}//end confidentialItem()

	/**
	 * The papers that belong to an item.
	 *
	 * @param array<string, mixed> $item The agenda item.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function papersOf(array $item): array {
		$ids = self::idsOf(row: $item);
		$papers = [];
		foreach ($this->rows(schema: 'digital-document', filters: []) as $paper) {
			if (in_array((string)($paper['agendaItem'] ?? ''), $ids, true) === true) {
				$papers[] = $paper;
			}
		}

		return $papers;
	}//end papersOf()

	/**
	 * The person whose portal account a session is, or ''.
	 *
	 * @param string $subjectRef The session's subject reference.
	 *
	 * @return string The person id.
	 */
	private function personOf(string $subjectRef): string {
		if ($subjectRef === '') {
			return '';
		}

		foreach ($this->rows(schema: 'person', filters: ['portalAccountRef' => $subjectRef]) as $person) {
			if (($person['portalAccountRef'] ?? null) === $subjectRef) {
				return self::idOf(row: $person);
			}
		}

		return '';
	}//end personOf()

	/**
	 * One paper as a portal document entry, or null without a file.
	 *
	 * @param array<string, mixed> $paper The digital document.
	 *
	 * @return array<string, mixed>|null
	 */
	private static function entry(array $paper): ?array {
		$fileId = ($paper['fileId'] ?? null);
		$id = self::idOf(row: $paper);
		if ($id === '' || (is_int($fileId) === false && (is_string($fileId) === false || $fileId === ''))) {
			return null;
		}

		$entry = [
			'id' => $id,
			'title' => (string)($paper['name'] ?? $id),
			'kind' => (string)($paper['documentType'] ?? 'document'),
			'date' => (string)($paper['dateCreated'] ?? ($paper['@self']['created'] ?? '')),
			'file' => ['register' => self::REGISTER, 'schema' => 'digital-document', 'id' => $id, 'fileId' => (string)$fileId],
		];
		if (is_string($paper['encodingFormat'] ?? null) === true) {
			$entry['mimeType'] = $paper['encodingFormat'];
		}

		return $entry;
	}//end entry()

	/**
	 * Every name a row answers to: id, uuid and slug.
	 *
	 * @param array<string, mixed> $row The row.
	 *
	 * @return list<string>
	 */
	private static function idsOf(array $row): array {
		$ids = [];
		foreach ([($row['id'] ?? null), ($row['uuid'] ?? null), ($row['@self']['id'] ?? null), ($row['slug'] ?? null)] as $value) {
			if (is_string($value) === true && $value !== '') {
				$ids[] = $value;
			}
		}

		return $ids;
	}//end idsOf()

	/**
	 * The row's own id: id, else uuid, else slug.
	 *
	 * @param array<string, mixed> $row The row.
	 *
	 * @return string
	 */
	private static function idOf(array $row): string {
		return (self::idsOf(row: $row)[0] ?? '');
	}//end idOf()

	/**
	 * Rows of one schema, [] when the read fails.
	 *
	 * @param string               $schema  The schema slug.
	 * @param array<string, mixed> $filters Extra filters.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function rows(string $schema, array $filters): array {
		try {
			$found = $this->objectService->findAll(
				config: ['filters' => array_merge(['register' => self::REGISTER, 'schema' => $schema], $filters)],
				_rbac: false,
				_multitenancy: false
			);
		} catch (Throwable $e) {
			$this->logger->warning('Decidiq: could not read ' . $schema . ' for the portal', ['exception' => $e->getMessage()]);
			return [];
		}

		$rows = [];
		foreach (($found['results'] ?? $found) as $row) {
			if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
				$row = $row->jsonSerialize();
			}

			if (is_array($row) === true) {
				$rows[] = $row;
			}
		}

		return $rows;
	}//end rows()
}//end class
