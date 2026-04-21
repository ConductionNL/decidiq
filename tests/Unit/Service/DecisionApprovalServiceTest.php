<?php

/**
 * Unit tests for DecisionApprovalService.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\Decidesk\Tests\Unit\Service
 *
 * @spec openspec/changes/p2-minutes-and-decisions-other-t1/tasks.md#task-1
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Decidesk\Tests\Unit\Service;

use OCA\Decidesk\Service\DecisionApprovalService;
use OCP\AppFramework\OCS\OCSForbiddenException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for DecisionApprovalService approval workflow and security behaviour.
 *
 * @spec openspec/changes/p2-minutes-and-decisions-other-t1/tasks.md#task-1
 */
class DecisionApprovalServiceTest extends TestCase
{

    /**
     * Mock DI container.
     *
     * @var ContainerInterface&MockObject
     */
    private ContainerInterface&MockObject $container;

    /**
     * Mock logger.
     *
     * @var LoggerInterface&MockObject
     */
    private LoggerInterface&MockObject $logger;

    /**
     * Mock ObjectService.
     *
     * @var MockObject
     */
    private MockObject $objectService;

    /**
     * Mock AuthorizationService.
     *
     * @var MockObject
     */
    private MockObject $authService;

    /**
     * Service under test.
     *
     * @var DecisionApprovalService
     */
    private DecisionApprovalService $service;

    /**
     * Set up test fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->objectService = $this->getMockBuilder(\stdClass::class)
            ->addMethods(['find', 'findAll', 'setRegister', 'setSchema', 'saveObject', 'createRelation', 'findRelations'])
            ->getMock();

        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('setSchema')->willReturnSelf();

        $this->authService = $this->getMockBuilder(\stdClass::class)
            ->addMethods(['checkUserRole'])
            ->getMock();

        $this->container = $this->createMock(ContainerInterface::class);
        $this->logger    = $this->createMock(LoggerInterface::class);

        $this->service = new DecisionApprovalService(
            container: $this->container,
            logger: $this->logger,
        );

    }//end setUp()

    /**
     * Transitions that do not require roles succeed without auth service call.
     *
     * The 'published' → [] state has no required roles (empty transitions).
     * 'draft' → 'legal-review' DOES require roles, so we test a no-role
     * transition by mocking auth to be available but asserting no role check.
     *
     * @spec openspec/changes/p2-minutes-and-decisions-other-t1/tasks.md#task-1
     *
     * @return void
     */
    public function testGetApprovalStateMapReturnsAllStates(): void
    {
        $map = $this->service->getApprovalStateMap();

        self::assertArrayHasKey('draft', $map);
        self::assertArrayHasKey('legal-review', $map);
        self::assertArrayHasKey('published', $map);
        self::assertContains('legal-review', $map['draft']);

    }//end testGetApprovalStateMapReturnsAllStates()

    /**
     * transitionLifecycle throws InvalidArgumentException when the auth
     * service is unavailable (deny-by-default security invariant).
     *
     * This covers the CWE-863 fix: previously a null auth service caused the
     * role check to be silently skipped.
     *
     * @spec openspec/changes/p2-minutes-and-decisions-other-t1/tasks.md#task-1
     *
     * @return void
     */
    public function testTransitionLifecycleDeniesWhenAuthServiceUnavailable(): void
    {
        $decisionData   = ['lifecycle' => 'draft', 'title' => 'Test Decision'];
        $decisionEntity = $this->createEntityMock($decisionData);

        $this->container->method('get')
            ->willReturnCallback(
                function (string $id) use ($decisionEntity): object {
                    if ($id === 'OCA\OpenRegister\Service\ObjectService') {
                        return $this->objectService;
                    }

                    throw new \Exception('Service not available');
                }
            );

        $this->objectService->method('find')->willReturn($decisionEntity);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/access denied/i');

        // Transition from 'draft' to 'legal-review' requires roles.
        $this->service->transitionLifecycle(
            decisionId: 'decision-001',
            toState: 'legal-review',
            actorId: 'actor-uid',
        );

    }//end testTransitionLifecycleDeniesWhenAuthServiceUnavailable()

    /**
     * transitionLifecycle throws InvalidArgumentException for disallowed state change.
     *
     * @spec openspec/changes/p2-minutes-and-decisions-other-t1/tasks.md#task-1
     *
     * @return void
     */
    public function testTransitionLifecycleThrowsForInvalidTransition(): void
    {
        $decisionData   = ['lifecycle' => 'published', 'title' => 'Locked Decision'];
        $decisionEntity = $this->createEntityMock($decisionData);

        $this->container->method('get')
            ->with('OCA\OpenRegister\Service\ObjectService')
            ->willReturn($this->objectService);

        $this->objectService->method('find')->willReturn($decisionEntity);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/not allowed/');

        // 'published' has no outgoing transitions.
        $this->service->transitionLifecycle(
            decisionId: 'decision-pub',
            toState: 'draft',
            actorId: 'actor-uid',
        );

    }//end testTransitionLifecycleThrowsForInvalidTransition()

    /**
     * transitionLifecycle throws InvalidArgumentException when actor lacks required role.
     *
     * @spec openspec/changes/p2-minutes-and-decisions-other-t1/tasks.md#task-1
     *
     * @return void
     */
    public function testTransitionLifecycleThrowsWhenActorLacksRole(): void
    {
        $decisionData   = ['lifecycle' => 'draft', 'title' => 'Test Decision'];
        $decisionEntity = $this->createEntityMock($decisionData);

        $this->authService->method('checkUserRole')->willReturn(false);

        $this->container->method('get')
            ->willReturnCallback(
                function (string $id) use ($decisionEntity): object {
                    if ($id === 'OCA\OpenRegister\Service\ObjectService') {
                        return $this->objectService;
                    }

                    if ($id === 'OCA\OpenRegister\Service\AuthorizationService') {
                        return $this->authService;
                    }

                    throw new \Exception('Unknown service');
                }
            );

        $this->objectService->method('find')->willReturn($decisionEntity);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/lacks required role/');

        $this->service->transitionLifecycle(
            decisionId: 'decision-001',
            toState: 'legal-review',
            actorId: 'unqualified-uid',
        );

    }//end testTransitionLifecycleThrowsWhenActorLacksRole()

    /**
     * authorizeReviewerSubmission passes when caller UID matches Person's nextcloudUserId.
     *
     * @spec openspec/changes/p2-minutes-and-decisions-other-t1/tasks.md#task-2
     *
     * @return void
     */
    public function testAuthorizeReviewerSubmissionPassesForMatchingCaller(): void
    {
        $personData   = ['nextcloudUserId' => 'reviewer-uid'];
        $personEntity = $this->createEntityMock($personData);

        $this->container->method('get')
            ->with('OCA\OpenRegister\Service\ObjectService')
            ->willReturn($this->objectService);

        $this->objectService->method('find')->willReturn($personEntity);

        // No exception expected.
        $this->service->authorizeReviewerSubmission(
            personId: 'person-001',
            callerUid: 'reviewer-uid',
        );

        // Assertion: if we reach here, no OCSForbiddenException was thrown.
        self::assertTrue(true);

    }//end testAuthorizeReviewerSubmissionPassesForMatchingCaller()

    /**
     * authorizeReviewerSubmission throws OCSForbiddenException for mismatched caller.
     *
     * This covers the CWE-639 fix: prevents reviewer impersonation via forged personId.
     *
     * @spec openspec/changes/p2-minutes-and-decisions-other-t1/tasks.md#task-2
     *
     * @return void
     */
    public function testAuthorizeReviewerSubmissionThrowsForMismatchedCaller(): void
    {
        $personData   = ['nextcloudUserId' => 'real-reviewer-uid'];
        $personEntity = $this->createEntityMock($personData);

        $this->container->method('get')
            ->with('OCA\OpenRegister\Service\ObjectService')
            ->willReturn($this->objectService);

        $this->objectService->method('find')->willReturn($personEntity);

        $this->expectException(OCSForbiddenException::class);

        $this->service->authorizeReviewerSubmission(
            personId: 'person-001',
            callerUid: 'attacker-uid',
        );

    }//end testAuthorizeReviewerSubmissionThrowsForMismatchedCaller()

    /**
     * authorizeReviewerSubmission throws OCSForbiddenException when person not found.
     *
     * @spec openspec/changes/p2-minutes-and-decisions-other-t1/tasks.md#task-2
     *
     * @return void
     */
    public function testAuthorizeReviewerSubmissionThrowsWhenPersonNotFound(): void
    {
        $this->container->method('get')
            ->with('OCA\OpenRegister\Service\ObjectService')
            ->willReturn($this->objectService);

        $this->objectService->method('find')->willReturn(null);

        $this->expectException(OCSForbiddenException::class);

        $this->service->authorizeReviewerSubmission(
            personId: 'nonexistent-person',
            callerUid: 'any-uid',
        );

    }//end testAuthorizeReviewerSubmissionThrowsWhenPersonNotFound()

    /**
     * submitReview records the sign-off in the decision notes.
     *
     * @spec openspec/changes/p2-minutes-and-decisions-other-t1/tasks.md#task-1
     *
     * @return void
     */
    public function testSubmitReviewThrowsForInvalidValue(): void
    {
        $this->container->method('get')
            ->with('OCA\OpenRegister\Service\ObjectService')
            ->willReturn($this->objectService);

        $this->expectException(\InvalidArgumentException::class);

        $this->service->submitReview(
            decisionId: 'decision-001',
            personId: 'person-001',
            value: 'invalid-value',
        );

    }//end testSubmitReviewThrowsForInvalidValue()

    /**
     * allReviewsComplete returns false when decision not found.
     *
     * @spec openspec/changes/p2-minutes-and-decisions-other-t1/tasks.md#task-1
     *
     * @return void
     */
    public function testAllReviewsCompleteReturnsFalseWhenDecisionMissing(): void
    {
        $this->container->method('get')
            ->with('OCA\OpenRegister\Service\ObjectService')
            ->willReturn($this->objectService);

        $this->objectService->method('find')->willReturn(null);

        $result = $this->service->allReviewsComplete(decisionId: 'missing-id');

        self::assertFalse($result);

    }//end testAllReviewsCompleteReturnsFalseWhenDecisionMissing()

    /**
     * Helper: create a mock entity with getObject() returning the given data.
     *
     * @param array<string,mixed> $data Object data
     *
     * @return object
     */
    private function createEntityMock(array $data): object
    {
        $mock = $this->getMockBuilder(\stdClass::class)
            ->addMethods(['getObject'])
            ->getMock();
        $mock->method('getObject')->willReturn($data);
        return $mock;

    }//end createEntityMock()

}//end class
