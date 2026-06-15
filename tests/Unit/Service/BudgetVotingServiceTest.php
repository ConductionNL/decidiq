<?php

/**
 * Unit tests for BudgetVotingService.
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
 * @spec openspec/changes/citizen-participation/specs/citizen-participation/spec.md
 */

declare(strict_types=1);

namespace OCA\Decidesk\Tests\Unit\Service;

use OCA\Decidesk\Service\BudgetVotingService;
use OCA\Decidesk\Service\ParticipationLifecycleService;
use OCA\Decidesk\Service\VotingService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests proposal submission/validation guards and greedy allocation.
 *
 * @spec openspec/changes/citizen-participation/specs/citizen-participation/spec.md
 */
class BudgetVotingServiceTest extends TestCase
{

    /**
     * Service under test.
     *
     * @var BudgetVotingService
     */
    private BudgetVotingService $service;

    /**
     * Mock ObjectService.
     *
     * @var ObjectService&MockObject
     */
    private ObjectService&MockObject $objectService;

    /**
     * Set up fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $container           = $this->createMock(ContainerInterface::class);
        $this->objectService = $this->createMock(ObjectService::class);
        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('setSchema')->willReturnSelf();
        $container->method('get')->willReturn($this->objectService);

        $this->service = new BudgetVotingService(
            container: $container,
            logger: $this->createMock(LoggerInterface::class),
            lifecycleService: new ParticipationLifecycleService(
                container: $container,
                logger: $this->createMock(LoggerInterface::class),
            ),
            votingService: $this->createMock(VotingService::class),
        );

    }//end setUp()

    /**
     * Build an ObjectEntity mock serialising to the given array.
     *
     * @param array<string, mixed> $data Payload.
     *
     * @return ObjectEntity&MockObject
     */
    private function entity(array $data): ObjectEntity&MockObject
    {
        $entity = $this->createMock(ObjectEntity::class);
        $entity->method('jsonSerialize')->willReturn($data);
        return $entity;

    }//end entity()

    /**
     * A valid proposal is created with status 'submitted'.
     *
     * @return void
     *
     * @spec openspec/changes/citizen-participation/specs/citizen-participation/spec.md
     */
    public function testSubmitValidProposal(): void
    {
        $future = (new \DateTimeImmutable('+1 day'))->format(\DateTimeInterface::ATOM);
        $this->objectService->method('find')->willReturn(
            $this->entity(['id' => 'b1', 'status' => 'submission', 'submissionDeadline' => $future, 'totalAmount' => 100000])
        );
        $this->objectService->method('saveObject')->willReturnCallback(fn(...$a) => $this->entity($a[0] ?? []));

        $result = $this->service->submitProposal(budgetId: 'b1', title: 'Playground', description: 'Renovate', requested: 25000, submitterId: 'alice');
        self::assertSame('submitted', $result['status']);
        self::assertSame('alice', $result['submitter']);

    }//end testSubmitValidProposal()

    /**
     * An oversized proposal (requested > total) is rejected.
     *
     * @return void
     *
     * @spec openspec/changes/citizen-participation/specs/citizen-participation/spec.md
     */
    public function testOversizedProposalRejected(): void
    {
        $future = (new \DateTimeImmutable('+1 day'))->format(\DateTimeInterface::ATOM);
        $this->objectService->method('find')->willReturn(
            $this->entity(['id' => 'b1', 'status' => 'submission', 'submissionDeadline' => $future, 'totalAmount' => 10000])
        );
        $this->expectException(\InvalidArgumentException::class);
        $this->service->submitProposal(budgetId: 'b1', title: 'Big', description: 'Too big', requested: 25000, submitterId: 'alice');

    }//end testOversizedProposalRejected()

    /**
     * Proposal submission outside the submission phase is rejected.
     *
     * @return void
     *
     * @spec openspec/changes/citizen-participation/specs/citizen-participation/spec.md
     */
    public function testProposalOutsideSubmissionPhaseRejected(): void
    {
        $this->objectService->method('find')->willReturn($this->entity(['id' => 'b1', 'status' => 'voting', 'totalAmount' => 10000]));
        $this->expectException(\RuntimeException::class);
        $this->service->submitProposal(budgetId: 'b1', title: 'Late', description: 'x', requested: 100, submitterId: 'alice');

    }//end testProposalOutsideSubmissionPhaseRejected()

    /**
     * validateProposal flips submitted -> validated.
     *
     * @return void
     *
     * @spec openspec/changes/citizen-participation/specs/citizen-participation/spec.md
     */
    public function testValidateProposal(): void
    {
        $this->objectService->method('find')->willReturn($this->entity(['id' => 'p1', 'status' => 'submitted']));
        $this->objectService->method('saveObject')->willReturnCallback(fn(...$a) => $this->entity($a[0] ?? []));
        $result = $this->service->validateProposal(proposalId: 'p1', approve: true);
        self::assertSame('validated', $result['status']);

    }//end testValidateProposal()

    /**
     * Greedy allocation ranks by votesFor and funds within the total.
     *
     * @return void
     *
     * @spec openspec/changes/citizen-participation/specs/citizen-participation/spec.md
     */
    public function testGreedyAllocation(): void
    {
        $round     = ['id' => 'b1', 'totalAmount' => 30000];
        $proposals = [
            $this->entity(['id' => 'p1', 'title' => 'A', 'requestedAmount' => 20000, 'votesFor' => 5, 'votesAgainst' => 1, 'status' => 'validated', 'relations' => [['schema' => 'participatory-budget', 'id' => 'b1']]]),
            $this->entity(['id' => 'p2', 'title' => 'B', 'requestedAmount' => 15000, 'votesFor' => 10, 'votesAgainst' => 0, 'status' => 'validated', 'relations' => [['schema' => 'participatory-budget', 'id' => 'b1']]]),
            $this->entity(['id' => 'p3', 'title' => 'C', 'requestedAmount' => 12000, 'votesFor' => 3, 'votesAgainst' => 2, 'status' => 'validated', 'relations' => [['schema' => 'participatory-budget', 'id' => 'b1']]]),
        ];

        $this->objectService->method('find')->willReturn($this->entity($round));
        $this->objectService->method('findAll')->willReturn($proposals);
        $this->objectService->method('saveObject')->willReturnCallback(fn(...$a) => $this->entity($a[0] ?? []));

        $result = $this->service->calculateAllocation(budgetId: 'b1');
        // Ranked: p2 (10) first funded (15000), then p1 (5) funded (20000 -> total 35000 > 30000 NOT funded),
        // then p3 (3) funded (15000 + 12000 = 27000 <= 30000 funded).
        $ranked = $result['proposals'];
        self::assertSame('p2', $ranked[0]['proposalId']);
        self::assertTrue($ranked[0]['funded']);
        // p1 is rank 2 but cannot be funded (would exceed total).
        self::assertSame('p1', $ranked[1]['proposalId']);
        self::assertFalse($ranked[1]['funded']);
        // p3 is rank 3 and fits in the remaining budget.
        self::assertSame('p3', $ranked[2]['proposalId']);
        self::assertTrue($ranked[2]['funded']);
        self::assertSame(27000.0, $result['allocatedAmount']);

    }//end testGreedyAllocation()

}//end class
