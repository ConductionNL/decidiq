<?php

/**
 * Unit tests for drafting minutes from the meeting and sending approved
 * minutes to the members (minutes-draft-and-send, min-01 and min-07).
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
 * @spec openspec/specs/p2-minutes-and-decisions/spec.md#requirement-req-mds-001-draft-minutes-from-the-meeting
 * @spec openspec/specs/p2-minutes-and-decisions/spec.md#requirement-req-mds-003-send-approved-minutes-to-the-members
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service;

use OCA\Decidiq\Notification\Notifier;
use OCA\Decidiq\Service\ALVMinutesService;
use OCA\Decidiq\Service\MinutesContextResolver;
use OCA\Decidiq\Service\MinutesDraftRenderer;
use OCA\Decidiq\Service\MinutesGenerationService;
use OCA\Decidiq\Service\NotificationPreferenceService;
use OCA\Decidiq\Service\ParticipantResolver;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;
use OCP\Notification\IManager as INotificationManager;
use OCP\Notification\INotification;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use Psr\Log\NullLogger;

/**
 * The meeting of 14 October: Anna and Bert present, Cees excused, one decision.
 *
 * @spec openspec/specs/p2-minutes-and-decisions/spec.md#requirement-req-mds-001-draft-minutes-from-the-meeting
 */
class MinutesDraftAndSendTest extends TestCase {

	/**
	 * Notifications the manager received: [user, subject, parameters, objectId].
	 *
	 * @var array<int, array<int, mixed>>
	 */
	public array $sent = [];

	/**
	 * Wrap an array as an ObjectEntity double. Like OpenRegister, getObject()
	 * carries the properties without the id; the id comes from getUuid().
	 *
	 * @param string               $id   The UUID
	 * @param array<string, mixed> $data The properties
	 *
	 * @return ObjectEntity
	 */
	private function entity(string $id, array $data): ObjectEntity {
		$entity = $this->createMock(ObjectEntity::class);
		$entity->method('getObject')->willReturn($data);
		$entity->method('jsonSerialize')->willReturn(array_merge($data, ['id' => $id]));
		$entity->method('getUuid')->willReturn($id);
		return $entity;
	}//end entity()

	/**
	 * The OpenRegister double over the meeting of 14 October.
	 *
	 * @param string $lifecycle The minutes' lifecycle
	 *
	 * @return ObjectServiceInterface
	 */
	private function objectService(string $lifecycle='draft'): ObjectServiceInterface {
		$objects = [
			'min-14' => ['title' => 'Notulen raad 14 oktober', 'lifecycle' => $lifecycle, 'meeting' => 'm-14'],
			'm-14' => ['title' => 'Raad 14 oktober', 'scheduledDate' => '2026-10-14T19:30:00+02:00', 'governanceBody' => 'body-1'],
		];
		$rows = [
			'participant' => [
				['id' => 'P-anna', 'displayName' => 'Anna de Vries', 'nextcloudUserId' => 'anna'],
				['id' => 'P-bert', 'displayName' => 'Bert Bakker', 'nextcloudUserId' => 'bert'],
				['id' => 'P-cees', 'displayName' => 'Cees Jansen', 'nextcloudUserId' => 'cees'],
			],
			'meeting-attendance' => [
				['id' => 'a-1', 'meeting' => 'm-14', 'participant' => 'P-anna', 'status' => 'present'],
				['id' => 'a-2', 'meeting' => 'm-14', 'participant' => 'P-bert', 'status' => 'present'],
				['id' => 'a-3', 'meeting' => 'm-14', 'participant' => 'P-cees', 'status' => 'excused'],
			],
			'decision' => [
				['id' => 'd-1', 'title' => 'Bestemmingsplan Centrum', 'outcome' => 'adopted', 'meeting' => 'm-14'],
			],
		];

		$objectService = $this->createMock(ObjectServiceInterface::class);
		$objectService->method('find')->willReturnCallback(
			fn (int|string $id, mixed ...$rest): ?ObjectEntity => isset($objects[$id]) === true ? $this->entity((string)$id, $objects[$id]) : null
		);
		$objectService->method('findAll')->willReturnCallback(
			function (array $config) use ($rows): array {
				$schema = (string)($config['filters']['schema'] ?? '');
				if ($schema === 'decision' && isset($config['filters']['decisionType']) === true) {
					return [];
				}

				return array_map(fn (array $row): ObjectEntity => $this->entity($row['id'], $row), ($rows[$schema] ?? []));
			}
		);
		return $objectService;
	}//end objectService()

	/**
	 * The renderer lists who was present, excused, absent or represented.
	 *
	 * @return void
	 */
	public function testTheDraftListsTheAttendance(): void {
		$text = (new MinutesDraftRenderer())->render(
			minutes: ['title' => 'Notulen'],
			meeting: ['title' => 'Raad'],
			agendaItems: [],
			motions: [],
			votingRounds: [],
			decisions: [],
			attendance: [
				['name' => 'Anna de Vries', 'status' => 'present'],
				['name' => 'Bert Bakker', 'status' => 'present'],
				['name' => 'Cees Jansen', 'status' => 'excused'],
				['name' => 'Dirk Visser', 'status' => 'proxy'],
			]
		);

		$this->assertStringContainsString('## 2. Aanwezigheid', $text);
		$this->assertStringContainsString('Aanwezig (2): Anna de Vries, Bert Bakker', $text);
		$this->assertStringContainsString('Afgemeld (1): Cees Jansen', $text);
		$this->assertStringContainsString('Vertegenwoordigd met volmacht (1): Dirk Visser', $text);
		$this->assertStringNotContainsString('Afwezig', $text);
	}//end testTheDraftListsTheAttendance()

	/**
	 * Scenario "The secretary starts from a draft": the draft of the minutes
	 * holds the meeting's attendance and its decisions, although the meeting
	 * read through getObject() carries no id.
	 *
	 * @return void
	 */
	public function testTheDraftFromTheMeetingHoldsAttendanceAndDecisions(): void {
		$service = new MinutesGenerationService(
			logger: new NullLogger(),
			renderer: new MinutesDraftRenderer(),
			objectService: $this->objectService(),
		);

		$text = $service->generateDraft('min-14');

		$this->assertStringContainsString('Aanwezig (2): Anna de Vries, Bert Bakker', $text);
		$this->assertStringContainsString('Afgemeld (1): Cees Jansen', $text);
		$this->assertStringContainsString('Bestemmingsplan Centrum', $text);
	}//end testTheDraftFromTheMeetingHoldsAttendanceAndDecisions()

	/**
	 * The real preference service over a recording notification manager.
	 *
	 * @return NotificationPreferenceService
	 */
	private function preferences(): NotificationPreferenceService {
		$store = new class {

			/**
			 * @param string $register Register
			 *
			 * @return static
			 */
			public function setRegister(string $register): static {
				return $this;
			}

			/**
			 * @param string $schema Schema
			 *
			 * @return static
			 */
			public function setSchema(string $schema): static {
				return $this;
			}

			/**
			 * @param array<string, mixed> $config Find-all config
			 *
			 * @return array<int, array<string, mixed>>
			 */
			public function findAll(array $config=[]): array {
				return [];
			}
		};

		$test = $this;
		$manager = $this->createMock(INotificationManager::class);
		$manager->method('createNotification')->willReturnCallback(
			function () use ($test): INotification {
				$state = new \ArrayObject();
				$notification = $test->createMock(INotification::class);
				foreach (['setApp', 'setUser', 'setObject', 'setSubject', 'setDateTime'] as $setter) {
					$notification->method($setter)->willReturnCallback(
						function (...$args) use ($notification, $state, $setter): INotification {
							$state[$setter] = $args;
							return $notification;
						}
					);
				}

				$notification->method('getUser')->willReturnCallback(fn () => ($state['setUser'][0] ?? ''));
				$notification->method('getSubject')->willReturnCallback(fn () => ($state['setSubject'][0] ?? ''));
				$notification->method('getSubjectParameters')->willReturnCallback(fn () => ($state['setSubject'][1] ?? []));
				$notification->method('getObjectId')->willReturnCallback(fn () => ($state['setObject'][1] ?? ''));
				return $notification;
			}
		);
		$manager->method('notify')->willReturnCallback(
			function (INotification $notification) use ($test): void {
				$test->sent[] = [$notification->getUser(), $notification->getSubject(), $notification->getSubjectParameters(), $notification->getObjectId()];
			}
		);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id) use ($store, $manager) {
				return match ($id) {
					'OCA\OpenRegister\Service\ObjectService' => $store,
					INotificationManager::class => $manager,
					default => throw new class('not found: ' . $id) extends \Exception implements NotFoundExceptionInterface {
					},
				};
			}
		);

		return new NotificationPreferenceService(container: $container, logger: new NullLogger());
	}//end preferences()

	/**
	 * The distribution service as production builds it, over the meeting.
	 *
	 * @param string $lifecycle The minutes' lifecycle
	 *
	 * @return ALVMinutesService
	 */
	private function distribution(string $lifecycle): ALVMinutesService {
		$objectService = $this->objectService(lifecycle: $lifecycle);
		$participants = $this->createMock(ParticipantResolver::class);
		$participants->method('resolveMeetingParticipants')->willReturnCallback(
			static fn (string $meetingId): array => $meetingId === 'm-14' ? [
				['id' => 'P-anna', 'nextcloudUserId' => 'anna'],
				['id' => 'P-bert', 'nextcloudUserId' => 'bert'],
				['id' => 'P-oud', 'nextcloudUserId' => 'oud', 'leftAt' => '2026-01-01T00:00:00+00:00'],
			] : []
		);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $params=[]): string => vsprintf($text, $params));
		$factory = $this->createMock(IFactory::class);
		$factory->method('get')->willReturn($l10n);

		return new ALVMinutesService(
			logger: new NullLogger(),
			context: new MinutesContextResolver(objectService: $objectService),
			participantResolver: $participants,
			preferences: $this->preferences(),
			l10nFactory: $factory,
		);
	}//end distribution()

	/**
	 * Scenario "Members receive the minutes": every current member of the
	 * meeting's body gets a notice that links the minutes; a former member
	 * does not.
	 *
	 * @return void
	 */
	public function testApprovedMinutesReachEveryCurrentMember(): void {
		$count = $this->distribution(lifecycle: 'approved')->distribute('min-14');

		$this->assertSame(2, $count);
		$this->assertSame(['anna', 'bert'], array_column($this->sent, 0));
		foreach ($this->sent as [$user, $subject, $params, $objectId]) {
			$this->assertSame('minutes_available', $subject);
			$this->assertSame('min-14', $objectId);
			$this->assertSame('Raad 14 oktober', ($params['meetingTitle'] ?? null));
		}
	}//end testApprovedMinutesReachEveryCurrentMember()

	/**
	 * Draft minutes are not sent.
	 *
	 * @return void
	 */
	public function testDraftMinutesAreNotSent(): void {
		$this->expectExceptionCode(403);
		$this->distribution(lifecycle: 'draft')->distribute('min-14');
	}//end testDraftMinutesAreNotSent()

	/**
	 * The notice renders: its sentence names the meeting and links the minutes.
	 *
	 * @return void
	 */
	public function testTheNoticeNamesTheMeetingAndLinksTheMinutes(): void {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $params=[]): string => vsprintf($text, $params));
		$factory = $this->createMock(IFactory::class);
		$factory->method('get')->willReturn($l10n);
		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('linkToRouteAbsolute')->willReturn('https://nc/apps/decidiq/');
		$urls->method('getAbsoluteURL')->willReturnArgument(0);

		$state = new \ArrayObject();
		$notification = $this->createMock(INotification::class);
		$notification->method('getApp')->willReturn('decidiq');
		$notification->method('getSubject')->willReturn('minutes_available');
		$notification->method('getSubjectParameters')->willReturn(['meetingTitle' => 'Raad 14 oktober']);
		$notification->method('getObjectId')->willReturn('min-14');
		foreach (['setIcon', 'setParsedSubject', 'setParsedMessage', 'setLink'] as $setter) {
			$notification->method($setter)->willReturnCallback(
				function (...$args) use ($notification, $state, $setter): INotification {
					$state[$setter] = $args[0];
					return $notification;
				}
			);
		}

		(new Notifier(l10nFactory: $factory, urlGenerator: $urls))->prepare($notification, 'nl');

		$this->assertSame('The minutes of Raad 14 oktober are available', $state['setParsedSubject']);
		$this->assertStringContainsString('minutes/min-14', (string)$state['setLink']);
	}//end testTheNoticeNamesTheMeetingAndLinksTheMinutes()
}//end class
