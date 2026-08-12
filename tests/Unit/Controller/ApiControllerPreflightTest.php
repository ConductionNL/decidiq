<?php

/**
 * Wire-contract tests for the ApiController CORS preflight endpoints.
 *
 * @category Test
 * @package  OCA\Decidesk\Tests\Unit\Controller
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

namespace OCA\Decidesk\Tests\Unit\Controller;

use OCA\Decidesk\Controller\ApiController;
use OCP\AppFramework\Http;
use OCP\IConfig;
use OCP\IRequest;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Contract tests for `OPTIONS /api/v1/{resource}` and
 * `OPTIONS /api/v1/{resource}/{id}`.
 *
 * A CORS preflight has no body, so the WHOLE observable contract is the status
 * line plus the three `Access-Control-*` headers. A browser that does not
 * receive all three refuses to issue the real request, which is exactly the
 * failure this asserts against: the endpoint answering 200 with an empty body
 * and no headers would look healthy to every probe that reads only the status.
 *
 * @spec openspec/changes/p4-integration/tasks.md#task-1.4
 */
class ApiControllerPreflightTest extends TestCase {

	/**
	 * Mock IRequest.
	 *
	 * @var IRequest&MockObject
	 */
	private IRequest&MockObject $request;

	/**
	 * Mock IConfig.
	 *
	 * @var IConfig&MockObject
	 */
	private IConfig&MockObject $config;

	/**
	 * The controller under test.
	 *
	 * @var ApiController
	 */
	private ApiController $controller;

	/**
	 * Set up mocks and the controller.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->request = $this->createMock(IRequest::class);
		$this->config = $this->createMock(IConfig::class);

		$this->controller = new ApiController(
			$this->request,
			$this->createMock(IUserSession::class),
			$this->config,
			$this->createMock(ContainerInterface::class),
			$this->createMock(LoggerInterface::class),
		);

	}//end setUp()

	/**
	 * Read the headers the controller itself set on a response.
	 *
	 * `Response::getHeaders()` merges in framework headers by asking
	 * `\OC::$server` for the request, and `\OC` does not exist in a standalone
	 * unit run — which is why sibling controller tests settle for asserting the
	 * status only. For a CORS preflight the status IS NOT the contract, so this
	 * reads the private `headers` array Response::addHeader() writes into. It
	 * holds exactly what the controller emitted and nothing the framework adds.
	 *
	 * @param \OCP\AppFramework\Http\Response $response The response to inspect.
	 *
	 * @return array<string, string> The controller-set headers.
	 */
	private function controllerHeaders(\OCP\AppFramework\Http\Response $response): array {
		$property = new \ReflectionProperty(\OCP\AppFramework\Http\Response::class, 'headers');
		$property->setAccessible(true);

		return (array)$property->getValue($response);
	}//end controllerHeaders()

	/**
	 * The list preflight answers 200 with an empty body and the full CORS
	 * header triple, echoing the instance's configured origin.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/p4-integration/tasks.md#task-1.4
	 */
	public function testPreflightReturnsCorsHeaders(): void {
		$this->config->method('getSystemValueString')->willReturn('https://raad.example');

		$response = $this->controller->preflight(resource: 'meetings');

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame([], $response->getData());

		$headers = $this->controllerHeaders($response);
		self::assertSame('https://raad.example', $headers['Access-Control-Allow-Origin']);
		self::assertSame('GET, OPTIONS', $headers['Access-Control-Allow-Methods']);
		self::assertStringContainsString('Authorization', $headers['Access-Control-Allow-Headers']);
		self::assertStringContainsString('Content-Type', $headers['Access-Control-Allow-Headers']);

	}//end testPreflightReturnsCorsHeaders()

	/**
	 * An instance with no configured overwrite URL falls back to the wildcard
	 * origin rather than emitting an empty `Access-Control-Allow-Origin`, which
	 * a browser treats as no header at all.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/p4-integration/tasks.md#task-1.4
	 */
	public function testPreflightFallsBackToWildcardOrigin(): void {
		$this->config->method('getSystemValueString')->willReturn('');

		$response = $this->controller->preflight(resource: 'motions');

		self::assertSame('*', $this->controllerHeaders($response)['Access-Control-Allow-Origin']);

	}//end testPreflightFallsBackToWildcardOrigin()

	/**
	 * The item preflight (`/api/v1/{resource}/{id}`) carries the same header
	 * triple — a preflight that only covered the collection URL would let every
	 * cross-origin detail read fail in the browser.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/p4-integration/tasks.md#task-1.4
	 */
	public function testPreflightItemReturnsCorsHeaders(): void {
		$this->config->method('getSystemValueString')->willReturn('https://raad.example');

		$response = $this->controller->preflightItem(resource: 'meetings', id: 'meeting-uuid-1');

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame([], $response->getData());

		$headers = $this->controllerHeaders($response);
		self::assertSame('https://raad.example', $headers['Access-Control-Allow-Origin']);
		self::assertSame('GET, OPTIONS', $headers['Access-Control-Allow-Methods']);

	}//end testPreflightItemReturnsCorsHeaders()

}//end class
