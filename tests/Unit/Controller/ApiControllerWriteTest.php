<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category  Test
 * @package   OCA\Decidiq\Tests\Unit\Controller
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 * @link      https://github.com/ConductionNL/decidiq
 */

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

/**
 * Contract tests for the ONE writable resource on the cross-app REST surface.
 *
 * The risk this guards is not that the write fails — it is that the write
 * succeeds for something it should not. `POST /api/v1/{resource}` is a wildcard
 * route, so every resource in RESOURCE_MAP is one missing check away from being
 * writable by anyone signed in.
 */
class ApiControllerWriteTest extends TestCase {
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
	 * Recorded saveObject() calls from the ObjectService fake.
	 *
	 * @var object
	 */
	private object $objectService;

	/**
	 * Build the controller with a recording ObjectService.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->request = $this->createMock(IRequest::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->container = $this->createMock(ContainerInterface::class);

		$this->objectService = new class {
			/**
			 * @var array<int, array<string, mixed>>
			 */
			public array $saves = [];

			/**
			 * Record the write.
			 *
			 * @param array<string, mixed> $object The object.
			 * @param string $register The register.
			 * @param string $schema The schema.
			 * @param string|null $uuid The uuid.
			 *
			 * @return array<string, mixed> The stored object.
			 */
			public function saveObject(array $object, string $register, string $schema, ?string $uuid = null): array {
				$this->saves[] = ['object' => $object, 'schema' => $schema, 'uuid' => $uuid];

				return ($object + ['id' => ($uuid ?? 'new-uuid')]);
			}
		};

		$this->container->method('get')->willReturn($this->objectService);
	}

	/**
	 * The controller under test.
	 *
	 * @return ApiController The controller.
	 */
	private function controller(): ApiController {
		return new ApiController(
			$this->request,
			$this->userSession,
			$this->createMock(IConfig::class),
			$this->container,
			$this->createMock(LoggerInterface::class),
		);
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
	 * A governance body is created and returned.
	 *
	 * @return void
	 */
	public function testItCreatesAGovernanceBody(): void {
		$this->signIn();
		$this->request->method('getParams')->willReturn([
			'name' => 'Bezwaaradviescommissie',
			'bodyType' => 'advisory-body',
			'domain' => 'municipal',
			'quorum' => 3,
			'resource' => 'governance-bodies',
			'_route' => 'decidiq.api.write',
		]);

		$response = $this->controller()->create(resource: 'governance-bodies');

		$this->assertSame(Http::STATUS_CREATED, $response->getStatus());
		$this->assertCount(1, $this->objectService->saves);
		$this->assertSame('governance-body', $this->objectService->saves[0]['schema']);
		$this->assertNull($this->objectService->saves[0]['uuid']);
	}

	/**
	 * The routing keys are stripped before the object is stored.
	 *
	 * `getParams()` merges route parameters with the body, so `resource`,
	 * `id` and `_route` arrive alongside the caller's fields. Storing them
	 * would write framework plumbing into a governance body as if the caller
	 * had sent it.
	 *
	 * @return void
	 */
	public function testItDoesNotStoreRoutingParametersAsData(): void {
		$this->signIn();
		$this->request->method('getParams')->willReturn([
			'name' => 'Commissie',
			'bodyType' => 'advisory-body',
			'domain' => 'municipal',
			'resource' => 'governance-bodies',
			'id' => 'some-uuid',
			'_route' => 'decidiq.api.write',
		]);

		$this->controller()->update(resource: 'governance-bodies', id: 'some-uuid');

		$stored = $this->objectService->saves[0]['object'];
		$this->assertArrayNotHasKey('resource', $stored);
		$this->assertArrayNotHasKey('id', $stored);
		$this->assertArrayNotHasKey('_route', $stored);
		$this->assertSame('Commissie', $stored['name']);
	}

	/**
	 * An update targets the given uuid and answers 200, not 201.
	 *
	 * @return void
	 */
	public function testAnUpdateTargetsTheUuid(): void {
		$this->signIn();
		$this->request->method('getParams')->willReturn(['active' => false, 'resource' => 'governance-bodies']);

		$response = $this->controller()->update(resource: 'governance-bodies', id: 'body-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('body-1', $this->objectService->saves[0]['uuid']);
	}

	/**
	 * NO other resource is writable, however readable it is.
	 *
	 * This is the assertion the wildcard route makes necessary. `meetings`,
	 * `decisions` and `votes` are all in RESOURCE_MAP and all reachable by the
	 * same URL shape.
	 *
	 * @param string $resource A readable-but-not-writable resource.
	 *
	 * @return void
	 *
	 * @dataProvider readOnlyResources
	 */
	public function testItRefusesEveryOtherResource(string $resource): void {
		$this->signIn();
		$this->request->method('getParams')->willReturn(['name' => 'x']);

		$response = $this->controller()->create(resource: $resource);

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame([], $this->objectService->saves, 'Nothing may be written for a read-only resource.');
	}

	/**
	 * Resources that are readable and must stay read-only.
	 *
	 * @return array<int, array<int, string>> The cases.
	 */
	public static function readOnlyResources(): array {
		return [['meetings'], ['decisions'], ['votes'], ['persons'], ['minutes'], ['motions']];
	}

	/**
	 * Every writable slug also has a schema mapping.
	 *
	 * The shared write path reads RESOURCE_MAP without a null guard because RESOURCE_WRITABLE
	 * is a subset of it. That is an invariant two constants have to keep between
	 * them, and nothing in the language enforces it — so this does. Adding a
	 * writable slug with no schema mapping fails here rather than 500ing a
	 * request in production.
	 *
	 * @return void
	 */
	public function testEveryWritableResourceHasASchemaMapping(): void {
		$reflection = new \ReflectionClass(ApiController::class);
		$writable = $reflection->getConstant('RESOURCE_WRITABLE');
		$map = $reflection->getConstant('RESOURCE_MAP');

		$this->assertNotEmpty($writable);
		foreach ($writable as $slug) {
			$this->assertArrayHasKey(
				$slug,
				$map,
				sprintf('"%s" is writable but has no schema mapping; write() would fail on a missing key.', $slug)
			);
		}
	}

	/**
	 * An unknown resource is refused rather than guessed at.
	 *
	 * @return void
	 */
	public function testItRefusesAnUnknownResource(): void {
		$this->signIn();
		$this->request->method('getParams')->willReturn(['name' => 'x']);

		$response = $this->controller()->create(resource: 'not-a-resource');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame([], $this->objectService->saves);
	}

	/**
	 * An unauthenticated write is refused and stores nothing.
	 *
	 * @return void
	 */
	public function testItRefusesAnUnauthenticatedWrite(): void {
		$this->userSession->method('getUser')->willReturn(null);
		$this->request->method('getParams')->willReturn(['name' => 'x', 'bodyType' => 'advisory-body']);

		$response = $this->controller()->create(resource: 'governance-bodies');

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertSame([], $this->objectService->saves);
	}

	/**
	 * An empty body is refused rather than written as a blank object.
	 *
	 * @return void
	 */
	public function testItRefusesAnEmptyBody(): void {
		$this->signIn();
		$this->request->method('getParams')->willReturn(['resource' => 'governance-bodies']);

		$response = $this->controller()->create(resource: 'governance-bodies');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame([], $this->objectService->saves);
	}
}
