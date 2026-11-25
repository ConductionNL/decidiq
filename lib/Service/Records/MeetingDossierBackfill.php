<?php

/**
 * Points every meeting that has an archival dossier at it.
 *
 * @category Service
 * @package  OCA\Decidiq\Service\Records
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/records-management-archiving/specs/records-management-archiving/spec.md#requirement-req-rma-007-archive-completeness-and-compliance-dashboard
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Decidiq\Service\Records;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * A meeting names its archival dossier (`archivalDossier`), and the app writes
 * that reference when it forms the dossier. Dossiers that arrive another way
 * (formed before the reference existed, or imported with an example set, which
 * writes the dossier's `meeting` but not the meeting's `archivalDossier`) leave
 * it empty, and the archive dashboard would count their meetings as meetings
 * without a dossier. This walks every dossier and writes the reference from the
 * dossier's own `meeting`. A meeting that already names its dossier is left
 * alone, so running it again changes nothing.
 *
 * @spec openspec/changes/records-management-archiving/specs/records-management-archiving/spec.md#requirement-req-rma-007-archive-completeness-and-compliance-dashboard
 */
class MeetingDossierBackfill {

	/**
	 * The dossiers.
	 */
	private const DOSSIERS = ['register' => 'decidiq', 'schema' => 'archival-dossier'];

	/**
	 * Dossiers read per page.
	 */
	private const PAGE = 500;

	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface $objectService OpenRegister's object service
	 * @param MeetingDossierLink     $meetingLink   Writes the meeting's reference
	 * @param LoggerInterface        $logger        Logger
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		private readonly MeetingDossierLink $meetingLink,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Point every meeting with a dossier at it.
	 *
	 * @return int How many meetings now name their dossier that did not before.
	 *
	 * @spec openspec/changes/records-management-archiving/specs/records-management-archiving/spec.md#requirement-req-rma-007-archive-completeness-and-compliance-dashboard
	 */
	public function fill(): int {
		$linked = 0;
		$offset = 0;
		do {
			$page = $this->page(offset: $offset);
			$size = count($page);
			foreach ($page as $dossier) {
				$linked += (int)$this->meetingLink->link(meetingId: $dossier['meeting'], dossierId: $dossier['id']);
			}

			$offset += self::PAGE;
		} while ($size === self::PAGE);

		return $linked;
	}//end fill()

	/**
	 * One page of dossiers as id and meeting; none when they cannot be read.
	 *
	 * The page keeps its length (rows it cannot use become empty pairs, which
	 * the link skips), so the paging loop sees a full page as a full page.
	 *
	 * @param int $offset Where the page starts.
	 *
	 * @return list<array{id: string, meeting: string}>
	 */
	private function page(int $offset): array {
		$config = ['filters' => self::DOSSIERS, 'limit' => self::PAGE, 'offset' => $offset];
		try {
			$found = $this->objectService->findAll(config: $config, _rbac: false, _multitenancy: false);
		} catch (Throwable $e) {
			$this->logger->info('Decidiq: MeetingDossierBackfill found no dossiers', ['exception' => $e->getMessage()]);
			return [];
		}

		$rows = [];
		foreach (($found['results'] ?? $found) as $row) {
			$rows[] = self::pair(row: $row);
		}

		return $rows;
	}//end page()

	/**
	 * A found row as its id and meeting, empty strings for what it lacks.
	 *
	 * @param mixed $row A row as findAll returns it.
	 *
	 * @return array{id: string, meeting: string}
	 */
	private static function pair(mixed $row): array {
		$uuid = '';
		if (is_object($row) === true && method_exists($row, 'getUuid') === true) {
			$uuid = (string)$row->getUuid();
		}

		if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
			$row = $row->jsonSerialize();
		}

		if (is_array($row) === false) {
			return ['id' => '', 'meeting' => ''];
		}

		if ($uuid === '') {
			$uuid = (string)($row['id'] ?? ($row['@self']['id'] ?? ''));
		}

		$meeting = ($row['meeting'] ?? '');
		if (is_string($meeting) === false) {
			$meeting = '';
		}

		return ['id' => $uuid, 'meeting' => $meeting];
	}//end pair()
}//end class
