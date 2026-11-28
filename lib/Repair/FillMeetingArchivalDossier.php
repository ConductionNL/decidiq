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

use OCA\Decidiq\Service\Records\MeetingDossierBackfill;
use OCA\Decidiq\Service\Records\MeetingDossierLink;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;

/**
 * Dossiers formed before meetings named their dossier leave the meeting's
 * `archivalDossier` empty, so the archive dashboard would count them as
 * meetings without a dossier. This step writes the reference from each
 * dossier's own `meeting` (MeetingDossierBackfill). A meeting that already
 * names its dossier is left alone, so a second run changes nothing.
 *
 * @spec openspec/changes/records-management-archiving/specs/records-management-archiving/spec.md#requirement-req-rma-007-archive-completeness-and-compliance-dashboard
 */
class FillMeetingArchivalDossier implements IRepairStep {

	/**
	 * The walk over the dossiers.
	 *
	 * @var MeetingDossierBackfill
	 */
	private readonly MeetingDossierBackfill $backfill;

	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface $objectService OpenRegister's object service
	 * @param MeetingDossierLink     $meetingLink   Writes the meeting's reference
	 * @param LoggerInterface        $logger        Logger
	 */
	public function __construct(
		ObjectServiceInterface $objectService,
		MeetingDossierLink $meetingLink,
		LoggerInterface $logger,
	) {
		$this->backfill = new MeetingDossierBackfill(objectService: $objectService, meetingLink: $meetingLink, logger: $logger);
	}//end __construct()

	/**
	 * The step's name.
	 *
	 * @return string
	 */
	public function getName(): string {
		return 'Point meetings at their archival dossier';
	}//end getName()

	/**
	 * Point every meeting with a dossier at it.
	 *
	 * @param IOutput $output The repair output.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/records-management-archiving/specs/records-management-archiving/spec.md#requirement-req-rma-007-archive-completeness-and-compliance-dashboard
	 */
	public function run(IOutput $output): void {
		$linked = $this->backfill->fill();
		$output->info(sprintf('FillMeetingArchivalDossier: %d meeting(s) now name their archival dossier.', $linked));
	}//end run()
}//end class
