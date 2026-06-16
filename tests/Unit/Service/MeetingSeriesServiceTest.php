<?php

/**
 * Unit tests for MeetingSeriesService.
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
 * @spec openspec/specs/meeting-management/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidesk\Tests\Unit\Service;

use OCA\Decidesk\Service\AuditLogService;
use OCA\Decidesk\Service\MeetingSeriesService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for the recurring meeting series generation engine.
 *
 * @spec openspec/specs/meeting-management/spec.md
 */
class MeetingSeriesServiceTest extends TestCase
{


    /**
     * Build the service with an in-memory meetings map.
     *
     * @param array<string, array<string, mixed>> &$meetings Map of meetingId => meeting row
     * @param array<int, array<string, mixed>>    &$created  Captured created (no-uuid) objects
     * @param array<int, array<string, mixed>>    &$audited  Captured audit-log calls
     *
     * @return MeetingSeriesService
     */
    private function makeService(array &$meetings, array &$created, array &$audited): MeetingSeriesService
    {
        $logger = $this->createMock(LoggerInterface::class);

        $objectService = $this->createMock(ObjectService::class);
        $objectService->method('find')->willReturnCallback(
            function (int|string $id, ?array $_extend=[], bool $files=false, string|int|null $register=null, string|int|null $schema=null) use (&$meetings): ?ObjectEntity {
                if (isset($meetings[(string) $id]) === false) {
                    return null;
                }

                $row    = $meetings[(string) $id];
                $entity = $this->createMock(ObjectEntity::class);
                $entity->method('jsonSerialize')->willReturn($row);
                $entity->method('getObject')->willReturn($row);
                return $entity;
            }
        );
        $objectService->method('saveObject')->willReturnCallback(
            function (array $object, ?array $extend=[], string|int|null $register=null, string|int|null $schema=null, ?string $uuid=null) use (&$meetings, &$created): ObjectEntity {
                if ($uuid === null) {
                    $uuid             = 'gen-'.(count($created) + 1);
                    $row              = array_merge(['id' => $uuid], $object);
                    $created[]        = $row;
                    $meetings[$uuid]  = $row;
                } else {
                    $row             = array_merge(['id' => $uuid], $object);
                    $meetings[$uuid] = $row;
                }

                $entity = $this->createMock(ObjectEntity::class);
                $entity->method('jsonSerialize')->willReturn($row);
                $entity->method('getObject')->willReturn($row);
                return $entity;
            }
        );

        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')->willReturn($objectService);

        $auditLog = $this->createMock(AuditLogService::class);
        $auditLog->method('append')->willReturnCallback(
            static function (string $actor, string $action, array $objectUids, array $payload=[]) use (&$audited): array {
                $audited[] = compact('actor', 'action', 'objectUids', 'payload');
                return ['success' => true, 'entry' => [], 'message' => 'ok'];
            }
        );

        return new MeetingSeriesService($container, $logger, $auditLog);

    }//end makeService()


    /**
     * Build a bare service for pure expandPattern tests.
     *
     * @return MeetingSeriesService
     */
    private function makeBareService(): MeetingSeriesService
    {
        $meetings = [];
        $created  = [];
        $audited  = [];
        return $this->makeService($meetings, $created, $audited);

    }//end makeBareService()


    /**
     * REQ-MSR-001-S1: monthly expansion from April 23 until December 31
     * produces 9 instances (April through December), preserving time + offset.
     *
     * @return void
     */
    public function testMonthlyExpansionProducesNineInstances(): void
    {
        $service = $this->makeBareService();

        $result = $service->expandPattern(
            '2026-04-23T19:30:00+02:00',
            ['frequency' => 'monthly', 'interval' => 1, 'until' => '2026-12-31']
        );

        $this->assertFalse($result['truncated']);
        $this->assertCount(9, $result['dates']);
        $this->assertSame('2026-04-23T19:30:00+02:00', $result['dates'][0]);
        $this->assertSame('2026-12-23T19:30:00+02:00', $result['dates'][8]);

    }//end testMonthlyExpansionProducesNineInstances()


    /**
     * REQ-MSR-001-S2: exception dates are skipped.
     *
     * @return void
     */
    public function testWeeklyExpansionSkipsExceptions(): void
    {
        $service = $this->makeBareService();

        $result = $service->expandPattern(
            '2026-07-02T10:00:00+02:00',
            [
                'frequency'  => 'weekly',
                'interval'   => 1,
                'until'      => '2026-07-30',
                'exceptions' => ['2026-07-23'],
            ]
        );

        $this->assertSame(
            [
                '2026-07-02T10:00:00+02:00',
                '2026-07-09T10:00:00+02:00',
                '2026-07-16T10:00:00+02:00',
                '2026-07-30T10:00:00+02:00',
            ],
            $result['dates']
        );

    }//end testWeeklyExpansionSkipsExceptions()


    /**
     * REQ-MSR-001-S3: at most 52 instances are generated (cap flagged).
     *
     * @return void
     */
    public function testExpansionCapsAtFiftyTwoInstances(): void
    {
        $service = $this->makeBareService();

        $result = $service->expandPattern(
            '2026-01-05T09:00:00+01:00',
            ['frequency' => 'weekly', 'interval' => 1, 'until' => '2028-12-31']
        );

        $this->assertTrue($result['truncated']);
        $this->assertCount(52, $result['dates']);

    }//end testExpansionCapsAtFiftyTwoInstances()


    /**
     * Monthly on the 31st skips months without a 31st instead of rolling over.
     *
     * @return void
     */
    public function testMonthlyOnThirtyFirstSkipsShortMonths(): void
    {
        $service = $this->makeBareService();

        $result = $service->expandPattern(
            '2026-01-31T14:00:00+01:00',
            ['frequency' => 'monthly', 'interval' => 1, 'until' => '2026-06-30']
        );

        // Fixed-offset input (+01:00) keeps its offset across the year —
        // no DST shift because the timezone is an offset, not a region.
        $this->assertSame(
            [
                '2026-01-31T14:00:00+01:00',
                '2026-03-31T14:00:00+01:00',
                '2026-05-31T14:00:00+01:00',
            ],
            $result['dates']
        );

    }//end testMonthlyOnThirtyFirstSkipsShortMonths()


    /**
     * Daily expansion honours the interval.
     *
     * @return void
     */
    public function testDailyExpansionHonoursInterval(): void
    {
        $service = $this->makeBareService();

        $result = $service->expandPattern(
            '2026-06-01T08:00:00+02:00',
            ['frequency' => 'daily', 'interval' => 3, 'until' => '2026-06-10']
        );

        $this->assertSame(
            [
                '2026-06-01T08:00:00+02:00',
                '2026-06-04T08:00:00+02:00',
                '2026-06-07T08:00:00+02:00',
                '2026-06-10T08:00:00+02:00',
            ],
            $result['dates']
        );

    }//end testDailyExpansionHonoursInterval()


    /**
     * Invalid patterns throw InvalidArgumentException.
     *
     * @return void
     */
    public function testExpansionValidatesPattern(): void
    {
        $service = $this->makeBareService();

        try {
            $service->expandPattern('2026-06-01T08:00:00Z', ['frequency' => 'yearly', 'until' => '2027-01-01']);
            $this->fail('Expected InvalidArgumentException for unknown frequency.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('frequency', $e->getMessage());
        }

        try {
            $service->expandPattern('2026-06-01T08:00:00Z', ['frequency' => 'weekly', 'interval' => 0, 'until' => '2027-01-01']);
            $this->fail('Expected InvalidArgumentException for interval < 1.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('interval', $e->getMessage());
        }

        try {
            $service->expandPattern('2026-06-01T08:00:00Z', ['frequency' => 'weekly']);
            $this->fail('Expected InvalidArgumentException for missing until.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('until', $e->getMessage());
        }

    }//end testExpansionValidatesPattern()


    /**
     * generateSeries creates one instance per non-template date, all sharing
     * the derived series slug, stamps the template, and audits the run.
     *
     * @return void
     */
    public function testGenerateSeriesCreatesInstancesWithSharedSlug(): void
    {
        $meetings = [
            'tpl-1' => [
                'id'            => 'tpl-1',
                'title'         => 'Gemeenteraad Delft',
                'meetingType'   => 'regular',
                'meetingMode'   => 'hybrid',
                'scheduledDate' => '2026-04-23T19:30:00+02:00',
                'lifecycle'     => 'scheduled',
                'location'      => 'Raadzaal',
            ],
        ];
        $created  = [];
        $audited  = [];
        $service  = $this->makeService($meetings, $created, $audited);

        $result = $service->generateSeries(
            'tpl-1',
            ['frequency' => 'monthly', 'interval' => 1, 'until' => '2026-12-31'],
            'alice'
        );

        $this->assertTrue($result['success']);
        $this->assertSame('gemeenteraad-delft-2026', $result['series']);

        // 9 expanded dates minus the template's own date = 8 new instances.
        $this->assertCount(8, $created);
        foreach ($created as $instance) {
            $this->assertSame('gemeenteraad-delft-2026', $instance['series']);
            $this->assertSame('scheduled', $instance['lifecycle']);
            $this->assertSame('Gemeenteraad Delft', $instance['title']);
            $this->assertSame('Raadzaal', $instance['location']);
            $this->assertArrayNotHasKey('seriesPattern', $instance);
        }

        $this->assertSame('2026-05-23T19:30:00+02:00', $created[0]['scheduledDate']);

        // The template is stamped with series + pattern.
        $this->assertSame('gemeenteraad-delft-2026', $meetings['tpl-1']['series']);
        $this->assertSame('monthly', $meetings['tpl-1']['seriesPattern']['frequency']);

        // Audit entry records the generation with the instance count.
        $this->assertCount(1, $audited);
        $this->assertSame('series-generated', $audited[0]['action']);
        $this->assertSame(['tpl-1'], $audited[0]['objectUids']);
        $this->assertSame(8, $audited[0]['payload']['instances']);

    }//end testGenerateSeriesCreatesInstancesWithSharedSlug()


    /**
     * generateSeries reuses an existing series slug on the template.
     *
     * @return void
     */
    public function testGenerateSeriesReusesExistingSlug(): void
    {
        $meetings = [
            'tpl-1' => [
                'id'            => 'tpl-1',
                'title'         => 'AB Delfland',
                'series'        => 'ab-delfland-2026',
                'scheduledDate' => '2026-06-04T10:00:00+02:00',
            ],
        ];
        $created  = [];
        $audited  = [];
        $service  = $this->makeService($meetings, $created, $audited);

        $result = $service->generateSeries(
            'tpl-1',
            ['frequency' => 'monthly', 'interval' => 1, 'until' => '2026-08-31'],
            'alice'
        );

        $this->assertTrue($result['success']);
        $this->assertSame('ab-delfland-2026', $result['series']);
        $this->assertCount(2, $created);

    }//end testGenerateSeriesReusesExistingSlug()


    /**
     * generateSeries rejects unknown meetings (OR RBAC null → not found) and
     * invalid patterns without creating anything.
     *
     * @return void
     */
    public function testGenerateSeriesGuards(): void
    {
        $meetings = [
            'tpl-1' => [
                'id'            => 'tpl-1',
                'title'         => 'Board',
                'scheduledDate' => '2026-06-04T10:00:00+02:00',
            ],
        ];
        $created  = [];
        $audited  = [];
        $service  = $this->makeService($meetings, $created, $audited);

        $missing = $service->generateSeries('unknown', ['frequency' => 'weekly', 'until' => '2026-08-31'], 'alice');
        $this->assertFalse($missing['success']);
        $this->assertSame('Meeting not found.', $missing['message']);

        $invalid = $service->generateSeries('tpl-1', ['frequency' => 'yearly', 'until' => '2026-08-31'], 'alice');
        $this->assertFalse($invalid['success']);
        $this->assertStringContainsString('frequency', $invalid['message']);

        $this->assertSame([], $created);
        $this->assertSame([], $audited);

    }//end testGenerateSeriesGuards()


}//end class
