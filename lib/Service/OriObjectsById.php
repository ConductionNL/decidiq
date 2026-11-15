<?php

/**
 * Decidiq ORI Objects By Id
 *
 * Reads one schema of the decidiq register for an ORI publication rule,
 * unscoped and capped at the rule's own limit, into a map keyed by object id.
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
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2
declare(strict_types=1);

namespace OCA\Decidiq\Service;

use OCA\OpenRegister\Contract\ObjectServiceInterface;

/**
 * Id-keyed read of one decidiq schema for the ORI publication rules.
 *
 * @spec openspec/specs/openregister-integration/spec.md#requirement-backend-service-access
 */
final class OriObjectsById {

	/**
	 * The register the ORI publication rules read.
	 *
	 * @var string
	 */
	private const REGISTER = 'decidiq';

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
	 * Every object of a schema, keyed by id.
	 *
	 * @param string $schema The schema slug
	 * @param int    $limit  The most objects to read
	 *
	 * @return array<string, array<string, mixed>> The objects by id
	 *
	 * @spec openspec/specs/openregister-integration/spec.md#requirement-backend-service-access
	 */
	public function all(string $schema, int $limit): array {
		$entities = $this->objectService->findAll(
			config: ['filters' => ['register' => self::REGISTER, 'schema' => $schema], 'limit' => $limit],
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
