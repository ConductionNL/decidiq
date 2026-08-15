<?php

/**
 * Decidesk Participant UUID Lookup
 *
 * Maps a Nextcloud user login (UID) onto the OpenRegister Participant object
 * UUID that the voting, proxy and evaluation flows key on.
 *
 * @category Service
 * @package  OCA\Decidesk\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/voting-system/spec.md
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2
declare(strict_types=1);

namespace OCA\Decidesk\Service;

use OCA\OpenRegister\Contract\ObjectServiceInterface;

/**
 * Nextcloud UID -> Participant UUID resolution.
 *
 * @spec openspec/specs/voting-system/spec.md
 */
class ParticipantUuidLookup {
	/**
	 * Constructor for ParticipantUuidLookup.
	 *
	 * @param ContainerInterface $container The DI container (lazy ObjectService resolution)
	 *
	 * @return void
	 *
	 * @spec openspec/specs/voting-system/spec.md
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
	) {

	}//end __construct()

	/**
	 * Resolve the OpenRegister participant UUID for a given Nextcloud user ID.
	 *
	 * Queries the participant register by nextcloudUserId field. Returns null when
	 * no matching participant object is found.
	 *
	 * @param string $nextcloudUid The Nextcloud user login name (UID)
	 *
	 * @return string|null The participant object UUID, or null if not found
	 *
	 * @spec openspec/specs/voting-system/spec.md
	 */
	public function forNextcloudUser(string $nextcloudUid): ?string {
		$this->objectService->setRegister('decidesk');
		$this->objectService->setSchema('participant');
		$entities = $this->objectService->findAll(['filters' => ['nextcloudUserId' => $nextcloudUid]]);

		foreach ($entities as $participantEntity) {
			$participant = $participantEntity->jsonSerialize();
			return ($participant['uuid'] ?? $participant['id'] ?? null);
		}

		return null;
	}//end forNextcloudUser()

	/**
	 * Resolve the participant UUID for a Nextcloud user WITHIN one governance body.
	 *
	 * A person sits on more than one body — that is the ordinary case in this
	 * domain, not an edge case — so one Nextcloud UID maps to as many
	 * Participant objects as the bodies they serve on. forNextcloudUser()
	 * returns whichever of those the store happens to hand back first, which is
	 * only ever correct by accident once a second body exists. Any flow that
	 * checks the resolved participant against a per-body roster must therefore
	 * scope the lookup to that body, or it compares an identity from body A
	 * with a roster from body B and rejects a legitimate member.
	 *
	 * OpenRegister exposes the link both as the scalar `governanceBody`
	 * property and, once materialised, under `@self.relations`; both shapes are
	 * accepted because which one is present depends on how the object was
	 * written.
	 *
	 * @param string $nextcloudUid The Nextcloud user login name (UID)
	 * @param string $governanceBodyId UUID of the governance body to scope to
	 *
	 * @return string|null The participant UUID on that body, or null when the
	 *                     user is not a participant of it
	 *
	 * @spec openspec/specs/board-self-evaluation/spec.md#requirement-req-eval-003-responses-are-anonymous-and-untraceable-to-the-member
	 */
	public function forNextcloudUserInBody(string $nextcloudUid, string $governanceBodyId): ?string {
		if ($nextcloudUid === '' || $governanceBodyId === '') {
			return null;
		}

		$this->objectService->setRegister('decidesk');
		$this->objectService->setSchema('participant');
		$entities = $this->objectService->findAll(['filters' => ['nextcloudUserId' => $nextcloudUid]]);

		foreach ($entities as $participantEntity) {
			$participant = $participantEntity->jsonSerialize();
			if ($this->belongsToBody(participant: $participant, governanceBodyId: $governanceBodyId) === false) {
				continue;
			}

			return ($participant['uuid'] ?? $participant['id'] ?? null);
		}

		return null;
	}//end forNextcloudUserInBody()

	/**
	 * Whether a serialised participant is bound to the given governance body.
	 *
	 * @param array<string, mixed> $participant The serialised Participant object
	 * @param string $governanceBodyId UUID of the governance body
	 *
	 * @return bool True when the participant belongs to that body
	 *
	 * @spec openspec/specs/board-self-evaluation/spec.md#requirement-req-eval-003-responses-are-anonymous-and-untraceable-to-the-member
	 */
	private function belongsToBody(array $participant, string $governanceBodyId): bool {
		if ((string)($participant['governanceBody'] ?? '') === $governanceBodyId) {
			return true;
		}

		$relations = (array)($participant['@self']['relations'] ?? []);

		return (string)($relations['governanceBody'] ?? '') === $governanceBodyId;
	}//end belongsToBody()
}//end class
