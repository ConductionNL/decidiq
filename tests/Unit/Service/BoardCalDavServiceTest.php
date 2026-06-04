<?php

/**
 * Unit tests for BoardCalDavService ICS build/parse round-trip.
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
 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-7.1
 */

declare(strict_types=1);

namespace OCA\Decidesk\Tests\Unit\Service;

use OCA\Decidesk\Service\BoardCalDavService;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for X-DECIDESK-* property preservation through ICS (ADR-002).
 *
 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-7.1
 */
final class BoardCalDavServiceTest extends TestCase
{

    /**
     * Service under test.
     *
     * @var BoardCalDavService
     */
    private BoardCalDavService $service;

    /**
     * Set up fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new BoardCalDavService(
            $this->createMock(ContainerInterface::class)
        );

    }//end setUp()

    /**
     * The built VEVENT carries the X-DECIDESK-* registry and round-trips back.
     *
     * @return void
     */
    public function testVeventRoundTrip(): void
    {
        $meeting = [
            'caldavUid'      => 'decidesk-test@decidesk',
            'meetingStart'   => '2025-03-12T14:00:00Z',
            'meetingEnd'     => '2025-03-12T17:00:00Z',
            'meetingType'    => 'regular',
            'location'       => 'Boardroom',
            'boardId'        => 'board-1',
            'status'         => 'notice-sent',
            'quorumRequired' => 3,
        ];

        $ics = $this->service->createBoardMeetingVevent($meeting);
        $this->assertStringContainsString('BEGIN:VEVENT', $ics);
        $this->assertStringContainsString('X-DECIDESK-LIFECYCLE:notice-sent', $ics);
        $this->assertStringContainsString('X-DECIDESK-QUORUM-REQUIRED:3', $ics);

        $parsed = $this->service->readBoardMeetingData($ics);
        $this->assertSame('decidesk-test@decidesk', $parsed['caldavUid']);
        $this->assertSame('board-1', $parsed['boardId']);
        $this->assertSame('notice-sent', $parsed['status']);
        $this->assertSame('3', $parsed['quorumRequired']);

    }//end testVeventRoundTrip()
}//end class
