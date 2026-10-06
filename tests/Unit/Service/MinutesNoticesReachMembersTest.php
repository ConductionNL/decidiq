<?php

/**
 * Two minutes paths that reached nobody: the approval request on submit
 * (MinutesService asked the container for an OpenRegisterNotificationService
 * nothing registers) and the ALV draft's member count (a
 * `_relations.governance-body` filter that matches no object-API participant).
 * Both now read the body's members through the real ParticipantResolver.
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
 * @spec openspec/specs/p2-minutes-and-decisions/spec.md#requirement-req-ml-003-submit-minutes-for-review
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service;

use OCA\Decidiq\Service\ALVMinutesService;
use OCA\Decidiq\Service\MinutesContextResolver;
use OCA\Decidiq\Service\MinutesService;
use OCA\Decidiq\Service\NotificationPreferenceService;
use OCA\Decidiq\Service\OpenRegisterNotificationPreferenceSync;
use OCA\Decidiq\Service\ParticipantResolver;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\IL10N;
use OCP\L10N\IFactory;
use OCP\Notification\IManager as INotificationManager;
use OCP\Notification\INotification;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use Psr\Log\NullLogger;

/**
 * Body 1 has chair Anna, secretary Bert, member Cees and former chair Oud;
 * body 2 has chair Dirk. Participants carry the body as a flat relation,
 * the shape the OpenRegister object API writes.
 *
 * @covers \OCA\Decidiq\Service\MinutesService
 * @covers \OCA\Decidiq\Service\ALVMinutesService
 * @uses   \OCA\Decidiq\Service\MinutesContextResolver
 * @uses   \OCA\Decidiq\Service\ParticipantResolver
 * @uses   \OCA\Decidiq\Service\NotificationPreferenceService
 */
class MinutesNoticesReachMembersTest extends TestCase {

	/**
	 * Notifications the manager received: [user, subject, parameters].
	 *
	 * @var array<int, array<int, mixed>>
	 */
	public array $sent = [];

	/**
	 * Wrap an array as an ObjectEntity double, like OpenRegister serialises it.
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
	 * The OpenRegister double. findAll() answers per the schema last set and,
	 * like OpenRegister, ignores nothing: a `_relations.governance-body`
	 * filter matches none of these participants.
	 *
	 * @param string $meetingType The meeting's type
	 *
	 * @return ObjectServiceInterface
	 */
	private function objectService(string $meetingType='council'): ObjectServiceInterface {
		$flat = static fn (string $body): array => ['@self' => ['relations' => ['governanceBody' => $body]], 'governanceBody' => $body];
		$objects = [
			'min-14' => ['title' => 'Notulen ALV 14 oktober', 'lifecycle' => 'draft', 'meeting' => 'm-14'],
			'm-14' => array_merge(['title' => 'ALV 14 oktober', 'meetingType' => $meetingType, 'scheduledDate' => '2026-10-14'], $flat('body-1')),
		];
		$participants = [
			['id' => 'P-anna', 'nextcloudUserId' => 'anna', 'role' => 'chair'] + $flat('body-1'),
			['id' => 'P-bert', 'nextcloudUserId' => 'bert', 'role' => 'secretary'] + $flat('body-1'),
			['id' => 'P-cees', 'nextcloudUserId' => 'cees', 'role' => 'member'] + $flat('body-1'),
			['id' => 'P-oud', 'nextcloudUserId' => 'oud', 'role' => 'chair', 'leftAt' => '2026-01-01T00:00:00+00:00'] + $flat('body-1'),
			['id' => 'P-dirk', 'nextcloudUserId' => 'dirk', 'role' => 'chair'] + $flat('body-2'),
		];

		$schema = new \ArrayObject(['current' => '']);
		$objectService = $this->createMock(ObjectServiceInterface::class);
		$objectService->method('setRegister')->willReturnSelf();
		$objectService->method('setSchema')->willReturnCallback(
			function (string $slug) use ($schema, $objectService) {
				$schema['current'] = $slug;
				return $objectService;
			}
		);
		$objectService->method('find')->willReturnCallback(
			fn (int|string $id, mixed ...$rest): ?ObjectEntity => isset($objects[$id]) === true ? $this->entity((string)$id, $objects[$id]) : null
		);
		$objectService->method('findAll')->willReturnCallback(
			function (array $config=[]) use ($schema, $participants): array {
				$filters = ($config['filters'] ?? []);
				if (isset($filters['_relations.governance-body']) === true) {
					return [];
				}

				$slug = (string)($filters['schema'] ?? $schema['current']);
				if ($slug !== 'participant') {
					return [];
				}

				$rows = $participants;
				if (isset($filters['role']) === true) {
					$rows = array_filter($rows, static fn (array $p): bool => in_array($p['role'], (array)$filters['role'], true));
				}

				return array_map(fn (array $row): ObjectEntity => $this->entity($row['id'], $row), array_values($rows));
			}
		);
		return $objectService;
	}//end objectService()

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
				return $notification;
			}
		);
		$manager->method('notify')->willReturnCallback(
			function (INotification $notification) use ($test): void {
				$test->sent[] = [$notification->getUser(), $notification->getSubject(), $notification->getSubjectParameters()];
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

		return new NotificationPreferenceService(
			container: $container,
			logger: new NullLogger(),
			openRegisterSync: new OpenRegisterNotificationPreferenceSync(container: $container, logger: new NullLogger())
		);
	}//end preferences()

	/**
	 * A translator that returns the English text with its parameters.
	 *
	 * @return IL10N
	 */
	private function l10n(): IL10N {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $params=[]): string => vsprintf($text, $params));
		return $l10n;
	}//end l10n()

	/**
	 * Submitting minutes for approval tells the chair and the secretary of
	 * the meeting's own body, and nobody else: not a plain member, not a
	 * former chair, not the chair of another body.
	 *
	 * @return void
	 */
	public function testSubmittedMinutesReachTheChairAndSecretaryOfTheBody(): void {
		$objectService = $this->objectService();
		$service = new MinutesService(
			logger: new NullLogger(),
			context: new MinutesContextResolver(objectService: $objectService),
			participantResolver: new ParticipantResolver(logger: new NullLogger(), objectService: $objectService),
			preferences: $this->preferences(),
			l10n: $this->l10n(),
		);

		$count = $service->notifyApproversOnSubmit('min-14');

		$this->assertSame(2, $count);
		$this->assertSame(['anna', 'bert'], array_column($this->sent, 0));
		foreach ($this->sent as [$user, $subject, $params]) {
			$this->assertSame('decidiq_message', $subject);
			$this->assertSame('/minutes/min-14', ($params['link'] ?? null));
			$this->assertStringContainsString('ALV 14 oktober', (string)($params['title'] ?? ''));
		}
	}//end testSubmittedMinutesReachTheChairAndSecretaryOfTheBody()

	/**
	 * The ALV draft counts the current members of the meeting's body (4 in
	 * body 1, one of whom left: 3), not zero.
	 *
	 * @return void
	 */
	public function testTheAlvDraftCountsTheBodysCurrentMembers(): void {
		$objectService = $this->objectService(meetingType: 'alv');
		$factory = $this->createMock(IFactory::class);
		$factory->method('get')->willReturn($this->l10n());
		$service = new ALVMinutesService(
			logger: new NullLogger(),
			context: new MinutesContextResolver(objectService: $objectService),
			participantResolver: new ParticipantResolver(logger: new NullLogger(), objectService: $objectService),
			preferences: $this->preferences(),
			l10nFactory: $factory,
		);

		$draft = $service->generateALVDraft('min-14');

		$this->assertSame(3, $draft['recipientCount']);
	}//end testTheAlvDraftCountsTheBodysCurrentMembers()
}//end class
