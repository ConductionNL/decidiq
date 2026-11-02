<?php

/**
 * Golden test for the agenda notices and the agenda item queries of AgendaService.
 *
 * Runs publishAgenda, reviseAgenda, notifyAgendaChanged, reorderItems and
 * processHamerstukken over meeting, item and participant shapes, and records in
 * order every object-service call, every notice dispatched, every translated
 * text, every publication event and every log line, plus what each call
 * returns or throws. The expected values live in
 * tests/fixtures/agenda-notices-golden.json; run with
 * RECORD_AGENDA_NOTICES_GOLDEN=1 to write them, then run again without it.
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
 * @spec openspec/specs/decidesk-notifications/spec.md#requirement-req-acn-003-agenda-notices-follow-the-members-delivery-choice
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service;

use OCA\Decidiq\Service\AgendaService;
use OCA\Decidiq\Service\NotificationPreferenceService;
use OCA\Decidiq\Service\ParticipantResolver;
use OCA\Decidiq\Service\PublicationEventRecorder;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\CalendarEventService;
use OCP\IL10N;
use OCP\L10N\IFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use ReflectionMethod;
use RuntimeException;
use Stringable;
use Throwable;

/**
 * Golden test for the agenda notices of AgendaService.
 *
 * @covers \OCA\Decidiq\Service\AgendaService
 * @uses   \OCA\Decidiq\Service\AgendaInvitation
 * @uses   \OCA\Decidiq\Service\AgendaNotices
 * @uses   \OCA\Decidiq\Exception\NotFoundException
 */
class AgendaNoticesGoldenTest extends TestCase {

	private const GOLDEN = __DIR__ . '/../../fixtures/agenda-notices-golden.json';

	/**
	 * Every recorded call of the current case.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $log = [];

	/**
	 * Every case answers as recorded.
	 *
	 * @return void
	 */
	public function testEveryNoticeMatchesTheRecording(): void {
		$calls = [
			'publish'     => fn (AgendaService $s, string $id) => $s->publishAgenda($id),
			'revise'      => fn (AgendaService $s, string $id) => $s->reviseAgenda($id),
			'changed'     => fn (AgendaService $s, string $id) => $s->notifyAgendaChanged($id),
			'reorder'     => fn (AgendaService $s, string $id) => $s->reorderItems($id, ['item-2', 'foreign', 'item-1', 'uuid-4']),
			'formalities' => fn (AgendaService $s, string $id) => $s->processHamerstukken($id),
		];

		$actual = [];
		foreach (array_keys($this->meetings()) as $id) {
			foreach ($calls as $name => $call) {
				$this->log = [];
				try {
					$result = ['returns' => $call($this->service(), $id)];
				} catch (Throwable $e) {
					$result = ['throws' => get_class($e), 'message' => $e->getMessage()];
				}

				$actual[$id][$name] = $result + ['calls' => $this->log];
			}
		}

		$json = json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
		$json = preg_replace('/\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(Z|[+-]\d{2}:\d{2})/', '<date>', $json);
		$json = preg_replace('/\d{8}T\d{6}Z/', '<stamp>', $json);
		if (getenv('RECORD_AGENDA_NOTICES_GOLDEN') === '1') {
			file_put_contents(self::GOLDEN, $json);
		}

		self::assertSame(file_get_contents(self::GOLDEN), $json);
	}//end testEveryNoticeMatchesTheRecording()

	/**
	 * Meeting shapes by id; null is a meeting that does not exist.
	 *
	 * @return array<string, array<string, mixed>|null>
	 */
	private function meetings(): array {
		$council = [
			'title' => 'Raad',
			'scheduledDate' => '2026-10-08T19:30:00+02:00',
			'endDate' => '2026-10-08T22:30:00+02:00',
			'location' => 'Raadzaal',
		];
		return [
			'gone'       => null,
			'no-items'   => $council,
			'first'      => $council,
			'untitled'   => [
				'agendaPublishedAt' => '2026-10-01T10:00:00+02:00',
				'agendaVersion' => 2,
				'agendaVersions' => [['version' => 1, 'items' => []], ['version' => 2, 'items' => [['id' => 'item-1']]]],
				'convocationSentAt' => '2026-10-01T10:00:00+02:00',
				'agendaNoticeSentAt' => ['anna' => date(DATE_ATOM, (time() - 10)), 'cees' => '2020-01-01T00:00:00+00:00'],
			],
			'revising'   => $council + ['agendaPublishedAt' => '2026-10-01T10:00:00+02:00', 'agendaVersion' => 1, 'agendaUnderRevision' => true],
			'bad-history' => $council + ['agendaPublishedAt' => '2026-10-01T10:00:00+02:00', 'agendaVersion' => '3', 'agendaVersions' => 'x', 'agendaNoticeSentAt' => 'x'],
		];
	}//end meetings()

	/**
	 * The agenda items of a meeting: arrays and entities, with every id shape
	 * and every formality marking.
	 *
	 * @param string $meetingId The meeting
	 *
	 * @return array<int, mixed>
	 */
	private function items(string $meetingId): array {
		if ($meetingId === 'no-items' || $meetingId === 'gone') {
			return [];
		}

		return [
			['id' => 'item-1', 'title' => 'Opening', 'orderNumber' => 1],
			$this->entity(['@self' => ['id' => 'item-2', 'tags' => ['hamerstuk']], 'title' => 'Notulen', 'orderNumber' => 2]),
			['id' => 'item-3', 'title' => 'Begroting', 'orderNumber' => 3, 'isFormality' => true, 'formalityOutcome' => 'adopted-without-debate'],
			['uuid' => 'uuid-4', 'title' => 'Ingekomen stukken', 'isFormality' => true],
			['title' => 'No id', 'isFormality' => true],
			['id' => 'item-6', 'tags' => ['other'], 'orderNumber' => 6],
		];
	}//end items()

	/**
	 * Build the service over recording doubles.
	 *
	 * @return AgendaService
	 */
	private function service(): AgendaService {
		$meetings = $this->meetings();

		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->method('find')->willReturnCallback(
			function (mixed ...$arguments) use ($meetings): mixed {
				$arguments = $this->named(ObjectServiceInterface::class, 'find', $arguments);
				$this->log[] = ['find' => $arguments];
				$row = ($meetings[$arguments['id']] ?? null);
				return $row === null ? null : $this->entity($row);
			}
		);
		$objects->method('findAll')->willReturnCallback(
			function (array $config): array {
				$this->log[] = ['findAll' => $config];
				return $this->items(meetingId: (string)($config['filters']['_relations.meeting'] ?? ''));
			}
		);
		$objects->method('saveObject')->willReturnCallback(
			function (mixed ...$arguments): ObjectEntity {
				$arguments = $this->named(ObjectServiceInterface::class, 'saveObject', $arguments);
				$this->log[] = ['saveObject' => $arguments];
				return $this->entity($arguments['object']);
			}
		);
		$objects->method('patchObject')->willReturnCallback(
			function (mixed ...$arguments): ObjectEntity {
				$arguments = $this->named(ObjectServiceInterface::class, 'patchObject', $arguments);
				$this->log[] = ['patchObject' => $arguments];
				return $this->entity($arguments['data']);
			}
		);

		$resolver = $this->createMock(ParticipantResolver::class);
		$resolver->method('resolveMeetingParticipants')->willReturnCallback(
			function (string $meetingId): array {
				$this->log[] = ['participants' => $meetingId];
				return [
					['nextcloudUserId' => 'anna', 'leftAt' => null],
					$this->entity(['nextcloudUserId' => 'bram', 'leftAt' => '2026-09-01T00:00:00+00:00']),
					['owner' => 'cees'],
					['nextcloudUserId' => 'anna'],
					['nextcloudUserId' => ''],
					['nextcloudUserId' => 'dora'],
				];
			}
		);

		$preferences = $this->createMock(NotificationPreferenceService::class);
		$preferences->method('dispatch')->willReturnCallback(
			function (mixed ...$arguments): int {
				$arguments = $this->named(NotificationPreferenceService::class, 'dispatch', $arguments);
				$this->log[] = ['dispatch' => $arguments];
				if (($arguments['personId'] ?? '') === 'dora') {
					throw new RuntimeException('mail down');
				}

				return 1;
			}
		);

		$recorder = $this->createMock(PublicationEventRecorder::class);
		foreach (['agendaPublished', 'agendaChanged'] as $method) {
			$recorder->method($method)->willReturnCallback(
				function (mixed ...$arguments) use ($method): void {
					$this->log[] = [$method => $this->named(PublicationEventRecorder::class, $method, $arguments)];
				}
			);
		}

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(
			function (string $text, array|string $params = []): string {
				$this->log[] = ['t' => [$text, $params]];
				return '[' . vsprintf($text, (array)$params) . ']';
			}
		);
		$factory = $this->createMock(IFactory::class);
		$factory->method('get')->willReturnCallback(
			function (string $app) use ($l10n): IL10N {
				$this->log[] = ['l10n' => $app];
				return $l10n;
			}
		);

		return new AgendaService(
			objectService: $objects,
			calendarEventService: $this->createMock(CalendarEventService::class),
			preferences: $preferences,
			logger: $this->logger(),
			participantResolver: $resolver,
			l10nFactory: $factory,
			eventRecorder: $recorder,
		);
	}//end service()

	/**
	 * Name the positional arguments of a call, leaving out those passed at
	 * their default, so the recording does not follow the loaded signature.
	 *
	 * @param class-string      $class     The called class
	 * @param string            $method    The called method
	 * @param array<int, mixed> $arguments The positional arguments
	 *
	 * @return array<string, mixed>
	 */
	private function named(string $class, string $method, array $arguments): array {
		$named = [];
		foreach ((new ReflectionMethod($class, $method))->getParameters() as $i => $parameter) {
			if (array_key_exists($i, $arguments) === false) {
				break;
			}

			if ($parameter->isDefaultValueAvailable() === true && $parameter->getDefaultValue() === $arguments[$i]) {
				continue;
			}

			$named[$parameter->getName()] = $arguments[$i];
		}

		return $named;
	}//end named()

	/**
	 * A logger that records into the call log.
	 *
	 * @return AbstractLogger
	 */
	private function logger(): AbstractLogger {
		$log = &$this->log;
		return new class($log) extends AbstractLogger {
			/**
			 * The shared call log.
			 *
			 * @var array<int, array<string, mixed>>
			 */
			private array $log;

			/**
			 * Record into the given log.
			 *
			 * @param array<int, array<string, mixed>> $log The call log
			 */
			public function __construct(array &$log) {
				$this->log = &$log;
			}

			/**
			 * Record one log line.
			 *
			 * @param mixed             $level   The level
			 * @param string|Stringable $message The message
			 * @param array<mixed>      $context The context
			 *
			 * @return void
			 */
			public function log($level, string|Stringable $message, array $context = []): void {
				$this->log[] = ['log' => [$level, (string)$message, $context]];
			}
		};
	}//end logger()

	/**
	 * An entity double serialising to the row.
	 *
	 * @param array<string, mixed> $row The row
	 *
	 * @return ObjectEntity
	 */
	private function entity(array $row): ObjectEntity {
		$entity = $this->createMock(ObjectEntity::class);
		$entity->method('getObject')->willReturn($row);
		$entity->method('jsonSerialize')->willReturn($row);
		return $entity;
	}//end entity()
}//end class
