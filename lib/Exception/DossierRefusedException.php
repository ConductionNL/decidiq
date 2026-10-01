<?php

/**
 * Decidiq Dossier Refused Exception
 *
 * An archival dossier action the dossier's state does not allow: gathering or
 * closing a dossier that is already frozen, or closing one with gaps and no
 * reason.
 *
 * @category Exception
 * @package  OCA\Decidiq\Exception
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

namespace OCA\Decidiq\Exception;

use RuntimeException;

/**
 * A dossier action refused by the dossier's state.
 *
 * @spec openspec/changes/records-management-archiving/specs/records-management-archiving/spec.md#requirement-req-rma-001-archival-dossier-assembly
 */
class DossierRefusedException extends RuntimeException {
	/**
	 * The dossier is no longer forming: its member list is frozen.
	 */
	public const FROZEN = 'frozen';

	/**
	 * The dossier still has gaps and no reason to close it anyway was given.
	 */
	public const GAPS = 'gaps';

	/**
	 * Constructor.
	 *
	 * @param string       $message The message, already translated
	 * @param string       $reason  self::FROZEN or self::GAPS
	 * @param list<string> $gaps    The gaps that block closing
	 *
	 * @spec openspec/changes/records-management-archiving/specs/records-management-archiving/spec.md#requirement-req-rma-001-archival-dossier-assembly
	 */
	public function __construct(
		string $message,
		private readonly string $reason,
		private readonly array $gaps = [],
	) {
		parent::__construct($message);
	}//end __construct()

	/**
	 * Why the action was refused.
	 *
	 * @return string self::FROZEN or self::GAPS
	 *
	 * @spec openspec/changes/records-management-archiving/specs/records-management-archiving/spec.md#requirement-req-rma-001-archival-dossier-assembly
	 */
	public function getReason(): string {
		return $this->reason;
	}//end getReason()

	/**
	 * The gaps that block closing.
	 *
	 * @return list<string>
	 *
	 * @spec openspec/changes/records-management-archiving/specs/records-management-archiving/spec.md#requirement-req-rma-001-archival-dossier-assembly
	 */
	public function getGaps(): array {
		return $this->gaps;
	}//end getGaps()
}//end class
