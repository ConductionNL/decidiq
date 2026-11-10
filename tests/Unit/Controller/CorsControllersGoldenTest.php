<?php
/**
 * Golden test for the three CORS-decorated controllers.
 *
 * Drives every public endpoint of ApiController, OriController and
 * HealthController through its error, refusal and success paths under three
 * configured origins, and records the status, body and controller-set headers
 * of each answer, plus every object-service, config and logger call. The
 * expected values live in tests/fixtures/cors-controllers-golden.json; run with
 * RECORD_CORS_CONTROLLERS_GOLDEN=1 to write them, then run again without it.
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/p4-integration/tasks.md#task-1.4
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Controller;

use OCA\OpenRegister\Service\Object\ObjectSaveOptions;
use OCA\Decidiq\Controller\ApiController;
use OCA\Decidiq\Controller\HealthController;
use OCA\Decidiq\Controller\OriController;
use OCA\Decidiq\Service\OriPersonPublicationRule;
use OCA\Decidiq\Service\OriSerializer;
use OCA\Decidiq\Service\OriVotePublicationRule;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http\Response;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use ReflectionProperty;
use RuntimeException;

/**
 * Golden test for ApiController, OriController and HealthController.
 *
 * @covers \OCA\Decidiq\Controller\ApiController
 * @covers \OCA\Decidiq\Controller\OriController
 * @uses   \OCA\Decidiq\Controller\CorsJsonController
 * @covers \OCA\Decidiq\Controller\HealthController
 * @uses   \OCA\Decidiq\Service\OriSerializer
 */
class CorsControllersGoldenTest extends TestCase {

	private const GOLDEN = __DIR__ . '/../../fixtures/cors-controllers-golden.json';

	/**
	 * Every recorded collaborator call of the current case.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $log = [];

	/**
	 * Request parameters of the current case.
	 *
	 * @var array<string, mixed>
	 */
	private array $params = [];

	/**
	 * Whether a user is logged in for the current case.
	 *
	 * @var bool
	 */
	private bool $loggedIn = true;

	/**
	 * How the object service answers in the current case: ok, throws or missing.
	 *
	 * @var string
	 */
	private string $mode = 'ok';

	/**
	 * The Origin header the request carries in the current case.
	 *
	 * @var string
	 */
	private string $originHeader = '';

	/**
	 * Every endpoint answer matches the recording.
	 *
	 * @return void
	 */
	public function testEveryAnswerMatchesTheRecording(): void {
		$actual = [];
		$headers = ['' => '', ' (request Origin: https://evil.example)' => 'https://evil.example'];
		foreach (['https://raad.example', '', '*'] as $origin) {
			foreach ($headers as $suffix => $header) {
				foreach ($this->cases() as $name => [$mode, $loggedIn, $params, $call]) {
					$this->log          = [];
					$this->mode         = $mode;
					$this->loggedIn     = $loggedIn;
					$this->params       = $params;
					$this->originHeader = $header;
					$response           = $call(...$this->controllers(origin: $origin));
					$actual[($origin === '' ? '(empty)' : $origin)][$name . $suffix] = [
						'status'  => $response->getStatus(),
						'data'    => $response->getData(),
						'headers' => $this->controllerHeaders(response: $response),
						'calls'   => $this->log,
					];
				}
			}
		}

		$json = json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
		if (getenv('RECORD_CORS_CONTROLLERS_GOLDEN') === '1') {
			file_put_contents(self::GOLDEN, $json);
		}

		self::assertSame(file_get_contents(self::GOLDEN), $json);
	}//end testEveryAnswerMatchesTheRecording()

	/**
	 * The cases: name to [object-service mode, logged in, request params, call].
	 *
	 * @return array<string, array{0: string, 1: bool, 2: array<string, mixed>, 3: callable}>
	 */
	private function cases(): array {
		$ok   = 'ok';
		$body = ['name' => 'Raad', 'resource' => 'governance-bodies', 'id' => 'x', '_route' => 'r'];
		return [
			'api index unknown'         => [$ok, true, [], fn ($api) => $api->index(resource: 'nope')],
			'api index anonymous'       => [$ok, false, [], fn ($api) => $api->index(resource: 'meetings')],
			'api index bad limit'       => [$ok, true, ['_limit' => 101], fn ($api) => $api->index(resource: 'meetings')],
			'api index bad page'        => [$ok, true, ['_page' => 0], fn ($api) => $api->index(resource: 'meetings')],
			'api index meetings'        => [$ok, true, ['_page' => 2, '_limit' => 3], fn ($api) => $api->index(resource: 'meetings')],
			'api index motions'         => [$ok, true, [], fn ($api) => $api->index(resource: 'motions')],
			'api index throws'          => ['throws', true, [], fn ($api) => $api->index(resource: 'meetings')],
			'api show unknown'          => [$ok, true, [], fn ($api) => $api->show(resource: 'nope', id: 'meeting-pub')],
			'api show anonymous'        => [$ok, false, [], fn ($api) => $api->show(resource: 'meetings', id: 'meeting-pub')],
			'api show found'            => [$ok, true, [], fn ($api) => $api->show(resource: 'meetings', id: 'meeting-pub')],
			'api show null'             => [$ok, true, [], fn ($api) => $api->show(resource: 'meetings', id: 'null')],
			'api show motion'           => [$ok, true, [], fn ($api) => $api->show(resource: 'motions', id: 'motion-pub')],
			'api show wrong type'       => [$ok, true, [], fn ($api) => $api->show(resource: 'amendments', id: 'motion-pub')],
			'api show throws'           => ['throws', true, [], fn ($api) => $api->show(resource: 'meetings', id: 'meeting-pub')],
			'api create not writable'   => [$ok, true, $body, fn ($api) => $api->create(resource: 'meetings')],
			'api create anonymous'      => [$ok, false, $body, fn ($api) => $api->create(resource: 'governance-bodies')],
			'api create empty'          => [$ok, true, ['resource' => 'governance-bodies'], fn ($api) => $api->create(resource: 'governance-bodies')],
			'api create ok'             => [$ok, true, $body, fn ($api) => $api->create(resource: 'governance-bodies')],
			'api create throws'         => ['throws', true, $body, fn ($api) => $api->create(resource: 'governance-bodies')],
			'api update ok'             => [$ok, true, $body, fn ($api) => $api->update(resource: 'governance-bodies', id: 'gb-1')],
			'api preflight'             => [$ok, true, [], fn ($api) => $api->preflight(resource: 'meetings')],
			'api preflight item'        => [$ok, true, [], fn ($api) => $api->preflightItem(resource: 'meetings', id: 'x')],
			'ori index unknown'         => [$ok, false, [], fn ($api, $ori) => $ori->index(resource: 'nope')],
			'ori index events'          => [$ok, false, [], fn ($api, $ori) => $ori->index(resource: 'events')],
			'ori index motions'         => [$ok, false, [], fn ($api, $ori) => $ori->index(resource: 'motions')],
			'ori index memberships'     => [$ok, false, [], fn ($api, $ori) => $ori->index(resource: 'memberships')],
			'ori index publications'    => [$ok, false, [], fn ($api, $ori) => $ori->index(resource: 'publications')],
			'ori index throws'          => ['throws', false, [], fn ($api, $ori) => $ori->index(resource: 'events')],
			'ori index votes'           => [$ok, false, ['voter' => 'p-1'], fn ($api, $ori) => $ori->index(resource: 'votes')],
			'ori index voteevents'      => [$ok, false, [], fn ($api, $ori) => $ori->index(resource: 'voteevents')],
			'ori index persons'         => [$ok, false, [], fn ($api, $ori) => $ori->index(resource: 'persons')],
			'ori index ruled throws'    => ['throws', false, [], fn ($api, $ori) => $ori->index(resource: 'persons')],
			'ori show unknown'          => [$ok, false, [], fn ($api, $ori) => $ori->show(resource: 'nope', id: 'meeting-pub')],
			'ori show event'            => [$ok, false, [], fn ($api, $ori) => $ori->show(resource: 'events', id: 'meeting-pub')],
			'ori show draft'            => [$ok, false, [], fn ($api, $ori) => $ori->show(resource: 'events', id: 'meeting-draft')],
			'ori show null'             => [$ok, false, [], fn ($api, $ori) => $ori->show(resource: 'events', id: 'null')],
			'ori show missing'          => ['missing', false, [], fn ($api, $ori) => $ori->show(resource: 'events', id: 'meeting-pub')],
			'ori show throws'           => ['throws', false, [], fn ($api, $ori) => $ori->show(resource: 'events', id: 'meeting-pub')],
			'ori show payload live'     => [$ok, false, [], fn ($api, $ori) => $ori->show(resource: 'publications', id: 'payload-live')],
			'ori show payload future'   => [$ok, false, [], fn ($api, $ori) => $ori->show(resource: 'publications', id: 'payload-future')],
			'ori show vote'             => [$ok, false, [], fn ($api, $ori) => $ori->show(resource: 'votes', id: 'v-1')],
			'ori show vote none'        => [$ok, false, [], fn ($api, $ori) => $ori->show(resource: 'votes', id: 'none')],
			'ori show person missing'   => ['missing', false, [], fn ($api, $ori) => $ori->show(resource: 'persons', id: 'p-1')],
			'ori show voteevent throws' => ['throws', false, [], fn ($api, $ori) => $ori->show(resource: 'voteevents', id: 'r-1')],
			'ori preflight'             => [$ok, false, [], fn ($api, $ori) => $ori->preflight(resource: 'events')],
			'ori preflight item'        => [$ok, false, [], fn ($api, $ori) => $ori->preflightItem(resource: 'events', id: 'x')],
			'health degraded'           => [$ok, false, [], fn ($api, $ori, $health) => $health->index()],
			'health options'            => [$ok, false, [], fn ($api, $ori, $health) => $health->statusOptions()],
		];
	}//end cases()

	/**
	 * Build the three controllers over recording collaborators.
	 *
	 * @param string $origin The configured overwrite.cli.url
	 *
	 * @return array{0: ApiController, 1: OriController, 2: HealthController}
	 */
	private function controllers(string $origin): array {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(fn (string $key, $default = null) => ($this->params[$key] ?? $default));
		$request->method('getParams')->willReturnCallback(fn () => $this->params);
		$request->method('getHeader')->willReturnCallback(fn (string $name) => (strcasecmp($name, 'Origin') === 0 ? $this->originHeader : ''));

		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValueString')->willReturnCallback(
			function (string $key, string $default = '') use ($origin): string {
				$this->log[] = ['config' => $key, 'default' => $default];
				return $origin;
			}
		);

		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturnCallback(fn () => ($this->loggedIn === true ? $this->createMock(IUser::class) : null));

		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('error')->willReturnCallback(
			function (string $message, array $context = []): void {
				unset($context['exception']);
				$this->log[] = ['error' => $message, 'context' => $context];
			}
		);

		$objects   = $this->objectService();
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id) use ($objects): object {
				$this->log[] = ['container' => $id];
				if ($id !== 'OCA\\OpenRegister\\Service\\ObjectService') {
					throw new RuntimeException('not registered');
				}

				return $objects;
			}
		);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('1.2.3');

		return [
			new ApiController($request, $session, $config, $container, $logger),
			new OriController($request, $config, $container, $logger, new OriSerializer(), $this->voteRule(), $this->personRule()),
			new HealthController($request, $config, $container, $appConfig),
		];
	}//end controllers()

	/**
	 * A recording object service that answers by the current mode.
	 *
	 * @return object The object service.
	 */
	private function objectService(): object {
		$test = $this;
		return new class($test) {
			/**
			 * Keep the test that records.
			 *
			 * @param CorsControllersGoldenTest $test The test.
			 */
			public function __construct(private readonly CorsControllersGoldenTest $test) {
			}//end __construct()

			/**
			 * Record a findAll() call.
			 *
			 * @param array<string, mixed> $config The query.
			 *
			 * @return array<int, array<string, mixed>> The rows.
			 */
			public function findAll(array $config): array {
				$this->test->answer(call: ['findAll' => $config]);
				return [
					['id' => 'a', 'title' => 'First', 'lifecycle' => 'published', 'publicationDate' => '2000-01-01T00:00:00+00:00'],
					['id' => 'b', 'title' => 'Second', 'lifecycle' => 'published', 'publicationDate' => '2999-01-01T00:00:00+00:00'],
				];
			}//end findAll()

			/**
			 * Record a find() call.
			 *
			 * @param string $id       The id.
			 * @param string $register The register.
			 * @param string $schema   The schema.
			 *
			 * @return ObjectEntity|null The entity.
			 */
			public function find(string $id, string $register, string $schema): ?ObjectEntity {
				$this->test->answer(call: ['find' => compact('id', 'register', 'schema')]);
				return $this->test->entity(id: $id);
			}//end find()

			/**
			 * Record a saveObject() call.
			 *
			 * @param array<string, mixed> $object   The body.
			 * @param string               $register The register.
			 * @param string               $schema   The schema.
			 * @param string|null          $uuid     The id.
			 *
			 * @return array<string, mixed> The stored object.
			 */
			public function saveObject(array $object, ObjectSaveOptions $options = new ObjectSaveOptions(), bool $_rbac = true, bool $_multitenancy = true): array {
				$register = (string)$options->target->register;
				$schema = (string)$options->target->schema;
				$uuid = $options->uuid;
				$this->test->answer(call: ['saveObject' => compact('object', 'register', 'schema', 'uuid')]);
				return ['id' => ($uuid ?? 'new'), 'stored' => $object];
			}//end saveObject()
		};
	}//end objectService()

	/**
	 * Record a call and fail it the way the current mode says.
	 *
	 * @param array<string, mixed> $call The call.
	 *
	 * @return void
	 */
	public function answer(array $call): void {
		$this->log[] = $call;
		if ($this->mode === 'throws') {
			throw new RuntimeException('register down');
		}

		if ($this->mode === 'missing') {
			throw new DoesNotExistException('hidden');
		}
	}//end answer()

	/**
	 * The entity find() hands out for an id.
	 *
	 * @param string $id The id.
	 *
	 * @return ObjectEntity|null The entity, or null for id "null".
	 */
	public function entity(string $id): ?ObjectEntity {
		$data = [
			'meeting-pub'    => ['id' => $id, 'title' => 'Raad', 'lifecycle' => 'published'],
			'meeting-draft'  => ['id' => $id, 'title' => 'Raad', 'lifecycle' => 'draft'],
			'motion-pub'     => ['id' => $id, 'title' => 'Motie', 'decisionType' => 'motion', 'isPublished' => 'public'],
			'payload-live'   => ['id' => $id, 'oriType' => 'Besluit', 'title' => 'B', 'publicationDate' => '2000-01-01T00:00:00+00:00'],
			'payload-future' => ['id' => $id, 'oriType' => 'Besluit', 'title' => 'B', 'publicationDate' => '2999-01-01T00:00:00+00:00'],
		][$id] ?? null;
		if ($data === null) {
			return null;
		}

		$entity = $this->getMockBuilder(ObjectEntity::class)->disableOriginalConstructor()->onlyMethods(['jsonSerialize'])->getMock();
		$entity->method('jsonSerialize')->willReturn($data);
		return $entity;
	}//end entity()

	/**
	 * A vote rule that answers from fixed rows and the current mode.
	 *
	 * @return OriVotePublicationRule The rule.
	 */
	private function voteRule(): OriVotePublicationRule {
		$rule = $this->createMock(OriVotePublicationRule::class);
		$rule->method('votes')->willReturnCallback(fn (?string $voter) => $this->ruled(call: 'votes', row: ['id' => 'v-1', 'voter' => $voter]));
		$rule->method('voteEvents')->willReturnCallback(fn () => $this->ruled(call: 'voteEvents', row: ['id' => 'r-1', 'result' => 'pass']));
		$rule->method('vote')->willReturnCallback(fn (string $voteId) => $this->ruledOne(call: 'vote', id: $voteId));
		$rule->method('voteEvent')->willReturnCallback(fn (string $roundId) => $this->ruledOne(call: 'voteEvent', id: $roundId));
		return $rule;
	}//end voteRule()

	/**
	 * A person rule that answers from fixed rows and the current mode.
	 *
	 * @return OriPersonPublicationRule The rule.
	 */
	private function personRule(): OriPersonPublicationRule {
		$rule = $this->createMock(OriPersonPublicationRule::class);
		$rule->method('persons')->willReturnCallback(fn () => $this->ruled(call: 'persons', row: ['id' => 'p-1', 'name' => 'Jan']));
		$rule->method('person')->willReturnCallback(fn (string $personId) => $this->ruledOne(call: 'person', id: $personId));
		return $rule;
	}//end personRule()

	/**
	 * Record a ruled collection call.
	 *
	 * @param string               $call The rule method.
	 * @param array<string, mixed> $row  The one row it answers.
	 *
	 * @return list<array<string, mixed>> The rows.
	 */
	private function ruled(string $call, array $row): array {
		$this->answer(call: ['rule' => $call]);
		return [$row];
	}//end ruled()

	/**
	 * Record a ruled single-row call.
	 *
	 * @param string $call The rule method.
	 * @param string $id   The id asked for.
	 *
	 * @return array<string, mixed>|null The row, or null for id "none".
	 */
	private function ruledOne(string $call, string $id): ?array {
		$this->answer(call: ['rule' => $call, 'id' => $id]);
		return ($id === 'none' ? null : ['id' => $id, 'name' => 'Row']);
	}//end ruledOne()

	/**
	 * The headers the controller itself set on a response.
	 *
	 * @param Response $response The response.
	 *
	 * @return array<string, string> The controller-set headers.
	 */
	private function controllerHeaders(Response $response): array {
		return (array)(new ReflectionProperty(Response::class, 'headers'))->getValue($response);
	}//end controllerHeaders()
}//end class
