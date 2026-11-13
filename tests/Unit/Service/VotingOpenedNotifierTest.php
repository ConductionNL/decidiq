<?php

/**
 * Unit tests for VotingOpenedNotifier.
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\Service
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

namespace OCA\Decidiq\Tests\Unit\Service;

use OCA\Decidiq\Service\ActivityPublisherService;
use OCA\Decidiq\Service\NotificationPreferenceService;
use OCA\Decidiq\Service\ParticipantResolver;
use OCA\Decidiq\Service\VotingOpenedNotifier;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The activity entry and participant notices sent when a vote opens.
 *
 * @covers \OCA\Decidiq\Service\VotingOpenedNotifier
 */
class VotingOpenedNotifierTest extends TestCase {

	/**
	 * Mock logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Mock participant resolver.
	 *
	 * @var ParticipantResolver&MockObject
	 */
	private ParticipantResolver&MockObject $participantResolver;

	/**
	 * Mock activity publisher.
	 *
	 * @var ActivityPublisherService&MockObject
	 */
	private ActivityPublisherService&MockObject $activity;

	/**
	 * Mock preference service.
	 *
	 * @var NotificationPreferenceService&MockObject
	 */
	private NotificationPreferenceService&MockObject $preferences;

	/**
	 * Mock OpenRegister object service.
	 *
	 * @var ObjectService&MockObject
	 */
	private ObjectService&MockObject $objectService;

	/**
	 * Container entries, overridable per test.
	 *
	 * @var array<string, mixed>
	 */
	private array $services = [];

	/**
	 * Set up shared mocks.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->logger = $this->createMock(LoggerInterface::class);
		$this->participantResolver = $this->createMock(ParticipantResolver::class);
		$this->activity = $this->createMock(ActivityPublisherService::class);
		$this->preferences = $this->createMock(NotificationPreferenceService::class);
		$this->objectService = $this->createMock(ObjectService::class);

		$this->services = [
			ActivityPublisherService::class => $this->activity,
			NotificationPreferenceService::class => $this->preferences,
			'OCA\OpenRegister\Service\ObjectService' => $this->objectService,
		];

	}//end setUp()

	/**
	 * Build the notifier under test.
	 *
	 * @return VotingOpenedNotifier
	 */
	private function notifier(): VotingOpenedNotifier {
		$container = $this->createMock(\Psr\Container\ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id): mixed {
				$service = ($this->services[$id] ?? null);
				if ($service instanceof \Throwable) {
					throw $service;
				}

				return $service;
			}
		);

		return new VotingOpenedNotifier(
			container: $container,
			logger: $this->logger,
			participantResolver: $this->participantResolver,
		);

	}//end notifier()

	/**
	 * Wrap a payload in an ObjectEntity double.
	 *
	 * @param array<string, mixed> $object The payload
	 *
	 * @return ObjectEntity
	 */
	private function entity(array $object): ObjectEntity {
		$entity = $this->getMockBuilder(ObjectEntity::class)
			->disableOriginalConstructor()
			->onlyMethods(['jsonSerialize'])
			->getMock();
		$entity->method('jsonSerialize')->willReturn($object);
		return $entity;

	}//end entity()

	/**
	 * Capture every dispatch() call.
	 *
	 * @return \ArrayObject<int, array<string, string>>
	 */
	private function captureDispatches(): \ArrayObject {
		$calls = new \ArrayObject();
		$this->preferences->method('dispatch')->willReturnCallback(
			static function (string $personId, string $eventType, string $title, string $message, string $deepLink = '') use ($calls): int {
				$calls->append(compact('personId', 'eventType', 'title', 'message', 'deepLink'));
				return 1;
			}
		);
		return $calls;

	}//end captureDispatches()

	/**
	 * Opening a vote publishes an activity and notifies every active participant with an account.
	 *
	 * @return void
	 */
	public function testAnnouncePublishesActivityAndNotifiesActiveParticipants(): void {
		$this->activity->expects($this->once())
			->method('publishGovernanceEvent')
			->with(
				[
					'subject' => 'vote_initiated',
					'title' => 'majority',
					'status' => 'open',
					'objectType' => 'voting-round',
					'objectUuid' => 'round-uuid',
					'segment' => 'voting-rounds',
				]
			)
			->willReturn(1);
		$this->objectService->expects($this->once())
			->method('find')
			->with('motion-1', [], false, 'decidiq', 'motion')
			->willReturn($this->entity(['title' => 'Motie A']));
		$this->participantResolver->method('resolveMeetingParticipants')->with('meeting-1')->willReturn(
			[
				['nextcloudUserId' => 'alice'],
				['nextcloudUserId' => 'bob', 'leftAt' => '2026-01-01'],
				['nextcloudUserId' => ''],
				[],
				['nextcloudUserId' => 'carol', 'leftAt' => null],
			]
		);
		$calls = $this->captureDispatches();

		$this->notifier()->announce(
			['uuid' => 'round-uuid', 'votingMethod' => 'majority'],
			'motion-1',
			'meeting-1',
			'2026-02-01T12:00:00Z',
			'motion'
		);

		$expected = static fn (string $person): array => [
			'personId' => $person,
			'eventType' => 'votingOpened',
			'title' => 'Pending vote: Motie A',
			'message' => 'A new vote is open in your body (meeting meeting-1). Voting deadline: 2026-02-01T12:00:00Z.',
			'deepLink' => '/motions/motion-1',
		];
		$this->assertSame([$expected('alice'), $expected('carol')], $calls->getArrayCopy());

	}//end testAnnouncePublishesActivityAndNotifiesActiveParticipants()

	/**
	 * Amendments are looked up in their own schema and fall back to "Amendment"; no deadline is spelled out.
	 *
	 * @return void
	 */
	public function testAmendmentWithoutTitleOrDeadline(): void {
		$this->activity->expects($this->once())
			->method('publishGovernanceEvent')
			->with($this->callback(static fn (array $a): bool => $a['title'] === 'voting round' && $a['objectUuid'] === ''))
			->willReturn(1);
		$this->objectService->expects($this->once())
			->method('find')
			->with('amend-1', [], false, 'decidiq', 'amendment')
			->willReturn($this->entity([]));
		$this->participantResolver->method('resolveMeetingParticipants')->willReturn([['nextcloudUserId' => 'alice']]);
		$calls = $this->captureDispatches();

		$this->notifier()->announce([], 'amend-1', 'meeting-1', '', 'amendment');

		$this->assertSame('Pending vote: Amendment', $calls[0]['title']);
		$this->assertSame('A new vote is open in your body (meeting meeting-1). Voting deadline: no deadline set.', $calls[0]['message']);
		$this->assertSame('/motions/amend-1', $calls[0]['deepLink']);

	}//end testAmendmentWithoutTitleOrDeadline()

	/**
	 * An unknown motion and a null deadline use the fallbacks.
	 *
	 * @return void
	 */
	public function testUnknownMotionUsesFallbackTitle(): void {
		$this->objectService->method('find')->willReturn(null);
		$this->participantResolver->method('resolveMeetingParticipants')->willReturn([['nextcloudUserId' => 'alice']]);
		$calls = $this->captureDispatches();

		$this->notifier()->announce(['id' => 'r-1'], 'motion-1', 'meeting-1', null, 'motion');

		$this->assertSame('Pending vote: Motion', $calls[0]['title']);
		$this->assertStringEndsWith('Voting deadline: no deadline set.', $calls[0]['message']);

	}//end testUnknownMotionUsesFallbackTitle()

	/**
	 * A failing title lookup is logged at debug level and the fallback title used.
	 *
	 * @return void
	 */
	public function testTitleLookupFailureFallsBack(): void {
		$this->services['OCA\OpenRegister\Service\ObjectService'] = new RuntimeException('no OR');
		$this->participantResolver->method('resolveMeetingParticipants')->willReturn([['nextcloudUserId' => 'alice']]);
		$calls = $this->captureDispatches();
		$this->logger->expects($this->once())
			->method('debug')
			->with('Decidiq: motion lookup for vote notification failed', ['error' => 'no OR']);

		$this->notifier()->announce(['id' => 'r-1'], 'motion-1', 'meeting-1', null, 'motion');

		$this->assertSame('Pending vote: Motion', $calls[0]['title']);

	}//end testTitleLookupFailureFallsBack()

	/**
	 * A failing activity publish is logged and does not stop the notices.
	 *
	 * @return void
	 */
	public function testActivityFailureDoesNotBlockNotices(): void {
		$this->activity->method('publishGovernanceEvent')->willThrowException(new RuntimeException('activity down'));
		$this->objectService->method('find')->willReturn($this->entity(['title' => 'M']));
		$this->participantResolver->method('resolveMeetingParticipants')->willReturn([['nextcloudUserId' => 'alice']]);
		$calls = $this->captureDispatches();
		$this->logger->expects($this->once())
			->method('debug')
			->with('Decidiq: activity publish skipped', ['error' => 'activity down']);

		$this->notifier()->announce(['id' => 'r-1'], 'motion-1', 'meeting-1', null, 'motion');

		$this->assertCount(1, $calls);

	}//end testActivityFailureDoesNotBlockNotices()

	/**
	 * Without a preference service nothing is resolved or dispatched.
	 *
	 * @return void
	 */
	public function testMissingPreferenceServiceSkipsNotices(): void {
		$this->services[NotificationPreferenceService::class] = new \stdClass();
		$this->participantResolver->expects($this->never())->method('resolveMeetingParticipants');
		$this->logger->expects($this->never())->method('warning');

		$this->notifier()->announce(['id' => 'r-1'], 'motion-1', 'meeting-1', null, 'motion');

	}//end testMissingPreferenceServiceSkipsNotices()

	/**
	 * A failing dispatch is logged as a warning rather than thrown.
	 *
	 * @return void
	 */
	public function testDispatchFailureIsLogged(): void {
		$this->objectService->method('find')->willReturn($this->entity(['title' => 'M']));
		$this->participantResolver->method('resolveMeetingParticipants')->willReturn([['nextcloudUserId' => 'alice']]);
		$this->preferences->method('dispatch')->willThrowException(new RuntimeException('mail down'));
		$this->logger->expects($this->once())
			->method('warning')
			->with('Decidiq: votingOpened notification dispatch failed', ['error' => 'mail down']);

		$this->notifier()->announce(['id' => 'r-1'], 'motion-1', 'meeting-1', null, 'motion');

	}//end testDispatchFailureIsLogged()
}//end class
