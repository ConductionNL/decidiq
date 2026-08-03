<?php
/**
 * Unit tests for GovernanceScopeGuard — OR-projected signatory/chair scope
 * consumer (consume-or-rbac-authorization). Proves the migrated signing
 * authorization still allows signatories and blocks non-signatories, and fails
 * closed on any ambiguity.
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
 * @spec openspec/changes/consume-or-rbac-authorization/specs/authorization-via-or-rbac/spec.md#requirement-req-rbac-002-signatory-authorization-is-an-openregister-rbac-rule-not-an-app-local-service
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidesk\Tests\Unit\Service;

use OCA\Decidesk\Service\GovernanceScopeGuard;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IGroupManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for GovernanceScopeGuard.
 *
 * @spec openspec/changes/consume-or-rbac-authorization/specs/authorization-via-or-rbac/spec.md#requirement-req-rbac-002-signatory-authorization-is-an-openregister-rbac-rule-not-an-app-local-service
 */
class GovernanceScopeGuardTest extends TestCase
{

    /**
     * The scope group id follows the canonical per-body convention.
     *
     * @return void
     */
    public function testScopeGroupIdConvention(): void
    {
        $guard = new GovernanceScopeGuard(
            $this->createMock(ContainerInterface::class),
            $this->createMock(IGroupManager::class),
            $this->createMock(LoggerInterface::class)
        );

        $this->assertSame(
            'decidesk:body:body-1:signatory',
            $guard->scopeGroupId('body-1', GovernanceScopeGuard::SCOPE_SIGNATORY)
        );
        $this->assertSame(
            'decidesk:body:body-1:chair',
            $guard->scopeGroupId('body-1', GovernanceScopeGuard::SCOPE_CHAIR)
        );
    }//end testScopeGroupIdConvention()

    /**
     * isInBodyScope delegates to the NC group manager for the canonical group.
     *
     * @return void
     */
    public function testIsInBodyScopeConsultsProjectedGroup(): void
    {
        $groupManager = $this->createMock(IGroupManager::class);
        $groupManager->expects($this->once())
            ->method('isInGroup')
            ->with('alice', 'decidesk:body:body-1:signatory')
            ->willReturn(true);

        $guard = new GovernanceScopeGuard(
            $this->createMock(ContainerInterface::class),
            $groupManager,
            $this->createMock(LoggerInterface::class)
        );

        $this->assertTrue($guard->isInBodyScope('alice', 'body-1', GovernanceScopeGuard::SCOPE_SIGNATORY));
    }//end testIsInBodyScopeConsultsProjectedGroup()

    /**
     * isInBodyScope fails closed on empty user or empty body (never consults).
     *
     * @return void
     */
    public function testIsInBodyScopeFailsClosedOnEmptyArgs(): void
    {
        $groupManager = $this->createMock(IGroupManager::class);
        $groupManager->expects($this->never())->method('isInGroup');

        $guard = new GovernanceScopeGuard(
            $this->createMock(ContainerInterface::class),
            $groupManager,
            $this->createMock(LoggerInterface::class)
        );

        $this->assertFalse($guard->isInBodyScope('', 'body-1', GovernanceScopeGuard::SCOPE_CHAIR));
        $this->assertFalse($guard->isInBodyScope('alice', '', GovernanceScopeGuard::SCOPE_CHAIR));
    }//end testIsInBodyScopeFailsClosedOnEmptyArgs()

    /**
     * A signatory (member of the body's signatory scope) may initiate signing.
     *
     * @return void
     */
    public function testCanInitiateSigningAllowsSignatory(): void
    {
        $groupManager = $this->createMock(IGroupManager::class);
        $groupManager->method('isInGroup')
            ->with('alice', 'decidesk:body:body-9:signatory')
            ->willReturn(true);

        $guard = new GovernanceScopeGuard(
            $this->makeContainer('body-9'),
            $groupManager,
            $this->createMock(LoggerInterface::class)
        );

        $this->assertTrue($guard->canInitiateSigning('alice', 'min-1'));
    }//end testCanInitiateSigningAllowsSignatory()

    /**
     * A non-signatory is denied — OR-projected scope returns no membership.
     *
     * @return void
     */
    public function testCanInitiateSigningDeniesNonSignatory(): void
    {
        $groupManager = $this->createMock(IGroupManager::class);
        $groupManager->method('isInGroup')->willReturn(false);

        $guard = new GovernanceScopeGuard(
            $this->makeContainer('body-9'),
            $groupManager,
            $this->createMock(LoggerInterface::class)
        );

        $this->assertFalse($guard->canInitiateSigning('mallory', 'min-1'));
    }//end testCanInitiateSigningDeniesNonSignatory()

    /**
     * Fail-closed: when the owning body cannot be resolved (missing Meeting or
     * body relation) signing is denied and the scope is never consulted.
     *
     * @return void
     */
    public function testCanInitiateSigningFailsClosedWhenBodyUnresolved(): void
    {
        $groupManager = $this->createMock(IGroupManager::class);
        $groupManager->expects($this->never())->method('isInGroup');

        // Minutes with no Meeting relation -> body cannot be resolved.
        $guard = new GovernanceScopeGuard(
            $this->makeContainer(null),
            $groupManager,
            $this->createMock(LoggerInterface::class)
        );

        $this->assertFalse($guard->canInitiateSigning('alice', 'min-1'));
    }//end testCanInitiateSigningFailsClosedWhenBodyUnresolved()

    /**
     * Fail-closed: an OpenRegister error while resolving denies (no fail-open),
     * and it is logged rather than treated as "check skipped".
     *
     * @return void
     */
    public function testCanInitiateSigningFailsClosedOnException(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning');

        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')->willThrowException(new \RuntimeException('OR unavailable'));

        $guard = new GovernanceScopeGuard(
            $container,
            $this->createMock(IGroupManager::class),
            $logger
        );

        $this->assertFalse($guard->canInitiateSigning('alice', 'min-1'));
    }//end testCanInitiateSigningFailsClosedOnException()

    /**
     * Build a container whose ObjectService resolves Minutes -> Meeting ->
     * GovernanceBody to the given body id (or leaves it unresolvable when null).
     *
     * @param string|null $bodyId GovernanceBody UUID to resolve, or null for unresolvable
     *
     * @return ContainerInterface
     */
    private function makeContainer(?string $bodyId): ContainerInterface
    {
        $minutesRow = ['id' => 'min-1', 'relations' => ['Meeting' => 'meet-1']];
        $meetingRow = ['id' => 'meet-1'];
        if ($bodyId !== null) {
            $meetingRow['relations'] = ['GovernanceBody' => $bodyId];
        }

        $objectService = $this->createMock(ObjectService::class);
        $objectService->method('find')->willReturnCallback(
            function (mixed $id, mixed $register=null, mixed $schema=null) use ($minutesRow, $meetingRow): ?ObjectEntity {
                $row = null;
                if ((string) $id === 'min-1') {
                    $row = $minutesRow;
                } else if ((string) $id === 'meet-1') {
                    $row = $meetingRow;
                }

                if ($row === null) {
                    return null;
                }

                $entity = $this->createMock(ObjectEntity::class);
                $entity->method('jsonSerialize')->willReturn($row);
                return $entity;
            }
        );

        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')->willReturn($objectService);
        return $container;
    }//end makeContainer()
}//end class
