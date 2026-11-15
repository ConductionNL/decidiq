<?php

/**
 * Edge-case unit tests for VotingOpenedNotifier: the written-round announcement.
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
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Members of a written round's electorate are told a decision waits for them
 * (REQ-WRS-006), fail-soft per member and when the dispatcher is unavailable.
 */
#[CoversClass(VotingOpenedNotifier::class)]
class VotingOpenedNotifierEdgesTest extends TestCase {

	/**
	 * Mock logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

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
		$this->activity = $this->createMock(ActivityPublisherService::class);
		$this->preferences = $this->createMock(NotificationPreferenceService::class);

		$this->services = [
			ActivityPublisherService::class => $this->activity,
			NotificationPreferenceService::class => $this->preferences,
		];

	}//end setUp()

	/**
	 * Build the notifier under test.
	 *
	 * @return VotingOpenedNotifier
	 */
	private function notifier(): VotingOpenedNotifier {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id): mixed {
				$service = ($this->services[$id] ?? null);
				if ($service instanceof \Throwable) {
					throw $service;
				}

				return $service;
			}
		);

		$participantResolver = $this->createMock(ParticipantResolver::class);
		// A written round has no meeting: the roster is never consulted.
		$participantResolver->expects($this->never())->method('resolveMeetingParticipants');

		return new VotingOpenedNotifier(
			container: $container,
			logger: $this->logger,
			participantResolver: $participantResolver,
		);

	}//end notifier()

	/**
	 * Capture every dispatch() call, optionally failing for one member.
	 *
	 * @param string|null $failFor The member whose dispatch throws.
	 *
	 * @return \ArrayObject<int, array<string, string>>
	 */
	private function captureDispatches(?string $failFor = null): \ArrayObject {
		$calls = new \ArrayObject();
		$this->preferences->method('dispatch')->willReturnCallback(
			static function (string $personId, string $eventType, string $title, string $message, string $deepLink = '') use ($calls, $failFor): int {
				if ($personId === $failFor) {
					throw new RuntimeException('mail down');
				}

				$calls->append(compact('personId', 'eventType', 'title', 'message', 'deepLink'));
				return 1;
			}
		);
		return $calls;

	}//end captureDispatches()

	/**
	 * Every distinct member with a usable user id is told once; the count says how many.
	 *
	 * @return void
	 */
	public function testAnnounceWrittenNotifiesEachDistinctMemberOnce(): void {
		$this->activity->expects($this->once())
			->method('publishGovernanceEvent')
			->with(
				[
					'subject' => 'vote_initiated',
					'title' => 'written',
					'status' => 'open',
					'objectType' => 'voting-round',
					'objectUuid' => 'wr-1',
					'segment' => 'voting-rounds',
				]
			)
			->willReturn(1);
		$calls = $this->captureDispatches();
		$this->logger->expects($this->never())->method('warning');

		$notified = $this->notifier()->announceWritten(
			[
				'id' => 'wr-1',
				'votingMethod' => 'written',
				'votingDeadline' => '2026-12-01T12:00:00+00:00',
				'electorateUserIds' => ['alice', 'bob', 'alice', '', 42, null, 'carol'],
			],
			'decision-7',
			'Begroting 2027'
		);

		$this->assertSame(3, $notified);
		$expected = static fn (string $person): array => [
			'personId' => $person,
			'eventType' => 'votingOpened',
			'title' => 'A decision is waiting for your answer: Begroting 2027',
			'message' => 'Answer before 2026-12-01T12:00:00+00:00. Silence is not consent.',
			'deepLink' => '/decisions/decision-7',
		];
		$this->assertSame([$expected('alice'), $expected('bob'), $expected('carol')], $calls->getArrayCopy());

	}//end testAnnounceWrittenNotifiesEachDistinctMemberOnce()

	/**
	 * A deadline that is not a string is spelled as "no deadline set"; no electorate notifies nobody.
	 *
	 * @return void
	 */
	public function testAnnounceWrittenWithoutDeadlineOrElectorate(): void {
		$calls = $this->captureDispatches();

		$this->assertSame(0, $this->notifier()->announceWritten(['votingDeadline' => 1767225600], 'decision-1', 'X'));
		$this->assertCount(0, $calls);

		$this->assertSame(
			1,
			$this->notifier()->announceWritten(['votingDeadline' => 1767225600, 'electorateUserIds' => ['dave']], 'decision-1', 'X')
		);
		$this->assertSame('Answer before no deadline set. Silence is not consent.', $calls[0]['message']);

	}//end testAnnounceWrittenWithoutDeadlineOrElectorate()

	/**
	 * One member's failing dispatch is logged and does not stop or count against the others.
	 *
	 * @return void
	 */
	public function testAFailingMemberIsLoggedAndSkipped(): void {
		$calls = $this->captureDispatches(failFor: 'bob');
		$this->logger->expects($this->once())
			->method('warning')
			->with('Decidiq: written round notification to a member failed', ['uid' => 'bob', 'error' => 'mail down']);

		$notified = $this->notifier()->announceWritten(
			['id' => 'wr-2', 'electorateUserIds' => ['alice', 'bob', 'carol']],
			'decision-2',
			'Y'
		);

		$this->assertSame(2, $notified);
		$this->assertSame(['alice', 'carol'], array_column($calls->getArrayCopy(), 'personId'));

	}//end testAFailingMemberIsLoggedAndSkipped()

	/**
	 * When the preference service cannot be resolved the round still opens: nobody is notified and it is logged.
	 *
	 * @return void
	 */
	public function testUnresolvablePreferenceServiceNotifiesNobody(): void {
		$this->services[NotificationPreferenceService::class] = new RuntimeException('container down');
		$this->preferences->expects($this->never())->method('dispatch');
		$this->activity->expects($this->once())->method('publishGovernanceEvent');
		$this->logger->expects($this->once())
			->method('warning')
			->with('Decidiq: written round notification dispatch failed', ['error' => 'container down']);

		$this->assertSame(
			0,
			$this->notifier()->announceWritten(['id' => 'wr-3', 'electorateUserIds' => ['alice']], 'decision-3', 'Z')
		);

	}//end testUnresolvablePreferenceServiceNotifiesNobody()

	/**
	 * A container entry of the wrong type is treated as "no dispatcher", silently.
	 *
	 * @return void
	 */
	public function testWrongPreferenceServiceTypeNotifiesNobody(): void {
		$this->services[NotificationPreferenceService::class] = new \stdClass();
		$this->preferences->expects($this->never())->method('dispatch');
		$this->logger->expects($this->never())->method('warning');

		$this->assertSame(
			0,
			$this->notifier()->announceWritten(['id' => 'wr-4', 'electorateUserIds' => ['alice']], 'decision-4', 'Z')
		);

	}//end testWrongPreferenceServiceTypeNotifiesNobody()
}//end class
