<?php

/**
 * Unit tests for ActivityPublisherService.
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

use OCA\Decidesk\Service\ActivityPublisherService;
use OCP\Activity\IEvent;
use OCP\Activity\IManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests audience deduplication, author inclusion, and fail-soft behavior.
 *
 * @spec openspec/specs/nextcloud-integration/spec.md
 */
class ActivityPublisherServiceTest extends TestCase {

	/**
	 * Build the service over a mock activity manager + session.
	 *
	 * @param IManager|null $activityManager Activity manager (null = container throws)
	 * @param string|null $sessionUid Session user UID (null = no session)
	 *
	 * @return ActivityPublisherService
	 */
	private function makeService(?IManager $activityManager, ?string $sessionUid): ActivityPublisherService {
		$userSession = $this->createMock(IUserSession::class);
		if ($sessionUid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($sessionUid);
			$userSession->method('getUser')->willReturn($user);
		} else {
			$userSession->method('getUser')->willReturn(null);
		}

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($activityManager, $userSession) {
				if ($id === \OCP\Activity\IManager::class) {
					if ($activityManager === null) {
						throw new \RuntimeException('Activity app unavailable');
					}

					return $activityManager;
				}

				return $userSession;
			}
		);

		return new ActivityPublisherService(
			container: $container,
			logger: $this->createMock(LoggerInterface::class),
		);

	}//end makeService()

	/**
	 * One event per unique affected user; the acting user is included.
	 *
	 * @spec openspec/specs/nextcloud-integration/spec.md
	 *
	 * @return void
	 */
	public function testPublishesPerUniqueUserIncludingAuthor(): void {
		$published = [];

		$event = $this->createMock(IEvent::class);
		foreach (['setApp', 'setType', 'setTimestamp', 'setSubject', 'setObject', 'setAuthor'] as $setter) {
			$event->method($setter)->willReturnSelf();
		}

		$affected = [];
		$event->method('setAffectedUser')->willReturnCallback(
			static function (string $uid) use (&$affected, $event) {
				$affected[] = $uid;
				return $event;
			}
		);

		$manager = $this->createMock(IManager::class);
		$manager->method('generateEvent')->willReturn($event);
		$manager->method('publish')->willReturnCallback(
			static function () use (&$published): void {
				$published[] = true;
			}
		);

		$service = $this->makeService(activityManager: $manager, sessionUid: 'chair');

		$count = $service->publishGovernanceEvent(
			subject: 'decision_recorded',
			title: 'Budget',
			status: 'adopted',
			objectType: 'decision',
			objectUuid: 'uuid-1',
			segment: 'decisions',
			affectedUserIds: ['alice', 'bob', 'alice', '', 'chair']
		);

		self::assertSame(expected: 3, actual: $count);
		self::assertSame(expected: ['alice', 'bob', 'chair'], actual: $affected);

	}//end testPublishesPerUniqueUserIncludingAuthor()

	/**
	 * No audience at all → nothing published, no exception.
	 *
	 * @spec openspec/specs/nextcloud-integration/spec.md
	 *
	 * @return void
	 */
	public function testNoAudienceSkips(): void {
		$manager = $this->createMock(IManager::class);
		$manager->expects(self::never())->method('publish');

		$service = $this->makeService(activityManager: $manager, sessionUid: null);

		self::assertSame(
			expected: 0,
			actual: $service->publishGovernanceEvent(
				subject: 'decision_recorded',
				title: 'Budget',
				status: '',
				objectType: 'decision',
				objectUuid: 'uuid-1',
				segment: 'decisions'
			)
		);

	}//end testNoAudienceSkips()

	/**
	 * Activity app unavailable → fail soft (0 published, no exception).
	 *
	 * @spec openspec/specs/nextcloud-integration/spec.md
	 *
	 * @return void
	 */
	public function testFailsSoftWhenActivityUnavailable(): void {
		$service = $this->makeService(activityManager: null, sessionUid: 'chair');

		self::assertSame(
			expected: 0,
			actual: $service->publishGovernanceEvent(
				subject: 'decision_recorded',
				title: 'Budget',
				status: '',
				objectType: 'decision',
				objectUuid: 'uuid-1',
				segment: 'decisions',
				affectedUserIds: ['alice']
			)
		);

	}//end testFailsSoftWhenActivityUnavailable()
}//end class
