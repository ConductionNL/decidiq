<?php

/**
 * Decidiq RestrictedPaperViews
 *
 * Who opened a paper under an active confidentiality restriction, and when.
 * Every opening through decidiq's own paper read path writes one entry in
 * the OpenRegister audit trail first; the paper is served only once that
 * entry is written. The staff list those entries per restriction.
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
 * @spec openspec/changes/embargo-geheimhouding/specs/embargo-geheimhouding/spec.md#requirement-req-emb-007-view-audit-trail-for-stukken-under-geheimhouding
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Service;

use OCA\Decidiq\Exception\ConfidentialityImposeRefusedException;
use OCA\Decidiq\Kernel\Contracts\AuditLogServiceInterface;
use OCA\Decidiq\Listener\EntityPayloadReader;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
use OCP\AppFramework\Http;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Records and lists the openings of papers under an active restriction.
 *
 * Fail closed: when the restrictions cannot be read, or an entry cannot be
 * written, the opening is refused rather than served unrecorded. A paper
 * whose restriction was lifted, or lapsed, is not specially recorded.
 *
 * @spec openspec/changes/embargo-geheimhouding/specs/embargo-geheimhouding/spec.md#requirement-req-emb-007-view-audit-trail-for-stukken-under-geheimhouding
 */
class RestrictedPaperViews {

	/**
	 * The audit action of an opening (shared with the portal's confidential papers).
	 *
	 * @var string
	 */
	public const ACTION = 'material-access';

	/**
	 * The kind that tells these entries apart from other material access.
	 *
	 * @var string
	 */
	public const KIND = 'restricted-paper-view';

	/**
	 * Restriction states that are in force.
	 *
	 * @var list<string>
	 */
	private const ACTIVE_STATES = ['imposed', 'ratified'];

	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface   $objectService The OpenRegister object service.
	 * @param AuditLogServiceInterface $auditLog      The OpenRegister audit trail.
	 * @param ConfidentialityImposer   $imposer       Who holds the imposing body's authority.
	 * @param LoggerInterface          $logger        Logs a refused opening.
	 *
	 * @spec openspec/changes/embargo-geheimhouding/specs/embargo-geheimhouding/spec.md#requirement-req-emb-007-view-audit-trail-for-stukken-under-geheimhouding
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		private readonly AuditLogServiceInterface $auditLog,
		private readonly ConfidentialityImposer $imposer,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Record one opening of a paper, for every active restriction on it.
	 *
	 * @param string      $readerUid    The reader.
	 * @param string|null $agendaItemId The agenda item the paper is on, or null for a meeting's paper.
	 * @param int         $fileId       The paper's file id.
	 *
	 * @return string|null Null when the opening may go ahead (recorded, or nothing to record), else the refusal.
	 *
	 * @spec openspec/changes/embargo-geheimhouding/specs/embargo-geheimhouding/spec.md#scenario-viewing-a-geheim-document-is-audited
	 */
	public function record(string $readerUid, ?string $agendaItemId, int $fileId): ?string {
		try {
			$restrictions = $this->activeOn(agendaItemId: trim((string)$agendaItemId), fileId: $fileId);
		} catch (Throwable $e) {
			$this->logger->error(
				'Decidiq: the confidentiality of a paper could not be read; the paper is not shown',
				['fileId' => $fileId, 'exception' => $e->getMessage()]
			);
			return 'This paper is not shown: its confidentiality could not be checked. Try again in a moment.';
		}

		foreach ($restrictions as $restriction) {
			$document = (string)($restriction['targetDocument'] ?? '');
			$uids     = array_values(
				array_filter([(string)($restriction['id'] ?? ''), $document, (string)$agendaItemId], static fn (string $uid): bool => $uid !== '')
			);
			$result   = $this->auditLog->append(
				actor: $readerUid,
				action: self::ACTION,
				objectUids: $uids,
				payload: [
					'kind'        => self::KIND,
					'channel'     => 'app',
					'restriction' => (string)($restriction['id'] ?? ''),
					'document'    => $document,
					'agendaItem'  => (string)$agendaItemId,
					'fileId'      => $fileId,
				]
			);
			if (($result['success'] ?? false) !== true) {
				return 'This paper is not shown: the opening of a confidential paper could not be recorded. Try again in a moment.';
			}
		}

		return null;
	}//end record()

	/**
	 * The openings recorded for one restriction, newest first.
	 *
	 * @param string $userId        The caller.
	 * @param string $restrictionId The restriction.
	 *
	 * @return list<array{actor: string, at: string, document: string, agendaItem: string, fileId: int}>
	 *
	 * @throws ConfidentialityImposeRefusedException With 404 (unreadable restriction), 403 (not staff) or 503 (audit unreadable).
	 *
	 * @spec openspec/changes/embargo-geheimhouding/specs/embargo-geheimhouding/spec.md#scenario-viewing-a-geheim-document-is-audited
	 */
	public function viewsOf(string $userId, string $restrictionId): array {
		try {
			$entity = $this->objectService->find(
				id: $restrictionId,
				options: new ObjectFindOptions(register: 'decidiq', schema: 'confidentiality-restriction')
			);
		} catch (Throwable) {
			$entity = null;
		}

		if ($entity === null) {
			throw new ConfidentialityImposeRefusedException(message: 'The restriction was not found.', status: Http::STATUS_NOT_FOUND);
		}

		$restriction = (new EntityPayloadReader())->payloadOf(entity: $entity);
		if ($this->imposer->mayImpose(userId: $userId, bodyId: (string)($restriction['imposedByBody'] ?? '')) === false) {
			throw new ConfidentialityImposeRefusedException(
				message: 'Only the chair, vice-chair or secretary of the imposing body, the secretariat or an administrator '
					. 'may see who opened these papers.',
				status: Http::STATUS_FORBIDDEN
			);
		}

		$found = $this->auditLog->query(filters: ['action' => self::ACTION, 'objectUuid' => $restrictionId, 'limit' => 1000]);
		if (($found['success'] ?? false) !== true) {
			throw new ConfidentialityImposeRefusedException(message: 'The audit trail could not be read.', status: Http::STATUS_SERVICE_UNAVAILABLE);
		}

		return self::views(entries: (array)($found['entries'] ?? []), restrictionId: $restrictionId);
	}//end viewsOf()

	/**
	 * The audit entries that are openings of this restriction's papers, newest first.
	 *
	 * @param array<int, mixed> $entries       The audit entries.
	 * @param string            $restrictionId The restriction.
	 *
	 * @return list<array{actor: string, at: string, document: string, agendaItem: string, fileId: int}>
	 *
	 * @spec openspec/changes/embargo-geheimhouding/specs/embargo-geheimhouding/spec.md#scenario-viewing-a-geheim-document-is-audited
	 */
	public static function views(array $entries, string $restrictionId): array {
		$views = [];
		foreach ($entries as $entry) {
			if (is_array($entry) === false) {
				continue;
			}

			$payload = (array)($entry['payload'] ?? []);
			if (($payload['kind'] ?? null) !== self::KIND || ($payload['restriction'] ?? null) !== $restrictionId) {
				continue;
			}

			$views[] = [
				'actor'      => (string)($entry['actorUuid'] ?? ''),
				'at'         => (string)($entry['timestamp'] ?? ''),
				'document'   => (string)($payload['document'] ?? ''),
				'agendaItem' => (string)($payload['agendaItem'] ?? ''),
				'fileId'     => (int)($payload['fileId'] ?? 0),
			];
		}

		usort($views, static fn (array $one, array $two): int => strcmp($two['at'], $one['at']));

		return $views;
	}//end views()

	/**
	 * The active restrictions on the paper's agenda item or on its document records.
	 *
	 * @param string $agendaItemId The agenda item, or ''.
	 * @param int    $fileId       The paper's file id.
	 *
	 * @return list<array<string, mixed>>
	 *
	 * @spec openspec/changes/embargo-geheimhouding/specs/embargo-geheimhouding/spec.md#requirement-req-emb-007-view-audit-trail-for-stukken-under-geheimhouding
	 */
	private function activeOn(string $agendaItemId, int $fileId): array {
		$documents = [];
		foreach ($this->rows(filters: ['schema' => 'digital-document', 'fileId' => $fileId]) as $document) {
			if ((int)($document['fileId'] ?? 0) === $fileId && (string)($document['id'] ?? '') !== '') {
				$documents[] = (string)$document['id'];
			}
		}

		$active = [];
		foreach ($this->rows(filters: ['schema' => 'confidentiality-restriction']) as $restriction) {
			if (in_array(($restriction['lifecycle'] ?? ''), self::ACTIVE_STATES, true) === true
				&& self::covers(restriction: $restriction, agendaItemId: $agendaItemId, documents: $documents) === true
			) {
				$active[] = $restriction;
			}
		}

		return $active;
	}//end activeOn()

	/**
	 * Whether a restriction targets the agenda item or one of the paper's document records.
	 *
	 * @param array<string, mixed> $restriction  The restriction.
	 * @param string               $agendaItemId The agenda item, or ''.
	 * @param list<string>         $documents    The paper's document record ids.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/embargo-geheimhouding/specs/embargo-geheimhouding/spec.md#requirement-req-emb-007-view-audit-trail-for-stukken-under-geheimhouding
	 */
	private static function covers(array $restriction, string $agendaItemId, array $documents): bool {
		$scope = ($restriction['scope'] ?? '');
		if ($scope === 'item') {
			return $agendaItemId !== '' && (string)($restriction['targetAgendaItem'] ?? '') === $agendaItemId;
		}

		return $scope === 'document' && in_array((string)($restriction['targetDocument'] ?? ''), $documents, true) === true;
	}//end covers()

	/**
	 * The rows matching the filters, read as the system.
	 *
	 * @param array<string, mixed> $filters The filters, with `schema`.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function rows(array $filters): array {
		$found = $this->objectService->findAll(
			config: ['filters' => array_merge(['register' => 'decidiq'], $filters)],
			_rbac: false,
			_multitenancy: false
		);

		$rows = [];
		foreach (($found['results'] ?? $found) as $row) {
			$rows[] = (new EntityPayloadReader())->payloadOf(entity: $row);
		}

		return $rows;
	}//end rows()
}//end class
