<?php

/**
 * Decidiq Meeting Rule Source
 *
 * Reads the rules a voting round takes from its meeting's type and body.
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
 * @spec openspec/specs/meeting-management/spec.md#requirement-req-mrb-002-votes-follow-the-body-rules
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2
declare(strict_types=1);

namespace OCA\Decidiq\Service;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use Throwable;

/**
 * The meeting's governance body, its type's vote threshold, and whether its
 * members reach the quorum (BodyQuorum over the body's participants).
 *
 * Every lookup fails soft to "not set": a missing body or type never blocks
 * a round, and a missing meeting fails the quorum closed.
 *
 * @spec openspec/specs/meeting-management/spec.md#requirement-req-mrb-002-votes-follow-the-body-rules
 */
final class MeetingRuleSource {

	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface $objectService       Reads meeting, type and body
	 * @param ParticipantResolver    $participantResolver Resolves the meeting's body and participants
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		private readonly ParticipantResolver $participantResolver,
	) {
	}//end __construct()

	/**
	 * Whether the members present reach the meeting's quorum.
	 *
	 * @param string $meetingId The meeting
	 *
	 * @return bool False when the meeting cannot be read
	 *
	 * @spec openspec/specs/meeting-management/spec.md#requirement-req-mrb-002-votes-follow-the-body-rules
	 */
	public function quorumMet(string $meetingId): bool {
		$entity = $this->objectService->find(id: $meetingId, register: 'decidiq', schema: 'meeting');
		if ($entity === null) {
			return false;
		}

		return (new BodyQuorum())->isMet(
			meeting: (array)$entity->jsonSerialize(),
			body: $this->loadBody(bodyId: $this->bodyIdOf(meetingId: $meetingId)),
			participants: $this->participantResolver->resolveMeetingParticipants(meetingId: $meetingId)
		);

	}//end quorumMet()

	/**
	 * The governance body of the meeting, or null (fails soft).
	 *
	 * @param string $meetingId The meeting
	 *
	 * @return string|null
	 *
	 * @spec openspec/specs/meeting-management/spec.md#requirement-req-mrb-002-votes-follow-the-body-rules
	 */
	public function bodyIdOf(string $meetingId): ?string {
		if ($meetingId === '') {
			return null;
		}

		try {
			return $this->participantResolver->resolveGovernanceBodyId(meetingId: $meetingId);
		} catch (Throwable) {
			return null;
		}

	}//end bodyIdOf()

	/**
	 * The vote threshold the meeting's type sets, or null (fails soft). It
	 * comes after the chair's own pick and before the body's template.
	 *
	 * @param string $meetingId The meeting
	 *
	 * @return string|null
	 *
	 * @spec openspec/specs/meeting-management/spec.md#requirement-req-mrb-002-votes-follow-the-body-rules
	 */
	public function meetingTypeThreshold(string $meetingId): ?string {
		try {
			$meeting = (array)$this->objectService->find(id: $meetingId, register: 'decidiq', schema: 'meeting')?->jsonSerialize();
			$typeId = ($meeting['type'] ?? null);
			if (is_array($typeId) === true) {
				$typeId = ($typeId['id'] ?? null);
			}

			if (is_string($typeId) === false || $typeId === '') {
				return null;
			}

			$type = (array)$this->objectService->find(id: $typeId, register: 'decidiq', schema: 'meeting-type')?->jsonSerialize();
		} catch (Throwable) {
			return null;
		}

		$threshold = ($type['defaultVoteThreshold'] ?? null);
		if (is_string($threshold) === false || $threshold === '') {
			return null;
		}

		return $threshold;

	}//end meetingTypeThreshold()

	/**
	 * The governance body as an array, or null (fails soft).
	 *
	 * @param string|null $bodyId The body
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/specs/meeting-management/spec.md#requirement-req-mrb-002-votes-follow-the-body-rules
	 */
	private function loadBody(?string $bodyId): ?array {
		if ($bodyId === null) {
			return null;
		}

		try {
			$entity = $this->objectService->find(id: $bodyId, register: 'decidiq', schema: 'governance-body');
		} catch (Throwable) {
			return null;
		}

		if ($entity === null) {
			return null;
		}

		return (array)$entity->jsonSerialize();

	}//end loadBody()
}//end class
