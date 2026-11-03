<?php

/**
 * Decidiq Conflict Declaration
 *
 * A conflict-of-interest declaration as a member records it: the Membership,
 * the agenda item, the kind of conflict, the rationale, how serious it is,
 * and whether the member keeps herself out of the vote at once.
 *
 * @category Service
 * @package  OCA\Decidiq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/conflict-of-interest/spec.md
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2
declare(strict_types=1);

namespace OCA\Decidiq\Service;

/**
 * An immutable conflict-of-interest declaration.
 *
 * The severity and the initial action are set with assessed(); left unset
 * the declaration is material and asks no action.
 *
 * @spec openspec/specs/conflict-of-interest/spec.md
 */
final class ConflictDeclaration {

	/**
	 * 'material' or 'non-material'.
	 *
	 * @var string
	 */
	private string $severity = 'material';

	/**
	 * The action the declaration records: 'recused-from-vote' when the member
	 * keeps herself out of the vote, 'no-action-needed' otherwise.
	 *
	 * @var string
	 */
	private string $actionTaken = 'no-action-needed';

	/**
	 * Constructor for a ConflictDeclaration.
	 *
	 * @param string $membershipId UUID of the declaring member's Membership
	 * @param string $agendaItemId UUID of the agenda item
	 * @param string $type         One of ConflictOfInterestService::DECLARATION_TYPES
	 * @param string $description  Free-text rationale
	 *
	 * @return void
	 *
	 * @spec openspec/specs/conflict-of-interest/spec.md#requirement-req-coir-001-declare-a-conflict-of-interest-from-the-page
	 */
	public function __construct(
		public readonly string $membershipId,
		public readonly string $agendaItemId,
		public readonly string $type,
		public readonly string $description,
	) {
	}//end __construct()

	/**
	 * The same declaration with its severity and initial action. A member who
	 * declares may keep herself out of the vote at once; any other action is
	 * the chair's to record later (bod-10).
	 *
	 * @param string $severity      'material' or 'non-material'
	 * @param string $initialAction 'recused-from-vote', or anything else for no action
	 *
	 * @return self The assessed declaration.
	 *
	 * @spec openspec/specs/conflict-of-interest/spec.md#requirement-req-coir-001-declare-a-conflict-of-interest-from-the-page
	 */
	public function assessed(string $severity, string $initialAction): self {
		$declaration = clone $this;
		$declaration->severity = $severity;
		$declaration->actionTaken = 'no-action-needed';
		if ($initialAction === 'recused-from-vote') {
			$declaration->actionTaken = 'recused-from-vote';
		}

		return $declaration;
	}//end assessed()

	/**
	 * The severity.
	 *
	 * @return string 'material' or 'non-material' (or the unchecked value asked for).
	 *
	 * @spec openspec/specs/conflict-of-interest/spec.md
	 */
	public function severity(): string {
		return $this->severity;
	}//end severity()

	/**
	 * The action the declaration records.
	 *
	 * @return string 'recused-from-vote' or 'no-action-needed'.
	 *
	 * @spec openspec/specs/conflict-of-interest/spec.md#requirement-req-coir-002-a-recused-member-cannot-vote-on-the-matter
	 */
	public function actionTaken(): string {
		return $this->actionTaken;
	}//end actionTaken()
}//end class
