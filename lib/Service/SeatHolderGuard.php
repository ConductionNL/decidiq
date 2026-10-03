<?php

/**
 * Decidiq Seat Holder Guard
 *
 * On the casting path, after the meeting-membership check: a member whose seat
 * a substitute holds does not vote in that meeting, and an observer or guest
 * votes only while holding a member's seat as their substitute
 * (bodies-substitute-mandate-swap, REQ-MSW-002). It sits beside RecusalGuard
 * in VoteCastingService rather than inside VoteCastGuard, which is at its
 * coupling ceiling.
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
 * @spec openspec/changes/bodies-substitute-mandate-swap/specs/meeting-attendees/spec.md#requirement-req-msw-002-while-a-substitution-is-active-the-substitute-votes-for-the-seat
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2
declare(strict_types=1);

namespace OCA\Decidiq\Service;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Who holds the seat may cast; who gave it up may not.
 *
 * @spec openspec/changes/bodies-substitute-mandate-swap/specs/meeting-attendees/spec.md#requirement-req-msw-002-while-a-substitution-is-active-the-substitute-votes-for-the-seat
 */
final class SeatHolderGuard {

	/**
	 * The roles that vote only while holding a member's seat.
	 *
	 * @var list<string>
	 */
	private const NON_VOTING_ROLES = ['observer', 'guest'];

	/**
	 * Constructor.
	 *
	 * @param AmendmentOrderService  $amendmentOrder      The meeting a round belongs to
	 * @param ParticipantResolver    $participantResolver The meeting's participants
	 * @param ObjectServiceInterface $objectService       Reads the substitutions
	 * @param LoggerInterface|null   $logger              Logs a failed read
	 *
	 * @return void
	 */
	public function __construct(
		private readonly AmendmentOrderService $amendmentOrder,
		private readonly ParticipantResolver $participantResolver,
		private readonly ObjectServiceInterface $objectService,
		private readonly ?LoggerInterface $logger=null,
	) {
	}//end __construct()

	/**
	 * Refuse a caster who gave their seat to a substitute, or a non-voting
	 * participant who holds no seat.
	 *
	 * @param array<string, mixed> $round         The open round
	 * @param string               $participantId The caster
	 *
	 * @return void
	 *
	 * @throws RuntimeException When the caster does not hold a seat in the meeting
	 *
	 * @spec openspec/changes/bodies-substitute-mandate-swap/specs/meeting-attendees/spec.md#requirement-req-msw-002-while-a-substitution-is-active-the-substitute-votes-for-the-seat
	 */
	public function assertHoldsSeat(array $round, string $participantId): void {
		$meetingId = $this->amendmentOrder->resolveMeetingIdForRound(round: $round);
		if ($meetingId === null) {
			return;
		}

		$substitutions = new SubstitutionResolver(objectService: $this->objectService, logger: $this->logger);
		if ($substitutions->isSubstitutedOut(meetingId: $meetingId, participantId: $participantId) === true) {
			throw new RuntimeException('Uw zetel wordt in deze vergadering ingenomen door uw plaatsvervanger; u kunt niet stemmen zolang de vervanging loopt');
		}

		$roles = [];
		foreach ($this->participantResolver->resolveMeetingParticipants(meetingId: $meetingId) as $row) {
			$roles[$substitutions->idOf(row: $row)] = (string)($row['role'] ?? '');
		}

		if (in_array(($roles[$participantId] ?? ''), self::NON_VOTING_ROLES, true) === true
			&& in_array($participantId, $substitutions->activeSubstitutes(meetingId: $meetingId), true) === false
		) {
			throw new RuntimeException('Deelnemer heeft geen stemrecht in deze vergadering');
		}
	}//end assertHoldsSeat()
}//end class
