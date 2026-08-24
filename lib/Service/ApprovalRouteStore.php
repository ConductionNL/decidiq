<?php

/**
 * Decidiq approval-route persistence.
 *
 * The OpenRegister reads and writes the approval-route engine needs, split out
 * of {@see \OCA\Decidiq\Service\ApprovalRouteService} so the engine holds only
 * the rules — which stage is active, what an action does to it, what is refused
 * — and not the shape of the storage layer underneath.
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
 * @spec openspec/changes/approval-routes/specs/approval-routes/spec.md
 */

declare(strict_types=1);

namespace OCA\Decidiq\Service;

use OCA\Decidiq\AppInfo\Application;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Reads and writes the objects an approval route is made of.
 *
 * @spec openspec/changes/approval-routes/specs/approval-routes/spec.md
 */
class ApprovalRouteStore {
	/**
	 * The register every schema here lives in.
	 */
	private const REGISTER = 'decidiq';

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container Service container, for OpenRegister.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Write an object.
	 *
	 * @param string $schema The schema slug.
	 * @param array<string, mixed> $object The object or patch.
	 * @param string|null $uuid The uuid when updating.
	 *
	 * @return array<string, mixed> The stored object.
	 *
	 * @throws RuntimeException When OpenRegister is unavailable.
	 */
	public function save(string $schema, array $object, ?string $uuid = null): array {
		$stored = $this->objectService()->saveObject(
			object: $object,
			register: self::REGISTER,
			schema: $schema,
			uuid: $uuid,
		);

		return $this->normalise(row: $stored);
	}//end save()

	/**
	 * Read objects.
	 *
	 * @param string $schema The schema slug.
	 * @param array<string, mixed> $filters The filters.
	 *
	 * @return array<int, array<string, mixed>> The objects.
	 *
	 * @throws RuntimeException When OpenRegister is unavailable.
	 */
	public function findAll(string $schema, array $filters): array {
		$results = $this->objectService()->findAll(
			[
				'filters' => (['register' => self::REGISTER, 'schema' => $schema] + $filters),
			]
		);
		if (is_array($results) === false) {
			return [];
		}

		$rows = [];
		foreach ($results as $row) {
			$rows[] = $this->normalise(row: $row);
		}

		return $rows;
	}//end findAll()

	/**
	 * Collapse OpenRegister's array-or-entity shape.
	 *
	 * @param mixed $row The row.
	 *
	 * @return array<string, mixed> The array form.
	 */
	private function normalise(mixed $row): array {
		if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
			$row = $row->jsonSerialize();
		}

		return (array)$row;
	}//end normalise()

	/**
	 * OpenRegister's ObjectService, resolved by name.
	 *
	 * By name and not by type-hint: decidiq must install and boot without
	 * OpenRegister, where the class does not exist to hint against.
	 *
	 * @return object The service.
	 *
	 * @throws RuntimeException When OpenRegister is unavailable.
	 */
	private function objectService(): object {
		try {
			return $this->container->get('OCA\OpenRegister\Service\ObjectService');
		} catch (Throwable $e) {
			$this->logger->error(
				'Decidiq: OpenRegister is unavailable, so no approval route can run',
				['app' => Application::APP_ID, 'exception' => $e->getMessage()]
			);
			throw new RuntimeException('OpenRegister is unavailable.');
		}
	}//end objectService()
}//end class
