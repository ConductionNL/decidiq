<?php

/**
 * Unit tests for MetricsController.
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\Controller
 *
 * @spec openspec/specs/apphost-adoption/spec.md
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Controller;

use OCA\Decidiq\Controller\MetricsController;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Http\TextPlainResponse;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use ReflectionProperty;
use RuntimeException;

/**
 * Tests for the Prometheus metrics endpoint.
 *
 * @covers \OCA\Decidiq\Controller\MetricsController
 *
 * @spec openspec/specs/apphost-adoption/spec.md
 */
class MetricsControllerTest extends TestCase {
	private function headerOf(TextPlainResponse $response, string $name): ?string {
		// getHeaders() needs a running server; the raw header list does not.
		$headers = (new ReflectionProperty(Response::class, 'headers'))->getValue($response);

		return ($headers[$name] ?? null);
	}

	private function controller(ContainerInterface $container): MetricsController {
		return new MetricsController($this->createMock(IRequest::class), $container);
	}

	public function testRendersTheAppManifestThroughTheEngine(): void {
		$loader = new class {
			public array $loaded = [];

			public function load(string $appId): array {
				$this->loaded[] = $appId;
				return ['app' => $appId];
			}
		};
		$engine = new class {
			public function render(array $manifest): string {
				return "decidiq_up{app=\"" . $manifest['app'] . "\"} 1\n";
			}
		};
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnMap(
			[
				['OCA\\OpenRegister\\AppHost\\Observability\\ManifestLoader', $loader],
				['OCA\\OpenRegister\\AppHost\\Observability\\MetricsEngine', $engine],
			]
		);

		$response = $this->controller($container)->index();

		$this->assertSame(200, $response->getStatus());
		$this->assertSame("decidiq_up{app=\"decidiq\"} 1\n", $response->render());
		$this->assertSame('text/plain; version=0.0.4; charset=utf-8', $this->headerOf($response, 'Content-Type'));
		$this->assertSame(['decidiq'], $loader->loaded);
	}

	public function testAnswersUnavailableWithoutTheEngine(): void {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willThrowException(new RuntimeException('not found'));

		$response = $this->controller($container)->index();

		$this->assertSame(503, $response->getStatus());
		$this->assertSame(
			"# metrics unavailable: the OpenRegister AppHost observability engine is not installed\n",
			$response->render()
		);
		$this->assertSame('text/plain; version=0.0.4; charset=utf-8', $this->headerOf($response, 'Content-Type'));
	}
}
