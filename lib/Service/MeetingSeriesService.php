<?php

/**
 * Decidesk Meeting Series Service
 *
 * Expands a recurrence pattern on a template meeting into individual
 * Meeting instances sharing a series identifier (meeting-series
 * REQ-MSR-001, meeting-management "Schedule a recurring monthly meeting").
 *
 * @category Service
 * @package  OCA\Decidesk\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/meeting-management/spec.md
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2
declare(strict_types=1);

namespace OCA\Decidesk\Service;

use DateInterval;
use DateTimeImmutable;
use Generator;
use InvalidArgumentException;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Owns recurring-meeting series generation.
 *
 * The series *semantics* (series slug on each instance, pattern JSON on the
 * template, 52-instance cap, exceptions) are defined by the meeting-series
 * capability spec (REQ-MSR-001..005); the list filter/badge UI already
 * consumes the `series` field. This service supplies the missing generation
 * engine: pure date expansion + instance creation through OpenRegister.
 *
 * @spec openspec/specs/meeting-management/spec.md
 */
class MeetingSeriesService {

	/**
	 * Hard cap on the number of generated instances (REQ-MSR-001-S3).
	 *
	 * @var int
	 */
	public const MAX_INSTANCES = 52;

	/**
	 * Allowed recurrence frequencies.
	 *
	 * @var string[]
	 */
	public const FREQUENCIES = ['daily', 'weekly', 'monthly'];

	/**
	 * Descriptive Meeting fields copied from the template onto every
	 * generated instance (each instance stays independently editable —
	 * REQ-MSR-002 is untouched because instances are plain OR objects).
	 *
	 * @var string[]
	 */
	private const COPIED_FIELDS = [
		'title',
		'meetingType',
		'meetingMode',
		'location',
		'virtualLocation',
		'eventAttendanceMode',
		'governanceBody',
		'quorumRequired',
		'chair',
		'isPublic',
	];

	/**
	 * Constructor for MeetingSeriesService.
	 *
	 * @param ContainerInterface $container The DI container (lazy-loads OpenRegister's ObjectService)
	 * @param LoggerInterface $logger The logger
	 * @param AuditLogService $auditLogService Audit log dependency for series-generation events
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
		private readonly AuditLogService $auditLogService,
	) {
	}//end __construct()

	/**
	 * Pure date expansion of a recurrence pattern.
	 *
	 * Expands `{frequency, interval, until, exceptions}` from the template's
	 * start datetime into ISO-8601 datetimes *including* the template date,
	 * preserving the template's time-of-day and timezone offset. Months that
	 * lack the template's day-of-month (e.g. the 31st) are skipped rather
	 * than rolled over. Capped at MAX_INSTANCES with a logged warning.
	 *
	 * @param string $startDate Template start datetime (ISO-8601)
	 * @param array<string, mixed> $pattern Recurrence pattern (frequency, interval, until, exceptions)
	 *
	 * @throws InvalidArgumentException When the pattern or start date is invalid
	 *
	 * @spec openspec/specs/meeting-management/spec.md
	 *
	 * @return array{dates: string[], truncated: bool} Expanded datetimes + cap flag
	 */
	public function expandPattern(string $startDate, array $pattern): array {
		$frequency = (string)($pattern['frequency'] ?? '');
		$interval = (int)($pattern['interval'] ?? 1);
		$untilRaw = (string)($pattern['until'] ?? '');
		$this->assertPatternValid(frequency: $frequency, interval: $interval, untilRaw: $untilRaw);

		$start = $this->parseStart(startDate: $startDate);
		$until = $this->parseUntil(untilRaw: $untilRaw, start: $start);

		$occurrences = $this->steppedOccurrences(
			start: $start,
			until: $until,
			stepDays: $this->stepDays(frequency: $frequency, interval: $interval)
		);
		if ($frequency === 'monthly') {
			$occurrences = $this->monthlyOccurrences(start: $start, until: $until, interval: $interval);
		}

		$result = $this->capOccurrences(
			occurrences: $occurrences,
			exceptions: $this->indexExceptions(pattern: $pattern)
		);
		if ($result['truncated'] === true) {
			$this->logger->warning(
				'Decidesk: series expansion truncated at ' . self::MAX_INSTANCES . ' instances',
				['startDate' => $startDate, 'until' => $untilRaw]
			);
		}

		return $result;
	}//end expandPattern()

	/**
	 * Validate the frequency/interval/until triple of a recurrence pattern.
	 *
	 * @param string $frequency Requested recurrence frequency
	 * @param int $interval Requested recurrence interval
	 * @param string $untilRaw Raw inclusive end date (may be empty)
	 *
	 * @throws InvalidArgumentException When any part of the triple is invalid
	 *
	 * @spec openspec/specs/meeting-management/spec.md
	 *
	 * @return void
	 */
	private function assertPatternValid(string $frequency, int $interval, string $untilRaw): void {
		if (in_array($frequency, self::FREQUENCIES, true) === false) {
			throw new InvalidArgumentException(
				'frequency must be one of: ' . implode(', ', self::FREQUENCIES) . '.'
			);
		}

		if ($interval < 1) {
			throw new InvalidArgumentException('interval must be >= 1.');
		}

		if ($untilRaw === '') {
			throw new InvalidArgumentException('until is required.');
		}

	}//end assertPatternValid()

	/**
	 * Parse the template start datetime.
	 *
	 * @param string $startDate Template start datetime (ISO-8601)
	 *
	 * @throws InvalidArgumentException When the start date is unparsable
	 *
	 * @spec openspec/specs/meeting-management/spec.md
	 *
	 * @return DateTimeImmutable Parsed start datetime
	 */
	private function parseStart(string $startDate): DateTimeImmutable {
		try {
			return new DateTimeImmutable($startDate);
		} catch (\Throwable) {
			throw new InvalidArgumentException('Invalid start date: ' . $startDate);
		}

	}//end parseStart()

	/**
	 * Parse the inclusive `until` bound in the template's timezone.
	 *
	 * @param string $untilRaw Raw inclusive end date (Y-m-d)
	 * @param DateTimeImmutable $start Parsed template start (supplies the timezone)
	 *
	 * @throws InvalidArgumentException When the until date is unparsable
	 *
	 * @spec openspec/specs/meeting-management/spec.md
	 *
	 * @return DateTimeImmutable End of the `until` day in the template's timezone
	 */
	private function parseUntil(string $untilRaw, DateTimeImmutable $start): DateTimeImmutable {
		try {
			// `until` is inclusive and compared on the date part in the template's timezone.
			return new DateTimeImmutable($untilRaw . ' 23:59:59', $start->getTimezone());
		} catch (\Throwable) {
			throw new InvalidArgumentException('Invalid until date: ' . $untilRaw);
		}

	}//end parseUntil()

	/**
	 * Index the pattern's exception dates by their `Y-m-d` day key.
	 *
	 * @param array<string, mixed> $pattern Recurrence pattern
	 *
	 * @spec openspec/specs/meeting-management/spec.md
	 *
	 * @return array<string, bool> Map of excluded day => true
	 */
	private function indexExceptions(array $pattern): array {
		$exceptions = [];
		foreach ((array)($pattern['exceptions'] ?? []) as $exception) {
			$exceptions[substr((string)$exception, 0, 10)] = true;
		}

		return $exceptions;
	}//end indexExceptions()

	/**
	 * Translate a daily/weekly frequency + interval into a day step.
	 *
	 * @param string $frequency Recurrence frequency (`daily` or `weekly`)
	 * @param int $interval Recurrence interval
	 *
	 * @spec openspec/specs/meeting-management/spec.md
	 *
	 * @return int Number of days between two consecutive occurrences
	 */
	private function stepDays(string $frequency, int $interval): int {
		if ($frequency === 'weekly') {
			return ($interval * 7);
		}

		return $interval;
	}//end stepDays()

	/**
	 * Yield same-day-of-month occurrences up to and including `until`.
	 *
	 * Months lacking the template's day-of-month (e.g. the 31st) are skipped
	 * rather than rolled over to the next month.
	 *
	 * @param DateTimeImmutable $start Template start datetime
	 * @param DateTimeImmutable $until Inclusive end bound
	 * @param int $interval Months between two consecutive occurrences
	 *
	 * @spec openspec/specs/meeting-management/spec.md
	 *
	 * @return Generator<int, DateTimeImmutable> Occurrence datetimes
	 */
	private function monthlyOccurrences(DateTimeImmutable $start, DateTimeImmutable $until, int $interval): Generator {
		$dayOfMonth = (int)$start->format('j');

		for ($offset = 0; true; $offset += $interval) {
			$firstOfMonth = $start->modify('first day of +' . $offset . ' month');
			if ($firstOfMonth > $until) {
				return;
			}

			if ($dayOfMonth > (int)$firstOfMonth->format('t')) {
				continue;
			}

			$occurrence = $firstOfMonth->setDate(
				(int)$firstOfMonth->format('Y'),
				(int)$firstOfMonth->format('n'),
				$dayOfMonth
			);
			if ($occurrence > $until) {
				return;
			}

			yield $occurrence;
		}//end for

	}//end monthlyOccurrences()

	/**
	 * Yield fixed-day-step occurrences up to and including `until`.
	 *
	 * @param DateTimeImmutable $start Template start datetime
	 * @param DateTimeImmutable $until Inclusive end bound
	 * @param int $stepDays Days between two consecutive occurrences
	 *
	 * @spec openspec/specs/meeting-management/spec.md
	 *
	 * @return Generator<int, DateTimeImmutable> Occurrence datetimes
	 */
	private function steppedOccurrences(DateTimeImmutable $start, DateTimeImmutable $until, int $stepDays): Generator {
		for ($step = 0; true; $step++) {
			$occurrence = $start->add(new DateInterval('P' . ($step * $stepDays) . 'D'));
			if ($occurrence > $until) {
				return;
			}

			yield $occurrence;
		}//end for

	}//end steppedOccurrences()

	/**
	 * Drop exception days and cap the occurrence stream at MAX_INSTANCES.
	 *
	 * @param Generator<int, DateTimeImmutable> $occurrences Lazy occurrence stream
	 * @param array<string, bool> $exceptions Map of excluded day => true
	 *
	 * @spec openspec/specs/meeting-management/spec.md
	 *
	 * @return array{dates: string[], truncated: bool} Expanded datetimes + cap flag
	 */
	private function capOccurrences(Generator $occurrences, array $exceptions): array {
		$dates = [];
		$kept = 0;
		$truncated = false;

		foreach ($occurrences as $occurrence) {
			if (isset($exceptions[$occurrence->format('Y-m-d')]) === true) {
				continue;
			}

			if ($kept >= self::MAX_INSTANCES) {
				$truncated = true;
				break;
			}

			$dates[] = $occurrence->format('Y-m-d\TH:i:sP');
			$kept++;
		}

		return [
			'dates' => $dates,
			'truncated' => $truncated,
		];

	}//end capOccurrences()

	/**
	 * Generate the meeting instances for a recurrence pattern.
	 *
	 * Loads the template via ObjectService (OpenRegister RBAC: callers
	 * without read access get null → "not found"), derives or reuses the
	 * series slug, stamps `series` + `seriesPattern` on the template, and
	 * creates one independently-editable Meeting per expanded date.
	 *
	 * @param string $meetingId UUID of the template meeting
	 * @param array<string, mixed> $pattern Recurrence pattern (frequency, interval, until, exceptions)
	 * @param string $actor Acting user UID (for the audit log)
	 *
	 * @spec openspec/specs/meeting-management/spec.md
	 *
	 * @return array{success: bool, series: string|null, instances: array<int, array<string, mixed>>, truncated: bool, message: string}
	 */
	public function generateSeries(string $meetingId, array $pattern, string $actor): array {
		try {
			$objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
			$entity = $objectService->find(id: $meetingId, register: 'decidesk', schema: 'meeting');
		} catch (\Throwable $e) {
			$this->logger->error(
				'Decidesk: MeetingSeriesService::generateSeries lookup failed',
				['meetingId' => $meetingId, 'exception' => $e->getMessage()]
			);
			return $this->failure(message: 'Failed to load template meeting.');
		}

		if ($entity === null) {
			return $this->failure(message: 'Meeting not found.');
		}

		$template = (array)$entity->jsonSerialize();
		if (method_exists($entity, 'getObject') === true) {
			$template = $entity->getObject();
		}

		$scheduledDate = (string)($template['scheduledDate'] ?? '');
		if ($scheduledDate === '') {
			return $this->failure(message: 'Template meeting has no scheduledDate.');
		}

		try {
			$expansion = $this->expandPattern(startDate: $scheduledDate, pattern: $pattern);
		} catch (InvalidArgumentException $e) {
			return $this->failure(message: $e->getMessage());
		}

		$series = (string)($template['series'] ?? '');
		if ($series === '') {
			$series = $this->deriveSeriesSlug(template: $template, scheduledDate: $scheduledDate);
		}

		try {
			// Stamp series + pattern on the template (REQ-MSR-001: pattern
			// is stored as JSON on the first/template meeting).
			$objectService->saveObject(
				object: array_merge($template, ['series' => $series, 'seriesPattern' => $pattern]),
				register: 'decidesk',
				schema: 'meeting',
				uuid: $meetingId
			);

			$instances = $this->createInstances(
				objectService: $objectService,
				template: $template,
				series: $series,
				dates: $expansion['dates'],
				templateDay: substr($scheduledDate, 0, 10)
			);
		} catch (\Throwable $e) {
			$this->logger->error(
				'Decidesk: MeetingSeriesService::generateSeries failed',
				['meetingId' => $meetingId, 'exception' => $e->getMessage()]
			);
			return $this->failure(message: 'Failed to generate series instances.', series: $series);
		}//end try

		$this->auditLogService->append(
			actor: $actor,
			action: 'series-generated',
			objectUids: [$meetingId],
			payload: [
				'series' => $series,
				'instances' => count($instances),
				'truncated' => $expansion['truncated'],
			]
		);

		$this->logger->info(
			'Decidesk: meeting series generated',
			['meetingId' => $meetingId, 'series' => $series, 'instances' => count($instances)]
		);

		return [
			'success' => true,
			'series' => $series,
			'instances' => $instances,
			'truncated' => $expansion['truncated'],
			'message' => sprintf('Generated %d meeting instance(s) in series %s.', count($instances), $series),
		];

	}//end generateSeries()

	/**
	 * Build the unsuccessful generateSeries() envelope.
	 *
	 * @param string $message Human-readable failure reason
	 * @param string|null $series Series slug already derived, when known
	 *
	 * @spec openspec/specs/meeting-management/spec.md
	 *
	 * @return array{success: bool, series: string|null, instances: array<int, array<string, mixed>>, truncated: bool, message: string}
	 */
	private function failure(string $message, ?string $series = null): array {
		return [
			'success' => false,
			'series' => $series,
			'instances' => [],
			'truncated' => false,
			'message' => $message,
		];

	}//end failure()

	/**
	 * Create one independently-editable Meeting per non-template date.
	 *
	 * @param object $objectService OpenRegister ObjectService instance
	 * @param array<string, mixed> $template Template meeting payload
	 * @param string $series Series slug shared by every instance
	 * @param string[] $dates Expanded ISO-8601 datetimes
	 * @param string $templateDay `Y-m-d` day the template itself covers
	 *
	 * @spec openspec/specs/meeting-management/spec.md
	 *
	 * @return array<int, array<string, mixed>> Created instance payloads
	 */
	private function createInstances(
		object $objectService,
		array $template,
		string $series,
		array $dates,
		string $templateDay,
	): array {
		$instances = [];
		foreach ($dates as $date) {
			if (substr($date, 0, 10) === $templateDay) {
				// The template itself covers its own date.
				continue;
			}

			$instance = [
				'scheduledDate' => $date,
				'lifecycle' => 'scheduled',
				'series' => $series,
			];
			foreach (self::COPIED_FIELDS as $field) {
				if (array_key_exists($field, $template) === true && $template[$field] !== null) {
					$instance[$field] = $template[$field];
				}
			}

			$saved = $objectService->saveObject(
				object: $instance,
				register: 'decidesk',
				schema: 'meeting'
			);

			$entry = $instance;
			if (is_object($saved) === true) {
				$entry = (array)$saved->jsonSerialize();
			}

			$instances[] = $entry;
		}//end foreach

		return $instances;
	}//end createInstances()

	/**
	 * Derive a stable series slug from the template title + start year.
	 *
	 * @param array<string, mixed> $template Template meeting payload
	 * @param string $scheduledDate Template start datetime
	 *
	 * @spec openspec/specs/meeting-management/spec.md
	 *
	 * @return string Series slug, e.g. `gemeenteraad-delft-2026`
	 */
	private function deriveSeriesSlug(array $template, string $scheduledDate): string {
		$title = (string)($template['title'] ?? 'meeting');
		$slug = strtolower(trim(preg_replace('/[^a-zA-Z0-9]+/', '-', $title) ?? 'meeting', '-'));
		if ($slug === '') {
			$slug = 'meeting';
		}

		$year = substr($scheduledDate, 0, 4);

		return $slug . '-' . $year;
	}//end deriveSeriesSlug()
}//end class
