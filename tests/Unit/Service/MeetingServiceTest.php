<?php

/**
 * Unit tests for MeetingService.
 *
 * @category Test
 * @package  OCA\Decidesk\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/p2-meeting-management/tasks.md#task-3.1
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidesk\Tests\Unit\Service;

use OCA\Decidesk\Lifecycle\MeetingTransitionGuard;
use OCA\Decidesk\Service\GovernanceScopeGuard;
use OCA\Decidesk\Service\MeetingCostService;
use OCA\Decidesk\Service\MeetingService;
use OCA\Decidesk\Service\WorkflowService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for MeetingService lifecycle transitions.
 *
 * @spec openspec/changes/p2-meeting-management/tasks.md#task-3.1
 */
class MeetingServiceTest extends TestCase {

	/**
	 * Service under test.
	 *
	 * @var MeetingService
	 */
	private MeetingService $service;

	/**
	 * Mock DI container.
	 *
	 * @var ContainerInterface&MockObject
	 */
	private ContainerInterface&MockObject $container;

	/**
	 * Mock logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Mock ObjectService from OpenRegister.
	 *
	 * @var ObjectService&MockObject
	 */
	private ObjectService&MockObject $objectService;

	/**
	 * Mock WorkflowService.
	 *
	 * @var WorkflowService&MockObject
	 */
	private WorkflowService&MockObject $workflowService;

	/**
	 * Mock MeetingTransitionGuard.
	 *
	 * @var MeetingTransitionGuard&MockObject
	 */
	private MeetingTransitionGuard&MockObject $transitionGuard;

	/**
	 * Mock MeetingCostService (meeting-efficiency cost stamping on close).
	 *
	 * @var MeetingCostService&MockObject
	 */
	private MeetingCostService&MockObject $meetingCostService;

	/**
	 * Mock GovernanceScopeGuard (consumes the OR-projected chair scope).
	 *
	 * @var GovernanceScopeGuard&MockObject
	 */
	private GovernanceScopeGuard&MockObject $scopeGuard;

	/**
	 * Set up test fixtures.
	 *
	 * Default workflow mocks permit all transitions (operations domain semantics)
	 * so that existing tests continue to work without modification.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->objectService = $this->createMock(originalClassName: ObjectService::class);
		$this->container = $this->createMock(originalClassName: ContainerInterface::class);
		$this->logger = $this->createMock(originalClassName: LoggerInterface::class);
		$this->workflowService = $this->createMock(originalClassName: WorkflowService::class);
		$this->transitionGuard = $this->createMock(originalClassName: MeetingTransitionGuard::class);
		$this->meetingCostService = $this->createMock(originalClassName: MeetingCostService::class);
		$this->scopeGuard = $this->createMock(originalClassName: GovernanceScopeGuard::class);

		$this->container->method('get')
			->with('OCA\OpenRegister\Service\ObjectService')
			->willReturn($this->objectService);

		$this->service = new MeetingService(
			container: $this->container,
			logger: $this->logger,
			workflowService: $this->workflowService,
			transitionGuard: $this->transitionGuard,
			meetingCostService: $this->meetingCostService,
			scopeGuard: $this->scopeGuard,
		);

	}//end setUp()

	/**
	 * Helper to build a mock ObjectEntity with a given lifecycle, domain, and optional chair.
	 *
	 * @param string $lifecycle The lifecycle state to set on the mock entity
	 * @param string $domain The governance domain (default: 'operations')
	 * @param string|null $chair The Nextcloud UID of the meeting chair (default: null)
	 * @param string|null $body The GovernanceBody UUID relation (default: null)
	 *
	 * @return ObjectEntity&MockObject
	 */
	private function buildMockEntity(string $lifecycle, string $domain = 'operations', ?string $chair = null, ?string $body = null): ObjectEntity&MockObject {
		$entity = $this->createMock(originalClassName: ObjectEntity::class);
		$data = ['lifecycle' => $lifecycle, 'domain' => $domain];
		if ($chair !== null) {
			$data['chair'] = $chair;
		}

		if ($body !== null) {
			$data['governanceBody'] = $body;
		}

		$entity->method('getObject')->willReturn($data);
		$entity->method('jsonSerialize')->willReturn(array_merge($data, ['id' => 'test-uuid']));
		return $entity;
	}//end buildMockEntity()

	/**
	 * Test that a valid transition (scheduled → open → opened) returns success.
	 *
	 * @return void
	 */
	public function testValidTransitionReturnsSuccess(): void {
		$this->markTestSkipped(message: 'See https://codeberg.org/Conduction/decidesk/issues/90 — real ObjectService loads instead of stub.');

		$uuid = 'aaaaaaaa-0000-0000-0000-000000000001';
		$currentState = 'scheduled';
		$entity = $this->buildMockEntity(lifecycle: $currentState);
		$updatedEntity = $this->buildMockEntity(lifecycle: 'opened');

		$this->objectService->expects($this->once())
			->method('find')
			->with(id: $uuid)
			->willReturn($entity);

		$this->objectService->expects($this->once())
			->method('updateFromArray')
			->with(
				id: $uuid,
				object: ['lifecycle' => 'opened'],
				updateVersion: true,
				patch: true,
			)
			->willReturn($updatedEntity);

		$result = $this->service->transition(meetingId: $uuid, action: 'open');

		self::assertTrue(condition: $result['success']);
		self::assertSame(expected: 'opened', actual: $result['meeting']['lifecycle']);

	}//end testValidTransitionReturnsSuccess()

	/**
	 * Test that trying to pause a draft meeting returns a failure with a descriptive message.
	 *
	 * @return void
	 */
	public function testInvalidTransitionReturnsFailure(): void {
		$this->markTestSkipped(message: 'See https://codeberg.org/Conduction/decidesk/issues/90 — real ObjectService loads instead of stub.');

		$uuid = 'aaaaaaaa-0000-0000-0000-000000000002';
		$entity = $this->buildMockEntity(lifecycle: 'draft');

		$this->objectService->expects($this->once())
			->method('find')
			->with(id: $uuid)
			->willReturn($entity);

		$this->objectService->expects($this->never())
			->method('updateFromArray');

		$result = $this->service->transition(meetingId: $uuid, action: 'pause');

		self::assertFalse(condition: $result['success']);
		self::assertNull(actual: $result['meeting']);
		self::assertStringContainsString(needle: 'draft', haystack: $result['message']);

	}//end testInvalidTransitionReturnsFailure()

	/**
	 * Test that an unknown action name returns a failure with a list of valid actions.
	 *
	 * @return void
	 */
	public function testUnknownActionReturnsFailure(): void {
		$this->objectService->expects($this->never())
			->method('find');

		$result = $this->service->transition(meetingId: 'some-uuid', action: 'fly-to-the-moon');

		self::assertFalse(condition: $result['success']);
		self::assertNull(actual: $result['meeting']);
		self::assertStringContainsString(needle: 'Unknown action', haystack: $result['message']);

	}//end testUnknownActionReturnsFailure()

	/**
	 * Test that transitioning a non-existent meeting returns a failure.
	 *
	 * @return void
	 */
	public function testMeetingNotFoundReturnsFailure(): void {
		$uuid = 'aaaaaaaa-0000-0000-0000-000000000099';

		$this->objectService->expects($this->once())
			->method('find')
			->with(id: $uuid)
			->willReturn(null);

		$result = $this->service->transition(meetingId: $uuid, action: 'open');

		self::assertFalse(condition: $result['success']);
		self::assertNull(actual: $result['meeting']);
		self::assertStringContainsString(needle: 'not found', haystack: $result['message']);

	}//end testMeetingNotFoundReturnsFailure()

	/**
	 * Test that a DoesNotExistException thrown by ObjectService is handled gracefully.
	 *
	 * Covers the catch (DoesNotExistException) path in MeetingService::transition().
	 *
	 * @spec openspec/changes/p2-meeting-management/tasks.md#task-3.1
	 *
	 * @return void
	 */
	public function testDoesNotExistExceptionReturnsFailure(): void {
		$uuid = 'aaaaaaaa-0000-0000-0000-000000000098';

		$this->objectService->expects($this->once())
			->method('find')
			->with(id: $uuid)
			->willThrowException(new DoesNotExistException('Meeting not found'));

		$result = $this->service->transition(meetingId: $uuid, action: 'open');

		self::assertFalse(condition: $result['success']);
		self::assertNull(actual: $result['meeting']);
		self::assertStringContainsString(needle: 'not found', haystack: $result['message']);

	}//end testDoesNotExistExceptionReturnsFailure()

	/**
	 * Test that the full close path (opened → close → closed) works correctly.
	 *
	 * @return void
	 */
	public function testCloseFromOpenedReturnsSuccess(): void {
		$this->markTestSkipped(message: 'See https://codeberg.org/Conduction/decidesk/issues/90 — real ObjectService loads instead of stub.');

		$uuid = 'aaaaaaaa-0000-0000-0000-000000000003';
		$entity = $this->buildMockEntity(lifecycle: 'opened');
		$updatedEntity = $this->buildMockEntity(lifecycle: 'closed');

		$this->objectService->method('find')->willReturn($entity);
		$this->objectService->method('updateFromArray')->willReturn($updatedEntity);

		$result = $this->service->transition(meetingId: $uuid, action: 'close');

		self::assertTrue(condition: $result['success']);
		self::assertSame(expected: 'closed', actual: $result['meeting']['lifecycle']);

	}//end testCloseFromOpenedReturnsSuccess()

	/**
	 * Test getAvailableActions returns only valid actions for a given state.
	 *
	 * @return void
	 */
	public function testGetAvailableActionsForScheduled(): void {
		$actions = $this->service->getAvailableActions('scheduled');

		self::assertContains(needle: 'open', haystack: $actions);
		self::assertContains(needle: 'close', haystack: $actions);
		self::assertNotContains(needle: 'pause', haystack: $actions);
		self::assertNotContains(needle: 'resume', haystack: $actions);

	}//end testGetAvailableActionsForScheduled()

	/**
	 * Test getAvailableActions returns empty array for terminal 'closed' state.
	 *
	 * @return void
	 */
	public function testGetAvailableActionsForClosedReturnsEmpty(): void {
		$actions = $this->service->getAvailableActions('closed');

		self::assertEmpty(actual: $actions);

	}//end testGetAvailableActionsForClosedReturnsEmpty()

	/**
	 * Test that a domain-restricted transition (pause in 'corporate') is blocked.
	 *
	 * @spec openspec/changes/p2-meeting-management-core-t1/tasks.md#task-2.2
	 *
	 * @return void
	 */
	public function testDomainDisallowedTransitionReturnsFailure(): void {
		$uuid = 'aaaaaaaa-0000-0000-0000-000000000010';
		$entity = $this->buildMockEntity(lifecycle: 'opened', domain: 'corporate');

		$this->objectService->expects($this->once())
			->method('find')
			->with(id: $uuid)
			->willReturn($entity);

		$workflowService = $this->createMock(originalClassName: WorkflowService::class);
		$workflowService->method('isTransitionAllowed')->willReturn(false);

		$transitionGuard = $this->createMock(originalClassName: MeetingTransitionGuard::class);

		$service = new MeetingService(
			container: $this->container,
			logger: $this->logger,
			workflowService: $workflowService,
			transitionGuard: $transitionGuard,
			meetingCostService: $this->meetingCostService,
			scopeGuard: $this->scopeGuard,
		);

		$result = $service->transition(meetingId: $uuid, action: 'pause');

		self::assertFalse(condition: $result['success']);
		self::assertNull(actual: $result['meeting']);
		self::assertStringContainsString(needle: 'not permitted', haystack: $result['message']);

	}//end testDomainDisallowedTransitionReturnsFailure()

	/**
	 * Test that a chair-only transition is blocked when the caller is not the chair.
	 *
	 * @spec openspec/changes/p2-meeting-management-core-t1/tasks.md#task-2.2
	 *
	 * @return void
	 */
	public function testChairOnlyTransitionBlockedWithoutChairRole(): void {
		$uuid = 'aaaaaaaa-0000-0000-0000-000000000011';
		$bodyId = 'body-uuid-1';
		$entity = $this->buildMockEntity(lifecycle: 'opened', domain: 'legislative', body: $bodyId);

		$this->objectService->method('find')->with(id: $uuid)->willReturn($entity);

		$workflowService = $this->createMock(originalClassName: WorkflowService::class);
		$workflowService->method('isTransitionAllowed')->willReturn(true);
		$workflowService->method('requiresChairAuthorization')->willReturn(true);

		$transitionGuard = $this->createMock(originalClassName: MeetingTransitionGuard::class);

		// Caller is NOT in the body's OR-projected chair scope → OR RBAC denies.
		$scopeGuard = $this->createMock(originalClassName: GovernanceScopeGuard::class);
		$scopeGuard->method('isInBodyScope')
			->with('uid-other-user', $bodyId, GovernanceScopeGuard::SCOPE_CHAIR)
			->willReturn(false);

		$service = new MeetingService(
			container: $this->container,
			logger: $this->logger,
			workflowService: $workflowService,
			transitionGuard: $transitionGuard,
			meetingCostService: $this->meetingCostService,
			scopeGuard: $scopeGuard,
		);

		// Caller is NOT the chair.
		$result = $service->transition(meetingId: $uuid, action: 'adjourn', currentUserId: 'uid-other-user');

		self::assertFalse(condition: $result['success']);
		self::assertNull(actual: $result['meeting']);
		self::assertStringContainsString(needle: 'chair', haystack: $result['message']);

	}//end testChairOnlyTransitionBlockedWithoutChairRole()

	/**
	 * Test that a chair-only transition is blocked when the body scope cannot be
	 * resolved (fail-closed): a chair-only transition on a meeting with no
	 * resolvable GovernanceBody is denied regardless of caller.
	 *
	 * @spec openspec/changes/consume-or-rbac-authorization/specs/authorization-via-or-rbac/spec.md#requirement-req-rbac-005-fail-closed-authorization-is-preserved-end-to-end
	 *
	 * @return void
	 */
	public function testChairOnlyTransitionFailsClosedWhenBodyUnresolved(): void {
		$uuid = 'aaaaaaaa-0000-0000-0000-00000000001a';
		// No governanceBody relation → resolveBodyId() returns null → deny.
		$entity = $this->buildMockEntity(lifecycle: 'opened', domain: 'legislative');

		$this->objectService->method('find')->with(id: $uuid)->willReturn($entity);

		$workflowService = $this->createMock(originalClassName: WorkflowService::class);
		$workflowService->method('isTransitionAllowed')->willReturn(true);
		$workflowService->method('requiresChairAuthorization')->willReturn(true);

		$transitionGuard = $this->createMock(originalClassName: MeetingTransitionGuard::class);

		// The scope guard must not even be consulted with a null body — but if it
		// were, it would also deny. Assert the transition is refused.
		$scopeGuard = $this->createMock(originalClassName: GovernanceScopeGuard::class);
		$scopeGuard->method('isInBodyScope')->willReturn(false);

		$service = new MeetingService(
			container: $this->container,
			logger: $this->logger,
			workflowService: $workflowService,
			transitionGuard: $transitionGuard,
			meetingCostService: $this->meetingCostService,
			scopeGuard: $scopeGuard,
		);

		$result = $service->transition(meetingId: $uuid, action: 'adjourn', currentUserId: 'uid-chair');

		self::assertFalse(condition: $result['success']);
		self::assertNull(actual: $result['meeting']);
		self::assertStringContainsString(needle: 'chair', haystack: $result['message']);

	}//end testChairOnlyTransitionFailsClosedWhenBodyUnresolved()

	/**
	 * Test that a chair-only transition succeeds when the caller IS the chair.
	 *
	 * @spec openspec/changes/p2-meeting-management-core-t1/tasks.md#task-2.2
	 *
	 * @return void
	 */
	public function testChairOnlyTransitionSucceedsForChair(): void {
		$this->markTestSkipped(message: 'See https://codeberg.org/Conduction/decidesk/issues/90 — real ObjectService loads instead of stub.');

		$uuid = 'aaaaaaaa-0000-0000-0000-000000000012';
		$bodyId = 'body-uuid-2';
		$entity = $this->buildMockEntity(lifecycle: 'opened', domain: 'legislative', body: $bodyId);
		$updatedEntity = $this->buildMockEntity(lifecycle: 'adjourned', domain: 'legislative', body: $bodyId);

		$this->objectService->method('find')->willReturn($entity);
		$this->objectService->method('saveObject')->willReturn($updatedEntity);

		$workflowService = $this->createMock(originalClassName: WorkflowService::class);
		$workflowService->method('isTransitionAllowed')->willReturn(true);
		$workflowService->method('requiresChairAuthorization')->willReturn(true);

		$transitionGuard = $this->createMock(originalClassName: MeetingTransitionGuard::class);

		// Caller IS in the body's OR-projected chair scope → OR RBAC allows.
		$scopeGuard = $this->createMock(originalClassName: GovernanceScopeGuard::class);
		$scopeGuard->method('isInBodyScope')
			->with('uid-chair', $bodyId, GovernanceScopeGuard::SCOPE_CHAIR)
			->willReturn(true);

		$service = new MeetingService(
			container: $this->container,
			logger: $this->logger,
			workflowService: $workflowService,
			transitionGuard: $transitionGuard,
			meetingCostService: $this->meetingCostService,
			scopeGuard: $scopeGuard,
		);

		// Caller IS the chair.
		$result = $service->transition(meetingId: $uuid, action: 'adjourn', currentUserId: 'uid-chair');

		self::assertTrue(condition: $result['success']);
		self::assertSame(expected: 'adjourned', actual: $result['meeting']['lifecycle']);

	}//end testChairOnlyTransitionSucceedsForChair()

	/**
	 * Test that opening a meeting with quorum not met is blocked.
	 *
	 * @spec openspec/changes/p2-meeting-management-core-t1/tasks.md#task-3.3
	 *
	 * @return void
	 */
	public function testOpenBlockedWhenQuorumNotMet(): void {
		$uuid = 'aaaaaaaa-0000-0000-0000-000000000013';
		$entity = $this->buildMockEntity(lifecycle: 'scheduled', domain: 'legislative');

		$this->objectService->expects($this->once())
			->method('find')
			->with(id: $uuid)
			->willReturn($entity);

		$workflowService = $this->createMock(originalClassName: WorkflowService::class);
		$workflowService->method('isTransitionAllowed')->willReturn(true);
		$workflowService->method('requiresChairAuthorization')->willReturn(false);
		$workflowService->method('isQuorumRequired')->willReturn(true);

		$transitionGuard = $this->createMock(originalClassName: MeetingTransitionGuard::class);
		$transitionGuard->method('isOpenAllowed')->willReturn(false);

		$service = new MeetingService(
			container: $this->container,
			logger: $this->logger,
			workflowService: $workflowService,
			transitionGuard: $transitionGuard,
			meetingCostService: $this->meetingCostService,
			scopeGuard: $this->scopeGuard,
		);

		$result = $service->transition(meetingId: $uuid, action: 'open');

		self::assertFalse(condition: $result['success']);
		self::assertNull(actual: $result['meeting']);
		self::assertStringContainsString(needle: 'Quorum', haystack: $result['message']);

	}//end testOpenBlockedWhenQuorumNotMet()

	/**
	 * Opening a meeting stamps openedAt (first open) into the saved object.
	 *
	 * @spec openspec/specs/meeting-efficiency/spec.md
	 *
	 * @return void
	 */
	public function testOpenStampsOpenedAt(): void {
		$uuid = 'aaaaaaaa-0000-0000-0000-0000000000ee';
		$entity = $this->buildMockEntity(lifecycle: 'scheduled');

		$this->objectService->method('find')->with(id: $uuid)->willReturn($entity);
		$this->workflowService->method('isTransitionAllowed')->willReturn(true);
		$this->workflowService->method('requiresChairAuthorization')->willReturn(false);
		$this->workflowService->method('isQuorumRequired')->willReturn(false);

		$captured = null;
		$this->objectService->method('saveObject')->willReturnCallback(
			function (array $object) use (&$captured, $entity) {
				$captured = $object;
				return $entity;
			}
		);

		$result = $this->service->transition(meetingId: $uuid, action: 'open');

		self::assertTrue(condition: $result['success']);
		self::assertIsArray(actual: $captured);
		self::assertArrayHasKey('openedAt', $captured);
		self::assertNotEmpty(actual: $captured['openedAt']);
		self::assertArrayNotHasKey('closedAt', $captured);

	}//end testOpenStampsOpenedAt()

	/**
	 * Closing a meeting stamps closedAt and the fail-soft meetingCost.
	 *
	 * @spec openspec/specs/meeting-efficiency/spec.md
	 *
	 * @return void
	 */
	public function testCloseStampsClosedAtAndCost(): void {
		$uuid = 'aaaaaaaa-0000-0000-0000-0000000000ef';
		$entity = $this->buildMockEntity(lifecycle: 'opened');

		$this->objectService->method('find')->with(id: $uuid)->willReturn($entity);
		$this->workflowService->method('isTransitionAllowed')->willReturn(true);
		$this->workflowService->method('requiresChairAuthorization')->willReturn(false);

		// Cost service resolves a final figure.
		$this->meetingCostService->method('calculateForMeeting')->willReturn(675.0);

		$captured = null;
		$this->objectService->method('saveObject')->willReturnCallback(
			function (array $object) use (&$captured, $entity) {
				$captured = $object;
				return $entity;
			}
		);

		$result = $this->service->transition(meetingId: $uuid, action: 'close');

		self::assertTrue(condition: $result['success']);
		self::assertArrayHasKey('closedAt', $captured);
		self::assertSame(expected: 675.0, actual: $captured['meetingCost']);

	}//end testCloseStampsClosedAtAndCost()

	/**
	 * A meetingCost computation failure does not block closing (fail-soft).
	 *
	 * @spec openspec/specs/meeting-efficiency/spec.md
	 *
	 * @return void
	 */
	public function testCloseIsFailSoftWhenCostThrows(): void {
		$uuid = 'aaaaaaaa-0000-0000-0000-0000000000f0';
		$entity = $this->buildMockEntity(lifecycle: 'opened');

		$this->objectService->method('find')->with(id: $uuid)->willReturn($entity);
		$this->workflowService->method('isTransitionAllowed')->willReturn(true);
		$this->workflowService->method('requiresChairAuthorization')->willReturn(false);

		$this->meetingCostService->method('calculateForMeeting')
			->willThrowException(new \RuntimeException('cost failed'));

		$captured = null;
		$this->objectService->method('saveObject')->willReturnCallback(
			function (array $object) use (&$captured, $entity) {
				$captured = $object;
				return $entity;
			}
		);

		$result = $this->service->transition(meetingId: $uuid, action: 'close');

		self::assertTrue(condition: $result['success']);
		self::assertArrayHasKey('closedAt', $captured);
		self::assertArrayNotHasKey('meetingCost', $captured);

	}//end testCloseIsFailSoftWhenCostThrows()
}//end class
