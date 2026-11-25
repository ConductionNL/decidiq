<?php

/**
 * Decidiq Meeting Dossier Link
 *
 * Writes the meeting's `archivalDossier` reference: the id of the dossier that
 * holds the meeting's records. The dossier stays the source (its own `meeting`
 * reference); this is the reverse pointer that lets a counter find closed
 * meetings without a dossier with one isnull filter on one schema, an anti-join
 * across two schemas that no OpenRegister filter expresses.
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
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Service\Records;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindTarget;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

/**
 * Point a meeting at its archival dossier.
 *
 * @spec openspec/changes/records-management-archiving/specs/records-management-archiving/spec.md#requirement-req-rma-007-archive-completeness-and-compliance-dashboard
 */
class MeetingDossierLink {
	/**
	 * The meeting property that names the dossier.
	 */
	public const PROPERTY = 'archivalDossier';

	/**
	 * The register both objects live in.
	 */
	private const REGISTER = 'decidiq';

	/**
	 * Where a failed link is reported.
	 *
	 * @var LoggerInterface
	 */
	private readonly LoggerInterface $logger;

	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface $objectService OpenRegister's object facade
	 * @param LoggerInterface|null   $logger        Logger; none drops the failure report
	 *
	 * @spec openspec/changes/records-management-archiving/specs/records-management-archiving/spec.md#requirement-req-rma-007-archive-completeness-and-compliance-dashboard
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		?LoggerInterface $logger = null,
	) {
		$this->logger = ($logger ?? new NullLogger());
	}//end __construct()

	/**
	 * Write the dossier's id on its meeting, unless the meeting already names it.
	 *
	 * The link is derived from the dossier, so a failure here never fails the
	 * dossier: it is logged, and the next forming call or the repair step
	 * writes it again.
	 *
	 * @param string $meetingId The meeting
	 * @param string $dossierId The meeting's dossier
	 *
	 * @return bool True when the meeting was written
	 *
	 * @spec openspec/changes/records-management-archiving/specs/records-management-archiving/spec.md#requirement-req-rma-007-archive-completeness-and-compliance-dashboard
	 */
	public function link(string $meetingId, string $dossierId): bool {
		if ($meetingId === '' || $dossierId === '') {
			return false;
		}

		try {
			$entity = $this->objectService->find(
				id: $meetingId,
				options: new ObjectFindOptions(register: self::REGISTER, schema: 'meeting'),
				_rbac: false,
				_multitenancy: false
			);
			$meeting = (array)$entity?->getObject();
			if ($entity === null || ($meeting[self::PROPERTY] ?? null) === $dossierId) {
				return false;
			}

			unset($meeting['id'], $meeting['@self']);
			$meeting[self::PROPERTY] = $dossierId;
			$this->objectService->saveObject(
				object: $meeting,
				options: new ObjectSaveOptions(target: new ObjectFindTarget(register: self::REGISTER, schema: 'meeting'), uuid: $meetingId),
				_rbac: false,
				_multitenancy: false
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Decidiq: a meeting could not be linked to its archival dossier',
				['meeting' => $meetingId, 'dossier' => $dossierId, 'error' => $e->getMessage()]
			);
			return false;
		}

		return true;
	}//end link()
}//end class
