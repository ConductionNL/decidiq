<?php

/**
 * Unit tests for VotingDeadlineReminderService.
 *
 * @category Test
 * @package  OCA\Decidesk\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/nextcloud-integration/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidesk\Tests\Unit\Service;

use OCA\Decidesk\Service\VotingDeadlineReminderService;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests window calculation, round selection, already-voted skip,
 * and marker stamping.
 *
 * @spec openspec/specs/nextcloud-integration/spec.md
 */
class VotingDeadlineReminderServiceTest extends TestCase {

	/**
	 * Fixed "now" used across tests: 2026-06-12T12:00:00Z.
	 *
	 * @var int
	 */
	private const NOW = 1781265600;

	/**
	 * Notifications recorded by the fake notification service.
	 *
	 * @var \ArrayObject<int, array<string, string>>
	 */
	private \ArrayObject $sent;

	/**
	 * Objects saved through the fake ObjectService.
	 *
	 * @var \ArrayObject<int, array<string, mixed>>
	 */
	private \ArrayObject $saved;

	/**
	 * Build the service over fixture collections.
	 *
	 * @param array<int, array<string, mixed>> $rounds voting-round rows
	 * @param array<int, array<string, mixed>> $votes vote rows
	 * @param array<int, array<string, mixed>> $participants participant rows
	 * @param array<string, array<string, mixed>> $objects uuid → object (motions, participants by id)
	 *
	 * @return VotingDeadlineReminderService
	 */
	private function makeService(array $rounds = [], array $votes = [], array $participants = [], array $objects = []): VotingDeadlineReminderService {
		$this->sent = new \ArrayObject();
		$this->saved = new \ArrayObject();

		$sent = $this->sent;
		$saved = $this->saved;

		$objectService = new class($rounds, $votes, $participants, $objects, $saved) {

			/**
			 * @param array<int, array<string, mixed>> $rounds voting-round rows
			 * @param array<int, array<string, mixed>> $votes vote rows
			 * @param array<int, array<string, mixed>> $participants participant rows
			 * @param array<string, array<string, mixed>> $objects uuid → object map
			 * @param \ArrayObject $saved save recorder
			 */
			public function __construct(
				private array $rounds,
				private array $votes,
				private array $participants,
				private array $objects,
				private \ArrayObject $saved,
			) {
			}

			/**
			 * Schema-routed findAll fixture.
			 *
			 * @param array<string, mixed> $config Query config
			 *
			 * @return array<int, array<string, mixed>>
			 */
			public function findAll(array $config = []): array {
				return match ($config['schema'] ?? '') {
					'voting-round' => $this->rounds,
					'vote' => $this->votes,
					'participant' => $this->participants,
					default => [],
				};

			}//end findAll()

			/**
			 * Uuid-keyed find fixture.
			 *
			 * @param int|string $id Object id
			 * @param array|null $_extend Unused
			 * @param bool $files Unused
			 * @param string|int|null $register Unused
			 * @param string|int|null $schema Unused
			 *
			 * @return object|null
			 */
			public function find(int|string $id, ?array $_extend = [], bool $files = false, string|int|null $register = null, string|int|null $schema = null): ?object {
				$payload = ($this->objects[(string)$id] ?? null);
				if ($payload === null) {
					return null;
				}

				return new class($payload) implements \JsonSerializable {
					/**
					 * @param array<string, mixed> $payload Object payload
					 */
					public function __construct(
						private array $payload,
					) {
					}

					/**
					 * @return array<string, mixed>
					 */
					public function jsonSerialize(): array {
						return $this->payload;
					}//end jsonSerialize()
				};

			}//end find()

			/**
			 * Record a save.
			 *
			 * @param array<string, mixed> $object Payload
			 * @param array|null $extend Unused
			 * @param string|int|null $register Unused
			 * @param string|int|null $schema Unused
			 * @param string|null $uuid Unused
			 *
			 * @return object
			 */
			public function saveObject(array $object, ?array $extend = [], string|int|null $register = null, string|int|null $schema = null, ?string $uuid = null): object {
				$this->saved->append($object);
				return new \stdClass();
			}//end saveObject()
		};

		$notificationService = new class($sent) {

			/**
			 * @param \ArrayObject $sent Notification recorder
			 */
			public function __construct(
				private \ArrayObject $sent,
			) {
			}

			/**
			 * Record a notification.
			 *
			 * @param string $userId Recipient UID
			 * @param string $title Title
			 * @param string $message Message
			 * @param string $deepLink Deep link
			 *
			 * @return void
			 */
			public function sendNotification(string $userId, string $title, string $message, string $deepLink): void {
				$this->sent->append(['userId' => $userId, 'title' => $title, 'deepLink' => $deepLink]);

			}//end sendNotification()
		};

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($objectService, $notificationService) {
				if ($id === 'OpenRegisterNotificationService') {
					return $notificationService;
				}

				return $objectService;
			}
		);

		return new VotingDeadlineReminderService(
			container: $container,
			logger: $this->createMock(LoggerInterface::class),
		);

	}//end makeService()

	/**
	 * Window edges: inside (0, 24h], outside, past, malformed.
	 *
	 * @spec openspec/specs/nextcloud-integration/spec.md
	 *
	 * @return void
	 */
	public function testWindowCalculationEdges(): void {
		$service = $this->makeService();

		$in1h = gmdate('Y-m-d\TH:i:s\Z', (self::NOW + 3600));
		$in24h = gmdate('Y-m-d\TH:i:s\Z', (self::NOW + 86400));
		$in25h = gmdate('Y-m-d\TH:i:s\Z', (self::NOW + 90000));
		$past1h = gmdate('Y-m-d\TH:i:s\Z', (self::NOW - 3600));

		self::assertTrue(condition: $service->isWithinReminderWindow(deadline: $in1h, now: self::NOW));
		self::assertTrue(condition: $service->isWithinReminderWindow(deadline: $in24h, now: self::NOW));
		self::assertFalse(condition: $service->isWithinReminderWindow(deadline: $in25h, now: self::NOW));
		self::assertFalse(condition: $service->isWithinReminderWindow(deadline: $past1h, now: self::NOW));
		self::assertFalse(condition: $service->isWithinReminderWindow(deadline: '', now: self::NOW));
		self::assertFalse(condition: $service->isWithinReminderWindow(deadline: 'not-a-date', now: self::NOW));

	}//end testWindowCalculationEdges()

	/**
	 * Selection: open + in-window + not-yet-reminded rounds only.
	 *
	 * @spec openspec/specs/nextcloud-integration/spec.md
	 *
	 * @return void
	 */
	public function testRoundSelection(): void {
		$inWindow = gmdate('Y-m-d\TH:i:s\Z', (self::NOW + 7200));
		$rounds = [
			['id' => 'r-due', 'votingDeadline' => $inWindow],
			['id' => 'r-closed', 'votingDeadline' => $inWindow, 'closedAt' => gmdate('Y-m-d\TH:i:s\Z', self::NOW)],
			['id' => 'r-reminded', 'votingDeadline' => $inWindow, 'deadlineReminderSentAt' => gmdate('Y-m-d\TH:i:s\Z', (self::NOW - 3600))],
			['id' => 'r-far', 'votingDeadline' => gmdate('Y-m-d\TH:i:s\Z', (self::NOW + 500000))],
			['id' => 'r-no-deadline'],
		];

		$service = $this->makeService(rounds: $rounds);
		$due = $service->findRoundsNeedingReminder(now: self::NOW);

		self::assertCount(expectedCount: 1, haystack: $due);
		self::assertSame(expected: 'r-due', actual: $due[0]['id']);

	}//end testRoundSelection()

	/**
	 * remindRound notifies only participants who have not voted and
	 * stamps the marker.
	 *
	 * @spec openspec/specs/nextcloud-integration/spec.md
	 *
	 * @return void
	 */
	public function testRemindSkipsVotersAndStampsMarker(): void {
		$round = [
			'id' => 'r-1',
			'motion' => 'mot-1',
			'votingDeadline' => gmdate('Y-m-d\TH:i:s\Z', (self::NOW + 7200)),
		];

		$service = $this->makeService(
			rounds: [$round],
			votes: [
				['id' => 'v-1', 'caster' => 'part-alice'],
			],
			participants: [
				['id' => 'part-alice', 'nextcloudUserId' => 'alice'],
				['id' => 'part-bob', 'nextcloudUserId' => 'bob'],
				['id' => 'part-nolink'],
			],
			objects: [
				'mot-1' => ['id' => 'mot-1', 'meeting' => 'meet-1'],
				'part-alice' => ['id' => 'part-alice', 'nextcloudUserId' => 'alice'],
			]
		);

		$sentCount = $service->remindRound(round: $round, now: self::NOW);

		// Alice voted; only Bob gets the reminder (no-link participant skipped).
		self::assertSame(expected: 1, actual: $sentCount);
		$sent = $this->sent->getArrayCopy();
		self::assertSame(expected: 'bob', actual: $sent[0]['userId']);

		// Marker stamped exactly once.
		$saved = $this->saved->getArrayCopy();
		self::assertCount(expectedCount: 1, haystack: $saved);
		self::assertNotEmpty(actual: $saved[0]['deadlineReminderSentAt']);

	}//end testRemindSkipsVotersAndStampsMarker()

	/**
	 * run() sweeps all due rounds and reports the total sent.
	 *
	 * @spec openspec/specs/nextcloud-integration/spec.md
	 *
	 * @return void
	 */
	public function testRunSweepsDueRounds(): void {
		$round = [
			'id' => 'r-1',
			'motion' => 'mot-1',
			'votingDeadline' => gmdate('Y-m-d\TH:i:s\Z', (self::NOW + 7200)),
		];

		$service = $this->makeService(
			rounds: [$round],
			participants: [
				['id' => 'part-bob', 'nextcloudUserId' => 'bob'],
			],
			objects: [
				'mot-1' => ['id' => 'mot-1', 'meeting' => 'meet-1'],
			]
		);

		self::assertSame(expected: 1, actual: $service->run(now: self::NOW));

	}//end testRunSweepsDueRounds()
}//end class
