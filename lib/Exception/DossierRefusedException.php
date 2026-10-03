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
	 * Only a closed dossier goes to the archive or to destruction.
	 */
	public const NOT_CLOSED = 'not-closed';

	/**
	 * The dossier already sits on a transfer or destruction list.
	 */
	public const ALREADY_PROPOSED = 'already-proposed';

	/**
	 * OpenRegister has no e-depot transport configured.
	 */
	public const TRANSFER_UNAVAILABLE = 'transfer-unavailable';

	/**
	 * OpenRegister has no destruction-list register configured.
	 */
	public const DESTRUCTION_UNAVAILABLE = 'destruction-unavailable';

	/**
	 * OpenRegister found none of the records eligible for destruction.
	 */
	public const NOTHING_ELIGIBLE = 'nothing-eligible';

	/**
	 * The dossier schema names no Selectielijst category the register ships.
	 */
	public const NO_CATEGORY = 'no-category';

	/**
	 * OpenRegister holds no destruction certificate for the dossier's list yet.
	 */
	public const CERTIFICATE_MISSING = 'certificate-missing';

	/**
	 * The reasons that are a conflict with the dossier's or OpenRegister's
	 * state (409) rather than a request that cannot be met (422).
	 */
	private const CONFLICTS = [
		self::FROZEN,
		self::NOT_CLOSED,
		self::ALREADY_PROPOSED,
		self::TRANSFER_UNAVAILABLE,
		self::DESTRUCTION_UNAVAILABLE,
		self::CERTIFICATE_MISSING,
	];

	/**
	 * Constructor.
	 *
	 * @param string       $message The message, already translated
	 * @param string       $reason  One of the reason constants
	 * @param list<string> $gaps    The gaps that block closing, or the records OpenRegister refused as "uuid: reason"
	 *
	 * @spec openspec/changes/records-management-archiving/specs/records-management-archiving/spec.md#requirement-req-rma-001-archival-dossier-assembly
	 */
	public function __construct(
		string $message,
		private readonly string $reason,
		private readonly array $gaps = [],
	) {
		parent::__construct(message: $message);
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

	/**
	 * Whether the refusal is a conflict with state rather than a request that
	 * cannot be met.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/records-management-archiving/specs/records-management-archiving/spec.md#requirement-req-rma-004-transfer-via-openregister-transfer-lists-and-e-depot
	 */
	public function isConflict(): bool {
		return in_array($this->reason, self::CONFLICTS, true);
	}//end isConflict()
}//end class
