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
use OCP\IURLGenerator;
use OCP\IUserManager;
use OCP\Notification\IManager as INotificationManager;
use OCP\Notification\INotification;
use OCP\Mail\IAttachment;
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
	 * Plain-text bodies of the e-mails sent.
	 *
	 * @var array<int, string>
	 */
	public array $emailBodies = [];

	/**
	 * Recorded e-mail sends (public so the mailer double can append).
	 *
	 * @var string[]
	 */
	public array $emailSends = [];

	/**
	 * Files attached to the e-mails sent: [data, filename, contentType].
	 *
	 * @var array<int, array<int, mixed>>
	 */
	public array $emailAttachments = [];

	/**
	 * Attachments created but not yet attached, by object id.
	 *
	 * @var array<int, array<int, mixed>>
	 */
	public array $pendingAttachments = [];

	/**
	 * Build the service with a container double.
	 *
	 * @param array<string, array<string, mixed>> $preferenceRows Preference row per person id.
	 * @param string|null $accountEmail Account email returned by IUserManager.
	 * @param \Psr\Log\LoggerInterface|null $logger Logger (defaults to a NullLogger).
	 * @param object|null $openRegisterPreferences OpenRegister's preference service double, or null when absent.
	 *
	 * @return NotificationPreferenceService
	 */
	private function buildService(
		array $preferenceRows = [],
		?string $accountEmail = null,
		?\Psr\Log\LoggerInterface $logger = null,
		?object $openRegisterPreferences = null,
	): NotificationPreferenceService {
		$this->inAppSends = [];
		$this->emailSends = [];
		$this->emailAttachments = [];

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

		// The in-app channel is Nextcloud's own notification manager
		// (agenda-change-notices-reach-members, REQ-ACN-002). Each notify()
		// records what was sent, read back off the real INotification setters.
		$test = $this;
		$notificationManager = $this->createMock(INotificationManager::class);
		$notificationManager->method('createNotification')->willReturnCallback(
			function () use ($test): INotification {
				$state = new \ArrayObject();
				$n = $test->createMock(INotification::class);
				foreach (['setApp', 'setUser', 'setObject', 'setSubject', 'setDateTime'] as $setter) {
					$n->method($setter)->willReturnCallback(
						function (...$args) use ($n, $state, $setter): INotification {
							$state[$setter] = $args;
							return $n;
						}
					);
				}

				$n->method('getApp')->willReturnCallback(fn () => ($state['setApp'][0] ?? ''));
				$n->method('getUser')->willReturnCallback(fn () => ($state['setUser'][0] ?? ''));
				$n->method('getSubject')->willReturnCallback(fn () => ($state['setSubject'][0] ?? ''));
				$n->method('getSubjectParameters')->willReturnCallback(fn () => ($state['setSubject'][1] ?? []));
				return $n;
			}
		);
		$notificationManager->method('notify')->willReturnCallback(
			function (INotification $n) use ($test): void {
				$params = $n->getSubjectParameters();
				$test->inAppSends[] = [
					'userId'     => $n->getUser(),
					'app'        => $n->getApp(),
					'subject'    => $n->getSubject(),
					'parameters' => $params,
					'title'      => (string)($params['title'] ?? ''),
					'message'    => (string)($params['message'] ?? ''),
					'deepLink'   => (string)($params['link'] ?? ''),
				];
			}
		);

		$mailer = $this->createMock(IMailer::class);
		$mailer->method('createMessage')->willReturnCallback(
			function () use ($test) {
				$message = $this->createMock(IMessage::class);
				$message->method('setPlainBody')->willReturnCallback(
					function (string $body) use ($test, $message): IMessage {
						$test->emailBodies[] = $body;
						return $message;
					}
				);
				$message->method('attach')->willReturnCallback(
					function (IAttachment $attachment) use ($test, $message): IMessage {
						$test->emailAttachments[] = $test->pendingAttachments[spl_object_id($attachment)];
						return $message;
					}
				);
				return $message;
			}
		);
		$mailer->method('createAttachment')->willReturnCallback(
			function ($data = null, $filename = null, $contentType = null) use ($test): IAttachment {
				$attachment = $test->createMock(IAttachment::class);
				$test->pendingAttachments[spl_object_id($attachment)] = [$data, $filename, $contentType];
				return $attachment;
			}
		);
		$mailer->method('send')->willReturnCallback(
			function () use ($test) {
				$test->emailSends[] = 'sent';
				return [];
			}
		);

		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('linkToRouteAbsolute')->willReturn('https://cloud.example/apps/decidiq/');

		$user = $this->createMock(IUser::class);
		$user->method('getEMailAddress')->willReturn($accountEmail);
		$userManager = $this->createMock(IUserManager::class);
		$userManager->method('get')->willReturn($accountEmail === null ? null : $user);

		$services = [
			'OCA\OpenRegister\Service\ObjectService' => $objectService,
			INotificationManager::class => $notificationManager,
			IMailer::class => $mailer,
			IUserManager::class => $userManager,
			IURLGenerator::class => $urls,
		];
		if ($openRegisterPreferences !== null) {
			$services['OCA\OpenRegister\Service\Notification\NotificationPreferenceService'] = $openRegisterPreferences;
		}

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

		return new NotificationPreferenceService(container: $container, logger: ($logger ?? new NullLogger()));
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

	/**
	 * Approval-stage lapse notices are addressed to one person about their
	 * own sign-off, so they pass the per-event filter on default preferences
	 * and cannot be switched off by an unrelated toggle (issue #1395).
	 *
	 * @spec openspec/specs/user-settings/spec.md
	 *
	 * @return void
	 */
	public function testApprovalStageLapseNoticeIsDeliveredOnDefaultPreferences(): void {
		$service = $this->buildService(preferenceRows: [], accountEmail: 'sub@example.com');

		$sent = $service->dispatch(
			personId: 'substitute',
			eventType: \OCA\Decidiq\Service\ApprovalStageLapseService::EVENT_TYPE,
			title: 'A sign-off is waiting, on behalf of a colleague',
			message: 'Please act on the step.'
		);

		self::assertSame(1, $sent, 'A lapse notice MUST be delivered on default preferences');
		self::assertCount(1, $this->inAppSends);
		self::assertSame('substitute', $this->inAppSends[0]['userId']);

		$emailUser = $this->buildService(
			preferenceRows: ['substitute' => ['person' => 'substitute', 'deliveryMethod' => 'email', 'votingOpened' => false, 'meetingReminder' => false]],
			accountEmail: 'sub@example.com'
		);
		self::assertSame(
			1,
			$emailUser->dispatch(personId: 'substitute', eventType: \OCA\Decidiq\Service\ApprovalStageLapseService::EVENT_TYPE, title: 'T', message: 'M'),
			'A lapse notice follows the delivery method and ignores the event toggles'
		);
		self::assertCount(1, $this->emailSends);

	}//end testApprovalStageLapseNoticeIsDeliveredOnDefaultPreferences()

	/**
	 * A publication digest goes to a member who subscribed: the subscription is
	 * his opt-in, so no event toggle stands in its way; the delivery method
	 * still applies (publication-subscriptions-and-daily-digest).
	 *
	 * @spec openspec/specs/public-publication/spec.md#requirement-req-psd-003-subscribers-receive-matching-events-immediately-daily-or-weekly
	 *
	 * @return void
	 */
	public function testPublicationDigestIsDeliveredOnDefaultPreferences(): void {
		$service = $this->buildService(preferenceRows: [], accountEmail: 'pieter@example.com');

		$sent = $service->dispatch(
			personId: 'pieter',
			eventType: \OCA\Decidiq\Service\PublicationDigestService::EVENT_TYPE,
			title: '5 updates from Gemeenteraad',
			message: 'Raadsvergadering 14 oktober'
		);

		self::assertSame(1, $sent, 'A digest the member subscribed to MUST be delivered on default preferences');
		self::assertCount(1, $this->inAppSends);
		self::assertSame('pieter', $this->inAppSends[0]['userId']);

	}//end testPublicationDigestIsDeliveredOnDefaultPreferences()

	/**
	 * An event type nobody declared is still dropped, and the drop is logged
	 * so a silent filter can be found in the log (issue #1395).
	 *
	 * @spec openspec/specs/user-settings/spec.md
	 *
	 * @return void
	 */
	public function testUnknownEventTypeIsDroppedWithALogLine(): void {
		$logger = new class extends \Psr\Log\AbstractLogger {
			/** @var array<int, string> */
			public array $lines = [];

			/**
			 * Record a log line.
			 *
			 * @param mixed $level Level
			 * @param string|\Stringable $message Message
			 * @param array<string, mixed> $context Context
			 *
			 * @return void
			 */
			public function log($level, string|\Stringable $message, array $context = []): void {
				$this->lines[] = (string)$message;
			}
		};
		$service = $this->buildService(preferenceRows: [], accountEmail: 'a@example.com', logger: $logger);

		self::assertSame(0, $service->dispatch(personId: 'alice', eventType: 'no-such-event', title: 'T', message: 'M'));
		self::assertCount(0, $this->inAppSends);
		self::assertNotEmpty($logger->lines, 'A dropped notice MUST leave a log line');

	}//end testUnknownEventTypeIsDroppedWithALogLine()

	/**
	 * The in-app channel is a decidiq notification sent through Nextcloud's
	 * notification manager, with the generic subject the notifier renders, and
	 * no service outside decidiq is needed for it.
	 *
	 * @spec openspec/specs/decidesk-notifications/spec.md#requirement-req-acn-002-preference-aware-in-app-notices-are-sent-as-decidiq
	 *
	 * @return void
	 */
	public function testInAppIsSentAsADecidiqNotification(): void {
		$service = $this->buildService(preferenceRows: [], accountEmail: null);

		self::assertSame(
			1,
			$service->dispatch(personId: 'aisha', eventType: 'votingOpened', title: 'Voting opened', message: 'Vote now.', deepLink: '/motions/m1')
		);
		self::assertSame('decidiq', $this->inAppSends[0]['app']);
		self::assertSame('decidiq_message', $this->inAppSends[0]['subject']);
		self::assertSame('aisha', $this->inAppSends[0]['userId']);
		self::assertSame('/motions/m1', $this->inAppSends[0]['deepLink']);

	}//end testInAppIsSentAsADecidiqNotification()

	/**
	 * A caller can hand its own subject for the bell, so the notifier renders
	 * it in each recipient's language (agenda notices do this).
	 *
	 * @spec openspec/specs/decidesk-notifications/spec.md#requirement-req-acn-003-agenda-notices-follow-the-members-delivery-choice
	 *
	 * @return void
	 */
	public function testACallerCanNameItsOwnSubject(): void {
		$service = $this->buildService(preferenceRows: [], accountEmail: null);

		$service->dispatch(
			personId: 'pieter',
			eventType: 'agendaChanged',
			title: 'The agenda changed',
			message: 'See the meeting.',
			deepLink: '/meetings/m-1',
			inApp: ['subject' => 'agenda_changed', 'parameters' => ['meetingId' => 'm-1'], 'objectType' => 'meeting', 'objectId' => 'm-1']
		);

		self::assertSame('agenda_changed', $this->inAppSends[0]['subject']);
		self::assertSame(['meetingId' => 'm-1'], $this->inAppSends[0]['parameters']);

	}//end testACallerCanNameItsOwnSubject()

	/**
	 * A member who reads email gets the agenda change by email, and the email
	 * carries an absolute link to the meeting.
	 *
	 * @spec openspec/specs/decidesk-notifications/spec.md#requirement-req-acn-003-agenda-notices-follow-the-members-delivery-choice
	 *
	 * @return void
	 */
	public function testAnEmailReaderGetsTheAgendaChangeWithALink(): void {
		$service = $this->buildService(
			preferenceRows: ['jan' => ['person' => 'jan', 'deliveryMethod' => 'email']],
			accountEmail: 'jan@example.com'
		);

		self::assertSame(1, $service->dispatch(personId: 'jan', eventType: 'agendaChanged', title: 'The agenda of Raad changed', message: 'Open the meeting.', deepLink: '/meetings/m-1'));
		self::assertCount(0, $this->inAppSends);
		self::assertStringContainsString('https://cloud.example/apps/decidiq/meetings/m-1', $this->emailBodies[0]);

	}//end testAnEmailReaderGetsTheAgendaChangeWithALink()

	/**
	 * A member who switched agenda changes off gets nothing.
	 *
	 * @spec openspec/specs/decidesk-notifications/spec.md#requirement-req-acn-003-agenda-notices-follow-the-members-delivery-choice
	 *
	 * @return void
	 */
	public function testAMemberWhoSwitchedAgendaChangesOffGetsNothing(): void {
		$service = $this->buildService(
			preferenceRows: ['els' => ['person' => 'els', 'agendaChanged' => false, 'deliveryMethod' => 'both']],
			accountEmail: 'els@example.com'
		);

		self::assertSame(0, $service->dispatch(personId: 'els', eventType: 'agendaChanged', title: 'T', message: 'M'));
		self::assertCount(0, $this->inAppSends);
		self::assertCount(0, $this->emailSends);

	}//end testAMemberWhoSwitchedAgendaChangesOffGetsNothing()

	/**
	 * An email reader gets the invitation with the calendar file attached;
	 * a bell reader gets the notice without it.
	 *
	 * @spec openspec/specs/agenda-publication/spec.md#requirement-req-apim-001-publishing-the-agenda-invites-the-members
	 *
	 * @return void
	 */
	public function testAnEmailReaderGetsTheCalendarFile(): void {
		$service = $this->buildService(
			preferenceRows: ['jan' => ['person' => 'jan', 'deliveryMethod' => 'both']],
			accountEmail: 'jan@example.com'
		);

		$sent = $service->dispatch(
			personId: 'jan',
			eventType: 'agendaChanged',
			title: 'The agenda of Raad was published',
			message: "When: 2026-10-08 19:30\n\nAgenda:\n1. Opening",
			deepLink: '/meetings/m-1',
			attachments: [['data' => "BEGIN:VCALENDAR\r\nEND:VCALENDAR\r\n", 'filename' => 'meeting.ics', 'contentType' => 'text/calendar']]
		);

		self::assertSame(2, $sent);
		self::assertCount(1, $this->inAppSends);
		self::assertStringContainsString('1. Opening', $this->emailBodies[0]);
		self::assertSame([["BEGIN:VCALENDAR\r\nEND:VCALENDAR\r\n", 'meeting.ics', 'text/calendar']], $this->emailAttachments);

	}//end testAnEmailReaderGetsTheCalendarFile()

	/**
	 * A switch turned off here silences the notices OpenRegister sends from the
	 * schema declarations (issue #1381): decidiq writes OpenRegister's per-user
	 * override for each of them, and clears it for a switch that is on.
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

		$service = $this->buildService(openRegisterPreferences: $overrides);
		$service->updatePreference(personId: 'alice', preferences: ['decisionPublished' => false, 'taskAssigned' => false]);

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
	 * Without OpenRegister's preference service the decidiq preference still saves.
	 *
	 * @spec openspec/specs/user-settings/spec.md
	 *
	 * @return void
	 */
	public function testSavingWorksWithoutOpenRegisterPreferences(): void {
		$service = $this->buildService();
		$saved = $service->updatePreference(personId: 'alice', preferences: ['decisionPublished' => false]);

		self::assertFalse($saved['decisionPublished']);

	}//end testSavingWorksWithoutOpenRegisterPreferences()
}//end class
