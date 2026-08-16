<?php

/**
 * Unit tests for DecideskToolProvider.
 *
 * Covers: getAppId, getTools catalogue, invokeTool dispatch, per-tool happy
 * paths, per-tool auth-failure paths, UUID validation, sources array shape,
 * source truncation, and state-machine guard.
 *
 * @category Test
 * @package  OCA\Decidesk\Tests\Unit\Mcp
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/decidesk-mcp-tools/specs/mcp-tools/spec.md#REQ-DMCP-010
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Decidesk\Tests\Unit\Mcp;

use OCA\Decidesk\Mcp\DecideskToolProvider;
use OCA\Decidesk\Service\MeetingService;
use OCA\Decidesk\Service\ParticipantResolver;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Unit test suite for DecideskToolProvider.
 *
 * Every test in this class runs in isolation with mocked services.
 * The stubs at tests/Stubs/Mcp/IMcpToolProvider.php satisfy the interface
 * declaration when the openregister runtime is absent.
 *
 * Meeting read now goes through ObjectService::find() (ADR-022: no pass-through
 * CRUD in MeetingService). Tests mock the container/ObjectService accordingly.
 *
 * @spec openspec/changes/decidesk-mcp-tools/specs/mcp-tools/spec.md#REQ-DMCP-010
 */
class DecideskToolProviderTest extends TestCase {

	/**
	 * Provider under test.
	 *
	 * @var DecideskToolProvider
	 */
	private DecideskToolProvider $provider;

	/**
	 * Mock MeetingService.
	 *
	 * @var MeetingService&MockObject
	 */
	private MeetingService&MockObject $meetingService;

	/**
	 * Mock IUserSession.
	 *
	 * @var IUserSession&MockObject
	 */
	private IUserSession&MockObject $userSession;

	/**
	 * Mock IGroupManager.
	 *
	 * @var IGroupManager&MockObject
	 */
	private IGroupManager&MockObject $groupManager;

	/**
	 * Mock ContainerInterface.
	 *
	 * @var ContainerInterface&MockObject
	 */
	private ContainerInterface&MockObject $container;

	/**
	 * Mock LoggerInterface.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Mock ParticipantResolver.
	 *
	 * @var ParticipantResolver&MockObject
	 */
	private ParticipantResolver&MockObject $participantResolver;

	/**
	 * Mock of OpenRegister's published object-service contract.
	 *
	 * ADR-083/084: the provider is handed this directly instead of resolving
	 * `OCA\OpenRegister\Service\ObjectService` from the container, so a test
	 * must configure THIS instance — one parked on the container mock is never
	 * consulted.
	 *
	 * @var ObjectServiceInterface&MockObject
	 */
	private ObjectServiceInterface&MockObject $objectService;

	/**
	 * The UUID fixture used in tests.
	 *
	 * @var string
	 */
	private string $meetingUuid = 'a1b2c3d4-e5f6-7890-abcd-ef1234567890';

	/**
	 * The null UUID fixture.
	 *
	 * @var string
	 */
	private string $nullUuid = '00000000-0000-0000-0000-000000000000';

	/**
	 * Set up mocks and provider instance.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->meetingService = $this->createMock(MeetingService::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->container = $this->createMock(ContainerInterface::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->participantResolver = $this->createMock(ParticipantResolver::class);
		$this->objectService = $this->createMock(ObjectServiceInterface::class);

		$this->provider = new DecideskToolProvider(
			meetingService: $this->meetingService,
			userSession: $this->userSession,
			groupManager: $this->groupManager,
			container: $this->container,
			logger: $this->logger,
			participantResolver: $this->participantResolver,
			objectService: $this->objectService,
		);

	}//end setUp()

	/**
	 * Build a mock IUser that returns the given UID.
	 *
	 * @param string $uid The user ID
	 *
	 * @return IUser&MockObject
	 */
	private function makeUser(string $uid): IUser&MockObject {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		return $user;
	}//end makeUser()

	/**
	 * Configure userSession to return the given user id (or null if empty).
	 *
	 * @param string $uid The user id, or empty string for anonymous
	 *
	 * @return void
	 */
	private function setCurrentUser(string $uid): void {
		if ($uid === '') {
			$this->userSession->method('getUser')->willReturn(null);
		} else {
			$this->userSession->method('getUser')->willReturn($this->makeUser($uid));
		}

		$this->groupManager->method('isAdmin')->willReturn(false);

	}//end setCurrentUser()

	/**
	 * Configure userSession to return an admin user.
	 *
	 * @param string $uid The admin user id
	 *
	 * @return void
	 */
	private function setAdminUser(string $uid): void {
		$this->userSession->method('getUser')->willReturn($this->makeUser($uid));
		$this->groupManager->method('isAdmin')->with($uid)->willReturn(true);

	}//end setAdminUser()

	/**
	 * Configure ParticipantResolver to accept the given user as a participant in any meeting.
	 *
	 * @param string $uid The user id to accept as participant
	 *
	 * @return void
	 */
	private function setParticipant(string $uid): void {
		$this->participantResolver
			->method('isParticipant')
			->willReturnCallback(static fn ($meetingId, $nextcloudUid) => $nextcloudUid === $uid);

	}//end setParticipant()

	/**
	 * Build a minimal meeting fixture array.
	 *
	 * @param string $uuid The meeting UUID
	 * @param string $lifecycle The lifecycle status
	 * @param string $chairUserId The chair user id
	 * @param array $participants Additional participant user ids
	 *
	 * @return array<string, mixed>
	 */
	private function makeMeeting(
		string $uuid,
		string $lifecycle = 'scheduled',
		string $chairUserId = 'alice',
		array $participants = [],
	): array {
		return [
			'uuid' => $uuid,
			'id' => $uuid,
			'title' => "Meeting {$uuid}",
			'lifecycle' => $lifecycle,
			'chair' => $chairUserId,
			'participants' => array_map(static fn ($uid) => ['userId' => $uid], $participants),
		];

	}//end makeMeeting()

	/**
	 * Wrap a meeting array in a mock ObjectEntity.
	 *
	 * The provider now calls `$objectService->find()` (returns ObjectEntity|null)
	 * then `->jsonSerialize()` — this helper builds that mock.
	 *
	 * @param array<string, mixed>|null $meetingArray The meeting data, or null to simulate not-found
	 *
	 * @return ObjectEntity&MockObject|null
	 */
	private function makeObjectEntityMock(?array $meetingArray): ?MockObject {
		if ($meetingArray === null) {
			return null;
		}

		$entity = $this->createMock(ObjectEntity::class);
		$entity->method('jsonSerialize')->willReturn($meetingArray);
		return $entity;
	}//end makeObjectEntityMock()

	/**
	 * Configure the INJECTED object-service double.
	 *
	 * When $meetingEntity is provided, `find()` returns it (or null for not-found).
	 * findAll() returns an empty array by default.
	 *
	 * @param ObjectEntity&MockObject|null $meetingEntity Entity to return from find(), or null
	 * @param callable|null $findAllCallback Optional callback for findAll responses
	 *
	 * @return ObjectServiceInterface&MockObject
	 */
	private function mockObjectService(?MockObject $meetingEntity = null, ?callable $findAllCallback = null): MockObject {
		if ($meetingEntity !== null) {
			$this->objectService->method('find')->willReturn($meetingEntity);
		} else {
			$this->objectService->method('find')->willReturn(null);
		}

		if ($findAllCallback !== null) {
			$this->objectService->method('findAll')->willReturnCallback($findAllCallback);
		} else {
			$this->objectService->method('findAll')->willReturn([]);
		}

		return $this->objectService;
	}//end mockObjectService()

	// =========================================================================
	// Task 5.2 — getAppId + getTools catalogue
	// =========================================================================

	/**
	 * getAppId returns the canonical slug "decidesk".
	 *
	 * @return void
	 *
	 * @spec openspec/changes/decidesk-mcp-tools/specs/mcp-tools/spec.md#REQ-DMCP-001
	 */
	public function testGetAppId(): void {
		self::assertSame('decidesk', $this->provider->getAppId());

	}//end testGetAppId()

	/**
	 * getTools returns exactly 5 tools with canonical ids.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/decidesk-mcp-tools/specs/mcp-tools/spec.md#REQ-DMCP-002
	 */
	public function testGetToolsReturnsFiveCanonicalIds(): void {
		$tools = $this->provider->getTools();

		self::assertCount(5, $tools);

		$expectedIds = [
			'decidesk.listOpenActionItems',
			'decidesk.listRecentMeetings',
			'decidesk.getMeetingDetails',
			'decidesk.startMeeting',
			'decidesk.addActionItem',
		];

		$actualIds = array_column($tools, 'id');
		self::assertEqualsCanonicalizing($expectedIds, $actualIds);

	}//end testGetToolsReturnsFiveCanonicalIds()

	/**
	 * Every tool id starts with "decidesk." and every descriptor has required keys.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/decidesk-mcp-tools/specs/mcp-tools/spec.md#REQ-DMCP-002
	 */
	public function testGetToolsAllIdsNamespacedAndHaveRequiredKeys(): void {
		$tools = $this->provider->getTools();

		foreach ($tools as $tool) {
			// All required keys are non-empty.
			self::assertNotEmpty($tool['id']);
			self::assertNotEmpty($tool['name']);
			self::assertNotEmpty($tool['description']);
			self::assertNotEmpty($tool['inputSchema']);

			// Id starts with "decidesk.".
			self::assertStringStartsWith('decidesk.', $tool['id']);

			// inputSchema has type=object and properties key.
			self::assertSame('object', $tool['inputSchema']['type']);
			self::assertArrayHasKey('properties', $tool['inputSchema']);
		}//end foreach

	}//end testGetToolsAllIdsNamespacedAndHaveRequiredKeys()

	// =========================================================================
	// Task 5.3 — unknown tool returns structured error
	// =========================================================================

	/**
	 * invokeTool with an unknown id returns a structured error, no throw.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/decidesk-mcp-tools/specs/mcp-tools/spec.md#REQ-DMCP-003
	 */
	public function testInvokeUnknownToolReturnsStructuredError(): void {
		$result = $this->provider->invokeTool('decidesk.doesNotExist', []);

		self::assertTrue($result['isError']);
		self::assertSame('unknown_tool', $result['error']);
		self::assertIsString($result['message']);
		self::assertNotEmpty($result['message']);

	}//end testInvokeUnknownToolReturnsStructuredError()

	// =========================================================================
	// Task 5.4 — invalid UUID returns invalid_arguments, service never called
	// =========================================================================

	/**
	 * startMeeting with invalid UUID returns invalid_arguments; services not called.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/decidesk-mcp-tools/specs/mcp-tools/spec.md#REQ-DMCP-007
	 */
	public function testInvalidUuidStartMeetingReturnsInvalidArguments(): void {
		$this->meetingService->expects(self::never())->method('transition');

		$result = $this->provider->invokeTool('decidesk.startMeeting', ['meetingUuid' => 'abc']);

		self::assertTrue($result['isError']);
		self::assertSame('invalid_arguments', $result['error']);

	}//end testInvalidUuidStartMeetingReturnsInvalidArguments()

	/**
	 * getMeetingDetails with invalid UUID returns invalid_arguments; service not called.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/decidesk-mcp-tools/specs/mcp-tools/spec.md#REQ-DMCP-007
	 */
	public function testInvalidUuidGetMeetingDetailsReturnsInvalidArguments(): void {
		$result = $this->provider->invokeTool('decidesk.getMeetingDetails', ['meetingUuid' => 'abc']);

		self::assertTrue($result['isError']);
		self::assertSame('invalid_arguments', $result['error']);

	}//end testInvalidUuidGetMeetingDetailsReturnsInvalidArguments()

	/**
	 * addActionItem with invalid UUID returns invalid_arguments; service not called.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/decidesk-mcp-tools/specs/mcp-tools/spec.md#REQ-DMCP-007
	 */
	public function testInvalidUuidAddActionItemReturnsInvalidArguments(): void {
		// Argument validation runs before any ObjectService resolution, so a
		// malformed UUID never reaches the ActionItem write path.
		$result = $this->provider->invokeTool(
			'decidesk.addActionItem',
			['meetingUuid' => 'abc', 'title' => 'Test task']
		);

		self::assertTrue($result['isError']);
		self::assertSame('invalid_arguments', $result['error']);

	}//end testInvalidUuidAddActionItemReturnsInvalidArguments()

	// =========================================================================
	// Task 5.5 — per-tool happy-path tests
	// =========================================================================

	/**
	 * listOpenActionItems happy path returns items + sources.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/decidesk-mcp-tools/specs/mcp-tools/spec.md#REQ-DMCP-006
	 */
	public function testListOpenActionItems_happyPath(): void {
		$this->setCurrentUser('alice');

		$this->objectService->method('findAll')->willReturn(
			[
				['uuid' => 'ai-uuid-1', 'title' => 'Fix bug'],
				['uuid' => 'ai-uuid-2', 'title' => 'Write docs'],
			]
		);

		$result = $this->provider->invokeTool('decidesk.listOpenActionItems', ['scope' => 'mine', 'limit' => 10]);

		self::assertTrue($result['success']);
		self::assertCount(2, $result['items']);
		self::assertCount(2, $result['sources']);

		$source = $result['sources'][0];
		self::assertArrayHasKey('type', $source);
		self::assertArrayHasKey('uuid', $source);
		self::assertArrayHasKey('url', $source);
		self::assertArrayHasKey('label', $source);
		self::assertSame('decidesk.actionItem', $source['type']);

	}//end testListOpenActionItems_happyPath()

	/**
	 * listOpenActionItems with invalid scope returns invalid_arguments.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/decidesk-mcp-tools/specs/mcp-tools/spec.md#REQ-DMCP-003
	 */
	public function testListOpenActionItems_invalidScope_returnsInvalidArguments(): void {
		$result = $this->provider->invokeTool('decidesk.listOpenActionItems', ['scope' => 'unknown']);

		self::assertTrue($result['isError']);
		self::assertSame('invalid_arguments', $result['error']);

	}//end testListOpenActionItems_invalidScope_returnsInvalidArguments()

	/**
	 * listOpenActionItems with out-of-range limit returns invalid_arguments.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/decidesk-mcp-tools/specs/mcp-tools/spec.md#REQ-DMCP-003
	 */
	public function testListOpenActionItems_invalidLimit_returnsInvalidArguments(): void {
		$result = $this->provider->invokeTool('decidesk.listOpenActionItems', ['limit' => 99]);

		self::assertTrue($result['isError']);
		self::assertSame('invalid_arguments', $result['error']);

	}//end testListOpenActionItems_invalidLimit_returnsInvalidArguments()

	/**
	 * listRecentMeetings happy path returns meetings ordered newest-first.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/decidesk-mcp-tools/specs/mcp-tools/spec.md#REQ-DMCP-006
	 */
	public function testListRecentMeetings_happyPath(): void {
		// Use admin so getCallerMeetingUuids returns null (unrestricted) and
		// the meeting-UUID scoping filter is bypassed.
		$this->setAdminUser('alice');

		$this->objectService->method('findAll')->willReturn(
			[
				['uuid' => 'mtg-uuid-2', 'title' => 'Newer meeting', 'scheduledDate' => '2026-05-10'],
				['uuid' => 'mtg-uuid-1', 'title' => 'Older meeting', 'scheduledDate' => '2026-05-01'],
			]
		);

		$result = $this->provider->invokeTool('decidesk.listRecentMeetings', ['limit' => 10]);

		self::assertTrue($result['success']);
		self::assertCount(2, $result['meetings']);
		self::assertCount(2, $result['sources']);

		// Confirm the first result is the newest (as returned by OR, ordered DESC).
		self::assertSame('Newer meeting', $result['meetings'][0]['title']);

		$source = $result['sources'][0];
		self::assertSame('decidesk.meeting', $source['type']);
		self::assertStringContainsString('/apps/decidesk/meetings/', $source['url']);

	}//end testListRecentMeetings_happyPath()

	/**
	 * getMeetingDetails happy path returns meeting + agenda + decisions + actions + sources.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/decidesk-mcp-tools/specs/mcp-tools/spec.md#REQ-DMCP-006
	 */
	public function testGetMeetingDetails_happyPath(): void {
		$this->setCurrentUser('alice');
		$this->setParticipant('alice');

		$meeting = $this->makeMeeting($this->meetingUuid, 'scheduled', 'alice');
		$meetingEntity = $this->makeObjectEntityMock($meeting);

		$this->objectService->method('find')->willReturn($meetingEntity);
		$this->objectService->method('findAll')->willReturnCallback(
			function (array $config) {
				$schema = $config['filters']['schema'] ?? '';
				if ($schema === 'agenda-item') {
					return [['uuid' => 'ai-1', 'title' => 'Item 1']];
				}

				if ($schema === 'decision') {
					return [['uuid' => 'dec-1', 'title' => 'Decision 1']];
				}

				if ($schema === 'action-item') {
					return [['uuid' => 'act-1', 'title' => 'Action 1']];
				}

				return [];
			}
		);

		$result = $this->provider->invokeTool(
			'decidesk.getMeetingDetails',
			['meetingUuid' => $this->meetingUuid]
		);

		self::assertTrue($result['success']);
		self::assertArrayHasKey('meeting', $result);
		self::assertArrayHasKey('agendaItems', $result);
		self::assertArrayHasKey('decisions', $result);
		self::assertArrayHasKey('actionItems', $result);
		self::assertArrayHasKey('sources', $result);

		// sources: 1 meeting + 1 agenda item + 1 decision + 1 action item = 4.
		self::assertCount(4, $result['sources']);

		$types = array_column($result['sources'], 'type');
		self::assertContains('decidesk.meeting', $types);
		self::assertContains('decidesk.agendaItem', $types);
		self::assertContains('decidesk.decision', $types);
		self::assertContains('decidesk.actionItem', $types);

	}//end testGetMeetingDetails_happyPath()

	/**
	 * startMeeting happy path transitions meeting and returns success payload.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/decidesk-mcp-tools/specs/mcp-tools/spec.md#REQ-DMCP-005
	 */
	public function testStartMeeting_happyPath(): void {
		$this->setCurrentUser('alice');

		$meeting = $this->makeMeeting($this->meetingUuid, 'scheduled', 'alice');
		$meetingEntity = $this->makeObjectEntityMock($meeting);
		$this->mockObjectService(meetingEntity: $meetingEntity);

		$this->meetingService->expects(self::once())->method('transition')
			->with($this->meetingUuid, 'open', 'alice')
			->willReturn(['success' => true, 'meeting' => $meeting, 'message' => 'Opened.']);

		$result = $this->provider->invokeTool(
			'decidesk.startMeeting',
			['meetingUuid' => $this->meetingUuid]
		);

		self::assertTrue($result['success']);
		self::assertTrue($result['started']);
		self::assertSame($this->meetingUuid, $result['meetingUuid']);
		self::assertArrayHasKey('startedAt', $result);
		self::assertCount(1, $result['sources']);
		self::assertSame('decidesk.meeting', $result['sources'][0]['type']);

	}//end testStartMeeting_happyPath()

	/**
	 * addActionItem happy path creates task and returns success payload.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/decidesk-mcp-tools/specs/mcp-tools/spec.md#REQ-DMCP-006
	 */
	public function testAddActionItem_happyPath(): void {
		$this->setCurrentUser('alice');
		$this->setParticipant('alice');

		$meeting = $this->makeMeeting($this->meetingUuid, 'opened', 'alice');
		$meetingEntity = $this->makeObjectEntityMock($meeting);

		// The injected object service returns the meeting entity on find().
		$this->objectService->method('find')->willReturn($meetingEntity);
		$this->objectService->method('findAll')->willReturn([]);

		// The action item is now written via ActionItemWriter::create()
		// (the action-item schema is a read-only VTODO projection; direct
		// ObjectService::saveObject is bypassed in favour of TaskService).
		$savedActionItemArray = [
			'uid' => 'action-item-uuid-1',
			'title' => 'Write test plan',
			'taskStatus' => 'open',
		];
		$writerMock = $this->getMockBuilder(\OCA\Decidesk\Service\ActionItemWriter::class)
			->disableOriginalConstructor()
			->getMock();
		$writerMock->method('create')->willReturn($savedActionItemArray);

		// The container is still consulted for the app-local ActionItemWriter;
		// OpenRegister is no longer resolved through it (ADR-083).
		$this->container->method('get')->willReturnCallback(
			function (string $id) use ($writerMock) {
				if ($id === \OCA\Decidesk\Service\ActionItemWriter::class) {
					return $writerMock;
				}

				throw new \RuntimeException("Unexpected container::get('{$id}')");
			}
		);

		$result = $this->provider->invokeTool(
			'decidesk.addActionItem',
			['meetingUuid' => $this->meetingUuid, 'title' => 'Write test plan']
		);

		self::assertTrue($result['success']);
		self::assertTrue($result['created']);
		self::assertArrayHasKey('actionItem', $result);
		self::assertCount(2, $result['sources']);

		$types = array_column($result['sources'], 'type');
		self::assertContains('decidesk.actionItem', $types);
		self::assertContains('decidesk.meeting', $types);

	}//end testAddActionItem_happyPath()

	// =========================================================================
	// Task 5.6 — per-tool forbidden-path tests
	// =========================================================================

	/**
	 * getMeetingDetails for a non-participant returns forbidden; findAll never called.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/decidesk-mcp-tools/specs/mcp-tools/spec.md#REQ-DMCP-004
	 */
	public function testGetMeetingDetails_nonParticipant_returnsForbidden(): void {
		$this->setCurrentUser('carol');

		$meeting = $this->makeMeeting($this->meetingUuid, 'scheduled', 'alice', ['bob']);
		$meetingEntity = $this->makeObjectEntityMock($meeting);

		$this->objectService->method('find')->willReturn($meetingEntity);
		// findAll should never be called — access is blocked after find().
		$this->objectService->expects(self::never())->method('findAll');

		$result = $this->provider->invokeTool(
			'decidesk.getMeetingDetails',
			['meetingUuid' => $this->meetingUuid]
		);

		self::assertTrue($result['isError']);
		self::assertSame('forbidden', $result['error']);

	}//end testGetMeetingDetails_nonParticipant_returnsForbidden()

	/**
	 * startMeeting for a non-chair returns forbidden; transition never called.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/decidesk-mcp-tools/specs/mcp-tools/spec.md#REQ-DMCP-004
	 */
	public function testStartMeeting_nonChair_returnsForbidden(): void {
		$this->setCurrentUser('bob');

		$meeting = $this->makeMeeting($this->meetingUuid, 'scheduled', 'alice');
		$meetingEntity = $this->makeObjectEntityMock($meeting);
		$this->mockObjectService(meetingEntity: $meetingEntity);

		// Transition must NEVER be called.
		$this->meetingService->expects(self::never())->method('transition');

		$result = $this->provider->invokeTool(
			'decidesk.startMeeting',
			['meetingUuid' => $this->meetingUuid]
		);

		self::assertTrue($result['isError']);
		self::assertSame('forbidden', $result['error']);
		self::assertStringContainsString('chair', $result['message']);

	}//end testStartMeeting_nonChair_returnsForbidden()

	/**
	 * addActionItem for a non-participant returns forbidden; no ActionItem written.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/decidesk-mcp-tools/specs/mcp-tools/spec.md#REQ-DMCP-004
	 */
	public function testAddActionItem_nonParticipant_returnsForbidden(): void {
		$this->setCurrentUser('carol');

		$meeting = $this->makeMeeting($this->meetingUuid, 'scheduled', 'alice', ['bob']);
		$meetingEntity = $this->makeObjectEntityMock($meeting);
		$objectService = $this->mockObjectService(meetingEntity: $meetingEntity);

		// Authorisation fails before the write path, so no ActionItem is saved.
		$objectService->expects(self::never())->method('saveObject');

		$result = $this->provider->invokeTool(
			'decidesk.addActionItem',
			['meetingUuid' => $this->meetingUuid, 'title' => 'Some task']
		);

		self::assertTrue($result['isError']);
		self::assertSame('forbidden', $result['error']);

	}//end testAddActionItem_nonParticipant_returnsForbidden()

	// =========================================================================
	// Task 5.7 — startMeeting with meeting already in progress
	// =========================================================================

	/**
	 * startMeeting returns invalid_state when the meeting is already in-progress.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/decidesk-mcp-tools/specs/mcp-tools/spec.md#REQ-DMCP-005
	 */
	public function testStartMeeting_alreadyInProgress_returnsInvalidState(): void {
		$this->setCurrentUser('alice');

		// Meeting is in 'opened' state (the internal name for in-progress).
		$meeting = $this->makeMeeting($this->meetingUuid, 'opened', 'alice');
		$meetingEntity = $this->makeObjectEntityMock($meeting);
		$this->mockObjectService(meetingEntity: $meetingEntity);

		$this->meetingService->expects(self::never())->method('transition');

		$result = $this->provider->invokeTool(
			'decidesk.startMeeting',
			['meetingUuid' => $this->meetingUuid]
		);

		self::assertTrue($result['isError']);
		self::assertSame('invalid_state', $result['error']);
		self::assertStringContainsString('in progress', $result['message']);

	}//end testStartMeeting_alreadyInProgress_returnsInvalidState()

	// =========================================================================
	// Task 5.8 — sources truncation at cap (35 → 20)
	// =========================================================================

	/**
	 * getMeetingDetails with 34 sub-objects produces 20 sources + truncation markers.
	 *
	 * Meeting (1) + 20 agenda items + 7 decisions + 7 action items = 35 total.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/decidesk-mcp-tools/specs/mcp-tools/spec.md#REQ-DMCP-006
	 */
	public function testSourcesTruncationAtCap(): void {
		$this->setCurrentUser('alice');
		$this->setParticipant('alice');

		$meeting = $this->makeMeeting($this->meetingUuid, 'scheduled', 'alice');
		$meetingEntity = $this->makeObjectEntityMock($meeting);

		$this->objectService->method('find')->willReturn($meetingEntity);

		// 20 agenda items + 7 decisions + 7 action items = 34, +1 meeting = 35 total.
		$agendaItems = [];
		for ($i = 1; $i <= 20; $i++) {
			$agendaItems[] = ['uuid' => "ag-{$i}", 'title' => "Agenda {$i}"];
		}

		$decisions = [];
		for ($i = 1; $i <= 7; $i++) {
			$decisions[] = ['uuid' => "dec-{$i}", 'title' => "Decision {$i}"];
		}

		$actionItems = [];
		for ($i = 1; $i <= 7; $i++) {
			$actionItems[] = ['uuid' => "act-{$i}", 'title' => "Action {$i}"];
		}

		$this->objectService->method('findAll')->willReturnCallback(
			function (array $config) use ($agendaItems, $decisions, $actionItems) {
				$schema = $config['filters']['schema'] ?? '';
				if ($schema === 'agenda-item') {
					return $agendaItems;
				}

				if ($schema === 'decision') {
					return $decisions;
				}

				if ($schema === 'action-item') {
					return $actionItems;
				}

				return [];
			}
		);

		$result = $this->provider->invokeTool(
			'decidesk.getMeetingDetails',
			['meetingUuid' => $this->meetingUuid]
		);

		self::assertTrue($result['success']);
		self::assertCount(20, $result['sources']);
		self::assertTrue($result['sourcesTruncated']);
		self::assertSame(35, $result['sourcesTotalCount']);

	}//end testSourcesTruncationAtCap()

	// =========================================================================
	// Task 5.9 — not_found for all three object-targeting tools
	// =========================================================================

	/**
	 * getMeetingDetails returns not_found when ObjectService::find returns null.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/decidesk-mcp-tools/specs/mcp-tools/spec.md#REQ-DMCP-007
	 */
	public function testNotFound_getMeetingDetails_returnsNotFoundEnvelope(): void {
		$this->setCurrentUser('alice');
		$this->mockObjectService(meetingEntity: null);

		$result = $this->provider->invokeTool(
			'decidesk.getMeetingDetails',
			['meetingUuid' => $this->nullUuid]
		);

		self::assertTrue($result['isError']);
		self::assertSame('not_found', $result['error']);

	}//end testNotFound_getMeetingDetails_returnsNotFoundEnvelope()

	/**
	 * startMeeting returns not_found when ObjectService::find returns null.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/decidesk-mcp-tools/specs/mcp-tools/spec.md#REQ-DMCP-007
	 */
	public function testNotFound_startMeeting_returnsNotFoundEnvelope(): void {
		$this->setCurrentUser('alice');
		$this->mockObjectService(meetingEntity: null);

		$result = $this->provider->invokeTool(
			'decidesk.startMeeting',
			['meetingUuid' => $this->nullUuid]
		);

		self::assertTrue($result['isError']);
		self::assertSame('not_found', $result['error']);

	}//end testNotFound_startMeeting_returnsNotFoundEnvelope()

	/**
	 * addActionItem returns not_found when ObjectService::find returns null.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/decidesk-mcp-tools/specs/mcp-tools/spec.md#REQ-DMCP-007
	 */
	public function testNotFound_addActionItem_returnsNotFoundEnvelope(): void {
		$this->setCurrentUser('alice');
		$this->mockObjectService(meetingEntity: null);

		$result = $this->provider->invokeTool(
			'decidesk.addActionItem',
			['meetingUuid' => $this->nullUuid, 'title' => 'Some task']
		);

		self::assertTrue($result['isError']);
		self::assertSame('not_found', $result['error']);

	}//end testNotFound_addActionItem_returnsNotFoundEnvelope()

	// =========================================================================
	// Admin override tests
	// =========================================================================

	/**
	 * An admin can start a meeting even when not the chair.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/decidesk-mcp-tools/specs/mcp-tools/spec.md#REQ-DMCP-004
	 */
	public function testAdmin_canStartMeetingAsNonChair(): void {
		$this->setAdminUser('admin');

		$meeting = $this->makeMeeting($this->meetingUuid, 'scheduled', 'alice');
		$meetingEntity = $this->makeObjectEntityMock($meeting);
		$this->mockObjectService(meetingEntity: $meetingEntity);

		$this->meetingService->method('transition')->willReturn(
			['success' => true, 'meeting' => $meeting, 'message' => 'Opened.']
		);

		$result = $this->provider->invokeTool(
			'decidesk.startMeeting',
			['meetingUuid' => $this->meetingUuid]
		);

		self::assertTrue($result['success']);
		self::assertTrue($result['started']);

	}//end testAdmin_canStartMeetingAsNonChair()

	// =========================================================================
	// addActionItem input validation tests
	// =========================================================================

	/**
	 * addActionItem with a title that is too short returns invalid_arguments.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/decidesk-mcp-tools/specs/mcp-tools/spec.md#REQ-DMCP-003
	 */
	public function testAddActionItem_titleTooShort_returnsInvalidArguments(): void {
		$result = $this->provider->invokeTool(
			'decidesk.addActionItem',
			['meetingUuid' => $this->meetingUuid, 'title' => 'Hi']
		);

		self::assertTrue($result['isError']);
		self::assertSame('invalid_arguments', $result['error']);

	}//end testAddActionItem_titleTooShort_returnsInvalidArguments()

	// =========================================================================
	// UUID validation (null UUID is syntactically valid)
	// =========================================================================

	/**
	 * The null UUID 00000000-0000-0000-0000-000000000000 is syntactically valid.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/decidesk-mcp-tools/specs/mcp-tools/spec.md#REQ-DMCP-007
	 */
	public function testNullUuidIsValidSyntax(): void {
		$this->setCurrentUser('alice');
		// ObjectService returns null → not_found (not invalid_arguments).
		$this->mockObjectService(meetingEntity: null);

		$result = $this->provider->invokeTool(
			'decidesk.startMeeting',
			['meetingUuid' => $this->nullUuid]
		);

		// UUID is syntactically valid, so we get not_found (not invalid_arguments).
		self::assertSame('not_found', $result['error']);

	}//end testNullUuidIsValidSyntax()

	/**
	 * Short strings like 'abc' and '' are not valid UUIDs.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/decidesk-mcp-tools/specs/mcp-tools/spec.md#REQ-DMCP-007
	 */
	public function testShortStringsAreInvalidUuids(): void {
		$result1 = $this->provider->invokeTool(
			'decidesk.getMeetingDetails',
			['meetingUuid' => 'abc']
		);
		$result2 = $this->provider->invokeTool(
			'decidesk.getMeetingDetails',
			['meetingUuid' => '']
		);

		self::assertSame('invalid_arguments', $result1['error']);
		self::assertSame('invalid_arguments', $result2['error']);

	}//end testShortStringsAreInvalidUuids()

}//end class
