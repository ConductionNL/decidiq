<?php

/**
 * Contract tests for the ApiController read endpoints and write failure path.
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

use OCA\Decidiq\Controller\ApiController;
use OCP\AppFramework\Http;
use OCP\IConfig;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * `GET /api/v1/{resource}` and `GET /api/v1/{resource}/{id}`: unknown
 * resources, unauthenticated callers, pagination validation, the ADR-005
 * decisionType narrowing, and the 500 envelope on a failing ObjectService.
 *
 * @covers \OCA\Decidiq\Controller\ApiController
 * @uses   \OCA\Decidiq\Controller\CorsJsonController
 */
class ApiControllerReadTest extends TestCase {

	/**
	 * @var IRequest&MockObject
	 */
	private IRequest&MockObject $request;

	/**
	 * @var IUserSession&MockObject
	 */
	private IUserSession&MockObject $userSession;

	/**
	 * @var ContainerInterface&MockObject
	 */
	private ContainerInterface&MockObject $container;

	/**
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Recording ObjectService fake.
	 *
	 * @var object
	 */
	private object $objectService;

	/**
	 * Build the mocks and the recording ObjectService fake.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->request = $this->createMock(IRequest::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->container = $this->createMock(ContainerInterface::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->objectService = new class {
			/**
			 * @var array<int, array<string, mixed>>
			 */
			public array $findAllConfigs = [];

			/**
			 * @var array<int, array<string, mixed>>
			 */
			public array $finds = [];

			/**
			 * @var mixed
			 */
			public mixed $listResult = [];

			/**
			 * @var array<string, mixed>|null
			 */
			public ?array $entity = null;

			/**
			 * Record a list call.
			 *
			 * @param array<string, mixed> $config The config.
			 *
			 * @return mixed The list.
			 */
			public function findAll(array $config): mixed {
				$this->findAllConfigs[] = $config;
				return $this->listResult;
			}

			/**
			 * Record a find call.
			 *
			 * @param string $id       The id.
			 * @param string $register The register.
			 * @param string $schema   The schema.
			 *
			 * @return object|null The entity.
			 */
			public function find(string $id, string $register, string $schema): ?object {
				$this->finds[] = ['id' => $id, 'register' => $register, 'schema' => $schema];
				if ($this->entity === null) {
					return null;
				}

				$data = $this->entity;
				return new class ($data) {
					/**
					 * @param array<string, mixed> $data The data.
					 */
					public function __construct(private array $data) {
					}

					/**
					 * @return array<string, mixed>
					 */
					public function jsonSerialize(): array {
						return $this->data;
					}
				};
			}
		};
	}

	/**
	 * The controller under test, with CORS origin configured.
	 *
	 * @return ApiController
	 */
	private function controller(): ApiController {
		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValueString')->willReturn('https://decidiq.example.org');

		return new ApiController(
			$this->request,
			$this->userSession,
			$config,
			$this->container,
			$this->logger,
		);
	}

	/**
	 * The headers the controller set, read without Response::getHeaders()
	 * (which asks the server container for framework headers).
	 *
	 * @param \OCP\AppFramework\Http\Response $response The response.
	 *
	 * @return array<string, string>
	 */
	private function controllerHeaders(\OCP\AppFramework\Http\Response $response): array {
		$property = new \ReflectionProperty(\OCP\AppFramework\Http\Response::class, 'headers');
		$property->setAccessible(true);

		return (array)$property->getValue($response);
	}

	/**
	 * Sign a user in.
	 *
	 * @return void
	 */
	private function signIn(): void {
		$this->userSession->method('getUser')->willReturn($this->createMock(IUser::class));
	}

	/**
	 * Wire the container to return the ObjectService fake.
	 *
	 * @return void
	 */
	private function wireObjectService(): void {
		$this->container->expects($this->once())
			->method('get')
			->with('OCA\\OpenRegister\\Service\\ObjectService')
			->willReturn($this->objectService);
	}

	/**
	 * Request params for pagination.
	 *
	 * @param mixed $page  The _page param.
	 * @param mixed $limit The _limit param.
	 *
	 * @return void
	 */
	private function paginate(mixed $page, mixed $limit): void {
		$this->request->method('getParam')->willReturnCallback(
			static fn (string $key, mixed $default=null): mixed => ['_page' => $page, '_limit' => $limit][$key] ?? $default
		);
	}

	/**
	 * An unknown resource answers 404 on the list without touching the session.
	 *
	 * @return void
	 */
	public function testIndexUnknownResourceIs404(): void {
		$this->userSession->expects($this->never())->method('getUser');
		$this->container->expects($this->never())->method('get');

		$response = $this->controller()->index(resource: 'not-a-resource');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame(['message' => 'Unknown resource', 'code' => 404], $response->getData());
		$this->assertSame('https://decidiq.example.org', $this->controllerHeaders($response)['Access-Control-Allow-Origin']);
	}

	/**
	 * An anonymous caller is refused 401 on the list.
	 *
	 * @return void
	 */
	public function testIndexUnauthenticatedIs401(): void {
		$this->userSession->method('getUser')->willReturn(null);
		$this->container->expects($this->never())->method('get');

		$response = $this->controller()->index(resource: 'meetings');

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertSame(['message' => 'Unauthorized', 'code' => 401], $response->getData());
	}

	/**
	 * Out-of-range pagination is refused 400.
	 *
	 * @param mixed $page  The _page param.
	 * @param mixed $limit The _limit param.
	 *
	 * @return void
	 *
	 * @dataProvider invalidPagination
	 */
	public function testIndexInvalidPaginationIs400(mixed $page, mixed $limit): void {
		$this->signIn();
		$this->paginate(page: $page, limit: $limit);
		$this->container->expects($this->never())->method('get');

		$response = $this->controller()->index(resource: 'meetings');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['message' => 'Invalid pagination parameters', 'code' => 400], $response->getData());
	}

	/**
	 * Pagination values that must be refused.
	 *
	 * @return array<string, array<int, mixed>>
	 */
	public static function invalidPagination(): array {
		return [
			'limit over 100' => [1, 101],
			'limit zero'     => [1, 0],
			'page zero'      => [0, 25],
		];
	}

	/**
	 * A list returns the envelope and passes register, schema, limit and offset in one config.
	 *
	 * @return void
	 */
	public function testIndexReturnsEnvelopeWithOffset(): void {
		$this->signIn();
		$this->paginate(page: '3', limit: '2');
		$this->wireObjectService();
		$this->objectService->listResult = [['id' => 'm-1'], ['id' => 'm-2']];

		$response = $this->controller()->index(resource: 'meetings');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(
			['total' => 2, 'page' => 3, 'pages' => 1, 'results' => [['id' => 'm-1'], ['id' => 'm-2']]],
			$response->getData()
		);
		$this->assertSame(
			[['filters' => ['register' => 'decidiq', 'schema' => 'meeting'], 'limit' => 2, 'offset' => 4]],
			$this->objectService->findAllConfigs
		);
		$this->assertSame('GET, OPTIONS', $this->controllerHeaders($response)['Access-Control-Allow-Methods']);
	}

	/**
	 * `/motions` lists decisions narrowed by decisionType=motion, with default pagination.
	 *
	 * @return void
	 */
	public function testIndexMotionsFiltersOnDecisionType(): void {
		$this->signIn();
		$this->request->method('getParam')->willReturnCallback(static fn (string $key, mixed $default=null): mixed => $default);
		$this->wireObjectService();
		$this->objectService->listResult = [];

		$response = $this->controller()->index(resource: 'motions');

		$this->assertSame(['total' => 0, 'page' => 1, 'pages' => 0, 'results' => []], $response->getData());
		$this->assertSame(
			[['filters' => ['register' => 'decidiq', 'schema' => 'decision', 'decisionType' => 'motion'], 'limit' => 25, 'offset' => 0]],
			$this->objectService->findAllConfigs
		);
	}

	/**
	 * A non-array list result counts as zero results.
	 *
	 * @return void
	 */
	public function testIndexNonArrayResultCountsAsZero(): void {
		$this->signIn();
		$this->paginate(page: 1, limit: 10);
		$this->wireObjectService();
		$this->objectService->listResult = null;

		$response = $this->controller()->index(resource: 'decisions');

		$this->assertSame(['total' => 0, 'page' => 1, 'pages' => 0, 'results' => []], $response->getData());
		$this->assertSame(['register' => 'decidiq', 'schema' => 'decision'], $this->objectService->findAllConfigs[0]['filters']);
	}

	/**
	 * A failing ObjectService is logged and answers 500.
	 *
	 * @return void
	 */
	public function testIndexFailureIsLoggedAnd500(): void {
		$this->signIn();
		$this->paginate(page: 1, limit: 10);
		$error = new RuntimeException('boom');
		$this->container->method('get')->willThrowException($error);
		$this->logger->expects($this->once())
			->method('error')
			->with('ApiController index failed', ['resource' => 'votes', 'exception' => $error]);

		$response = $this->controller()->index(resource: 'votes');

		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$this->assertSame(['message' => 'Internal server error', 'code' => 500], $response->getData());
	}

	/**
	 * An unknown resource answers 404 on the detail.
	 *
	 * @return void
	 */
	public function testShowUnknownResourceIs404(): void {
		$this->container->expects($this->never())->method('get');

		$response = $this->controller()->show(resource: 'nope', id: 'x');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame(['message' => 'Unknown resource', 'code' => 404], $response->getData());
	}

	/**
	 * An anonymous caller is refused 401 on the detail.
	 *
	 * @return void
	 */
	public function testShowUnauthenticatedIs401(): void {
		$this->userSession->method('getUser')->willReturn(null);
		$this->container->expects($this->never())->method('get');

		$response = $this->controller()->show(resource: 'meetings', id: 'm-1');

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertSame(['message' => 'Unauthorized', 'code' => 401], $response->getData());
	}

	/**
	 * A found entity is returned serialized with CORS headers.
	 *
	 * @return void
	 */
	public function testShowReturnsTheEntity(): void {
		$this->signIn();
		$this->wireObjectService();
		$this->objectService->entity = ['id' => 'm-1', 'title' => 'Raadsvergadering'];

		$response = $this->controller()->show(resource: 'meetings', id: 'm-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['id' => 'm-1', 'title' => 'Raadsvergadering'], $response->getData());
		$this->assertSame([['id' => 'm-1', 'register' => 'decidiq', 'schema' => 'meeting']], $this->objectService->finds);
		$this->assertSame('https://decidiq.example.org', $this->controllerHeaders($response)['Access-Control-Allow-Origin']);
	}

	/**
	 * A missing entity answers 404 Not found.
	 *
	 * @return void
	 */
	public function testShowMissingEntityIs404(): void {
		$this->signIn();
		$this->wireObjectService();
		$this->objectService->entity = null;

		$response = $this->controller()->show(resource: 'meetings', id: 'gone');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame(['message' => 'Not found', 'code' => 404], $response->getData());
	}

	/**
	 * A decision of the wrong decisionType is Not Found under `/motions/{id}`.
	 *
	 * @return void
	 */
	public function testShowMotionOfWrongTypeIs404(): void {
		$this->signIn();
		$this->wireObjectService();
		$this->objectService->entity = ['id' => 'd-1', 'decisionType' => 'amendment'];

		$response = $this->controller()->show(resource: 'motions', id: 'd-1');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame(['message' => 'Not found', 'code' => 404], $response->getData());
		$this->assertSame('decision', $this->objectService->finds[0]['schema']);
	}

	/**
	 * A decision of the right decisionType is returned under `/amendments/{id}`.
	 *
	 * @return void
	 */
	public function testShowAmendmentOfRightTypeIsReturned(): void {
		$this->signIn();
		$this->wireObjectService();
		$this->objectService->entity = ['id' => 'd-2', 'decisionType' => 'amendment'];

		$response = $this->controller()->show(resource: 'amendments', id: 'd-2');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['id' => 'd-2', 'decisionType' => 'amendment'], $response->getData());
	}

	/**
	 * A failing ObjectService on the detail is logged and answers 500.
	 *
	 * @return void
	 */
	public function testShowFailureIsLoggedAnd500(): void {
		$this->signIn();
		$error = new RuntimeException('db down');
		$this->container->method('get')->willThrowException($error);
		$this->logger->expects($this->once())
			->method('error')
			->with('ApiController show failed', ['resource' => 'votes', 'id' => 'v-1', 'exception' => $error]);

		$response = $this->controller()->show(resource: 'votes', id: 'v-1');

		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$this->assertSame(['message' => 'Internal server error', 'code' => 500], $response->getData());
	}

	/**
	 * A rejected write is logged and answers 400 with the rejection message.
	 *
	 * @return void
	 */
	public function testWriteRejectionIsLoggedAnd400WithMessage(): void {
		$this->signIn();
		$this->request->method('getParams')->willReturn(['name' => 'Commissie', 'resource' => 'governance-bodies']);
		$error = new RuntimeException('Missing required properties: bodyType, domain');
		$this->container->method('get')->willThrowException($error);
		$this->logger->expects($this->once())
			->method('error')
			->with('ApiController write failed', ['resource' => 'governance-bodies', 'id' => 'body-1', 'exception' => $error]);

		$response = $this->controller()->update(resource: 'governance-bodies', id: 'body-1');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['message' => 'Missing required properties: bodyType, domain', 'code' => 400], $response->getData());
	}
}//end class
