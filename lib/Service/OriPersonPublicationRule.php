<?php

/**
 * Decidiq ORI person publication rule
 *
 * Decides which people the public ORI API may name, and gives each the
 * fields an anonymous caller may read.
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
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/ori-api/spec.md#requirement-req-ori-007-the-public-ori-api-names-public-role-holders-only
 */

declare(strict_types=1);

namespace OCA\Decidiq\Service;

use OCA\OpenRegister\Contract\ObjectServiceInterface;

/**
 * The publication rule for ORI `persons`.
 *
 * A person is public when they hold, or held, a public role: a membership
 * with an office role (chair, vice-chair, secretary, treasurer or member, not
 * observer or guest) in a governance body that publishes its voting records.
 * That is the same body flag the vote rule reads, so every voter a public
 * vote names can be resolved to a name, and nobody else can. Everything is
 * read in system context because the rule, not the caller's rights, is the
 * gate; only the name, image and biography ever leave this class.
 *
 * @spec openspec/specs/ori-api/spec.md#requirement-req-ori-007-the-public-ori-api-names-public-role-holders-only
 */
class OriPersonPublicationRule {

	/**
	 * The register every object lives in.
	 */
	private const REGISTER = 'decidiq';

	/**
	 * How many people or memberships one collection request reads.
	 */
	private const LIMIT = 500;

	/**
	 * The membership roles that are a public office.
	 *
	 * @var list<string>
	 */
	private const PUBLIC_ROLES = ['chair', 'vice-chair', 'secretary', 'treasurer', 'member'];

	/**
	 * Constructor.
	 *
	 * @param VoteContextReader      $context       Reads one object in system context
	 * @param ObjectServiceInterface $objectService OpenRegister object service
	 *
	 * @spec openspec/specs/ori-api/spec.md#requirement-req-ori-007-the-public-ori-api-names-public-role-holders-only
	 */
	public function __construct(
		private readonly VoteContextReader $context,
		private readonly ObjectServiceInterface $objectService,
	) {
	}//end __construct()

	/**
	 * The public people.
	 *
	 * @return list<array{id: string, name: ?string, image: ?string, biography: ?string}>
	 *
	 * @spec openspec/specs/ori-api/spec.md#requirement-req-ori-007-the-public-ori-api-names-public-role-holders-only
	 */
	public function persons(): array {
		$public = $this->publicPersonIds();
		$items = [];
		foreach ($this->all(schema: 'person') as $id => $person) {
			if (isset($public[$id]) === true) {
				$items[] = self::fields(id: $id, person: $person);
			}
		}

		return $items;
	}//end persons()

	/**
	 * One public person by id, or null when the rule withholds them.
	 *
	 * @param string $personId The person id
	 *
	 * @return array{id: string, name: ?string, image: ?string, biography: ?string}|null
	 *
	 * @spec openspec/specs/ori-api/spec.md#requirement-req-ori-007-the-public-ori-api-names-public-role-holders-only
	 */
	public function person(string $personId): ?array {
		$person = $this->context->object(schema: 'person', id: $personId);
		if ($person === null || isset($this->publicPersonIds()[$personId]) === false) {
			return null;
		}

		return self::fields(id: $personId, person: $person);
	}//end person()

	/**
	 * The ids of every person with a public role, as a set.
	 *
	 * @return array<string, true>
	 */
	private function publicPersonIds(): array {
		$ids = [];
		foreach ($this->all(schema: 'membership') as $membership) {
			$personId = ($membership['person'] ?? null);
			$bodyId = ($membership['governanceBody'] ?? null);
			if (is_string($personId) === false || is_string($bodyId) === false
				|| in_array(($membership['role'] ?? null), self::PUBLIC_ROLES, true) === false
			) {
				continue;
			}

			if (($this->context->object(schema: 'governance-body', id: $bodyId)['publishVotingRecords'] ?? false) === true) {
				$ids[$personId] = true;
			}
		}

		return $ids;
	}//end publicPersonIds()

	/**
	 * The allow-listed fields of a person.
	 *
	 * @param string               $id     The person id
	 * @param array<string, mixed> $person The person
	 *
	 * @return array{id: string, name: ?string, image: ?string, biography: ?string}
	 */
	private static function fields(string $id, array $person): array {
		$fields = ['id' => $id];
		foreach (['name', 'image', 'biography'] as $key) {
			$value = ($person[$key] ?? null);
			if (is_string($value) === false || $value === '') {
				$value = null;
			}

			$fields[$key] = $value;
		}

		return $fields;
	}//end fields()

	/**
	 * Objects of one schema, read in system context, by id.
	 *
	 * @param string $schema The schema slug
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function all(string $schema): array {
		$entities = $this->objectService->findAll(
			config: ['filters' => ['register' => self::REGISTER, 'schema' => $schema], 'limit' => self::LIMIT],
			_rbac: false,
			_multitenancy: false
		);

		$objects = [];
		foreach ($entities as $entity) {
			$object = $entity->jsonSerialize();
			$id = ($object['id'] ?? ($object['@self']['id'] ?? ($object['uuid'] ?? null)));
			if (is_string($id) === true && $id !== '') {
				$objects[$id] = $object;
			}
		}

		return $objects;
	}//end all()
}//end class
