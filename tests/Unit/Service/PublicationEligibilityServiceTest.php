<?php

/**
 * Unit tests for PublicationEligibilityService.
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
 * @spec openspec/changes/publish-decisions-via-opencatalogi/specs/public-publication/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidesk\Tests\Unit\Service;

use OCA\Decidesk\Exception\AccessDeniedException;
use OCA\Decidesk\Exception\MissingObjectException;
use OCA\Decidesk\Service\PublicationEligibilityService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests the deny-list, eligibility matrix per type, and the flow-owned guard.
 *
 * @spec openspec/changes/publish-decisions-via-opencatalogi/specs/public-publication/spec.md
 */
class PublicationEligibilityServiceTest extends TestCase
{

    /**
     * Build a service whose ObjectService returns the given object data.
     *
     * @param array<string,mixed>|null $objectData Source object data or null (not found).
     *
     * @return PublicationEligibilityService
     */
    private function makeService(?array $objectData): PublicationEligibilityService
    {
        $logger = $this->createMock(LoggerInterface::class);

        $objectService = $this->createMock(ObjectService::class);
        $objectService->method('find')->willReturnCallback(
            function (int|string $id, ?array $_extend=[], bool $files=false, string|int|null $register=null, string|int|null $schema=null) use ($objectData): ?object {
                if ($objectData === null) {
                    return null;
                }

                $entity = $this->createMock(ObjectEntity::class);
                $entity->method('jsonSerialize')->willReturn($objectData);
                return $entity;
            }
        );

        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')->willReturn($objectService);

        return new PublicationEligibilityService($container, $logger);

    }//end makeService()

    /**
     * A decided/enacted decision is eligible.
     *
     * @return void
     */
    public function testEnactedDecisionIsEligible(): void
    {
        $service = $this->makeService(['lifecycle' => 'enacted', 'decisionType' => 'meeting-outcome']);
        $data    = $service->assertEligible('decision', 'dec-1');
        $this->assertSame('enacted', $data['lifecycle']);

    }//end testEnactedDecisionIsEligible()

    /**
     * A draft decision is refused with an eligibility error.
     *
     * @return void
     */
    public function testDraftDecisionRefused(): void
    {
        $service = $this->makeService(['lifecycle' => 'draft', 'decisionType' => 'motion']);
        $this->expectException(AccessDeniedException::class);
        $service->assertEligible('decision', 'dec-1');

    }//end testDraftDecisionRefused()

    /**
     * An agenda of a non-public meeting is refused.
     *
     * @return void
     */
    public function testAgendaOfNonPublicMeetingRefused(): void
    {
        $service = $this->makeService(['isPublic' => false, 'convocationSentAt' => '2025-01-01T00:00:00Z']);
        $this->expectException(AccessDeniedException::class);
        $service->assertEligible('agenda', 'meet-1');

    }//end testAgendaOfNonPublicMeetingRefused()

    /**
     * An agenda of a public meeting without sent convocation is refused.
     *
     * @return void
     */
    public function testAgendaWithoutConvocationRefused(): void
    {
        $service = $this->makeService(['isPublic' => true]);
        $this->expectException(AccessDeniedException::class);
        $service->assertEligible('agenda', 'meet-1');

    }//end testAgendaWithoutConvocationRefused()

    /**
     * A public meeting with a sent convocation is eligible.
     *
     * @return void
     */
    public function testPublicAgendaWithConvocationIsEligible(): void
    {
        $service = $this->makeService(['isPublic' => true, 'convocationSentAt' => '2025-01-01T00:00:00Z']);
        $data    = $service->assertEligible('agenda', 'meet-1');
        $this->assertTrue($data['isPublic']);

    }//end testPublicAgendaWithConvocationIsEligible()

    /**
     * Approved minutes are eligible; draft minutes are refused.
     *
     * @return void
     */
    public function testMinutesEligibilityByLifecycle(): void
    {
        $approved = $this->makeService(['lifecycle' => 'approved']);
        $this->assertSame('approved', $approved->assertEligible('minutes', 'min-1')['lifecycle']);

        $draft = $this->makeService(['lifecycle' => 'draft']);
        $this->expectException(AccessDeniedException::class);
        $draft->assertEligible('minutes', 'min-2');

    }//end testMinutesEligibilityByLifecycle()

    /**
     * Board-governance objects are structurally refused before eligibility.
     *
     * @return void
     */
    public function testBoardMinutesStructurallyRefused(): void
    {
        // Even with an "approved-like" lifecycle, the discriminator denies it.
        $service = $this->makeService(['lifecycle' => 'approved', 'type' => 'BoardMinutes']);
        $this->expectException(AccessDeniedException::class);
        $service->assertEligible('minutes', 'bm-1');

    }//end testBoardMinutesStructurallyRefused()

    /**
     * The deny-list check covers the board family, votes, transcripts, recordings.
     *
     * @return void
     */
    public function testDenyListCoverage(): void
    {
        $service = $this->makeService([]);
        foreach (['board-material', 'vote', 'voting-round', 'transcript', 'recording', 'audit-trail'] as $schema) {
            $this->assertTrue($service->isDeniedType($schema, []), "Expected $schema to be denied");
        }

        $this->assertFalse($service->isDeniedType('decision', ['decisionType' => 'motion']));

    }//end testDenyListCoverage()

    /**
     * A confidential Resolution-type decision is denied.
     *
     * @return void
     */
    public function testConfidentialResolutionDenied(): void
    {
        $service = $this->makeService([]);
        $this->assertTrue($service->isDeniedType('decision', ['decisionType' => 'resolution', 'confidentiality' => 'confidential']));

    }//end testConfidentialResolutionDenied()

    /**
     * A missing source object raises MissingObjectException.
     *
     * @return void
     */
    public function testMissingObjectThrows(): void
    {
        $service = $this->makeService(null);
        $this->expectException(MissingObjectException::class);
        $service->assertEligible('decision', 'nope');

    }//end testMissingObjectThrows()

    /**
     * A direct client write to isPublished/publishedAt is rejected.
     *
     * @return void
     */
    public function testDirectPublicationWriteRejected(): void
    {
        $service = $this->makeService([]);
        $stored  = ['isPublished' => 'internal', 'publishedAt' => null];

        $this->expectException(AccessDeniedException::class);
        $service->guardDirectPublicationWrite($stored, ['isPublished' => 'public']);

    }//end testDirectPublicationWriteRejected()

    /**
     * An update that leaves the flow-owned fields unchanged is allowed.
     *
     * @return void
     */
    public function testUnchangedPublicationFieldsAllowed(): void
    {
        $service = $this->makeService([]);
        $stored  = ['isPublished' => 'internal', 'publishedAt' => null, 'title' => 'x'];

        // No exception expected.
        $service->guardDirectPublicationWrite($stored, ['isPublished' => 'internal', 'title' => 'y']);
        $this->assertTrue(true);

    }//end testUnchangedPublicationFieldsAllowed()
}//end class
