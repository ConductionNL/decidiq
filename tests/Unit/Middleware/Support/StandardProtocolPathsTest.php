<?php

/**
 * Unit tests for the paths whose formats a standard or a peer defines.
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\Middleware\Support
 * @author   Conduction Development Team <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\Decidiq\Tests\Unit\Middleware\Support;

use OCA\Decidiq\Middleware\Support\StandardProtocolPaths;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The health check and the metrics endpoint speak formats defined outside the
 * app, so the app's own API conventions leave them alone; every other path,
 * including a near miss, is the app's own.
 *
 * @covers \OCA\Decidiq\Middleware\Support\StandardProtocolPaths
 *
 * @spec openspec/specs/api-contract/spec.md
 */
class StandardProtocolPathsTest extends TestCase {

	/**
	 * Paths and whether they speak a standard protocol.
	 *
	 * @return array<string,array{0:string,1:bool}>
	 */
	public static function paths(): array {
		return [
			'health'                  => ['/api/health', true],
			'metrics'                 => ['/api/metrics', true],
			'health without a slash'  => ['api/health', true],
			'health as request path'  => ['/index.php/apps/decidiq/api/health', true],
			'metrics as request path' => ['/apps/decidiq/api/metrics', true],
			'health subpath'          => ['/api/health/deep', false],
			'versioned health'        => ['/api/v1/health', false],
			'own api path'            => ['/api/things', false],
			'another app health'      => ['/index.php/apps/other/api/health/x', false],
		];
	}//end paths()

	/**
	 * Each path is or is not a standard protocol path.
	 *
	 * @param string $path    The path.
	 * @param bool   $matches Whether it speaks a standard protocol.
	 *
	 * @return void
	 */
	#[DataProvider('paths')]
	public function testWhetherAPathSpeaksAStandardProtocol(string $path, bool $matches): void {
		$this->assertSame($matches, StandardProtocolPaths::matches(path: $path));
	}//end testWhetherAPathSpeaksAStandardProtocol()

	/**
	 * Request paths and the app-relative path they resolve to.
	 *
	 * @return array<string,array{0:string,1:string}>
	 */
	public static function relatives(): array {
		return [
			'app-relative'          => ['/api/things', '/api/things'],
			'no leading slash'      => ['api/things', '/api/things'],
			'front controller'      => ['/index.php/apps/decidiq/api/things/7', '/api/things/7'],
			'pretty url'            => ['/apps/decidiq/api/health', '/api/health'],
			'app root alone'        => ['/apps/decidiq/', '/'],
			'similar app name'      => ['/apps/decidiqx/api/things', '/apps/decidiqx/api/things'],
			'outside the app'       => ['/apps/other/api/things', '/apps/other/api/things'],
		];
	}//end relatives()

	/**
	 * A request path is reduced to the part after the app root; any other path keeps its own form.
	 *
	 * @param string $path     The path.
	 * @param string $relative The app-relative path.
	 *
	 * @return void
	 */
	#[DataProvider('relatives')]
	public function testTheAppRelativePath(string $path, string $relative): void {
		$this->assertSame($relative, StandardProtocolPaths::appRelative(path: $path));
	}//end testTheAppRelativePath()
}//end class
