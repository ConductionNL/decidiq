<?php

/**
 * Runs an example-set import with the set's own urgent decisions granted, when the app can grant them.
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
 * @spec openspec/changes/urgent-decisions-register/specs/urgent-decision-procedure/spec.md
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Decidiq\Service;

use Psr\Container\ContainerInterface;
use Throwable;

/**
 * Takes SeedUrgencyGrants from the container and runs the import inside it; a
 * container without it (a unit run, an install without the service) runs the
 * import as is.
 *
 * @spec openspec/changes/urgent-decisions-register/specs/urgent-decision-procedure/spec.md
 */
class UrgentSeedImport {

	/**
	 * Run the import, granted when the container holds SeedUrgencyGrants.
	 *
	 * @param ContainerInterface   $container  The app container.
	 * @param array<string, mixed> $descriptor The example set.
	 * @param callable(): mixed    $import     The import.
	 *
	 * @return mixed What the import returns.
	 *
	 * @spec openspec/changes/urgent-decisions-register/specs/urgent-decision-procedure/spec.md
	 */
	public function run(ContainerInterface $container, array $descriptor, callable $import): mixed {
		try {
			$grants = $container->get(SeedUrgencyGrants::class);
		} catch (Throwable $e) {
			return $import();
		}

		if (($grants instanceof SeedUrgencyGrants) === false) {
			return $import();
		}

		return $grants->during(descriptor: $descriptor, import: $import);
	}//end run()
}//end class
