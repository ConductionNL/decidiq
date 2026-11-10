<?php
/**
 * Golden test for the five MCP tools, through the provider.
 *
 * Invokes every tool for each kind of caller (anonymous, admin, chair,
 * participant, stranger) over argument and meeting shapes, and records the
 * tool result plus every object-service, participant, admin, transition and
 * writer call by parameter name and in order. The expected values live in
 * tests/fixtures/mcp-tools-golden.json; run with RECORD_MCP_TOOLS_GOLDEN=1 to
 * write them, then run again without it.
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\Mcp
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/mcp-tools/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Mcp;

use OCA\Decidiq\Tests\Unit\Support\SaveCallShape;
use OCA\Decidiq\Mcp\DecidiqToolProvider;
use OCA\Decidiq\Mcp\McpActionItemTools;
use OCA\Decidiq\Mcp\McpMeetingTools;
use OCA\Decidiq\Service\ActionItemWriter;
use OCA\Decidiq\Service\MeetingService;
use OCA\Decidiq\Service\ParticipantResolver;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use RuntimeException;
use Throwable;

/**
 * Golden test for the MCP tools.
 *
 * @covers \OCA\Decidiq\Mcp\DecidiqToolProvider
 * @covers \OCA\Decidiq\Mcp\McpActionItemTools
 * @covers \OCA\Decidiq\Mcp\McpMeetingTools
 * @covers \OCA\Decidiq\Mcp\McpMeetingGate
 * @covers \OCA\Decidiq\Mcp\McpMeetingScopeResolver
 * @covers \OCA\Decidiq\Mcp\McpArgumentValidator
 * @covers \OCA\Decidiq\Mcp\McpSourceFormatter
 */
class McpToolsGoldenTest extends TestCase {

	private const GOLDEN = __DIR__ . '/../../fixtures/mcp-tools-golden.json';

	private const SCHEDULED = '11111111-1111-4111-8111-111111111111';
	private const OPENED    = '22222222-2222-4222-8222-222222222222';
	private const GONE      = '33333333-3333-4333-8333-333333333333';
	private const THROWS    = '44444444-4444-4444-8444-444444444444';
	private const STUCK     = '55555555-5555-4555-8555-555555555555';

	/**
	 * Every recorded call of the current case.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $log = [];

	/**
	 * The recording object service.
	 *
	 * @var ObjectServiceInterface|null
	 */
	private ?ObjectServiceInterface $objects = null;

	/**
	 * Every tool answer matches the recording.
	 *
	 * @return void
	 */
	public function testEveryToolAnswerMatchesTheRecording(): void {
		$actual = [];
		foreach (['anonymous', 'admin', 'carla', 'mia', 'sam'] as $user) {
			foreach ($this->calls() as $name => [$tool, $args]) {
				$this->log = [];
				try {
					$result = ['returns' => $this->provider(user: $user)->invokeTool($tool, $args)];
				} catch (Throwable $e) {
					$result = ['throws' => get_class($e), 'message' => $e->getMessage()];
				}

				$actual[$user][$name] = $result + ['calls' => $this->log];
			}
		}

		foreach ($actual as $user => $rows) {
			self::assertSame($rows['details scheduled'], $rows['details forged'], "A forged caller argument changed the answer for {$user}.");
		}

		$json = json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
		$json = preg_replace('/\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d+)?([+-]\d{2}:\d{2}|Z)/', '<date>', $json);
		if (getenv('RECORD_MCP_TOOLS_GOLDEN') === '1') {
			file_put_contents(self::GOLDEN, $json);
		}

		self::assertSame(file_get_contents(self::GOLDEN), $json);
	}//end testEveryToolAnswerMatchesTheRecording()

	/**
	 * The tool calls: name to [tool id, arguments].
	 *
	 * @return array<string, array{0: string, 1: array<string, mixed>}>
	 */
	private function calls(): array {
		return [
			'items default'      => ['decidesk.listOpenActionItems', []],
			'items all'          => ['decidesk.listOpenActionItems', ['scope' => 'all', 'limit' => 5]],
			'items bad scope'    => ['decidesk.listOpenActionItems', ['scope' => 'bogus']],
			'items bad limit'    => ['decidesk.listOpenActionItems', ['limit' => 0]],
			'meetings default'   => ['decidesk.listRecentMeetings', []],
			'meetings closed'    => ['decidesk.listRecentMeetings', ['statusFilter' => 'closed', 'limit' => 1]],
			'meetings bad limit' => ['decidesk.listRecentMeetings', ['limit' => 99]],
			'details scheduled'  => ['decidesk.getMeetingDetails', ['meetingUuid' => self::SCHEDULED]],
			'details forged'     => ['decidesk.getMeetingDetails', ['meetingUuid' => self::SCHEDULED, 'userId' => 'admin', 'callerUid' => 'admin', 'owner' => 'admin']],
			'details gone'       => ['decidesk.getMeetingDetails', ['meetingUuid' => self::GONE]],
			'details throws'     => ['decidesk.getMeetingDetails', ['meetingUuid' => self::THROWS]],
			'details bad uuid'   => ['decidesk.getMeetingDetails', ['meetingUuid' => 'not-a-uuid']],
			'details no uuid'    => ['decidesk.getMeetingDetails', []],
			'start scheduled'    => ['decidesk.startMeeting', ['meetingUuid' => self::SCHEDULED]],
			'start opened'       => ['decidesk.startMeeting', ['meetingUuid' => self::OPENED]],
			'start stuck'        => ['decidesk.startMeeting', ['meetingUuid' => self::STUCK]],
			'start gone'         => ['decidesk.startMeeting', ['meetingUuid' => self::GONE]],
			'add item'           => ['decidesk.addActionItem', ['meetingUuid' => self::SCHEDULED, 'title' => 'Follow up', 'assigneeUserId' => 'mia', 'dueDate' => '2030-01-31']],
			'add short title'    => ['decidesk.addActionItem', ['meetingUuid' => self::SCHEDULED, 'title' => 'ab']],
			'add bad date'       => ['decidesk.addActionItem', ['meetingUuid' => self::SCHEDULED, 'title' => 'Follow up', 'dueDate' => '31-01-2030']],
			'add write fails'    => ['decidesk.addActionItem', ['meetingUuid' => self::SCHEDULED, 'title' => 'fail me']],
			'add gone meeting'   => ['decidesk.addActionItem', ['meetingUuid' => self::GONE, 'title' => 'Follow up']],
			'unknown tool'       => ['decidesk.nope', []],
		];
	}//end calls()

	/**
	 * Build the provider over recording collaborators for a caller.
	 *
	 * @param string $user The caller uid, or "anonymous"
	 *
	 * @return DecidiqToolProvider The provider.
	 */
	private function provider(string $user): DecidiqToolProvider {
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

		$participants = $this->createMock(ParticipantResolver::class);
		$participants->method('isParticipant')->willReturnCallback(
			function (string $meetingId, string $nextcloudUid): bool {
				$this->log[] = ['isParticipant' => compact('meetingId', 'nextcloudUid')];
				return in_array($nextcloudUid, ['carla', 'mia'], true) && $meetingId !== self::STUCK;
			}
		);

		$meetings = $this->createMock(MeetingService::class);
		$meetings->method('transition')->willReturnCallback(
			function (string $meetingId, string $action, string $currentUserId): array {
				$this->log[] = ['transition' => compact('meetingId', 'action', 'currentUserId')];
				return ($meetingId === self::STUCK ? ['success' => false, 'message' => 'Transition refused'] : ['success' => true]);
			}
		);

		$writer = $this->createMock(ActionItemWriter::class);
		$writer->method('create')->willReturnCallback(
			function (array $item): ?array {
				$this->log[] = ['create' => $item];
				return (($item['title'] ?? '') === 'fail me' ? null : ['uid' => 'ai-new'] + $item);
			}
		);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id) use ($writer): object {
				$this->log[] = ['container' => $id];
				return $writer;
			}
		);

		$logger = $this->createMock(LoggerInterface::class);
		foreach (['warning', 'error', 'info', 'debug'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string $message, array $context = []) use ($level): void {
					$this->log[] = [$level => $message, 'context' => $context];
				}
			);
		}

		$objects       = $this->createMock(ObjectServiceInterface::class);
		$this->objects = $objects;
		foreach (['find', 'findAll'] as $method) {
			$objects->method($method)->willReturnCallback(fn (...$a) => $this->call(method: $method, args: $a));
		}

		$shared = ['userSession' => $session, 'groupManager' => $groups, 'logger' => $logger, 'participantResolver' => $participants, 'objectService' => $objects];
		return new DecidiqToolProvider(
			actionItemTools: new McpActionItemTools(...$shared, container: $container),
			meetingTools: new McpMeetingTools(...$shared, meetingService: $meetings),
		);
	}//end provider()

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
			return $this->meeting(id: (string)$named['id']);
		}

		$filters = (array)(reset($named)['filters'] ?? []);
		return match ($filters['schema'] ?? '') {
			'participant' => [$this->entity(data: ['id' => 'p-mia', 'relations' => [['schema' => 'governance-body', 'id' => 'gb-1'], ['schema' => 'other', 'id' => 'x']]])],
			'meeting'     => [
				['id' => self::SCHEDULED, 'title' => 'Raad', 'date' => '2030-02-01', 'lifecycle' => 'scheduled'],
				$this->entity(data: ['uuid' => self::OPENED, 'title' => 'Commissie', 'date' => '2030-03-01', 'lifecycle' => 'opened']),
				(object) ['id' => self::STUCK, 'name' => 'Vastgelopen', 'date' => '2029-12-01'],
			],
			'action-item' => [
				['id' => 'ai-1', 'title' => 'Mine', 'assignee' => 'mia', 'taskStatus' => 'open', 'relations' => ['Meeting' => [self::SCHEDULED]]],
				['id' => 'ai-2', 'name' => 'Theirs', 'assignee' => 'sam', 'taskStatus' => 'open', 'meeting' => self::OPENED],
				['id' => 'ai-3', 'title' => 'Done', 'assignee' => 'mia', 'taskStatus' => 'completed'],
			],
			default       => [['id' => 'x-1', 'title' => 'Item ' . ($filters['schema'] ?? '?'), 'orderNumber' => 2], ['id' => 'x-0', 'name' => 'First', 'orderNumber' => 1]],
		};
	}//end call()

	/**
	 * The meeting find() answers for an id.
	 *
	 * @param string $id The meeting id.
	 *
	 * @return ObjectEntity The entity; an unknown id throws, as OpenRegister's find() does.
	 */
	private function meeting(string $id): ObjectEntity {
		$data = match ($id) {
			self::SCHEDULED => ['id' => $id, 'title' => 'Raad', 'chair' => 'carla', 'lifecycle' => 'scheduled'],
			self::OPENED    => ['id' => $id, 'title' => 'Commissie', 'chair' => 'carla', 'lifecycle' => 'opened'],
			self::STUCK     => ['id' => $id, 'title' => 'Vast', 'chair' => 'carla', 'lifecycle' => 'scheduled'],
			self::THROWS    => new RuntimeException('register down'),
			default         => new DoesNotExistException('Object not found'),
		};
		if ($data instanceof Throwable) {
			throw $data;
		}

		return $this->entity(data: $data);
	}//end meeting()

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
	private function namedAsDeclared(string $method, array $args): array {
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
	}//end namedAsDeclared()

	/**
	 * The arguments by name; a saveObject() call is expanded back into its old
	 * parameter names, so the recording describes the write, not the call shape.
	 *
	 * @param mixed $method See namedAsDeclared()
	 * @param mixed $args See namedAsDeclared()
	 *
	 * @return array<string, mixed>
	 */
	private function named(string $method, array $args): array {
		$named = $this->namedAsDeclared($method, $args);
		if ($method === 'saveObject') {
			return SaveCallShape::legacyNamed(named: $named);
		}

		return $named;
	}//end named()
}//end class
