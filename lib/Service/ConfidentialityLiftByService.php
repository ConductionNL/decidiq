<?php

/**
 * Puts the lifting of a confidentiality restriction on the agenda before its end date.
 *
 * @category Service
 * @package  OCA\Decidiq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/confidentiality-term-and-lapse/specs/embargo-geheimhouding/spec.md#requirement-req-emb-022-the-planned-end-date-puts-the-lifting-on-the-agenda
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Decidiq\Service;

use DateTimeImmutable;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The end date prepares the lifting; it never decides it (design D3).
 *
 * A restriction still in force (imposed or ratified) whose `liftBy` falls
 * within the lead time and that has no lifting agenda item yet gets one: a
 * proposal "Opheffen geheimhouding <subject>" on the next scheduled meeting of
 * the body that ratified it, or of the body that imposed it when it needs no
 * ratification. The restriction then reads `liftStatus: preparing`; once
 * `liftBy` has passed it reads `overdue`. Its lifecycle never changes here.
 *
 * @spec openspec/changes/confidentiality-term-and-lapse/tasks.md#task-3
 */
class ConfidentialityLiftByService {
	/**
	 * The app config key of the lead time, in days.
	 *
	 * @var string
	 */
	public const LEAD_DAYS_KEY = 'confidentiality_lift_lead_days';

	/**
	 * The lead time when none is set.
	 *
	 * @var int
	 */
	public const LEAD_DAYS_DEFAULT = 30;

	/**
	 * The states of a restriction still in force.
	 *
	 * @var array<int, string>
	 */
	public const ACTIVE_STATES = ['imposed', 'ratified'];

	/**
	 * A lifting agenda item is placed.
	 *
	 * @var string
	 */
	public const STATUS_PREPARING = 'preparing';

	/**
	 * The end date has passed without a lifting decision.
	 *
	 * @var string
	 */
	public const STATUS_OVERDUE = 'overdue';

	/**
	 * The meeting states a new agenda item may go on.
	 *
	 * @var array<int, string>
	 */
	private const OPEN_MEETING_STATES = ['draft', 'scheduled'];

	/**
	 * The register.
	 *
	 * @var string
	 */
	private const REGISTER = 'decidiq';

	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface $objectService The OpenRegister object service.
	 * @param IAppConfig             $appConfig     The lead time setting.
	 * @param LoggerInterface        $logger        Logs what could not be placed.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * One run: place the lifting items that are due and mark the overdue restrictions.
	 *
	 * @param DateTimeImmutable $today Today.
	 *
	 * @return array{placed: int, overdue: int} What changed.
	 *
	 * @spec openspec/changes/confidentiality-term-and-lapse/tasks.md#task-3
	 */
	public function sweep(DateTimeImmutable $today): array {
		$day    = $today->format('Y-m-d');
		$until  = $today->modify('+' . $this->leadDays() . ' days')->format('Y-m-d');
		$result = ['placed' => 0, 'overdue' => 0];
		foreach ($this->rows(filters: ['schema' => 'confidentiality-restriction']) as $restriction) {
			$liftBy = substr((string)($restriction['liftBy'] ?? ''), 0, 10);
			if ($liftBy === '' || in_array(($restriction['lifecycle'] ?? ''), self::ACTIVE_STATES, true) === false) {
				continue;
			}

			if ($liftBy < $day) {
				$result['overdue'] += (int)$this->mark(restriction: $restriction, status: self::STATUS_OVERDUE);
				continue;
			}

			if ($liftBy <= $until && trim((string)($restriction['liftingAgendaItem'] ?? '')) === '') {
				$result['placed'] += (int)$this->place(restriction: $restriction, window: ['from' => $day, 'until' => $liftBy]);
			}
		}

		return $result;
	}//end sweep()

	/**
	 * The lead time in days: the admin setting, else 30.
	 *
	 * @return int
	 *
	 * @spec openspec/changes/confidentiality-term-and-lapse/tasks.md#task-3
	 */
	public function leadDays(): int {
		$days = $this->appConfig->getValueInt('decidiq', self::LEAD_DAYS_KEY, self::LEAD_DAYS_DEFAULT);
		if ($days < 0) {
			return self::LEAD_DAYS_DEFAULT;
		}

		return $days;
	}//end leadDays()

	/**
	 * Place the lifting proposal on the body's next meeting and link it.
	 *
	 * @param array<string, mixed>           $restriction The restriction.
	 * @param array{from:string,until:string} $window     Today and its end date, `Y-m-d`.
	 *
	 * @return bool True when an item was placed.
	 */
	private function place(array $restriction, array $window): bool {
		$liftBy = $window['until'];
		$id      = $this->idOf(row: $restriction);
		$body    = $this->bodyOf(restriction: $restriction);
		$meeting = $this->nextMeeting(body: $body, window: $window);
		if ($meeting === null) {
			$this->logger->info(
				'Decidiq: no meeting to put the lifting of a confidentiality on yet',
				['restriction' => $id, 'body' => $body, 'liftBy' => $liftBy]
			);
			return false;
		}

		$meetingId = $this->idOf(row: $meeting);
		try {
			$item = $this->objectService->saveObject(
				object: [
					'meeting'     => $meetingId,
					'title'       => 'Opheffen geheimhouding ' . $this->subjectOf(restriction: $restriction),
					'description' => trim((string)($restriction['liftingConditions'] ?? '')),
					'itemType'    => 'decision',
					'orderNumber' => $this->nextOrderNumber(meetingId: $meetingId),
				],
				register: self::REGISTER,
				schema: 'agenda-item'
			);
			$itemRow = $this->asRow(row: $item);
			$this->objectService->saveObject(
				object: array_merge($restriction, ['liftingAgendaItem' => $this->idOf(row: $itemRow), 'liftStatus' => self::STATUS_PREPARING]),
				register: self::REGISTER,
				schema: 'confidentiality-restriction',
				uuid: $id
			);
		} catch (Throwable $e) {
			$this->logger->error(
				'Decidiq: the lifting of a confidentiality could not be put on the agenda',
				['restriction' => $id, 'meeting' => $meetingId, 'error' => $e->getMessage()]
			);
			return false;
		}

		return true;
	}//end place()

	/**
	 * Write a lift status on a restriction when it is not already that.
	 *
	 * @param array<string, mixed> $restriction The restriction.
	 * @param string               $status      The status.
	 *
	 * @return bool True when it was written.
	 */
	private function mark(array $restriction, string $status): bool {
		if (($restriction['liftStatus'] ?? null) === $status) {
			return false;
		}

		try {
			$this->objectService->saveObject(
				object: array_merge($restriction, ['liftStatus' => $status]),
				register: self::REGISTER,
				schema: 'confidentiality-restriction',
				uuid: $this->idOf(row: $restriction)
			);
		} catch (Throwable $e) {
			$this->logger->error(
				'Decidiq: a confidentiality past its end date could not be marked overdue',
				['restriction' => $this->idOf(row: $restriction), 'error' => $e->getMessage()]
			);
			return false;
		}

		return true;
	}//end mark()

	/**
	 * The body that decides the lifting: the body whose meeting holds the
	 * ratification item, else the imposing body.
	 *
	 * @param array<string, mixed> $restriction The restriction.
	 *
	 * @return string The body's id, or ''.
	 */
	private function bodyOf(array $restriction): string {
		$item = $this->one(schema: 'agenda-item', id: (string)($restriction['ratificationAgendaItem'] ?? ''));
		if ($item !== null) {
			$meeting = $this->one(schema: 'meeting', id: (string)($item['meeting'] ?? ''));
			$body    = trim((string)($meeting['governanceBody'] ?? ''));
			if ($body !== '') {
				return $body;
			}
		}

		return trim((string)($restriction['imposedByBody'] ?? ''));
	}//end bodyOf()

	/**
	 * The body's first meeting from today up to the end date that still takes agenda items.
	 *
	 * @param string                          $body   The body.
	 * @param array{from:string,until:string} $window Today and the end date, `Y-m-d`.
	 *
	 * @return array<string, mixed>|null
	 */
	private function nextMeeting(string $body, array $window): ?array {
		if ($body === '') {
			return null;
		}

		$next = null;
		foreach ($this->rows(filters: ['schema' => 'meeting', 'governanceBody' => $body]) as $meeting) {
			$date = substr((string)($meeting['scheduledDate'] ?? ''), 0, 10);
			if (($meeting['governanceBody'] ?? null) !== $body
				|| in_array(($meeting['lifecycle'] ?? 'draft'), self::OPEN_MEETING_STATES, true) === false
				|| $date === '' || $date < $window['from'] || $date > $window['until']
			) {
				continue;
			}

			if ($next === null || $date < substr((string)$next['scheduledDate'], 0, 10)) {
				$next = $meeting;
			}
		}

		return $next;
	}//end nextMeeting()

	/**
	 * The order number after the meeting's last item.
	 *
	 * @param string $meetingId The meeting.
	 *
	 * @return int
	 */
	private function nextOrderNumber(string $meetingId): int {
		$last = 0;
		foreach ($this->rows(filters: ['schema' => 'agenda-item', 'meeting' => $meetingId]) as $item) {
			$last = max($last, (int)($item['orderNumber'] ?? 0));
		}

		return ($last + 1);
	}//end nextOrderNumber()

	/**
	 * What the restriction covers, in words: the document's name without its
	 * extension, the decision's or agenda item's title, else "stukken".
	 *
	 * @param array<string, mixed> $restriction The restriction.
	 *
	 * @return string
	 */
	private function subjectOf(array $restriction): string {
		foreach (['targetDocument' => 'digital-document', 'targetDecision' => 'Decision', 'targetAgendaItem' => 'agenda-item'] as $field => $schema) {
			$target = $this->one(schema: $schema, id: (string)($restriction[$field] ?? ''));
			$name   = trim((string)($target['title'] ?? ($target['name'] ?? '')));
			if ($name !== '') {
				return (string)preg_replace('/\.[A-Za-z0-9]{2,4}$/', '', $name);
			}
		}

		return 'stukken';
	}//end subjectOf()

	/**
	 * One object by id, null when it is absent or cannot be read.
	 *
	 * @param string $schema The schema.
	 * @param string $id     The id.
	 *
	 * @return array<string, mixed>|null
	 */
	private function one(string $schema, string $id): ?array {
		if (trim($id) === '') {
			return null;
		}

		try {
			$found = $this->objectService->find(id: $id, register: self::REGISTER, schema: $schema, _rbac: false, _multitenancy: false);
		} catch (Throwable $e) {
			return null;
		}

		if ($found === null) {
			return null;
		}

		return $this->asRow(row: $found);
	}//end one()

	/**
	 * The objects matching a filter, as arrays; none when they cannot be read.
	 *
	 * @param array<string, string> $filters The filters, schema included.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function rows(array $filters): array {
		try {
			$found = $this->objectService->findAll(
				config: ['filters' => ['register' => self::REGISTER] + $filters],
				_rbac: false,
				_multitenancy: false
			);
		} catch (Throwable $e) {
			$this->logger->warning('Decidiq: objects could not be read for the lifting sweep', ['filters' => $filters, 'error' => $e->getMessage()]);
			return [];
		}

		$rows = [];
		foreach (($found['results'] ?? $found) as $row) {
			$rows[] = $this->asRow(row: $row);
		}

		return $rows;
	}//end rows()

	/**
	 * An object or entity as an array.
	 *
	 * @param mixed $row The object.
	 *
	 * @return array<string, mixed>
	 */
	private function asRow(mixed $row): array {
		if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
			$row = $row->jsonSerialize();
		}

		return (array)$row;
	}//end asRow()

	/**
	 * An object's id.
	 *
	 * @param array<string, mixed> $row The object.
	 *
	 * @return string
	 */
	private function idOf(array $row): string {
		return (string)($row['id'] ?? ($row['uuid'] ?? ($row['@self']['id'] ?? '')));
	}//end idOf()
}//end class
