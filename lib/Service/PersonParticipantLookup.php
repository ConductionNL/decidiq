<?php

/**
 * Decidiq Person Participant Lookup
 *
 * Finds the participants that stand for a person, read-only: on the Nextcloud
 * user id first and only when that finds none on the email address. Unlike
 * ParticipantToPersonMembershipResolver, which is a migration step and creates
 * what it cannot find, this never writes.
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
 * @spec openspec/specs/person-and-membership/spec.md#requirement-req-mpr-004-the-profile-shows-the-members-voting-record
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Service;

use OCA\OpenRegister\Contract\ObjectServiceInterface;

/**
 * Read-only person to participant lookup.
 *
 * @spec openspec/specs/person-and-membership/spec.md#requirement-req-mpr-004-the-profile-shows-the-members-voting-record
 */
class PersonParticipantLookup {

	/**
	 * The person fields to match on, strongest first.
	 *
	 * @var list<string>
	 */
	private const MATCH_ORDER = ['nextcloudUserId', 'email'];

	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface $objectService OpenRegister object service
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
	) {
	}//end __construct()

	/**
	 * The ids of the participants that stand for a person.
	 *
	 * @param array<string, mixed> $person The person object
	 *
	 * @return list<string> Participant ids, empty when nothing matches
	 *
	 * @spec openspec/specs/person-and-membership/spec.md#requirement-req-mpr-004-the-profile-shows-the-members-voting-record
	 */
	public function participantIdsOf(array $person): array {
		foreach (self::MATCH_ORDER as $field) {
			$value = trim((string)($person[$field] ?? ''));
			if ($value === '') {
				continue;
			}

			$ids = $this->participantIdsWhere(field: $field, value: $value);
			if ($ids !== []) {
				return $ids;
			}
		}

		return [];
	}//end participantIdsOf()

	/**
	 * The person a participant stands for, matched the same way round: the
	 * Nextcloud user id first, then the email address.
	 *
	 * @param array<string, mixed> $participant The participant object
	 *
	 * @return string|null The person id, or null when no person matches
	 *
	 * @spec openspec/specs/ori-api/spec.md#requirement-req-mpr-006-the-public-ori-api-returns-public-votes-with-their-voter
	 */
	public function personIdOf(array $participant): ?string {
		foreach (self::MATCH_ORDER as $field) {
			$value = trim((string)($participant[$field] ?? ''));
			if ($value === '') {
				continue;
			}

			$people = $this->objectService->findAll(
				config: ['filters' => ['register' => 'decidiq', 'schema' => 'person', $field => $value], 'limit' => 1],
				_rbac: false,
				_multitenancy: false
			);
			foreach ($people as $entity) {
				$id = self::idOf(object: $entity->jsonSerialize());
				if ($id !== null) {
					return $id;
				}
			}
		}//end foreach

		return null;
	}//end personIdOf()

	/**
	 * The ids of the participants whose field has this value.
	 *
	 * @param string $field The participant field
	 * @param string $value The value to match
	 *
	 * @return list<string>
	 */
	private function participantIdsWhere(string $field, string $value): array {
		$entities = $this->objectService->findAll(
			config: ['filters' => ['register' => 'decidiq', 'schema' => 'participant', $field => $value], 'limit' => 50],
			_rbac: false,
			_multitenancy: false
		);

		$ids = [];
		foreach ($entities as $entity) {
			$id = self::idOf(object: $entity->jsonSerialize());
			if ($id !== null) {
				$ids[] = $id;
			}
		}

		return $ids;
	}//end participantIdsWhere()

	/**
	 * The id of a serialised object, wherever OpenRegister put it.
	 *
	 * @param array<string, mixed> $object The serialised object
	 *
	 * @return string|null
	 */
	private static function idOf(array $object): ?string {
		$id = ($object['id'] ?? ($object['@self']['id'] ?? ($object['uuid'] ?? null)));
		if (is_string($id) === true && $id !== '') {
			return $id;
		}

		return null;
	}//end idOf()
}//end class
