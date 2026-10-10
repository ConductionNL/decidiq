<?php

/**
 * Decidiq GovernanceBodyQueryService
 *
 * Reads a governance body a consumer app raised, with its roster, for the
 * cross-app read seam (GovernanceBodyStateRequestedEvent). The read half of
 * GovernanceBodyCommandService: it reads exactly the rows that service writes.
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
 * @spec openspec/specs/governance-body-events/spec.md#requirement-req-gbe-007-a-consumer-reads-its-governance-body-back-through-a-typed-event
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Service;

/**
 * Keyed read of one governance body and its roster.
 *
 * Reads run as the acting user through RegisterObjectStore, so OpenRegister's
 * RBAC and multitenancy decide what comes back: a body the caller may not read
 * answers exactly like one that does not exist.
 *
 * @spec openspec/specs/governance-body-events/spec.md#requirement-req-gbe-007-a-consumer-reads-its-governance-body-back-through-a-typed-event
 */
class GovernanceBodyQueryService {

	private const SCHEMA_BODY = 'governance-body';

	private const SCHEMA_PERSON = 'person';

	private const SCHEMA_MEMBERSHIP = 'membership';

	/**
	 * Body fields a consumer may read back. The same set the command service
	 * accepts, plus the identity and provenance keys.
	 */
	private const BODY_FIELDS = [
		'name',
		'bodyType',
		'domain',
		'active',
		'quorum',
		'quorumRule',
		'jurisdiction',
		'statutoryBasis',
		'votingDefault',
		'termStart',
		'termEnd',
		'parentBody',
		'sourceApp',
		'externalReference',
	];

	/**
	 * Constructor.
	 *
	 * @param RegisterObjectStore $store The register object store.
	 */
	public function __construct(
		private readonly RegisterObjectStore $store,
	) {
	}//end __construct()

	/**
	 * Read one governance body the consumer raised, with its roster.
	 *
	 * Looks up by `$governanceBodyId` when given, otherwise by
	 * (`$sourceApp`, `$externalReference`). Either way a body whose
	 * `sourceApp` is not the asking app is NOT returned: the seam answers a
	 * consumer about its own committees, never about another app's.
	 *
	 * @param string $sourceApp         The asking app id.
	 * @param string $externalReference The asking app's reference, or ''.
	 * @param string $governanceBodyId  The body id, or ''.
	 *
	 * @return array<string, mixed>|null The body (id, the BODY_FIELDS it carries, and
	 *         `members`: list of {uid, name, role, external, label?, startDate?, endDate?}),
	 *         or null when there is none for this consumer.
	 *
	 * @throws \RuntimeException When OpenRegister is unavailable.
	 *
	 * @spec openspec/specs/governance-body-events/spec.md#requirement-req-gbe-007-a-consumer-reads-its-governance-body-back-through-a-typed-event
	 */
	public function lookup(string $sourceApp, string $externalReference, string $governanceBodyId): ?array {
		if ($sourceApp === '') {
			return null;
		}

		$row = $this->findBody(sourceApp: $sourceApp, externalReference: $externalReference, governanceBodyId: $governanceBodyId);
		if ($row === null || (string)($row['sourceApp'] ?? '') !== $sourceApp) {
			return null;
		}

		$bodyId = $this->idOf(row: $row);
		if ($bodyId === '') {
			return null;
		}

		$body = ['id' => $bodyId];
		foreach (self::BODY_FIELDS as $field) {
			if (array_key_exists($field, $row) === true) {
				$body[$field] = $row[$field];
			}
		}

		$body['members'] = $this->rosterOf(bodyId: $bodyId);

		return $body;

	}//end lookup()

	/**
	 * Find the body row by id or by the consumer's key.
	 *
	 * @param string $sourceApp         The asking app id.
	 * @param string $externalReference The asking app's reference, or ''.
	 * @param string $governanceBodyId  The body id, or ''.
	 *
	 * @return array<string, mixed>|null The row, or null.
	 */
	private function findBody(string $sourceApp, string $externalReference, string $governanceBodyId): ?array {
		if (trim($governanceBodyId) !== '') {
			return $this->store->find(schema: self::SCHEMA_BODY, uuid: $governanceBodyId);
		}

		if ($externalReference === '') {
			return null;
		}

		$rows = $this->store->findAll(
			schema: self::SCHEMA_BODY,
			filters: ['sourceApp' => $sourceApp, 'externalReference' => $externalReference],
		);

		// Re-checked in PHP: a filter key the store drops returns EVERY body,
		// and the first unrelated row would then be answered as this committee.
		foreach ($rows as $row) {
			if ((string)($row['sourceApp'] ?? '') === $sourceApp && (string)($row['externalReference'] ?? '') === $externalReference) {
				return $row;
			}
		}

		return null;

	}//end findBody()

	/**
	 * The roster of one body: one entry per membership, with the person's uid.
	 *
	 * @param string $bodyId The body id.
	 *
	 * @return list<array<string, mixed>> The members.
	 */
	private function rosterOf(string $bodyId): array {
		$members = [];
		foreach ($this->store->findAll(schema: self::SCHEMA_MEMBERSHIP, filters: ['governanceBody' => $bodyId]) as $membership) {
			// Re-checked for the same reason as findBody().
			if ($this->refOf(value: ($membership['governanceBody'] ?? null)) !== $bodyId) {
				continue;
			}

			$person = $this->store->find(schema: self::SCHEMA_PERSON, uuid: $this->refOf(value: ($membership['person'] ?? null)));

			$member = [
				'uid' => (string)($person['nextcloudUserId'] ?? ''),
				'name' => (string)($person['name'] ?? ''),
				'role' => (string)($membership['role'] ?? 'member'),
				'external' => (($membership['external'] ?? false) === true),
			];
			foreach (['label', 'startDate', 'endDate'] as $optional) {
				if (($membership[$optional] ?? '') !== '') {
					$member[$optional] = (string)$membership[$optional];
				}
			}

			$members[] = $member;
		}//end foreach

		return $members;

	}//end rosterOf()

	/**
	 * The id of a stored row.
	 *
	 * @param array<string, mixed> $row The row.
	 *
	 * @return string The id, or ''.
	 */
	private function idOf(array $row): string {
		return (string)($row['id'] ?? ($row['@self']['id'] ?? ''));

	}//end idOf()

	/**
	 * Reduce a relation value (bare uuid or expanded object) to its id.
	 *
	 * @param mixed $value The relation value.
	 *
	 * @return string The id, or ''.
	 */
	private function refOf(mixed $value): string {
		if (is_array($value) === true) {
			return (string)($value['id'] ?? ($value['@self']['id'] ?? ''));
		}

		return (string)$value;

	}//end refOf()
}//end class
