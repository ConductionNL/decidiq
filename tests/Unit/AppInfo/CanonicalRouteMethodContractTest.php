<?php

/**
 * Tests for the canonical AppHost route table's method contract.
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\AppInfo
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/apphost-adoption/spec.md#requirement-boilerplate-delegation
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\AppInfo;

use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * The canonical AppHost route table routes a fixed set of names into THIS
 * app's controller namespace, and OpenRegister only substitutes its generic
 * controller when this app does not ship a class of that name.
 *
 * `OCA\OpenRegister\AppHost\Bootstrap::aliasControllerUnlessLeafDefinesIt()`
 * registers the DI alias `OCA\Decidiq\Controller\XController` ->
 * `OCA\OpenRegister\AppHost\Controller\GenericXController` ONLY when the leaf
 * class does not exist. So the seam has two sides, and they fail differently:
 *
 *   - Leaf does NOT ship the class -> the alias binds and the generic serves
 *     every canonical method. Nothing is owed.
 *   - Leaf DOES ship the class     -> the alias is skipped and the generic is
 *     never constructed, so the leaf owes EVERY method the canonical table
 *     routes to that controller. A missing one is not a 404: the router
 *     matches the URL, the dispatcher reflects the method, and the request
 *     dies with a 500.
 *
 * Measured 2026-08-08 on the dev instance: decidiq shipped its own
 * SettingsController with `index/create/load` but no `update()`, while both
 * the AppHost table and this app's own openregister-absent fallback route
 * `PUT /api/settings` to `settings#update`.
 *
 *   GET  /apps/decidiq/api/settings -> 200 (positive control)
 *   PUT  /apps/decidiq/api/settings -> 500
 *   ReflectionException: Method
 *   OCA\Decidiq\Controller\SettingsController::update() does not exist
 *
 * This test asserts the ITEM (each individual method), never the container
 * (the controller class merely existing).
 *
 * @spec openspec/specs/apphost-adoption/spec.md#requirement-boilerplate-delegation
 */
class CanonicalRouteMethodContractTest extends TestCase {

	/**
	 * The canonical route names supplied by the AppHost table, as reproduced
	 * verbatim by `appinfo/routes.php`'s openregister-absent fallback.
	 *
	 * Keyed `controllerPrefix => [method, ...]`.
	 *
	 * @var array<string, array<int, string>>
	 */
	private const CANONICAL_ROUTES = [
		'Dashboard' => ['page', 'catchAll'],
		'Settings' => ['index', 'create', 'update', 'load'],
		'Preferences' => ['getPreference', 'setPreference'],
		'Metrics' => ['index'],
		'Health' => ['index'],
	];

	/**
	 * Every canonical route name must be reproduced by the local fallback.
	 *
	 * decidiq's `appinfo/routes.php` carries a byte-equivalent local copy of
	 * `Routes::standard()`'s output for the case where openregister is absent
	 * (decidesk#377), so `settings#update` is live on BOTH code paths. This is
	 * also the positive control for the reflection test below: if `routes.php`
	 * stopped declaring these names the method assertions would still pass,
	 * but they would be asserting about routes nobody serves.
	 *
	 * @return void
	 */
	public function testFallbackRouteTableStillDeclaresEveryCanonicalName(): void {
		$routesSource = file_get_contents(__DIR__ . '/../../../appinfo/routes.php');
		$this->assertIsString($routesSource, 'appinfo/routes.php must be readable');

		$checked = 0;

		foreach (self::CANONICAL_ROUTES as $prefix => $methods) {
			foreach ($methods as $method) {
				$checked++;
				$routeName = lcfirst($prefix) . '#' . $method;

				// dashboard#page is a literal entry; dashboard#catchAll is
				// built from the $catchAllRoute variable — both name it.
				$this->assertStringContainsString(
					$routeName,
					$routesSource,
					sprintf(
						'appinfo/routes.php no longer mentions the canonical route "%s". '
						. 'Either the AppHost table changed and this test is stale, or a '
						. 'canonical route was silently dropped from the fallback.',
						$routeName
					)
				);
			}
		}

		// Positive control: a green above is only meaningful if the loop ran.
		$this->assertGreaterThan(
			0,
			$checked,
			'No canonical route name was checked — CANONICAL_ROUTES is empty, so the '
			. 'green above says nothing.'
		);
	}//end testFallbackRouteTableStillDeclaresEveryCanonicalName()

	/**
	 * `settings#update` specifically must be routed to `PUT /api/settings`.
	 *
	 * The substring assertion above would be satisfied by the name appearing
	 * anywhere, including a comment. This pins the verb and URL of the entry
	 * that produced the measured 500.
	 *
	 * @return void
	 */
	public function testFallbackRoutesPutApiSettingsToSettingsUpdate(): void {
		$routesSource = file_get_contents(__DIR__ . '/../../../appinfo/routes.php');
		$this->assertIsString($routesSource, 'appinfo/routes.php must be readable');

		$this->assertMatchesRegularExpression(
			"/'name'\s*=>\s*'settings#update'\s*,\s*'url'\s*=>\s*'\/api\/settings'\s*,\s*'verb'\s*=>\s*'PUT'/",
			$routesSource,
			'The openregister-absent fallback must still route PUT /api/settings to '
			. 'settings#update, byte-equivalent to Routes::standard().'
		);
	}//end testFallbackRoutesPutApiSettingsToSettingsUpdate()

	/**
	 * A controller this app ships itself must implement every canonical
	 * method routed to it — the AppHost generic will not fill the gap.
	 *
	 * @return void
	 */
	public function testLeafOwnedControllersImplementEveryCanonicalMethodRoutedToThem(): void {
		$inspected = 0;
		$missing = [];

		foreach (self::CANONICAL_ROUTES as $prefix => $methods) {
			$class = 'OCA\\Decidiq\\Controller\\' . $prefix . 'Controller';

			// The class file existing on disk is what makes the AppHost skip
			// the alias. `class_exists()` alone would be satisfied by the DI
			// alias target in a booted container, which is precisely the case
			// this test must NOT treat as leaf-owned.
			$file = __DIR__ . '/../../../lib/Controller/' . $prefix . 'Controller.php';
			if (file_exists($file) === false) {
				continue;
			}

			$this->assertTrue(
				class_exists($class),
				sprintf('%s exists on disk but does not autoload as %s', $file, $class)
			);

			$reflection = new ReflectionClass($class);

			foreach ($methods as $method) {
				$inspected++;
				if ($reflection->hasMethod($method) === false) {
					$missing[] = $prefix . 'Controller::' . $method . '()';
					continue;
				}

				$this->assertTrue(
					$reflection->getMethod($method)->isPublic(),
					sprintf('%s::%s() must be public to be dispatchable', $class, $method)
				);

				$this->assertFalse(
					$reflection->getMethod($method)->isStatic(),
					sprintf('%s::%s() must not be static to be dispatchable', $class, $method)
				);
			}
		}//end foreach

		// Positive control: a null result here ("no missing methods") is only
		// meaningful if something was actually inspected. Zero inspections
		// would mean the file-existence probe above silently matched nothing.
		$this->assertGreaterThan(
			0,
			$inspected,
			'No leaf-owned canonical controller method was inspected — the lib/Controller '
			. 'path probe is broken, so the empty finding list means nothing.'
		);

		$this->assertSame(
			[],
			$missing,
			sprintf(
				'The canonical AppHost route table routes to these method(s), but decidiq '
				. 'ships the controller itself so no generic is aliased in. Each of these is '
				. "a 500, not a 404.\n  - %s",
				implode("\n  - ", $missing)
			)
		);
	}//end testLeafOwnedControllersImplementEveryCanonicalMethodRoutedToThem()

}//end class
