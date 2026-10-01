<?php

/**
 * Decidiq Dossier Member Collector
 *
 * Gathers the records of one meeting for its archival dossier: the approved
 * minutes, the decisions, the voting rounds that decided them and the
 * documents, each by uuid, and names what is still missing.
 *
 * A decision is found two ways, because both are how decisions reach a
 * meeting in practice: through its own `meeting` property (the live decision
 * panel and the meeting's decisions tab write it), and through its
 * `agendaItem` (a motion raised on an agenda item). Voting rounds are found
 * through the decision's stages: a stage names its round, and a round names
 * its stage.
 *
 * Reads run in system context: a dossier is the complete record of the
 * meeting, not the part the person who forms it happens to see. It holds uuids
 * only, never the records' contents.
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
 * @spec openspec/changes/records-management-archiving/specs/records-management-archiving/spec.md#requirement-req-rma-001-archival-dossier-assembly
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Service;

use OCA\OpenRegister\Contract\ObjectServiceInterface;

/**
 * The records of a meeting, by uuid, and the gaps in them.
 *
 * @spec openspec/changes/records-management-archiving/specs/records-management-archiving/spec.md#requirement-req-rma-001-archival-dossier-assembly
 */
class DossierMemberCollector {
	/**
	 * Gap: the meeting has no minutes at all.
	 */
	public const GAP_MINUTES_MISSING = 'minutes-missing';

	/**
	 * Gap: the meeting has minutes, none of them approved yet.
	 */
	public const GAP_MINUTES_NOT_APPROVED = 'minutes-not-approved';

	/**
	 * Gap: the meeting itself is not closed yet.
	 */
	public const GAP_MEETING_NOT_CLOSED = 'meeting-not-closed';

	/**
	 * Minutes stages that count as approved.
	 */
	private const APPROVED_MINUTES = ['approved', 'signed', 'published'];

	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface $objectService OpenRegister's object facade
	 *
	 * @spec openspec/changes/records-management-archiving/specs/records-management-archiving/spec.md#requirement-req-rma-001-archival-dossier-assembly
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
	) {
	}//end __construct()

	/**
	 * The meeting's records and gaps.
	 *
	 * @param string               $meetingId The meeting
	 * @param array<string, mixed> $meeting   The meeting object
	 *
	 * @return array{minutes: list<string>, decisions: list<string>, votingRounds: list<string>, documents: list<string>, gaps: list<string>}
	 *
	 * @spec openspec/changes/records-management-archiving/specs/records-management-archiving/spec.md#requirement-req-rma-001-archival-dossier-assembly
	 */
	public function collect(string $meetingId, array $meeting): array {
		$items = $this->ids(rows: $this->rows(schema: 'agenda-item', filters: ['meeting' => $meetingId]));

		$decisions = $this->ids(rows: $this->rows(schema: 'decision', filters: ['meeting' => $meetingId]));
		$documents = $this->ids(rows: $this->rows(schema: 'digital-document', filters: ['meeting' => $meetingId]));
		foreach ($items as $itemId) {
			$decisions = array_merge($decisions, $this->ids(rows: $this->rows(schema: 'decision', filters: ['agendaItem' => $itemId])));
			$documents = array_merge($documents, $this->ids(rows: $this->rows(schema: 'digital-document', filters: ['agendaItem' => $itemId])));
		}

		$decisions = array_values(array_unique($decisions));
		[$minutes, $gaps] = $this->minutes(meetingId: $meetingId);
		if (($meeting['lifecycle'] ?? null) !== 'closed') {
			$gaps[] = self::GAP_MEETING_NOT_CLOSED;
		}

		return [
			'minutes' => $minutes,
			'decisions' => $decisions,
			'votingRounds' => $this->votingRounds(decisions: $decisions),
			'documents' => array_values(array_unique($documents)),
			'gaps' => $gaps,
		];
	}//end collect()

	/**
	 * The approved minutes, and the gap when there are none.
	 *
	 * @param string $meetingId The meeting
	 *
	 * @return array{0: list<string>, 1: list<string>}
	 *
	 * @spec openspec/changes/records-management-archiving/specs/records-management-archiving/spec.md#requirement-req-rma-001-archival-dossier-assembly
	 */
	private function minutes(string $meetingId): array {
		$all = $this->rows(schema: 'minutes', filters: ['meeting' => $meetingId]);
		if ($all === []) {
			return [[], [self::GAP_MINUTES_MISSING]];
		}

		$approved = array_filter(
			$all,
			static fn (array $row): bool => in_array(($row['lifecycle'] ?? null), self::APPROVED_MINUTES, true)
		);
		if ($approved === []) {
			return [[], [self::GAP_MINUTES_NOT_APPROVED]];
		}

		return [$this->ids(rows: $approved), []];
	}//end minutes()

	/**
	 * The voting rounds of the decisions, through their stages.
	 *
	 * @param list<string> $decisions The decision uuids
	 *
	 * @return list<string>
	 *
	 * @spec openspec/changes/records-management-archiving/specs/records-management-archiving/spec.md#requirement-req-rma-001-archival-dossier-assembly
	 */
	private function votingRounds(array $decisions): array {
		$rounds = [];
		foreach ($decisions as $decisionId) {
			foreach ($this->rows(schema: 'decision-stage', filters: ['decision' => $decisionId]) as $stage) {
				$named = ($stage['votingRound'] ?? null);
				if (is_string($named) === true && $named !== '') {
					$rounds[] = $named;
				}

				$rounds = array_merge($rounds, $this->ids(rows: $this->rows(schema: 'voting-round', filters: ['decisionStage' => $stage['id']])));
			}
		}

		return array_values(array_unique($rounds));
	}//end votingRounds()

	/**
	 * The uuids of rows.
	 *
	 * @param array<array<string, mixed>> $rows The rows
	 *
	 * @return list<string>
	 *
	 * @spec openspec/changes/records-management-archiving/specs/records-management-archiving/spec.md#requirement-req-rma-001-archival-dossier-assembly
	 */
	private function ids(array $rows): array {
		return array_values(array_filter(array_map(static fn (array $row): string => (string)$row['id'], $rows), static fn (string $id): bool => $id !== ''));
	}//end ids()

	/**
	 * Read objects in system context.
	 *
	 * @param string               $schema  The schema slug
	 * @param array<string, mixed> $filters Property filters
	 *
	 * @return list<array<string, mixed>>
	 *
	 * @spec openspec/changes/records-management-archiving/specs/records-management-archiving/spec.md#requirement-req-rma-001-archival-dossier-assembly
	 */
	private function rows(string $schema, array $filters): array {
		$found = $this->objectService->findAll(
			config: ['filters' => (['register' => 'decidiq', 'schema' => $schema] + $filters), 'limit' => 1000],
			_rbac: false,
			_multitenancy: false
		);

		$rows = [];
		foreach ($found as $entity) {
			$rows[] = ['id' => (string)$entity->getUuid()] + $entity->jsonSerialize();
		}

		return $rows;
	}//end rows()
}//end class
