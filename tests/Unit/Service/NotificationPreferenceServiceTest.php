<?php

/**
 * Unit tests for NotificationPreferenceService (user-settings-v1).
 *
 * Covers the defaults merge, the delegation window (including boundary
 * expiry), the recipient fan-out, the governance-email fallback and the
 * dispatch channel matrix. The OpenRegister ObjectService is replaced by a
 * plain anonymous double (NOT a PHPUnit mock of the stub class) so the
 * service's named-argument calls never depend on a stub signature — see
 * Codeberg issue #90 (pre-migration, not migrated to GitHub) for why mocking
 * the stub is brittle.
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
use OCP\IUser;
use OCP\IUserManager;
use OCP\Mail\IMailer;
use OCP\Mail\IMessage;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use Psr\Log\NullLogger;

/**
 * Tests for NotificationPreferenceService.
 *
 * @spec openspec/specs/user-settings/spec.md
 */
class NotificationPreferenceServiceTest extends TestCase {

	/**
	 * Recorded in-app notification sends (public so the recorder double can append).
	 *
	 * @var array<int, array<string, string>>
	 */
	public array $inAppSends = [];

	/**
	 * Recorded e-mail sends (public so the mailer double can append).
	 *
	 * @var string[]
	 */
	public array $emailSends = [];

	/**
	 * Build the service with a container double.
	 *
	 * @param array<string, array<string, mixed>> $preferenceRows Preference row per person id.
	 * @param string|null $accountEmail Account email returned by IUserManager.
	 *
	 * @return NotificationPreferenceService
	 */
	private function buildService(array $preferenceRows = [], ?string $accountEmail = null): NotificationPreferenceService {
		$this->inAppSends = [];
		$this->emailSends = [];

		// Plain double for OR ObjectService — only the methods + named
		// parameters the service actually uses.
		$objectService = new class($preferenceRows) {

			/**
			 * Constructor.
			 *
			 * @param array<string, array<string, mixed>> $rows Rows keyed by person id.
			 */
			public function __construct(
				private array $rows,
			) {
			}

			/**
			 * Fluent register setter.
			 *
			 * @param string $register Register slug.
			 *
			 * @return static
			 */
			public function setRegister(string $register): static {
				return $this;
			}

			/**
			 * Fluent schema setter.
			 *
			 * @param string $schema Schema slug.
			 *
			 * @return static
			 */
			public function setSchema(string $schema): static {
				return $this;
			}

			/**
			 * Filtered find-all returning the row for filters['person'].
			 *
			 * Mirrors OpenRegister's real ObjectService::findAll(array $config)
			 * signature — a single config array carrying `filters`/`limit`/
			 * `offset`. The previous fake declared the long-gone named-argument
			 * form (limit:/offset:/filters:), so it happily accepted calls that
			 * throw "Unknown named parameter" against the real service.
			 *
			 * @param array $config Find-all config (filters/limit/offset).
			 *
			 * @return array<int, array<string, mixed>>
			 */
			public function findAll(array $config = []): array {
				$filters = ($config['filters'] ?? []);
				$person = ($filters['person'] ?? '');
				if (isset($this->rows[$person]) === true) {
					return [$this->rows[$person]];
				}

				return [];
			}

			/**
			 * Echoing save.
			 *
			 * @param array $object The object payload.
			 * @param string|int|null $register Register slug.
			 * @param string|int|null $schema Schema slug.
			 *
			 * @return array<string, mixed>
			 */
			public function saveObject(array $object = [], string|int|null $register = null, string|int|null $schema = null): array {
				return $object;
			}
		};

		// Recorder double for the in-app notification service.
		$inAppRecorder = new class($this) {

			/**
			 * Constructor.
			 *
			 * @param NotificationPreferenceServiceTest $test The owning test (public sink arrays).
			 */
			public function __construct(
				private object $test,
			) {
			}

			/**
			 * Record one send.
			 *
			 * @param string $userId Recipient.
			 * @param string $title Title.
			 * @param string $message Message.
			 * @param string $deepLink Deep link.
			 *
			 * @return void
			 */
			public function sendNotification(string $userId, string $title, string $message, string $deepLink = ''): void {
				$this->test->inAppSends[] = [
					'userId' => $userId,
					'title' => $title,
					'message' => $message,
					'deepLink' => $deepLink,
				];
			}
		};

		$test = $this;
		$mailer = $this->createMock(IMailer::class);
		$mailer->method('createMessage')->willReturnCallback(
			function () {
				return $this->createMock(IMessage::class);
			}
		);
		$mailer->method('send')->willReturnCallback(
			function () use ($test) {
				$test->emailSends[] = 'sent';
				return [];
			}
		);

		$user = $this->createMock(IUser::class);
		$user->method('getEMailAddress')->willReturn($accountEmail);
		$userManager = $this->createMock(IUserManager::class);
		$userManager->method('get')->willReturn($accountEmail === null ? null : $user);

		$services = [
			'OCA\OpenRegister\Service\ObjectService' => $objectService,
			'OpenRegisterNotificationService' => $inAppRecorder,
			IMailer::class => $mailer,
			IUserManager::class => $userManager,
		];

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id) use ($services) {
				if (isset($services[$id]) === true) {
					return $services[$id];
				}

				throw new class('not found: ' . $id) extends \Exception implements NotFoundExceptionInterface {
				};
			}
		);

		return new NotificationPreferenceService(container: $container, logger: new NullLogger());
	}//end buildService()

	/**
	 * Defaults are returned when no record exists, including the new fields.
	 *
	 * @spec openspec/specs/user-settings/spec.md
	 *
	 * @return void
	 */
	public function testDefaultsWhenNoRecordExists(): void {
		$service = $this->buildService();
		$pref = $service->getPreferenceWithDefaults(personId: 'alice');

		self::assertSame('alice', $pref['person']);
		self::assertTrue($pref['meetingReminder']);
		self::assertSame(['24h', '1h'], $pref['reminderTimes'], 'Default reminder timing must be 24h + 1h before');
		self::assertSame('in-app', $pref['deliveryMethod']);
		self::assertNull($pref['delegate']);
		self::assertNull($pref['governanceEmail']);

	}//end testDefaultsWhenNoRecordExists()

	/**
	 * Stored values win over defaults in the merge.
	 *
	 * @spec openspec/specs/user-settings/spec.md
	 *
	 * @return void
	 */
	public function testStoredValuesMergeOverDefaults(): void {
		$service = $this->buildService(
			preferenceRows: [
				'alice' => [
					'person' => 'alice',
					'meetingReminder' => false,
					'reminderTimes' => ['48h', '1h'],
					'deliveryMethod' => 'both',
				],
			]
		);

		$pref = $service->getPreferenceWithDefaults(personId: 'alice');

		self::assertFalse($pref['meetingReminder']);
		self::assertSame(['48h', '1h'], $pref['reminderTimes']);
		self::assertSame('both', $pref['deliveryMethod']);
		self::assertTrue($pref['votingOpened'], 'Untouched toggles keep their default');

	}//end testStoredValuesMergeOverDefaults()

	/**
	 * Delegation is active inside the window, inclusive of both boundary days.
	 *
	 * @spec openspec/specs/user-settings/spec.md
	 *
	 * @return void
	 */
	public function testDelegationActiveInsideWindowAndOnBoundaries(): void {
		$service = $this->buildService(
			preferenceRows: [
				'memberA' => [
					'person' => 'memberA',
					'delegate' => 'memberB',
					'delegationFrom' => '2026-07-01',
					'delegationUntil' => '2026-07-14',
				],
			]
		);

		self::assertSame('memberB', $service->getActiveDelegate(personId: 'memberA', today: new \DateTimeImmutable('2026-07-07')));
		self::assertSame('memberB', $service->getActiveDelegate(personId: 'memberA', today: new \DateTimeImmutable('2026-07-01')), 'First day is inclusive');
		self::assertSame('memberB', $service->getActiveDelegate(personId: 'memberA', today: new \DateTimeImmutable('2026-07-14')), 'Last day is inclusive');

	}//end testDelegationActiveInsideWindowAndOnBoundaries()

	/**
	 * Delegation expires automatically after the end date and is inactive before the start.
	 *
	 * @spec openspec/specs/user-settings/spec.md
	 *
	 * @return void
	 */
	public function testDelegationExpiresAutomatically(): void {
		$service = $this->buildService(
			preferenceRows: [
				'memberA' => [
					'person' => 'memberA',
					'delegate' => 'memberB',
					'delegationFrom' => '2026-07-01',
					'delegationUntil' => '2026-07-14',
				],
			]
		);

		self::assertNull($service->getActiveDelegate(personId: 'memberA', today: new \DateTimeImmutable('2026-07-15')), 'Expired the day after delegationUntil');
		self::assertNull($service->getActiveDelegate(personId: 'memberA', today: new \DateTimeImmutable('2026-06-30')), 'Not yet active before delegationFrom');

	}//end testDelegationExpiresAutomatically()

	/**
	 * A delegation without an expiry date is never honoured.
	 *
	 * @spec openspec/specs/user-settings/spec.md
	 *
	 * @return void
	 */
	public function testUnboundedDelegationIsNotHonoured(): void {
		$service = $this->buildService(
			preferenceRows: [
				'memberA' => [
					'person' => 'memberA',
					'delegate' => 'memberB',
				],
			]
		);

		self::assertNull($service->getActiveDelegate(personId: 'memberA'));

	}//end testUnboundedDelegationIsNotHonoured()

	/**
	 * hasActiveDelegationTo matches only the configured delegate.
	 *
	 * @spec openspec/specs/user-settings/spec.md
	 *
	 * @return void
	 */
	public function testHasActiveDelegationToMatchesConfiguredDelegateOnly(): void {
		$today = new \DateTimeImmutable('2026-07-07');
		$service = $this->buildService(
			preferenceRows: [
				'memberA' => [
					'person' => 'memberA',
					'delegate' => 'memberB',
					'delegationFrom' => '2026-07-01',
					'delegationUntil' => '2026-07-14',
				],
			]
		);

		self::assertTrue($service->hasActiveDelegationTo(delegatorId: 'memberA', delegateId: 'memberB', today: $today));
		self::assertFalse($service->hasActiveDelegationTo(delegatorId: 'memberA', delegateId: 'memberC', today: $today));
		self::assertFalse($service->hasActiveDelegationTo(delegatorId: 'memberA', delegateId: '', today: $today));

	}//end testHasActiveDelegationToMatchesConfiguredDelegateOnly()

	/**
	 * Recipient fan-out includes the active delegate, and only the person otherwise.
	 *
	 * @spec openspec/specs/user-settings/spec.md
	 *
	 * @return void
	 */
	public function testNotificationRecipientFanOut(): void {
		$from = (new \DateTimeImmutable('-1 day'))->format('Y-m-d');
		$until = (new \DateTimeImmutable('+10 days'))->format('Y-m-d');

		$service = $this->buildService(
			preferenceRows: [
				'memberA' => [
					'person' => 'memberA',
					'delegate' => 'memberB',
					'delegationFrom' => $from,
					'delegationUntil' => $until,
				],
			]
		);

		self::assertSame(['memberA', 'memberB'], $service->getNotificationRecipients(personId: 'memberA'));
		self::assertSame(['memberC'], $service->getNotificationRecipients(personId: 'memberC'));

	}//end testNotificationRecipientFanOut()

	/**
	 * Governance email: the override wins; otherwise the account email; otherwise null.
	 *
	 * @spec openspec/specs/user-settings/spec.md
	 *
	 * @return void
	 */
	public function testGovernanceEmailOverrideAndFallback(): void {
		$withOverride = $this->buildService(
			preferenceRows: ['alice' => ['person' => 'alice', 'governanceEmail' => 'work@example.com']],
			accountEmail: 'personal@example.com'
		);
		self::assertSame('work@example.com', $withOverride->getGovernanceEmail(personId: 'alice'));

		$withFallback = $this->buildService(preferenceRows: [], accountEmail: 'personal@example.com');
		self::assertSame('personal@example.com', $withFallback->getGovernanceEmail(personId: 'alice'), 'Default MUST be the Nextcloud account email');

		$withNeither = $this->buildService();
		self::assertNull($withNeither->getGovernanceEmail(personId: 'alice'));

	}//end testGovernanceEmailOverrideAndFallback()

	/**
	 * Dispatch is suppressed entirely when the event toggle is off.
	 *
	 * @spec openspec/specs/user-settings/spec.md
	 *
	 * @return void
	 */
	public function testDispatchHonoursEventToggle(): void {
		$service = $this->buildService(
			preferenceRows: ['alice' => ['person' => 'alice', 'meetingReminder' => false]],
			accountEmail: 'alice@example.com'
		);

		$sent = $service->dispatch(personId: 'alice', eventType: 'meetingReminder', title: 'Reminder', message: 'Meeting soon');

		self::assertSame(0, $sent, 'Disabled event type MUST NOT notify');
		self::assertCount(0, $this->inAppSends);
		self::assertCount(0, $this->emailSends);

	}//end testDispatchHonoursEventToggle()

	/**
	 * Dispatch channel matrix: 'both' sends in-app AND email with the payload.
	 *
	 * @spec openspec/specs/user-settings/spec.md
	 *
	 * @return void
	 */
	public function testDispatchBothChannels(): void {
		$service = $this->buildService(
			preferenceRows: ['alice' => ['person' => 'alice', 'deliveryMethod' => 'both']],
			accountEmail: 'alice@example.com'
		);

		$sent = $service->dispatch(
			personId: 'alice',
			eventType: 'votingOpened',
			title: 'Pending vote: Budget motion',
			message: 'A new vote is open in your body. Voting deadline: 2026-07-01T12:00:00+00:00.',
			deepLink: '/motions/m1'
		);

		self::assertSame(2, $sent);
		self::assertCount(1, $this->inAppSends);
		self::assertSame('alice', $this->inAppSends[0]['userId']);
		self::assertStringContainsString('Pending vote', $this->inAppSends[0]['title']);
		self::assertStringContainsString('deadline', $this->inAppSends[0]['message']);
		self::assertCount(1, $this->emailSends);

	}//end testDispatchBothChannels()

	/**
	 * Dispatch channel matrix: default 'in-app' never emails; 'email' never sends in-app.
	 *
	 * @spec openspec/specs/user-settings/spec.md
	 *
	 * @return void
	 */
	public function testDispatchSingleChannelSelection(): void {
		$inAppOnly = $this->buildService(preferenceRows: [], accountEmail: 'alice@example.com');
		self::assertSame(1, $inAppOnly->dispatch(personId: 'alice', eventType: 'decisionPublished', title: 'T', message: 'M'));
		self::assertCount(1, $this->inAppSends);
		self::assertCount(0, $this->emailSends);

		$emailOnly = $this->buildService(
			preferenceRows: ['alice' => ['person' => 'alice', 'deliveryMethod' => 'email']],
			accountEmail: 'alice@example.com'
		);
		self::assertSame(1, $emailOnly->dispatch(personId: 'alice', eventType: 'decisionPublished', title: 'T', message: 'M'));
		self::assertCount(0, $this->inAppSends);
		self::assertCount(1, $this->emailSends);

	}//end testDispatchSingleChannelSelection()

	/**
	 * Dispatch fans out to the active delegate using the DELEGATE's own channels.
	 *
	 * @spec openspec/specs/user-settings/spec.md
	 *
	 * @return void
	 */
	public function testDispatchFansOutToDelegateWithOwnChannels(): void {
		$from = (new \DateTimeImmutable('-1 day'))->format('Y-m-d');
		$until = (new \DateTimeImmutable('+10 days'))->format('Y-m-d');

		$service = $this->buildService(
			preferenceRows: [
				'memberA' => [
					'person' => 'memberA',
					'deliveryMethod' => 'in-app',
					'delegate' => 'memberB',
					'delegationFrom' => $from,
					'delegationUntil' => $until,
				],
				'memberB' => [
					'person' => 'memberB',
					'deliveryMethod' => 'email',
				],
			],
			accountEmail: 'member@example.com'
		);

		$sent = $service->dispatch(personId: 'memberA', eventType: 'votingOpened', title: 'Pending vote', message: 'Vote now');

		self::assertSame(2, $sent);
		self::assertCount(1, $this->inAppSends, 'memberA receives in-app');
		self::assertSame('memberA', $this->inAppSends[0]['userId']);
		self::assertCount(1, $this->emailSends, 'memberB (delegate) receives email');

	}//end testDispatchFansOutToDelegateWithOwnChannels()
}//end class
