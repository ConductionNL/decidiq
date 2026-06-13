<?php

/**
 * Unit tests for DecisionLifecycleService.
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
 * @spec openspec/specs/decision-management/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidesk\Tests\Unit\Service;

use OCA\Decidesk\Lifecycle\DecisionTransitionGuard;
use OCA\Decidesk\Service\AuditLogService;
use OCA\Decidesk\Service\DecisionLifecycleService;
use OCA\Decidesk\Service\ProcessTemplateService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for the guarded decision lifecycle transition orchestration:
 * not-found, unknown action, invalid from-state, chair fail-closed,
 * quorum gate, enact outcome gate, and the happy path with audit append.
 *
 * @spec openspec/specs/decision-management/spec.md
 */
class DecisionLifecycleServiceTest extends TestCase
{

    /**
     * Service under test.
     *
     * @var DecisionLifecycleService
     */
    private DecisionLifecycleService $service;

    /**
     * Mock DI container.
     *
     * @var ContainerInterface&MockObject
     */
    private ContainerInterface&MockObject $container;

    /**
     * Mock OpenRegister ObjectService.
     *
     * @var ObjectService&MockObject
     */
    private ObjectService&MockObject $objectService;

    /**
     * Mock audit log service.
     *
     * @var AuditLogService&MockObject
     */
    private AuditLogService&MockObject $auditLogService;

    /**
     * Set up test fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->container       = $this->createMock(ContainerInterface::class);
        $this->objectService   = $this->createMock(ObjectService::class);
        $this->auditLogService = $this->createMock(AuditLogService::class);

        $this->container->method('get')
            ->with('OCA\OpenRegister\Service\ObjectService')
            ->willReturn($this->objectService);

        // Default: no body template assigned -> resolvePolicyForBody returns null,
        // so the guard falls back to the built-in hardcoded domain policy and every
        // pre-process-config test keeps its original behaviour.
        $templateService = $this->createMock(ProcessTemplateService::class);
        $templateService->method('resolvePolicyForBody')->willReturn(null);

        $this->service = new DecisionLifecycleService(
            container: $this->container,
            logger: $this->createMock(LoggerInterface::class),
            transitionGuard: new DecisionTransitionGuard(),
            auditLogService: $this->auditLogService,
            templateService: $templateService,
        );

    }//end setUp()

    /**
     * Build an ObjectEntity mock that serializes to the given array.
     *
     * @param array<string, mixed> $data Object payload
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
     * Unknown actions are rejected before any object load.
     *
     * @spec openspec/specs/decision-management/spec.md
     *
     * @return void
     */
    public function testUnknownActionRejected(): void
    {
        $result = $this->service->transition(decisionId: 'dec-1', action: 'teleport', currentUserId: 'alice');
        self::assertFalse(condition: $result['success']);
        self::assertStringContainsString(needle: 'Unknown action', haystack: $result['message']);

    }//end testUnknownActionRejected()

    /**
     * Missing / unreadable decisions (OR RBAC) report not found.
     *
     * @spec openspec/specs/decision-management/spec.md
     *
     * @return void
     */
    public function testNotFoundDecision(): void
    {
        $this->objectService->method('find')->willReturn(null);

        $result = $this->service->transition(decisionId: 'dec-404', action: 'propose', currentUserId: 'alice');
        self::assertFalse(condition: $result['success']);
        self::assertStringContainsString(needle: 'not found', haystack: $result['message']);

    }//end testNotFoundDecision()

    /**
     * A transition whose from-set excludes the current state is rejected.
     *
     * @spec openspec/specs/decision-management/spec.md
     *
     * @return void
     */
    public function testInvalidFromStateRejected(): void
    {
        $this->objectService->method('find')
            ->willReturn($this->entity(['id' => 'dec-1', 'lifecycle' => 'draft']));

        $result = $this->service->transition(decisionId: 'dec-1', action: 'enact', currentUserId: 'alice');
        self::assertFalse(condition: $result['success']);
        self::assertStringContainsString(needle: "Cannot 'enact'", haystack: $result['message']);

    }//end testInvalidFromStateRejected()

    /**
     * Chair-only transitions FAIL CLOSED when no chair can be resolved.
     *
     * @spec openspec/specs/decision-management/spec.md
     *
     * @return void
     */
    public function testChairOnlyFailsClosedWithoutResolvableChair(): void
    {
        // legislative domain: deliberating → voting is chair-only.
        // The linked meeting has no chair → reject, never skip.
        $this->objectService->method('find')->willReturnCallback(
            function (int|string $id, ?array $_extend=[], bool $files=false, string|int|null $register=null, string|int|null $schema=null) {
                if ($schema === 'decision') {
                    return $this->entity(['id' => 'dec-1', 'lifecycle' => 'deliberating', 'domain' => 'legislative', 'meeting' => 'meet-1']);
                }

                if ($schema === 'meeting') {
                    return $this->entity(['id' => 'meet-1', 'quorumMet' => true]);
                }

                return null;
            }
        );

        $result = $this->service->transition(decisionId: 'dec-1', action: 'openVoting', currentUserId: 'alice');
        self::assertFalse(condition: $result['success']);
        self::assertStringContainsString(needle: 'chair', haystack: $result['message']);

    }//end testChairOnlyFailsClosedWithoutResolvableChair()

    /**
     * A non-chair user is rejected on a chair-only transition.
     *
     * @spec openspec/specs/decision-management/spec.md
     *
     * @return void
     */
    public function testChairOnlyRejectsNonChair(): void
    {
        $this->objectService->method('find')->willReturnCallback(
            function (int|string $id, ?array $_extend=[], bool $files=false, string|int|null $register=null, string|int|null $schema=null) {
                if ($schema === 'decision') {
                    return $this->entity(['id' => 'dec-1', 'lifecycle' => 'deliberating', 'domain' => 'legislative', 'meeting' => 'meet-1']);
                }

                if ($schema === 'meeting') {
                    return $this->entity(['id' => 'meet-1', 'quorumMet' => true, 'chair' => 'part-1']);
                }

                if ($schema === 'participant') {
                    return $this->entity(['id' => 'part-1', 'nextcloudUserId' => 'the-chair']);
                }

                return null;
            }
        );

        $result = $this->service->transition(decisionId: 'dec-1', action: 'openVoting', currentUserId: 'mallory');
        self::assertFalse(condition: $result['success']);
        self::assertStringContainsString(needle: 'chair', haystack: $result['message']);

    }//end testChairOnlyRejectsNonChair()

    /**
     * Quorum-not-met blocks opening the vote in quorum-enforced domains.
     *
     * @spec openspec/specs/decision-management/spec.md
     *
     * @return void
     */
    public function testQuorumGateBlocksOpenVoting(): void
    {
        $this->objectService->method('find')->willReturnCallback(
            function (int|string $id, ?array $_extend=[], bool $files=false, string|int|null $register=null, string|int|null $schema=null) {
                if ($schema === 'decision') {
                    return $this->entity(['id' => 'dec-1', 'lifecycle' => 'deliberating', 'domain' => 'association', 'meeting' => 'meet-1']);
                }

                if ($schema === 'meeting') {
                    return $this->entity(['id' => 'meet-1', 'quorumMet' => false, 'chair' => 'part-1']);
                }

                if ($schema === 'participant') {
                    return $this->entity(['id' => 'part-1', 'nextcloudUserId' => 'the-chair']);
                }

                return null;
            }
        );

        $result = $this->service->transition(decisionId: 'dec-1', action: 'openVoting', currentUserId: 'the-chair');
        self::assertFalse(condition: $result['success']);
        self::assertStringContainsString(needle: 'Quorum', haystack: $result['message']);

    }//end testQuorumGateBlocksOpenVoting()

    /**
     * Enacting a non-adopted decision is rejected.
     *
     * @spec openspec/specs/decision-management/spec.md
     *
     * @return void
     */
    public function testEnactRequiresAdoptedOutcome(): void
    {
        $this->objectService->method('find')
            ->willReturn($this->entity(['id' => 'dec-1', 'lifecycle' => 'decided', 'outcome' => 'rejected']));

        $result = $this->service->transition(decisionId: 'dec-1', action: 'enact', currentUserId: 'alice');
        self::assertFalse(condition: $result['success']);
        self::assertStringContainsString(needle: 'adopted', haystack: $result['message']);

    }//end testEnactRequiresAdoptedOutcome()

    /**
     * Happy path: propose persists the new lifecycle and appends the
     * hash-chained decision-transition audit entry.
     *
     * @spec openspec/specs/decision-management/spec.md
     *
     * @return void
     */
    public function testHappyProposeWithAuditAppend(): void
    {
        $this->objectService->method('find')
            ->willReturn($this->entity(['id' => 'dec-1', 'lifecycle' => 'draft', 'title' => 'Test']));

        $saved = null;
        $this->objectService->method('saveObject')->willReturnCallback(
            function (array $object, ?array $extend=[], string|int|null $register=null, string|int|null $schema=null, ?string $uuid=null) use (&$saved) {
                $saved = $object;
                return $this->entity($object);
            }
        );

        $this->auditLogService->expects(self::once())
            ->method('append')
            ->with(
                self::equalTo('alice'),
                self::equalTo('decision-transition'),
                self::equalTo(['dec-1']),
                self::callback(
                    static function (array $payload): bool {
                        return $payload['transition'] === 'propose'
                            && $payload['from'] === 'draft'
                            && $payload['to'] === 'proposed';
                    }
                )
            )
            ->willReturn(['success' => true, 'entry' => [], 'message' => 'OK']);

        $result = $this->service->transition(decisionId: 'dec-1', action: 'propose', currentUserId: 'alice', comment: 'ready');
        self::assertTrue(condition: $result['success']);
        self::assertSame(expected: 'proposed', actual: $saved['lifecycle']);
        self::assertArrayNotHasKey(key: 'enactedAt', array: $saved);

    }//end testHappyProposeWithAuditAppend()

    /**
     * Enacting an adopted decision sets enactedAt.
     *
     * @spec openspec/specs/decision-management/spec.md
     *
     * @return void
     */
    public function testEnactSetsEnactedAtAndGeneratesResolution(): void
    {
        $this->objectService->method('find')
            ->willReturn($this->entity(['id' => 'dec-1', 'lifecycle' => 'decided', 'outcome' => 'adopted', 'title' => 'Budget 2026', 'text' => 'Adopt the budget.']));

        $savedBySchema = [];
        $this->objectService->method('saveObject')->willReturnCallback(
            function (array $object, ?array $extend=[], string|int|null $register=null, string|int|null $schema=null, ?string $uuid=null) use (&$savedBySchema) {
                $savedBySchema[(string) $schema] = $object;
                return $this->entity($object);
            }
        );
        $this->auditLogService->method('append')
            ->willReturn(['success' => true, 'entry' => [], 'message' => 'OK']);

        $result = $this->service->transition(decisionId: 'dec-1', action: 'enact', currentUserId: 'alice');
        self::assertTrue(condition: $result['success']);
        self::assertSame(expected: 'enacted', actual: $savedBySchema['decision']['lifecycle']);
        self::assertNotEmpty(actual: $savedBySchema['decision']['enactedAt']);

        // Enacting generates the formal resolution record (resolution-minutes spec).
        self::assertArrayHasKey(key: 'resolution', array: $savedBySchema);
        self::assertSame(expected: 'adopted', actual: $savedBySchema['resolution']['status']);
        self::assertSame(expected: 'Budget 2026', actual: $savedBySchema['resolution']['title']);
        self::assertSame(
            expected: $savedBySchema['decision']['enactedAt'],
            actual: $savedBySchema['resolution']['effectiveDate']
        );

    }//end testEnactSetsEnactedAtAndGeneratesResolution()

    /**
     * getAvailableTransitions reports state + per-action chairOnly flags.
     *
     * @spec openspec/specs/decision-management/spec.md
     *
     * @return void
     */
    public function testGetAvailableTransitions(): void
    {
        $this->objectService->method('find')->willReturnCallback(
            function (int|string $id, ?array $_extend=[], bool $files=false, string|int|null $register=null, string|int|null $schema=null) {
                if ($schema === 'decision') {
                    return $this->entity(['id' => 'dec-1', 'lifecycle' => 'deliberating', 'domain' => 'association', 'meeting' => 'meet-1']);
                }

                if ($schema === 'meeting') {
                    return $this->entity(['id' => 'meet-1', 'quorumMet' => true]);
                }

                return null;
            }
        );

        $result = $this->service->getAvailableTransitions(decisionId: 'dec-1');
        self::assertTrue(condition: $result['success']);
        self::assertSame(expected: 'deliberating', actual: $result['lifecycle']);
        self::assertSame(expected: 'association', actual: $result['domain']);
        self::assertCount(expectedCount: 1, haystack: $result['actions']);
        self::assertSame(expected: 'openVoting', actual: $result['actions'][0]['action']);
        self::assertTrue(condition: $result['actions'][0]['chairOnly']);

    }//end testGetAvailableTransitions()

    /**
     * getAvailableTransitions reports not-found for unreadable decisions.
     *
     * @spec openspec/specs/decision-management/spec.md
     *
     * @return void
     */
    public function testGetAvailableTransitionsNotFound(): void
    {
        $this->objectService->method('find')->willReturn(null);

        $result = $this->service->getAvailableTransitions(decisionId: 'dec-404');
        self::assertFalse(condition: $result['success']);
        self::assertNull(actual: $result['lifecycle']);

    }//end testGetAvailableTransitionsNotFound()
}//end class
