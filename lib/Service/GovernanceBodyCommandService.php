<?php

/**
 * Decidiq governance-body command engine.
 *
 * Applies a cross-app command to raise or update a GovernanceBody with its
 * roster. The engine that sits behind GovernanceBodyRequestedListener, kept
 * separate from it so the rules — what is resolved before what is written, what
 * is refused — are testable without a dispatcher.
 *
 * @category Service
 * @package  OCA\Decidiq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/governance-body-events/spec.md
 */

declare(strict_types=1);

namespace OCA\Decidiq\Service;

use RuntimeException;

/**
 * Idempotent upsert of a GovernanceBody plus its Person/Membership roster.
 *
 * Every write in here is preceded by a resolve. That is the whole design: a
 * migration in the producing app is re-runnable by construction, and a partial
 * run is completed by the next one rather than duplicated by it.
 *
 * @spec openspec/specs/governance-body-events/spec.md
 */
class GovernanceBodyCommandService {

	/**
	 * Schema slug of the body.
	 */
	private const SCHEMA_BODY = 'governance-body';

	/**
	 * Schema slug of a natural person.
	 */
	private const SCHEMA_PERSON = 'person';

	/**
	 * Schema slug of a person's seat on a body.
	 */
	private const SCHEMA_MEMBERSHIP = 'membership';

	/**
	 * Body fields a command may set beyond the five it states explicitly.
	 *
	 * A closed list, not a passthrough: an open merge would let a producing app
	 * write `sourceApp` or `externalReference` itself and break the key this
	 * service resolves on.
	 *
	 * @var list<string>
	 */
	private const ALLOWED_ATTRIBUTES = [
		'quorum',
		'quorumRule',
		'jurisdiction',
		'statutoryBasis',
		'votingDefault',
		'termStart',
		'termEnd',
		'parentBody',
		'workflowTemplate',
	];

	/**
	 * Membership roles this seam accepts, matching Membership.role.
	 *
	 * @var list<string>
	 */
	private const ALLOWED_ROLES = [
		'chair',
		'vice-chair',
		'secretary',
		'treasurer',
		'member',
		'observer',
	];

	/**
	 * Constructor.
	 *
	 * @param RegisterObjectStore $store Reads and writes decidiq's register objects.
	 */
	public function __construct(
		private readonly RegisterObjectStore $store,
	) {
	}//end __construct()

	/**
	 * Raise or update a governance body and its roster.
	 *
	 * @param string $sourceApp App id of the producer.
	 * @param string $externalReference The producer's own id for the originating record.
	 * @param array<string, mixed> $body Body fields; MUST carry name, bodyType, domain and active.
	 * @param array<int, array<string, mixed>> $members Roster entries of {uid, role, external, label}.
	 *
	 * @return array{id: string, created: bool} The resolved id and whether it was minted here.
	 *
	 * @throws RuntimeException When the command is incomplete or a write fails.
	 *
	 * @spec openspec/specs/governance-body-events/spec.md
	 */
	public function upsert(
		string $sourceApp,
		string $externalReference,
		array $body,
		array $members = [],
	): array {
		$provenance = self::provenanceOf(sourceApp: $sourceApp, externalReference: $externalReference);
		$payload = $this->buildBodyPayload(provenance: $provenance, body: $body);
		$existing = $this->findBody(provenance: $provenance);

		// The body is saved and its id read BEFORE the first membership write.
		// A membership written against an unsaved body points at nothing, and a
		// crash between the two then leaves orphans instead of a body the next
		// run can complete (REQ-GBE-004).
		$result = $this->saveBody(payload: $payload, existing: $existing);
		$this->syncRoster(bodyId: $result['id'], members: $members);

		return $result;

	}//end upsert()

	/**
	 * The provenance pair a command resolves on, refused when either half is empty.
	 *
	 * @param string $sourceApp App id of the producer.
	 * @param string $externalReference The producer's own reference.
	 *
	 * @return BodyProvenance The pair.
	 *
	 * @throws RuntimeException When either half is empty.
	 */
	private static function provenanceOf(string $sourceApp, string $externalReference): BodyProvenance {
		if ($sourceApp === '' || $externalReference === '') {
			throw new RuntimeException(
				'A governance-body command needs both sourceApp and externalReference: they are the key a re-run resolves on'
			);
		}

		return new BodyProvenance(sourceApp: $sourceApp, externalReference: $externalReference);

	}//end provenanceOf()

	/**
	 * Save the body over the existing row, if any, and read its id.
	 *
	 * @param array<string, mixed> $payload The body object to store.
	 * @param array<string, mixed>|null $existing The row it replaces, or null for a new body.
	 *
	 * @return array{id: string, created: bool} The stored id and whether it was minted here.
	 *
	 * @throws RuntimeException When the store returns no id.
	 */
	private function saveBody(array $payload, ?array $existing): array {
		$stored = $this->store->save(
			schema: self::SCHEMA_BODY,
			object: $payload,
			uuid: $this->idOf(row: ($existing ?? [])),
		);

		$bodyId = $this->idOf(row: $stored);
		if ($bodyId === null) {
			throw new RuntimeException('OpenRegister returned a governance body with no id');
		}

		return [
			'id' => $bodyId,
			'created' => ($existing === null),
		];

	}//end saveBody()

	/**
	 * Build the body object to store.
	 *
	 * @param BodyProvenance $provenance The producer and its own reference.
	 * @param array<string, mixed> $body The commanded body fields.
	 *
	 * @return array<string, mixed> The payload.
	 *
	 * @throws RuntimeException When a required field is missing.
	 */
	private function buildBodyPayload(BodyProvenance $provenance, array $body): array {
		self::assertRequiredFields(body: $body);
		self::assertActiveIsStated(body: $body);

		$payload = [
			'name' => (string)$body['name'],
			'bodyType' => (string)$body['bodyType'],
			'domain' => (string)$body['domain'],
			'active' => $body['active'],
			'sourceApp' => $provenance->sourceApp,
			'externalReference' => $provenance->externalReference,
		];

		return $payload + self::allowedAttributesOf(body: $body);

	}//end buildBodyPayload()

	/**
	 * Refuse a body without a name, a type or a domain.
	 *
	 * @param array<string, mixed> $body The commanded body fields.
	 *
	 * @return void
	 *
	 * @throws RuntimeException Naming the first missing field.
	 */
	private static function assertRequiredFields(array $body): void {
		foreach (['name', 'bodyType', 'domain'] as $required) {
			if (($body[$required] ?? '') === '') {
				throw new RuntimeException('A governance-body command needs a ' . $required);
			}
		}

	}//end assertRequiredFields()

	/**
	 * Refuse a body that does not state `active` as a boolean.
	 *
	 * @param array<string, mixed> $body The commanded body fields.
	 *
	 * @return void
	 *
	 * @throws RuntimeException When `active` is absent or not a boolean.
	 */
	private static function assertActiveIsStated(array $body): void {
		// `active` decides whether the body may be assigned new work, and the
		// consuming app throws on it. Defaulting an absent value to true would
		// route objections to a disbanded committee and nothing would error, so
		// an omitted `active` is refused instead (REQ-GBE-005).
		if (array_key_exists('active', $body) === false || is_bool($body['active']) === false) {
			throw new RuntimeException(
				'A governance-body command must state `active` as a boolean: it is never defaulted'
			);
		}

	}//end assertActiveIsStated()

	/**
	 * The allowed attributes the command sets to a value, in the closed list's order.
	 *
	 * @param array<string, mixed> $body The commanded body fields.
	 *
	 * @return array<string, mixed> The attributes; null and empty values are left out.
	 */
	private static function allowedAttributesOf(array $body): array {
		$attributes = [];
		foreach (self::ALLOWED_ATTRIBUTES as $key) {
			if (array_key_exists($key, $body) === true && $body[$key] !== null && $body[$key] !== '') {
				$attributes[$key] = $body[$key];
			}
		}

		return $attributes;

	}//end allowedAttributesOf()

	/**
	 * Resolve an existing body by its provenance pair.
	 *
	 * @param BodyProvenance $provenance The producer and its own reference.
	 *
	 * @return array<string, mixed>|null The row, or null when none matches.
	 */
	private function findBody(BodyProvenance $provenance): ?array {
		$rows = $this->store->findAll(
			schema: self::SCHEMA_BODY,
			filters: [
				'sourceApp' => $provenance->sourceApp,
				'externalReference' => $provenance->externalReference,
			],
		);

		// The filter is re-checked in PHP rather than trusted. A filter key the
		// store does not recognise is dropped rather than refused, and a dropped
		// filter returns EVERY body — which would make this method match the
		// first unrelated row and silently overwrite it.
		foreach ($rows as $row) {
			if ($provenance->isProvenanceOf(row: $row) === true) {
				return $row;
			}
		}

		return null;

	}//end findBody()

	/**
	 * Create or update one seat per roster entry.
	 *
	 * @param string $bodyId The stored body's id.
	 * @param array<int, array<string, mixed>> $members The roster.
	 *
	 * @return void
	 *
	 * @throws RuntimeException When an entry is unusable.
	 */
	private function syncRoster(string $bodyId, array $members): void {
		foreach ($members as $member) {
			$uid = self::uidOf(member: $member);
			$role = self::roleOf(member: $member);
			$seat = new RosterSeat(bodyId: $bodyId, personId: $this->resolvePerson(uid: $uid, member: $member));
			$seat->role = $role;
			$this->upsertMembership(seat: $seat, member: $member);
		}

	}//end syncRoster()

	/**
	 * The roster entry's uid, trimmed.
	 *
	 * @param array<string, mixed> $member The roster entry.
	 *
	 * @return string The uid.
	 *
	 * @throws RuntimeException When the entry has no uid.
	 */
	private static function uidOf(array $member): string {
		$uid = trim((string)($member['uid'] ?? ''));
		if ($uid === '') {
			throw new RuntimeException('A roster entry needs a uid');
		}

		return $uid;

	}//end uidOf()

	/**
	 * The roster entry's role, member when it names none.
	 *
	 * @param array<string, mixed> $member The roster entry.
	 *
	 * @return string The role.
	 *
	 * @throws RuntimeException When the role is not a membership role.
	 */
	private static function roleOf(array $member): string {
		$role = (string)($member['role'] ?? 'member');
		if (in_array($role, self::ALLOWED_ROLES, true) === false) {
			throw new RuntimeException('Unknown membership role: ' . $role);
		}

		return $role;

	}//end roleOf()

	/**
	 * Find the Person for a Nextcloud uid, creating one only when absent.
	 *
	 * @param string $uid The Nextcloud user id.
	 * @param array<string, mixed> $member The roster entry.
	 *
	 * @return string The person's id.
	 *
	 * @throws RuntimeException When the person cannot be stored.
	 */
	private function resolvePerson(string $uid, array $member): string {
		$id = $this->findPersonId(uid: $uid);
		if ($id !== null) {
			return $id;
		}

		return $this->createPerson(uid: $uid, member: $member);

	}//end resolvePerson()

	/**
	 * The id of the first stored Person carrying this uid.
	 *
	 * @param string $uid The Nextcloud user id.
	 *
	 * @return string|null The id, or null when no person with an id carries it.
	 */
	private function findPersonId(string $uid): ?string {
		$rows = $this->store->findAll(
			schema: self::SCHEMA_PERSON,
			filters: ['nextcloudUserId' => $uid],
		);

		foreach ($rows as $row) {
			$id = $this->personIdCarrying(row: $row, uid: $uid);
			if ($id !== null) {
				return $id;
			}
		}

		return null;

	}//end findPersonId()

	/**
	 * A person row's id, when the row carries this uid.
	 *
	 * @param array<string, mixed> $row The person row.
	 * @param string $uid The Nextcloud user id.
	 *
	 * @return string|null The id, or null when the row is somebody else's or has no id.
	 */
	private function personIdCarrying(array $row, string $uid): ?string {
		if ((string)($row['nextcloudUserId'] ?? '') !== $uid) {
			return null;
		}

		return $this->idOf(row: $row);

	}//end personIdCarrying()

	/**
	 * Store a new Person for a uid.
	 *
	 * @param string $uid The Nextcloud user id.
	 * @param array<string, mixed> $member The roster entry.
	 *
	 * @return string The new person's id.
	 *
	 * @throws RuntimeException When the store returns no id.
	 */
	private function createPerson(string $uid, array $member): string {
		$stored = $this->store->save(
			schema: self::SCHEMA_PERSON,
			object: [
				'name' => self::personNameOf(uid: $uid, member: $member),
				'nextcloudUserId' => $uid,
			],
		);

		$id = $this->idOf(row: $stored);
		if ($id === null) {
			throw new RuntimeException('OpenRegister returned a person with no id');
		}

		return $id;

	}//end createPerson()

	/**
	 * The name a new Person gets: the entry's name, or the uid when it has none.
	 *
	 * @param string $uid The Nextcloud user id.
	 * @param array<string, mixed> $member The roster entry.
	 *
	 * @return string The name.
	 */
	private static function personNameOf(string $uid, array $member): string {
		$name = trim((string)($member['name'] ?? ''));
		if ($name === '') {
			return $uid;
		}

		return $name;

	}//end personNameOf()

	/**
	 * Create or update the one seat this person holds on this body.
	 *
	 * @param RosterSeat $seat The body, the person and the role.
	 * @param array<string, mixed> $member The roster entry.
	 *
	 * @return void
	 */
	private function upsertMembership(RosterSeat $seat, array $member): void {
		$existingId = $this->findMembershipId(seat: $seat);

		$this->store->save(
			schema: self::SCHEMA_MEMBERSHIP,
			object: self::membershipObject(seat: $seat, member: $member),
			uuid: $existingId,
		);

	}//end upsertMembership()

	/**
	 * The id of the membership already filling this seat.
	 *
	 * @param RosterSeat $seat The body and the person.
	 *
	 * @return string|null The id, or null when the seat is new.
	 */
	private function findMembershipId(RosterSeat $seat): ?string {
		$rows = $this->store->findAll(
			schema: self::SCHEMA_MEMBERSHIP,
			filters: [
				'governanceBody' => $seat->bodyId,
				'person' => $seat->personId,
			],
		);

		foreach ($rows as $row) {
			if ($this->fillsSeat(row: $row, seat: $seat) === true) {
				return $this->idOf(row: $row);
			}
		}

		return null;

	}//end findMembershipId()

	/**
	 * Whether a membership row points at this seat's body and person.
	 *
	 * @param array<string, mixed> $row The membership row.
	 * @param RosterSeat $seat The seat.
	 *
	 * @return bool True when both relations match.
	 */
	private function fillsSeat(array $row, RosterSeat $seat): bool {
		$sameBody = ($this->refOf(value: ($row['governanceBody'] ?? null)) === $seat->bodyId);
		$samePerson = ($this->refOf(value: ($row['person'] ?? null)) === $seat->personId);

		return $sameBody === true && $samePerson === true;

	}//end fillsSeat()

	/**
	 * The membership object to store for a seat.
	 *
	 * @param RosterSeat $seat The body, the person and the role.
	 * @param array<string, mixed> $member The roster entry.
	 *
	 * @return array<string, mixed> The object.
	 */
	private static function membershipObject(RosterSeat $seat, array $member): array {
		$object = [
			'governanceBody' => $seat->bodyId,
			'person' => $seat->personId,
			'role' => $seat->role,
			// Awb 7:13(2): the secretary sits from outside the administrative
			// organ. The producer states it; an absent value reads as false
			// because "not declared external" is the ordinary case.
			'external' => (bool)($member['external'] ?? false),
		];

		$label = trim((string)($member['label'] ?? ''));
		if ($label !== '') {
			$object['label'] = $label;
		}

		return $object;

	}//end membershipObject()

	/**
	 * Read an object's id out of either shape OpenRegister returns.
	 *
	 * @param array<string, mixed> $row The row.
	 *
	 * @return string|null The id, or null when absent.
	 */
	private function idOf(array $row): ?string {
		$id = (string)($row['id'] ?? ($row['@self']['id'] ?? ''));
		if ($id === '') {
			return null;
		}

		return $id;

	}//end idOf()

	/**
	 * Reduce a relation value to the id it points at.
	 *
	 * OpenRegister returns a relation as a bare uuid string, or as the expanded
	 * object when the read inlined it. Comparing the raw value would miss the
	 * expanded form and mint a second membership on every re-run.
	 *
	 * @param mixed $value The relation value.
	 *
	 * @return string The id, or an empty string.
	 */
	private function refOf(mixed $value): string {
		if (is_array($value) === true) {
			return (string)($value['id'] ?? ($value['@self']['id'] ?? ''));
		}

		return (string)$value;

	}//end refOf()

}//end class
