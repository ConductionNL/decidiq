<?php

/**
 * Decidiq voter eligibility
 *
 * Who casts a vote in a body is decided per body: the body declares whether
 * its chair has a vote (a council's chair, the mayor, chairs without one; a
 * committee or a board's chair usually votes), and staff roles such as the
 * secretary (the griffier) never vote, whatever the body says.
 *
 * @category Service
 * @package  OCA\Decidiq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/voting-system/spec.md#requirement-req-vpb-001-a-body-declares-whether-its-chair-votes-and-staff-never-vote
 */

declare(strict_types=1);

namespace OCA\Decidiq\Service;

/**
 * Decides whether a membership or participant role carries a vote in a body.
 *
 * @spec openspec/specs/voting-system/spec.md#requirement-req-vpb-001-a-body-declares-whether-its-chair-votes-and-staff-never-vote
 */
class VoterEligibility {

	/**
	 * The roles that always carry a vote.
	 *
	 * @var list<string>
	 */
	private const VOTING_ROLES = ['member', 'vice-chair', 'treasurer'];

	/**
	 * The chair's role, whose vote the body declares.
	 */
	private const CHAIR = 'chair';

	/**
	 * Whether a role holder votes in a body.
	 *
	 * A member, vice-chair or treasurer votes. The chair votes unless the body
	 * sets `chairHasVote` to false. Every other role (secretary, observer,
	 * guest, or an unknown one) never votes.
	 *
	 * @param mixed                     $role The membership or participant role
	 * @param array<string, mixed>|null $body The governance body, or null when it cannot be read
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/voting-system/spec.md#requirement-req-vpb-001-a-body-declares-whether-its-chair-votes-and-staff-never-vote
	 */
	public function mayVote(mixed $role, ?array $body): bool {
		if (in_array($role, self::VOTING_ROLES, true) === true) {
			return true;
		}

		if ($role !== self::CHAIR) {
			return false;
		}

		return ($body['chairHasVote'] ?? true) !== false;
	}//end mayVote()
}//end class
