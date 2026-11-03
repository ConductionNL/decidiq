<?php

/**
 * Golden test for publishing a governance event to the Activity feed.
 *
 * Runs publishGovernanceEvent with and without a session user, with affected
 * users that repeat, are empty or are not strings, with no audience at all,
 * with a publish that fails for one user, an event factory that fails, a
 * session that cannot be read and an Activity manager that is missing. It
 * records every call on the event, the manager and the logger, and the count
 * returned. The expected values live in
 * tests/fixtures/activity-publisher-golden.json; run with
 * RECORD_ACTIVITY_PUBLISHER_GOLDEN=1 to write them, then run again without it.
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/nextcloud-integration/spec.md
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service;

use OCA\Decidiq\Service\ActivityPublisherService;
use OCP\Activity\IEvent;
use OCP\Activity\IManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Golden test for publishing a governance event.
 *
 * @covers \OCA\Decidiq\Service\ActivityPublisherService
 */
class ActivityPublisherGoldenTest extends TestCase {

	private const GOLDEN = __DIR__ . '/../../fixtures/activity-publisher-golden.json';

	/**
	 * Every recorded call of the current case.
	 *
	 * @var list<array<string, mixed>>
	 */
	private array $log = [];

	/**
	 * Every publication answers as recorded.
	 *
	 * @return void
	 */
	public function testEveryPublicationMatchesTheRecording(): void {
		$actual = [];
		foreach ($this->cases() as $name => $case) {
			$this->log = [];
			$before    = time();
			$count     = $this->service(case: $case)->publishGovernanceEvent(...$case['call']);
			$after     = time();
			foreach ($this->log as $i => $entry) {
				if (($entry['call'] ?? '') === 'setTimestamp') {
					$this->log[$i]['args'] = [($entry['args'][0] >= $before && $entry['args'][0] <= $after)];
				}
			}

			$actual[$name] = ['returns' => $count, 'calls' => $this->log];
		}

		$golden = [];
		if (is_file(self::GOLDEN) === true) {
			$golden = json_decode((string)file_get_contents(self::GOLDEN), true);
		}

		if (getenv('RECORD_ACTIVITY_PUBLISHER_GOLDEN') === '1') {
			file_put_contents(self::GOLDEN, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
			$golden = json_decode((string)file_get_contents(self::GOLDEN), true);
		}

		self::assertSame(array_keys($golden), array_keys($actual));
		foreach ($actual as $name => $value) {
			self::assertSame(json_encode($golden[$name]), json_encode($value), $name);
		}
	}//end testEveryPublicationMatchesTheRecording()

	/**
	 * The cases: the call and how the doubles behave.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function cases(): array {
		$call = [
			'subject' => 'decision_recorded', 'title' => 'Budget', 'status' => 'adopted',
			'objectType' => 'decision', 'objectUuid' => 'uuid-1', 'segment' => 'decisions',
		];

		return [
			'author-and-users'    => ['call' => $call + ['affectedUserIds' => ['alice', 'bob', 'alice', '', 'chair', 7, null]], 'session' => 'chair'],
			'no-session-user'     => ['call' => $call + ['affectedUserIds' => ['bob', 'alice']], 'session' => null],
			'author-only'         => ['call' => ['subject' => 's', 'title' => '', 'status' => '', 'objectType' => 'meeting', 'objectUuid' => '', 'segment' => 'meetings'], 'session' => 'chair'],
			'no-audience'         => ['call' => $call + ['affectedUserIds' => ['', '']], 'session' => null],
			'publish-fails-once'  => ['call' => $call + ['affectedUserIds' => ['alice', 'bob']], 'session' => 'chair', 'publishFails' => 'bob'],
			'generate-fails'      => ['call' => $call + ['affectedUserIds' => ['alice']], 'session' => null, 'generateFails' => true],
			'session-unreadable'  => ['call' => $call + ['affectedUserIds' => ['alice']], 'session' => 'throw'],
			'manager-missing'     => ['call' => $call + ['affectedUserIds' => ['alice']], 'session' => 'chair', 'manager' => false],
			'empty-session-uid'   => ['call' => $call + ['affectedUserIds' => ['alice']], 'session' => ''],
		];
	}//end cases()

	/**
	 * The service over the current case.
	 *
	 * @param array<string, mixed> $case The case
	 *
	 * @return ActivityPublisherService
	 */
	private function service(array $case): ActivityPublisherService {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id) use ($case): object {
				$this->log[] = ['call' => 'container.get', 'args' => [$id]];
				if ($id === IManager::class) {
					if (($case['manager'] ?? true) === false) {
						throw new RuntimeException('no activity app');
					}

					return $this->manager(case: $case);
				}

				return $this->session(uid: $case['session']);
			}
		);

		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('debug')->willReturnCallback(
			function (string $message, array $context = []): void {
				$this->log[] = ['call' => 'debug', 'args' => [$message, $context]];
			}
		);

		return new ActivityPublisherService(container: $container, logger: $logger);
	}//end service()

	/**
	 * The Activity manager double.
	 *
	 * @param array<string, mixed> $case The case
	 *
	 * @return IManager
	 */
	private function manager(array $case): IManager {
		$manager = $this->createMock(IManager::class);
		$manager->method('generateEvent')->willReturnCallback(
			function () use ($case): IEvent {
				$this->log[] = ['call' => 'generateEvent', 'args' => []];
				if (($case['generateFails'] ?? false) === true) {
					throw new RuntimeException('cannot generate');
				}

				return $this->event();
			}
		);
		$manager->method('publish')->willReturnCallback(
			function (IEvent $event) use ($case): void {
				$this->log[] = ['call' => 'publish', 'args' => [$event->getAffectedUser()]];
				if ($event->getAffectedUser() === ($case['publishFails'] ?? null)) {
					throw new RuntimeException('publish failed for ' . $event->getAffectedUser());
				}
			}
		);

		return $manager;
	}//end manager()

	/**
	 * An event double that records every setter.
	 *
	 * @return IEvent
	 */
	private function event(): IEvent {
		$event    = $this->createMock(IEvent::class);
		$affected = '';
		foreach (['setApp', 'setType', 'setAffectedUser', 'setTimestamp', 'setSubject', 'setObject', 'setAuthor'] as $setter) {
			$event->method($setter)->willReturnCallback(
				function (mixed ...$args) use ($setter, $event, &$affected): IEvent {
					$this->log[] = ['call' => $setter, 'args' => $args];
					if ($setter === 'setAffectedUser') {
						$affected = $args[0];
					}

					return $event;
				}
			);
		}

		$event->method('getAffectedUser')->willReturnCallback(
			static function () use (&$affected): string {
				return $affected;
			}
		);

		return $event;
	}//end event()

	/**
	 * The user session double.
	 *
	 * @param string|null $uid The session uid, null for none, 'throw' to fail
	 *
	 * @return IUserSession
	 */
	private function session(?string $uid): IUserSession {
		$session = $this->createMock(IUserSession::class);
		if ($uid === 'throw') {
			$session->method('getUser')->willThrowException(new RuntimeException('session gone'));
			return $session;
		}

		$user = null;
		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
		}

		$session->method('getUser')->willReturn($user);
		return $session;
	}//end session()
}//end class
