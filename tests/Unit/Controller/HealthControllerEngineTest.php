<?php

/**
 * Unit tests for HealthController over OpenRegister's health engine.
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Controller;

use OCA\Decidiq\Controller\HealthController;
use OCP\AppFramework\Http;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * Tests for HealthController when the engine answers.
 *
 * @covers \OCA\Decidiq\Controller\HealthController
 * @uses \OCA\Decidiq\Controller\CorsJsonController
 *
 * @spec openspec/changes/adopt-apphost/tasks.md#task-2.5
 */
class HealthControllerEngineTest extends TestCase {

	/**
	 * The app ids the manifest loader was asked about.
	 *
	 * @var array<int, string>
	 */
	public array $asked = [];

	/**
	 * A controller whose engine reports this result.
	 *
	 * @param object $result The engine's HealthCheckResult-shaped answer.
	 *
	 * @return HealthController The controller under test.
	 */
	private function controller(object $result): HealthController {
		$test = $this;
		$loader = new class ($test) {
			/**
			 * @param HealthControllerEngineTest $test Records the asked app ids.
			 */
			public function __construct(private HealthControllerEngineTest $test) {
			}

			/**
			 * @param string $appId The app.
			 *
			 * @return array<string, mixed>
			 */
			public function load(string $appId): array {
				$this->test->asked[] = $appId;
				return ['app' => $appId];
			}

			/**
			 * @param string $appId The app.
			 *
			 * @return string
			 */
			public function appVersion(string $appId): string {
				return '3.1.0';
			}
		};
		$executor = new class ($result) {
			/**
			 * @param object $result The answer.
			 */
			public function __construct(private object $result) {
			}

			/**
			 * @param array<string, mixed> $manifest The manifest.
			 *
			 * @return object
			 */
			public function execute(array $manifest): object {
				$this->result->manifest = $manifest;
				return $this->result;
			}
		};

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static fn (string $id): object => (str_ends_with($id, 'ManifestLoader') === true ? $loader : $executor)
		);
		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValueString')->willReturn('https://raad.example.nl');
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->expects(self::never())->method('getValueString');

		return new HealthController($this->createMock(IRequest::class), $config, $container, $appConfig);
	}//end controller()

	/**
	 * A healthy engine answer is reported as connected, with the engine's status code and version.
	 *
	 * @return void
	 */
	public function testAHealthyEngineIsConnected(): void {
		$result = (object)['status' => 'ok', 'checks' => ['openregister' => 'ok'], 'httpStatusCode' => 200];

		$response = $this->controller(result: $result)->index();

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame(
			['status' => 'ok', 'baseUrl' => 'https://raad.example.nl', 'version' => '3.1.0', 'openregister' => 'connected'],
			$response->getData()
		);
		self::assertSame(['decidiq'], $this->asked);
		self::assertSame(['app' => 'decidiq'], $result->manifest);
	}//end testAHealthyEngineIsConnected()

	/**
	 * A failed OpenRegister check is unavailable, under the engine's own status code.
	 *
	 * @return void
	 */
	public function testAFailedCheckIsUnavailable(): void {
		$result = (object)['status' => 'error', 'checks' => ['openregister' => 'failed: timeout'], 'httpStatusCode' => 503];

		$response = $this->controller(result: $result)->status();

		self::assertSame(Http::STATUS_SERVICE_UNAVAILABLE, $response->getStatus());
		self::assertSame('error', $response->getData()['status']);
		self::assertSame('unavailable', $response->getData()['openregister']);
	}//end testAFailedCheckIsUnavailable()

	/**
	 * An engine answer without an OpenRegister check counts as unavailable.
	 *
	 * @return void
	 */
	public function testAMissingCheckIsUnavailable(): void {
		$result = (object)['status' => 'degraded', 'checks' => [], 'httpStatusCode' => 200];

		self::assertSame('unavailable', $this->controller(result: $result)->index()->getData()['openregister']);
	}//end testAMissingCheckIsUnavailable()
}//end class
