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
// SPDX-License-Identifier: EUPL-1.2.
declare(strict_types=1);

namespace OCA\Decidesk\Service;

use DateInterval;
use DateTimeImmutable;
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
class MeetingSeriesService
{

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
     * @param ContainerInterface $container       The DI container (lazy-loads OpenRegister's ObjectService)
     * @param LoggerInterface    $logger          The logger
     * @param AuditLogService    $auditLogService Audit log dependency for series-generation events
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
     * @param string               $startDate Template start datetime (ISO-8601)
     * @param array<string, mixed> $pattern   Recurrence pattern (frequency, interval, until, exceptions)
     *
     * @throws InvalidArgumentException When the pattern or start date is invalid
     *
     * @spec openspec/specs/meeting-management/spec.md
     *
     * @return array{dates: string[], truncated: bool} Expanded datetimes + cap flag
     */
    public function expandPattern(string $startDate, array $pattern): array
    {
        $frequency = (string) ($pattern['frequency'] ?? '');
        if (in_array($frequency, self::FREQUENCIES, true) === false) {
            throw new InvalidArgumentException(
                'frequency must be one of: '.implode(', ', self::FREQUENCIES).'.'
            );
        }

        $interval = (int) ($pattern['interval'] ?? 1);
        if ($interval < 1) {
            throw new InvalidArgumentException('interval must be >= 1.');
        }

        $untilRaw = (string) ($pattern['until'] ?? '');
        if ($untilRaw === '') {
            throw new InvalidArgumentException('until is required.');
        }

        try {
            $start = new DateTimeImmutable($startDate);
        } catch (\Throwable) {
            throw new InvalidArgumentException('Invalid start date: '.$startDate);
        }

        try {
            // `until` is inclusive and compared on the date part in the template's timezone.
            $until = new DateTimeImmutable($untilRaw.' 23:59:59', $start->getTimezone());
        } catch (\Throwable) {
            throw new InvalidArgumentException('Invalid until date: '.$untilRaw);
        }

        $exceptions = [];
        foreach ((array) ($pattern['exceptions'] ?? []) as $exception) {
            $exceptions[substr((string) $exception, 0, 10)] = true;
        }

        $dates      = [];
        $truncated  = false;
        $dayOfMonth = (int) $start->format('j');

        if ($frequency === 'monthly') {
            // Same-day-of-month semantics: months lacking the day (e.g. the
            // 31st) are skipped rather than rolled over to the next month.
            for ($offset = 0; true; $offset += $interval) {
                $firstOfMonth = $start->modify('first day of +'.$offset.' month');
                if ($firstOfMonth > $until) {
                    break;
                }

                if ($dayOfMonth > (int) $firstOfMonth->format('t')) {
                    continue;
                }

                $occurrence = $firstOfMonth->setDate(
                    (int) $firstOfMonth->format('Y'),
                    (int) $firstOfMonth->format('n'),
                    $dayOfMonth
                );
                if ($occurrence > $until) {
                    break;
                }

                if (isset($exceptions[$occurrence->format('Y-m-d')]) === true) {
                    continue;
                }

                if (count($dates) >= self::MAX_INSTANCES) {
                    $truncated = true;
                    break;
                }

                $dates[] = $occurrence->format('Y-m-d\TH:i:sP');
            }//end for
        } else {
            $stepDays = $interval;
            if ($frequency === 'weekly') {
                $stepDays = ($interval * 7);
            }

            for ($step = 0; true; $step++) {
                $occurrence = $start->add(new DateInterval('P'.($step * $stepDays).'D'));
                if ($occurrence > $until) {
                    break;
                }

                if (isset($exceptions[$occurrence->format('Y-m-d')]) === true) {
                    continue;
                }

                if (count($dates) >= self::MAX_INSTANCES) {
                    $truncated = true;
                    break;
                }

                $dates[] = $occurrence->format('Y-m-d\TH:i:sP');
            }//end for
        }//end if

        if ($truncated === true) {
            $this->logger->warning(
                'Decidesk: series expansion truncated at '.self::MAX_INSTANCES.' instances',
                ['startDate' => $startDate, 'until' => $untilRaw]
            );
        }

        return [
            'dates'     => $dates,
            'truncated' => $truncated,
        ];

    }//end expandPattern()

    /**
     * Generate the meeting instances for a recurrence pattern.
     *
     * Loads the template via ObjectService (OpenRegister RBAC: callers
     * without read access get null → "not found"), derives or reuses the
     * series slug, stamps `series` + `seriesPattern` on the template, and
     * creates one independently-editable Meeting per expanded date.
     *
     * @param string               $meetingId UUID of the template meeting
     * @param array<string, mixed> $pattern   Recurrence pattern (frequency, interval, until, exceptions)
     * @param string               $actor     Acting user UID (for the audit log)
     *
     * @spec openspec/specs/meeting-management/spec.md
     *
     * @return array{success: bool, series: string|null, instances: array<int, array<string, mixed>>, truncated: bool, message: string}
     */
    public function generateSeries(string $meetingId, array $pattern, string $actor): array
    {
        try {
            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
            $entity        = $objectService->find(id: $meetingId, register: 'decidesk', schema: 'meeting');
        } catch (\Throwable $e) {
            $this->logger->error(
                'Decidesk: MeetingSeriesService::generateSeries lookup failed',
                ['meetingId' => $meetingId, 'exception' => $e->getMessage()]
            );
            return [
                'success'   => false,
                'series'    => null,
                'instances' => [],
                'truncated' => false,
                'message'   => 'Failed to load template meeting.',
            ];
        }

        if ($entity === null) {
            return [
                'success'   => false,
                'series'    => null,
                'instances' => [],
                'truncated' => false,
                'message'   => 'Meeting not found.',
            ];
        }

        $template = (array) $entity->jsonSerialize();
        if (method_exists($entity, 'getObject') === true) {
            $template = $entity->getObject();
        }

        $scheduledDate = (string) ($template['scheduledDate'] ?? '');
        if ($scheduledDate === '') {
            return [
                'success'   => false,
                'series'    => null,
                'instances' => [],
                'truncated' => false,
                'message'   => 'Template meeting has no scheduledDate.',
            ];
        }

        try {
            $expansion = $this->expandPattern(startDate: $scheduledDate, pattern: $pattern);
        } catch (InvalidArgumentException $e) {
            return [
                'success'   => false,
                'series'    => null,
                'instances' => [],
                'truncated' => false,
                'message'   => $e->getMessage(),
            ];
        }

        $series = (string) ($template['series'] ?? '');
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

            $templateDay = substr($scheduledDate, 0, 10);
            $instances   = [];
            foreach ($expansion['dates'] as $date) {
                if (substr($date, 0, 10) === $templateDay) {
                    // The template itself covers its own date.
                    continue;
                }

                $instance = [
                    'scheduledDate' => $date,
                    'lifecycle'     => 'scheduled',
                    'series'        => $series,
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

                if (is_object($saved) === true) {
                    $instances[] = (array) $saved->jsonSerialize();
                } else {
                    $instances[] = $instance;
                }
            }//end foreach
        } catch (\Throwable $e) {
            $this->logger->error(
                'Decidesk: MeetingSeriesService::generateSeries failed',
                ['meetingId' => $meetingId, 'exception' => $e->getMessage()]
            );
            return [
                'success'   => false,
                'series'    => $series,
                'instances' => [],
                'truncated' => false,
                'message'   => 'Failed to generate series instances.',
            ];
        }//end try

        $this->auditLogService->append(
            actor: $actor,
            action: 'series-generated',
            objectUids: [$meetingId],
            payload: [
                'series'    => $series,
                'instances' => count($instances),
                'truncated' => $expansion['truncated'],
            ]
        );

        $this->logger->info(
            'Decidesk: meeting series generated',
            ['meetingId' => $meetingId, 'series' => $series, 'instances' => count($instances)]
        );

        return [
            'success'   => true,
            'series'    => $series,
            'instances' => $instances,
            'truncated' => $expansion['truncated'],
            'message'   => sprintf('Generated %d meeting instance(s) in series %s.', count($instances), $series),
        ];

    }//end generateSeries()

    /**
     * Derive a stable series slug from the template title + start year.
     *
     * @param array<string, mixed> $template      Template meeting payload
     * @param string               $scheduledDate Template start datetime
     *
     * @spec openspec/specs/meeting-management/spec.md
     *
     * @return string Series slug, e.g. `gemeenteraad-delft-2026`
     */
    private function deriveSeriesSlug(array $template, string $scheduledDate): string
    {
        $title = (string) ($template['title'] ?? 'meeting');
        $slug  = strtolower(trim(preg_replace('/[^a-zA-Z0-9]+/', '-', $title) ?? 'meeting', '-'));
        if ($slug === '') {
            $slug = 'meeting';
        }

        $year = substr($scheduledDate, 0, 4);

        return $slug.'-'.$year;

    }//end deriveSeriesSlug()
}//end class
