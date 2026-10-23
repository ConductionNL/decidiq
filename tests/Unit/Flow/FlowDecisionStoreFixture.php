<?php

/**
 * A REAL FlowDecisionService over the REAL decidiq services, with only
 * OpenRegister's object store faked.
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\Flow
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/flow-request-decision-node/specs/flow-request-decision/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Flow;

use OCA\Decidiq\Service\AuditLogService;
use OCA\Decidiq\Service\DecisionIntegrationAuthorizationGuard;
use OCA\Decidiq\Service\DecisionIntegrationService;
use OCA\Decidiq\Service\DecisionTypeRegistry;
use OCA\Decidiq\Service\DelegatedDecisionDefaults;
use OCA\Decidiq\Service\FlowDecisionService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IAppConfig;
use OCP\IL10N;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * The fake store answers the way live OpenRegister does, and no more:
 *
 * - `saveObject()` stamps `@self.owner` from the identity the save runs as
 *   (in production the engine runs every node under the run's `runAs`), and
 *   `@self.id` from a generated uuid;
 * - `find()` resolves only by uuid and only under `decidiq`/`decision`;
 * - `findAll()` matches property filters on `decidiq`/`decision` (the
 *   idempotency lookup) and returns no signature stages.
 *
 * Nothing here derives a status or decides who may read: that is the real
 * `DecisionIntegrationService` and the real guard.
 */
trait FlowDecisionStoreFixture {

	/**
	 * Stored decisions by uuid.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $storedDecisions = [];

	/**
	 * The identity a save runs as, which OpenRegister stamps as the owner.
	 *
	 * @var string
	 */
	private string $savingAs = 'alice';

	/**
	 * Whether OpenRegister is reachable.
	 *
	 * @var bool
	 */
	private bool $storeReachable = true;

	/**
	 * Whether a save fails.
	 *
	 * @var bool
	 */
	private bool $saveFails = false;

	/**
	 * Build the real service stack over the fake store.
	 *
	 * @return FlowDecisionService The service under test
	 */
	private function realFlowDecisionService(): FlowDecisionService {
		$this->storedDecisions = [];
		$this->savingAs = 'alice';
		$this->storeReachable = true;
		$this->saveFails = false;

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id): object {
				if ($id !== 'OCA\\OpenRegister\\Service\\ObjectService' || $this->storeReachable === false) {
					throw new RuntimeException('OpenRegister is not available');
				}

				return $this->fakeObjectService();
			}
		);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(
			static fn (string $text, array $params = []): string => vsprintf($text, $params)
		);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueArray')->willReturn([]);

		$integration = new DecisionIntegrationService(
			container: $container,
			logger: new NullLogger(),
			auditLog: $this->createMock(AuditLogService::class),
			decisionDefaults: new DelegatedDecisionDefaults(l10n: $l10n),
			typeRegistry: new DecisionTypeRegistry(appConfig: $appConfig),
		);

		return new FlowDecisionService(
			integrationService: $integration,
			authorizationGuard: new DecisionIntegrationAuthorizationGuard(container: $container, logger: new NullLogger()),
			logger: new NullLogger(),
		);
	}//end realFlowDecisionService()

	/**
	 * Conclude a stored decision the way the lifecycle writes it.
	 *
	 * @param string $uuid The decision
	 * @param string $lifecycle The lifecycle state
	 * @param string $outcome The outcome
	 *
	 * @return void
	 */
	private function concludeStored(string $uuid, string $lifecycle, string $outcome = 'adopted'): void {
		$this->storedDecisions[$uuid]['lifecycle'] = $lifecycle;
		$this->storedDecisions[$uuid]['outcome'] = $outcome;
		$this->storedDecisions[$uuid]['decisionDate'] = '2026-09-28T10:00:00+00:00';
	}//end concludeStored()

	/**
	 * The fake OpenRegister object store.
	 *
	 * @return ObjectService The store
	 */
	private function fakeObjectService(): ObjectService {
		$service = $this->createMock(ObjectService::class);

		$service->method('saveObject')->willReturnCallback(
			function (array|ObjectEntity $object, ?array $_extend = [], mixed $register = null, mixed $schema = null): ObjectEntity {
				if ($this->saveFails === true || (string)$register !== 'decidiq' || (string)$schema !== 'decision') {
					throw new RuntimeException('save refused');
				}

				$uuid = 'decision-' . (count($this->storedDecisions) + 1);
				$row = (array)$object;
				$row['id'] = $uuid;
				$row['@self'] = ['id' => $uuid, 'owner' => $this->savingAs];
				$this->storedDecisions[$uuid] = $row;

				return $this->entity(row: $row);
			}
		);

		$service->method('find')->willReturnCallback(
			function (int|string $id, ?array $_extend = [], bool $files = false, mixed $register = null, mixed $schema = null): ?ObjectEntity {
				if ((string)$register !== 'decidiq' || (string)$schema !== 'decision') {
					return null;
				}

				$row = ($this->storedDecisions[(string)$id] ?? null);
				if ($row === null) {
					return null;
				}

				return $this->entity(row: $row);
			}
		);

		$service->method('findAll')->willReturnCallback(
			function (array $config = []): array {
				if (($config['schema'] ?? '') !== 'decision') {
					return [];
				}

				$filters = (array)($config['filters'] ?? []);
				$matches = [];
				foreach ($this->storedDecisions as $row) {
					foreach ($filters as $key => $value) {
						if ((string)($row[$key] ?? '') !== (string)$value) {
							continue 2;
						}
					}

					$matches[] = $this->entity(row: $row);
				}

				return $matches;
			}
		);

		return $service;
	}//end fakeObjectService()

	/**
	 * An entity that serialises to the stored row.
	 *
	 * @param array<string, mixed> $row The stored row
	 *
	 * @return ObjectEntity The entity
	 */
	private function entity(array $row): ObjectEntity {
		$entity = $this->createMock(ObjectEntity::class);
		$entity->method('jsonSerialize')->willReturn($row);

		return $entity;
	}//end entity()
}//end trait
