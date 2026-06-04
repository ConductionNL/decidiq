<?php

/**
 * Unit tests for QuorumVerificationService threshold math.
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
 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-2.4
 */

declare(strict_types=1);

namespace OCA\Decidesk\Tests\Unit\Service;

use OCA\Decidesk\Service\QuorumVerificationService;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for vote-threshold computation against total seats (REQ-008).
 *
 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-2.4
 */
final class QuorumVerificationServiceTest extends TestCase
{

    /**
     * Service under test.
     *
     * @var QuorumVerificationService
     */
    private QuorumVerificationService $service;

    /**
     * Set up fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new QuorumVerificationService(
            $this->createMock(ContainerInterface::class)
        );

    }//end setUp()

    /**
     * Simple majority requires floor(n/2)+1 votes.
     *
     * @return void
     */
    public function testSimpleMajority(): void
    {
        $this->assertSame(4, $this->service->requiredVotesFor('simple-majority', 7));
        $this->assertSame(4, $this->service->requiredVotesFor('simple-majority', 6));

    }//end testSimpleMajority()

    /**
     * Qualified majorities are computed against total seats by ceiling.
     *
     * @return void
     */
    public function testQualifiedMajorities(): void
    {
        $this->assertSame(6, $this->service->requiredVotesFor('qualified-majority-two-thirds', 9));
        $this->assertSame(6, $this->service->requiredVotesFor('qualified-majority-three-quarters', 8));
        $this->assertSame(9, $this->service->requiredVotesFor('unanimous', 9));

    }//end testQualifiedMajorities()

    /**
     * Attendance type validation accepts only the three valid forms.
     *
     * @return void
     */
    public function testVerifyAttendance(): void
    {
        $this->assertTrue($this->service->verifyAttendance('in-person'));
        $this->assertTrue($this->service->verifyAttendance('proxy-holder'));
        $this->assertFalse($this->service->verifyAttendance('absent'));

    }//end testVerifyAttendance()
}//end class
