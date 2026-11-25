<?php

/**
 * Points every meeting that has an archival dossier at it, once.
 *
 * @category Repair
 * @package  OCA\Decidiq\Repair
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

namespace OCA\Decidiq\Repair;

use OCA\Decidiq\Service\Records\MeetingDossierLink;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Dossiers formed before meetings named their dossier leave the meeting's
 * `archivalDossier` empty, so the archive dashboard would count them as
 * meetings without a dossier. This step writes the reference from each
 * dossier's own `meeting`. A meeting that already names its dossier is left
 * alone, so a second run changes nothing.
 *
 * @spec openspec/changes/records-management-archiving/specs/records-management-archiving/spec.md#requirement-req-rma-007-archive-completeness-and-compliance-dashboard
 */
class FillMeetingArchivalDossier implements IRepairStep {

	/**
	 * The filter that selects every archival dossier.
	 *
	 * @var array<string, string>
	 */
	private const DOSSIERS = ['register' => 'decidiq', 'schema' => 'archival-dossier'];

	/**
	 * How many dossiers are read per page.
	 *
	 * @var int
	 */
	private const PAGE = 500;

	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface $objectService The OpenRegister object service.
	 * @param MeetingDossierLink     $meetingLink   Writes the meeting's reference.
	 * @param LoggerInterface        $logger        Logger.
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		private readonly MeetingDossierLink $meetingLink,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Step name shown by `occ maintenance:repair`.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/records-management-archiving/specs/records-management-archiving/spec.md#requirement-req-rma-007-archive-completeness-and-compliance-dashboard
	 */
	public function getName(): string {
		return 'Point meetings at their archival dossier';
	}//end getName()

	/**
	 * Link the meeting of every dossier.
	 *
	 * @param IOutput $output Repair output.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/records-management-archiving/specs/records-management-archiving/spec.md#requirement-req-rma-007-archive-completeness-and-compliance-dashboard
	 */
	public function run(IOutput $output): void {
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

		$output->info(sprintf('FillMeetingArchivalDossier: %d meeting(s) now name their archival dossier.', $linked));
	}//end run()

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
			$this->logger->info('Decidiq: FillMeetingArchivalDossier found no dossiers', ['exception' => $e->getMessage()]);
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
