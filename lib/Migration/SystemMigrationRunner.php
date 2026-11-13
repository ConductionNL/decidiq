<?php

/**
 * Decidiq System Migration Runner
 *
 * The shared opening of the schema migrations' run(): skip with an info line
 * when OpenRegister is unavailable, warn when its ObjectService cannot be
 * resolved, and otherwise run the migration as the system user.
 *
 * 🔴 FAIL SOFT. A repair step that throws fails the whole `occ upgrade`, so
 * neither precondition is an exception.
 *
 * @category Migration
 * @package  OCA\Decidiq\Migration
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

namespace OCA\Decidiq\Migration;

use Closure;
use OCA\Decidiq\Service\SettingsService;
use OCP\Migration\IOutput;
use Psr\Container\ContainerInterface;
use Throwable;

/**
 * Runs a migration as the system user once OpenRegister is reachable.
 *
 * @spec openspec/specs/migrations-that-actually-run/spec.md
 */
final class SystemMigrationRunner {
	/**
	 * Constructor.
	 *
	 * @param SettingsService    $settingsService Tells whether OpenRegister is available
	 * @param ContainerInterface $container       Resolves OpenRegister's ObjectService
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly ContainerInterface $container,
	) {
	}//end __construct()

	/**
	 * Run the migration as the system user.
	 *
	 * @param IOutput $output     Progress reporting
	 * @param Closure $migrateAll The migration, called with `objectService:` and `output:`
	 *
	 * @return void
	 *
	 * @phpstan-param Closure(object $objectService, IOutput $output): void $migrateAll
	 *
	 * @spec openspec/specs/migrations-that-actually-run/spec.md
	 */
	public function run(IOutput $output, Closure $migrateAll): void {
		if ($this->settingsService->isOpenRegisterAvailable() === false) {
			$output->info('OpenRegister unavailable — nothing to migrate.');
			return;
		}

		$objectService = $this->objectService(output: $output);
		if ($objectService === null) {
			return;
		}

		// 🔴 RUN AS SYSTEM. A repair step has no session, so OpenRegister sees
		// the actor as 'Anonymous' and refuses `create`, and this step reports
		// that as a warning, which does not fail an upgrade.
		$objectService->runAsSystem(
			function () use ($objectService, $output, $migrateAll): void {
				$migrateAll(objectService: $objectService, output: $output);
			}
		);

	}//end run()

	/**
	 * Resolve OpenRegister's ObjectService, warning when it cannot be.
	 *
	 * @param IOutput $output Progress reporting
	 *
	 * @return object|null The ObjectService, or null when it cannot be resolved
	 */
	private function objectService(IOutput $output): ?object {
		try {
			return $this->container->get('OCA\OpenRegister\Service\ObjectService');
		} catch (Throwable $e) {
			$output->warning('Could not resolve OpenRegister ObjectService: ' . $e->getMessage());
			return null;
		}

	}//end objectService()
}//end class
