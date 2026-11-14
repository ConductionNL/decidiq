<?php

/**
 * Decidiq VotingGroupResolver
 *
 * Turns a voting group into the meeting participants who may vote in a round.
 *
 * @category Service
 * @package  OCA\Decidiq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/voting-groups-chosen-when-a-round-opens/specs/voting-group-presets/spec.md#requirement-req-vgp-002-chair-can-select-a-voting-group-preset-when-opening-a-votinground
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2
declare(strict_types=1);

namespace OCA\Decidiq\Service;

use InvalidArgumentException;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use Throwable;

/**
 * A voting group's persons, as the participants of one meeting.
 *
 * A group holds person ids; a round's eligibility is a list of the meeting's
 * participant ids. A person is matched to a participant the way the rest of
 * the app matches them ({@see PersonParticipantLookup}): the Nextcloud user id
 * first, then the e-mail address. The candidates are only the meeting's own
 * participants (the active members of its body), so a person who left the
 * body matches nobody and is reported as left out (REQ-VGP-005).
 *
 * 🔴 A GROUP THAT RESOLVES TO NOBODY IS REFUSED. A round with no participant
 * relations is open to every member of the body, so an empty resolution would
 * silently widen the vote from the group to everyone. It is refused instead.
 *
 * @spec openspec/changes/voting-groups-chosen-when-a-round-opens/specs/voting-group-presets/spec.md#requirement-req-vgp-002-chair-can-select-a-voting-group-preset-when-opening-a-votinground
 */
class VotingGroupResolver {

	/**
	 * The fields a person and a participant are matched on, strongest first.
	 *
	 * @var list<string>
	 */
	private const MATCH_ORDER = ['nextcloudUserId', 'email'];

	/**
	 * Constructor.
	 *
	 * @param ParticipantResolver    $participants  The meeting's participants and body.
	 * @param ObjectServiceInterface $objectService OpenRegister's object contract, for the group and its persons.
	 *
	 * @spec openspec/changes/voting-groups-chosen-when-a-round-opens/specs/voting-group-presets/spec.md#requirement-req-vgp-002-chair-can-select-a-voting-group-preset-when-opening-a-votinground
	 */
	public function __construct(
		private readonly ParticipantResolver $participants,
		private readonly ObjectServiceInterface $objectService,
	) {
	}//end __construct()

	/**
	 * The participants of a meeting a voting group stands for, and the members it left out.
	 *
	 * @param string      $groupId   The voting group's id.
	 * @param string      $meetingId The meeting the round opens in.
	 * @param string|null $bodyId    The round's body; null means the meeting's body.
	 *
	 * @return array{participantIds: list<string>, excluded: list<array{id: string, name: string}>} The eligible participants and the left-out members.
	 *
	 * @throws InvalidArgumentException When the group does not exist, is inactive, belongs to another body, or leaves nobody to vote.
	 *
	 * @spec openspec/changes/voting-groups-chosen-when-a-round-opens/specs/voting-group-presets/spec.md#requirement-req-vgp-005-the-chair-sees-who-a-voting-group-left-out
	 */
	public function resolve(string $groupId, string $meetingId, ?string $bodyId = null): array {
		$group = $this->usableGroup(groupId: $groupId, bodyId: ($bodyId ?? $this->participants->resolveGovernanceBodyId(meetingId: $meetingId)));
		$candidates = $this->participants->resolveMeetingParticipants(meetingId: $meetingId);

		$participantIds = [];
		$excluded = [];
		foreach (self::memberIds(group: $group) as $personId) {
			$person = $this->read(id: $personId, schema: 'person');
			$matched = null;
			if ($person !== null) {
				$matched = self::participantFor(person: $person, candidates: $candidates);
			}

			if ($matched === null) {
				$excluded[] = ['id' => $personId, 'name' => self::nameOf(person: $person, fallback: $personId)];
				continue;
			}

			$participantIds[$matched] = $matched;
		}

		if ($participantIds === []) {
			throw new InvalidArgumentException('No member of this voting group is a participant of the meeting.');
		}

		return ['participantIds' => array_values($participantIds), 'excluded' => $excluded];
	}//end resolve()

	/**
	 * The group, when it exists, is active and belongs to the round's body.
	 *
	 * @param string      $groupId The group's id.
	 * @param string|null $bodyId  The round's body.
	 *
	 * @return array<string, mixed> The group.
	 *
	 * @throws InvalidArgumentException When it cannot be used for this round.
	 */
	private function usableGroup(string $groupId, ?string $bodyId): array {
		$group = $this->read(id: $groupId, schema: 'voting-group');
		if ($group === null) {
			throw new InvalidArgumentException('The voting group does not exist.');
		}

		if (($group['active'] ?? true) === false) {
			throw new InvalidArgumentException('The voting group is not active.');
		}

		if ($bodyId === null || self::refOf(value: ($group['governanceBody'] ?? null)) !== $bodyId) {
			throw new InvalidArgumentException('The voting group belongs to another body.');
		}

		return $group;
	}//end usableGroup()

	/**
	 * An object of decidiq's register as an array, or null when it cannot be read.
	 *
	 * @param string $id     The object's id.
	 * @param string $schema The schema slug.
	 *
	 * @return array<string, mixed>|null The object.
	 */
	private function read(string $id, string $schema): ?array {
		try {
			$entity = $this->objectService->find(id: $id, register: 'decidiq', schema: $schema);
		} catch (Throwable $e) {
			return null;
		}

		if (is_object($entity) === true && method_exists($entity, 'jsonSerialize') === true) {
			$entity = $entity->jsonSerialize();
		}

		if (is_array($entity) === false) {
			return null;
		}

		return $entity;
	}//end read()

	/**
	 * The person ids of a group, in order, once each.
	 *
	 * @param array<string, mixed> $group The group.
	 *
	 * @return list<string> The ids.
	 */
	private static function memberIds(array $group): array {
		$members = ($group['members'] ?? []);
		if (is_array($members) === false) {
			$members = [];
		}

		$ids = [];
		foreach ($members as $member) {
			$id = self::refOf(value: $member);
			if ($id !== null) {
				$ids[$id] = $id;
			}
		}

		return array_values($ids);
	}//end memberIds()

	/**
	 * The id of the meeting participant a person is, matched on the Nextcloud user id, then the e-mail address.
	 *
	 * @param array<string, mixed>             $person     The person.
	 * @param array<int, array<string, mixed>> $candidates The meeting's participants.
	 *
	 * @return string|null The participant id, or null when none matches.
	 */
	private static function participantFor(array $person, array $candidates): ?string {
		foreach (self::MATCH_ORDER as $field) {
			$value = strtolower(trim((string)($person[$field] ?? '')));
			if ($value === '') {
				continue;
			}

			foreach ($candidates as $participant) {
				if (strtolower(trim((string)($participant[$field] ?? ''))) === $value) {
					return self::refOf(value: $participant);
				}
			}
		}

		return null;
	}//end participantFor()

	/**
	 * What to call a person in the notice: their name, else the id.
	 *
	 * @param array<string, mixed>|null $person   The person, or null when it could not be read.
	 * @param string                    $fallback The person's id.
	 *
	 * @return string The name.
	 */
	private static function nameOf(?array $person, string $fallback): string {
		$name = trim((string)($person['name'] ?? ''));
		if ($name === '') {
			$name = trim((string)($person['givenName'] ?? '').' '.(string)($person['familyName'] ?? ''));
		}

		if ($name === '') {
			return $fallback;
		}

		return $name;
	}//end nameOf()

	/**
	 * The id a reference names: a uuid string, or an object with an id.
	 *
	 * @param mixed $value The reference.
	 *
	 * @return string|null The id.
	 */
	private static function refOf(mixed $value): ?string {
		if (is_array($value) === true) {
			$value = ($value['id'] ?? ($value['@self']['id'] ?? ($value['uuid'] ?? null)));
		}

		if (is_string($value) === true && $value !== '') {
			return $value;
		}

		return null;
	}//end refOf()
}//end class
