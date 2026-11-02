<?php
/**
 * Golden test for motion co-authoring, controller and service together.
 *
 * Drives every MotionCoauthorController endpoint, over the real
 * MotionCoauthorService, for each kind of caller (anonymous, proposer,
 * co-author, stranger, admin) and each motion shape, and records the answer
 * plus every object-service and log call by parameter name and in order. The
 * expected values live in tests/fixtures/motion-coauthor-golden.json; run with
 * RECORD_MOTION_COAUTHOR_GOLDEN=1 to write them, then run again without it.
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
 * @spec openspec/changes/p4-collaboration/tasks.md#task-9.3
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Controller;

use DateTimeImmutable;
use DateTimeInterface;
use OCA\Decidiq\Controller\MotionCoauthorController;
use OCA\Decidiq\Service\MotionCoauthorService;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use RuntimeException;
use Throwable;

/**
 * Golden test for MotionCoauthorController and MotionCoauthorService.
 *
 * @covers \OCA\Decidiq\Controller\MotionCoauthorController
 * @covers \OCA\Decidiq\Service\MotionCoauthorService
 */
class MotionCoauthorGoldenTest extends TestCase {

	private const GOLDEN = __DIR__ . '/../../fixtures/motion-coauthor-golden.json';

	/**
	 * Every recorded call of the current case.
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
	 * The recording object service of the current case.
	 *
	 * @var ObjectServiceInterface|null
	 */
	private ?ObjectServiceInterface $objects = null;

	/**
	 * Every answer matches the recording.
	 *
	 * @return void
	 */
	public function testEveryAnswerMatchesTheRecording(): void {
		$calls = [
			'add new'      => [['personId' => 'p-new'], fn ($c, $id) => $c->addCoauthor($id)],
			'add missing'  => [[], fn ($c, $id) => $c->addCoauthor($id)],
			'add existing' => [['personId' => 'p-bob'], fn ($c, $id) => $c->addCoauthor($id)],
			'remove'       => [[], fn ($c, $id) => $c->removeCoauthor($id, 'p-bob')],
			'text'         => [['text' => "One\n\nTwo changed", 'summary' => 'Edit'], fn ($c, $id) => $c->updateText($id)],
			'text missing' => [['summary' => 'Edit'], fn ($c, $id) => $c->updateText($id)],
			'history'      => [[], fn ($c, $id) => $c->history($id)],
			'add forged'   => [['personId' => 'p-new', 'userId' => 'alice', 'callerUid' => 'alice', 'owner' => 'alice', 'author' => 'alice'], fn ($c, $id) => $c->addCoauthor($id)],
			'text forged'  => [['text' => 'New', 'userId' => 'alice', 'callerUid' => 'alice', 'author' => 'alice'], fn ($c, $id) => $c->updateText($id)],
		];

		$actual = [];
		foreach (array_keys($this->motions()) as $motionId) {
			foreach (['anonymous', 'alice', 'bob', 'eve', 'admin'] as $user) {
				foreach ($calls as $name => [$params, $call]) {
					$this->log    = [];
					$this->params = $params;
					try {
						$response = $call($this->controller(user: $user), $motionId);
						$result   = ['status' => $response->getStatus(), 'data' => $response->getData()];
					} catch (Throwable $e) {
						$result = ['throws' => get_class($e), 'message' => $e->getMessage()];
					}

					$actual[$motionId][$user][$name] = $result + ['calls' => $this->log];
				}
			}

			$this->log = [];
			try {
				$result = ['returns' => $this->service()->captureVersion($motionId, 'carol', 'Snapshot')];
			} catch (Throwable $e) {
				$result = ['throws' => get_class($e), 'message' => $e->getMessage()];
			}

			$actual[$motionId]['service']['captureVersion'] = $result + ['calls' => $this->log];
		}

		$json = json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
		$json = preg_replace('/\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}/', '<date>', $json);
		if (getenv('RECORD_MOTION_COAUTHOR_GOLDEN') === '1') {
			file_put_contents(self::GOLDEN, $json);
		}

		self::assertSame(file_get_contents(self::GOLDEN), $json);
	}//end testEveryAnswerMatchesTheRecording()

	/**
	 * Motion shapes by id: the serialised object, null for none, or an exception.
	 *
	 * @return array<string, mixed>
	 */
	private function motions(): array {
		$base   = ['decisionType' => 'motion', '@self' => ['owner' => 'alice'], 'coAuthors' => ['p-bob'], 'text' => "One\n\nTwo"];
		$recent = (new DateTimeImmutable('-60 seconds'))->format(DateTimeInterface::ATOM);
		return [
			'gone'          => null,
			'not-motion'    => ['decisionType' => 'amendment', '@self' => ['owner' => 'alice']],
			'find-throws'   => new RuntimeException('register down'),
			'owned'         => $base,
			'owner-field'   => ['decisionType' => 'motion', 'owner' => 'alice', 'text' => 'Plain'],
			'recent-edit'   => $base + ['versionHistory' => [['author' => 'carol', 'timestamp' => $recent, 'text' => 'Old', 'summary' => 's']]],
			'old-edit'      => $base + ['versionHistory' => [['author' => 'carol', 'timestamp' => '2001-01-01T00:00:00+00:00', 'text' => 'Old', 'summary' => 's']]],
			'save-fails'    => $base,
		];
	}//end motions()

	/**
	 * Build the controller over the real service for a caller.
	 *
	 * @param string $user The caller: anonymous, alice, bob, eve or admin
	 *
	 * @return MotionCoauthorController The controller.
	 */
	private function controller(string $user): MotionCoauthorController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(fn (string $key, $default = null) => ($this->params[$key] ?? $default));
		$request->method('getParams')->willReturnCallback(fn (): array => $this->params);

		$session = $this->createMock(IUserSession::class);
		$account = null;
		if ($user !== 'anonymous') {
			$account = $this->createMock(IUser::class);
			$account->method('getUID')->willReturn($user);
		}

		$session->method('getUser')->willReturn($account);

		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturnCallback(
			function (string $uid): bool {
				$this->log[] = ['isAdmin' => $uid];
				return $uid === 'admin';
			}
		);

		return new MotionCoauthorController($request, $this->service(), $session, $groups);
	}//end controller()

	/**
	 * The real service over a recording object service and logger.
	 *
	 * @return MotionCoauthorService The service.
	 */
	private function service(): MotionCoauthorService {
		$objects       = $this->createMock(ObjectServiceInterface::class);
		$this->objects = $objects;
		foreach (['setRegister', 'setSchema', 'find', 'findAll', 'saveObject'] as $method) {
			$objects->method($method)->willReturnCallback(fn (...$a) => $this->call(method: $method, args: $a));
		}

		$logger = $this->createMock(LoggerInterface::class);
		foreach (['warning', 'error', 'info', 'debug'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string $message, array $context = []) use ($level): void {
					$this->log[] = [$level => $message, 'context' => $context];
				}
			);
		}

		return new MotionCoauthorService(logger: $logger, objectService: $objects);
	}//end service()

	/**
	 * Record an object-service call and answer it.
	 *
	 * @param string       $method The method called
	 * @param array<mixed> $args   The positional arguments
	 *
	 * @return mixed The answer.
	 */
	private function call(string $method, array $args): mixed {
		$named       = $this->named(method: $method, args: $args);
		$this->log[] = [$method => $named];
		if ($method === 'find') {
			$shape = ($this->motions()[(string)$named['id']] ?? null);
			if ($shape instanceof Throwable) {
				throw $shape;
			}

			return ($shape === null ? null : $this->entity(data: $shape));
		}

		if ($method === 'findAll') {
			$uid = ($named['config']['filters']['nextcloudUserId'] ?? ($named['query']['filters']['nextcloudUserId'] ?? ''));
			return ($uid === 'bob' ? [$this->entity(data: ['uuid' => 'p-bob'])] : []);
		}

		if ($method === 'saveObject') {
			if (($named['uuid'] ?? null) === 'save-fails') {
				throw new RuntimeException('save refused');
			}

			return $this->entity(data: (array)$named['object']);
		}

		return $this->objects;
	}//end call()

	/**
	 * An entity double carrying the given data.
	 *
	 * @param array<string, mixed> $data The object data.
	 *
	 * @return ObjectEntity The entity.
	 */
	private function entity(array $data): ObjectEntity {
		$entity = $this->getMockBuilder(ObjectEntity::class)->disableOriginalConstructor()->onlyMethods(['getObject', 'jsonSerialize'])->getMock();
		$entity->method('getObject')->willReturn($data);
		$entity->method('jsonSerialize')->willReturn($data);
		return $entity;
	}//end entity()

	/**
	 * Name positional object-service arguments, leaving out defaults.
	 *
	 * @param string       $method The method
	 * @param array<mixed> $args   The positional arguments
	 *
	 * @return array<string, mixed> The named arguments.
	 */
	private function named(string $method, array $args): array {
		$out = [];
		foreach ((new ReflectionMethod(ObjectServiceInterface::class, $method))->getParameters() as $i => $param) {
			if (array_key_exists($i, $args) === false) {
				continue;
			}

			if ($param->isDefaultValueAvailable() === true && $param->getDefaultValue() === $args[$i]) {
				continue;
			}

			$out[$param->getName()] = $args[$i];
		}

		return $out;
	}//end named()
}//end class
