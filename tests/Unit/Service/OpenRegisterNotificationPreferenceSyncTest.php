<?php

/**
 * Unit tests for OpenRegisterNotificationPreferenceSync.
 *
 * Covers mirroring decidiq's notification switches onto OpenRegister's
 * per-user overrides (issue #1381), and the fail-soft paths when
 * OpenRegister's preference service is missing or refuses a write.
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/user-settings/spec.md
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service;

use OCA\Decidiq\Service\NotificationPreferenceService;
use OCA\Decidiq\Service\OpenRegisterNotificationPreferenceSync;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use Psr\Log\AbstractLogger;
use Psr\Log\NullLogger;

/**
 * Tests for OpenRegisterNotificationPreferenceSync.
 *
 * @spec openspec/specs/user-settings/spec.md
 */
class OpenRegisterNotificationPreferenceSyncTest extends TestCase {

	/**
	 * Build the sync with a container that holds the given OpenRegister double.
	 *
	 * @param object|null                   $openRegisterPreferences OpenRegister's preference service double, or null when absent.
	 * @param \Psr\Log\LoggerInterface|null $logger                  Logger (defaults to a NullLogger).
	 *
	 * @return OpenRegisterNotificationPreferenceSync
	 */
	private function buildSync(?object $openRegisterPreferences, ?\Psr\Log\LoggerInterface $logger = null): OpenRegisterNotificationPreferenceSync {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id) use ($openRegisterPreferences) {
				if ($id === 'OCA\OpenRegister\Service\Notification\NotificationPreferenceService' && $openRegisterPreferences !== null) {
					return $openRegisterPreferences;
				}

				throw new class('not found: ' . $id) extends \Exception implements NotFoundExceptionInterface {
				};
			}
		);

		return new OpenRegisterNotificationPreferenceSync(container: $container, logger: ($logger ?? new NullLogger()));
	}//end buildSync()

	/**
	 * Build a logger that records the messages it is given.
	 *
	 * @return AbstractLogger
	 */
	private function recordingLogger(): AbstractLogger {
		return new class extends AbstractLogger {

			/**
			 * Recorded messages.
			 *
			 * @var string[]
			 */
			public array $messages = [];

			/**
			 * Record a log line.
			 *
			 * @param mixed                $level   The level.
			 * @param string|\Stringable   $message The message.
			 * @param array<string, mixed> $context The context.
			 *
			 * @return void
			 */
			public function log($level, string|\Stringable $message, array $context = []): void {
				$this->messages[] = (string)$message;
			}
		};
	}//end recordingLogger()

	/**
	 * A switch turned off silences the notices OpenRegister sends from the
	 * schema declarations (issue #1381): the sync writes OpenRegister's
	 * per-user override for each of them, and clears it for a switch that is on.
	 *
	 * @spec openspec/specs/user-settings/spec.md
	 *
	 * @return void
	 */
	public function testSwitchesReachTheDeclaredOpenRegisterNotices(): void {
		$overrides = new class {

			/**
			 * Recorded overrides, keyed `<user>|<schema>/<key>`.
			 *
			 * @var array<string, array<string, mixed>|null>
			 */
			public array $written = [];

			/**
			 * Mirrors OpenRegister's setOverride() signature.
			 *
			 * @param string $userId The user.
			 * @param string $schemaSlug The schema slug.
			 * @param string $notificationKey The notification key.
			 * @param array<string, mixed>|null $override The override, or null to clear.
			 * @param string|null $scope The scope.
			 *
			 * @return void
			 */
			public function setOverride(string $userId, string $schemaSlug, string $notificationKey, ?array $override, ?string $scope = null): void {
				$this->written[$userId . '|' . $schemaSlug . '/' . $notificationKey] = $override;
			}
		};

		$merged = array_merge(
			NotificationPreferenceService::DEFAULTS,
			['decisionPublished' => false, 'taskAssigned' => false]
		);
		$this->buildSync(openRegisterPreferences: $overrides)->sync(personId: 'alice', merged: $merged);

		self::assertSame(
			[
				'alice|decision/decisionPublished' => ['enabled' => false],
				'alice|action-item/actionAssigned' => ['enabled' => false],
				'alice|action-item/actionItemAssignedToYou' => ['enabled' => false],
				'alice|meeting/meetingStartingSoon' => null,
			],
			$overrides->written,
			'Off switches store enabled:false; on switches clear the override'
		);

	}//end testSwitchesReachTheDeclaredOpenRegisterNotices()

	/**
	 * Without OpenRegister's preference service nothing is written and the gap is logged.
	 *
	 * @spec openspec/specs/user-settings/spec.md
	 *
	 * @return void
	 */
	public function testMissingOpenRegisterPreferencesIsLoggedNotThrown(): void {
		$logger = $this->recordingLogger();
		$this->buildSync(openRegisterPreferences: null, logger: $logger)->sync(
			personId: 'alice',
			merged: NotificationPreferenceService::DEFAULTS
		);

		self::assertCount(1, $logger->messages);
		self::assertStringContainsString('OpenRegister notification preferences unavailable', $logger->messages[0]);

	}//end testMissingOpenRegisterPreferencesIsLoggedNotThrown()

	/**
	 * A refused override is logged and the remaining notices are still written.
	 *
	 * @spec openspec/specs/user-settings/spec.md
	 *
	 * @return void
	 */
	public function testARefusedOverrideDoesNotStopTheOthers(): void {
		$overrides = new class {

			/**
			 * Notices that were written.
			 *
			 * @var string[]
			 */
			public array $written = [];

			/**
			 * Refuses the decision notice, records the rest.
			 *
			 * @param string $userId The user.
			 * @param string $schemaSlug The schema slug.
			 * @param string $notificationKey The notification key.
			 * @param array<string, mixed>|null $override The override, or null to clear.
			 *
			 * @return void
			 */
			public function setOverride(string $userId, string $schemaSlug, string $notificationKey, ?array $override): void {
				if ($schemaSlug === 'decision') {
					throw new \RuntimeException('refused');
				}

				$this->written[] = $schemaSlug . '/' . $notificationKey;
			}
		};

		$logger = $this->recordingLogger();
		$this->buildSync(openRegisterPreferences: $overrides, logger: $logger)->sync(
			personId: 'alice',
			merged: NotificationPreferenceService::DEFAULTS
		);

		self::assertSame(
			['action-item/actionAssigned', 'action-item/actionItemAssignedToYou', 'meeting/meetingStartingSoon'],
			$overrides->written
		);
		self::assertSame(['Decidiq: could not apply a notification switch to OpenRegister'], $logger->messages);

	}//end testARefusedOverrideDoesNotStopTheOthers()
}//end class
