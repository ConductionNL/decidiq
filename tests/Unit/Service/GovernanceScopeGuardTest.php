<?php

/**
 * Unit tests for GovernanceScopeGuard — OR-projected signatory/chair scope
 * consumer (consume-or-rbac-authorization). Proves the migrated signing
 * authorization still allows signatories and blocks non-signatories, and fails
 * closed on any ambiguity.
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\Service
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

namespace OCA\Decidiq\Tests\Unit\Service;

use OCA\Decidiq\Service\GovernanceScopeGuard;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\IGroupManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for GovernanceScopeGuard.
 *
 * @spec openspec/changes/consume-or-rbac-authorization/specs/authorization-via-or-rbac/spec.md#requirement-req-rbac-002-signatory-authorization-is-an-openregister-rbac-rule-not-an-app-local-service
 */
class GovernanceScopeGuardTest extends TestCase {

	/**
	 * The scope group id follows the canonical per-body convention.
	 *
	 * @return void
	 */
	public function testScopeGroupIdConvention(): void {
		$guard = new GovernanceScopeGuard(
			$this->createMock(IGroupManager::class),
			$this->createMock(LoggerInterface::class),
			objectService: $this->createMock(ObjectServiceInterface::class),
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
	public function testIsInBodyScopeConsultsProjectedGroup(): void {
		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->expects($this->once())
			->method('isInGroup')
			->with('alice', 'decidesk:body:body-1:signatory')
			->willReturn(true);

		$guard = new GovernanceScopeGuard(
			$groupManager,
			$this->createMock(LoggerInterface::class),
			objectService: $this->createMock(ObjectServiceInterface::class),
		);

		$this->assertTrue($guard->isInBodyScope('alice', 'body-1', GovernanceScopeGuard::SCOPE_SIGNATORY));
	}//end testIsInBodyScopeConsultsProjectedGroup()

	/**
	 * isInBodyScope fails closed on empty user or empty body (never consults).
	 *
	 * @return void
	 */
	public function testIsInBodyScopeFailsClosedOnEmptyArgs(): void {
		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->expects($this->never())->method('isInGroup');

		$guard = new GovernanceScopeGuard(
			$groupManager,
			$this->createMock(LoggerInterface::class),
			objectService: $this->createMock(ObjectServiceInterface::class),
		);

		$this->assertFalse($guard->isInBodyScope('', 'body-1', GovernanceScopeGuard::SCOPE_CHAIR));
		$this->assertFalse($guard->isInBodyScope('alice', '', GovernanceScopeGuard::SCOPE_CHAIR));
	}//end testIsInBodyScopeFailsClosedOnEmptyArgs()

	/**
	 * A signatory (member of the body's signatory scope) may initiate signing.
	 *
	 * @return void
	 */
	public function testCanInitiateSigningAllowsSignatory(): void {
		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('isInGroup')
			->with('alice', 'decidesk:body:body-9:signatory')
			->willReturn(true);

		$guard = new GovernanceScopeGuard(
			$groupManager,
			$this->createMock(LoggerInterface::class),
			objectService: $this->makeObjectService('body-9'),
		);

		$this->assertTrue($guard->canInitiateSigning('alice', 'min-1'));
	}//end testCanInitiateSigningAllowsSignatory()

	/**
	 * A non-signatory is denied — OR-projected scope returns no membership.
	 *
	 * @return void
	 */
	public function testCanInitiateSigningDeniesNonSignatory(): void {
		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('isInGroup')->willReturn(false);

		$guard = new GovernanceScopeGuard(
			$groupManager,
			$this->createMock(LoggerInterface::class),
			objectService: $this->makeObjectService('body-9'),
		);

		$this->assertFalse($guard->canInitiateSigning('mallory', 'min-1'));
	}//end testCanInitiateSigningDeniesNonSignatory()

	/**
	 * Fail-closed: when the owning body cannot be resolved (missing Meeting or
	 * body relation) signing is denied and the scope is never consulted.
	 *
	 * @return void
	 */
	public function testCanInitiateSigningFailsClosedWhenBodyUnresolved(): void {
		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->expects($this->never())->method('isInGroup');

		// Meeting with no GovernanceBody relation -> body cannot be resolved.
		$guard = new GovernanceScopeGuard(
			$groupManager,
			$this->createMock(LoggerInterface::class),
			objectService: $this->makeObjectService(null),
		);

		$this->assertFalse($guard->canInitiateSigning('alice', 'min-1'));
	}//end testCanInitiateSigningFailsClosedWhenBodyUnresolved()

	/**
	 * Fail-closed: an OpenRegister error while resolving denies (no fail-open),
	 * and it is logged rather than treated as "check skipped".
	 *
	 * @return void
	 */
	public function testCanInitiateSigningFailsClosedOnException(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning');

		$objectService = $this->createMock(ObjectServiceInterface::class);
		$objectService->method('find')->willThrowException(new \RuntimeException('OR unavailable'));

		$guard = new GovernanceScopeGuard(
			$this->createMock(IGroupManager::class),
			$logger,
			objectService: $objectService,
		);

		$this->assertFalse($guard->canInitiateSigning('alice', 'min-1'));
	}//end testCanInitiateSigningFailsClosedOnException()

	/**
	 * REQ-SIG-101/102: `isSignatoryForMinutes()` — the determination `verify()`
	 * and `finalize()` now consult directly — allows a member of the body's
	 * signatory scope and denies everyone else, on the SAME minutes.
	 *
	 * Both directions in one test on one fixture: a guard proven only in the
	 * deny direction cannot distinguish "correctly refuses an outsider" from
	 * "refuses everyone", which would silently break the signing flow.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/signature-and-outcome-authorization-guard/specs/signature-and-outcome-authorization/spec.md#requirement-req-sig-101-only-a-body-signatory-may-finalize-signed-minutes
	 */
	public function testIsSignatoryForMinutesAllowsSignatoryAndDeniesOthers(): void {
		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('isInGroup')
			->willReturnCallback(
				static function (string $uid, string $group): bool {
					return ($uid === 'alice' && $group === 'decidesk:body:body-9:signatory');
				}
			);

		$guard = new GovernanceScopeGuard(
			$groupManager,
			$this->createMock(LoggerInterface::class),
			objectService: $this->makeObjectService('body-9'),
		);

		$this->assertTrue($guard->isSignatoryForMinutes('alice', 'min-1'));
		$this->assertFalse($guard->isSignatoryForMinutes('mallory', 'min-1'));
		// Empty arguments fail closed rather than resolving to "everyone".
		$this->assertFalse($guard->isSignatoryForMinutes('alice', ''));
		$this->assertFalse($guard->isSignatoryForMinutes('', 'min-1'));
	}//end testIsSignatoryForMinutesAllowsSignatoryAndDeniesOthers()

	/**
	 * Build an ObjectService double that resolves Minutes -> Meeting ->
	 * GovernanceBody to the given body id (or leaves it unresolvable when null).
	 *
	 * @param string|null $bodyId GovernanceBody UUID to resolve, or null for unresolvable
	 *
	 * @return ObjectServiceInterface
	 */
	private function makeObjectService(?string $bodyId): ObjectServiceInterface {
		$minutesRow = ['id' => 'min-1', 'relations' => ['Meeting' => 'meet-1']];
		$meetingRow = ['id' => 'meet-1'];
		if ($bodyId !== null) {
			$meetingRow['relations'] = ['GovernanceBody' => $bodyId];
		}

		$objectService = $this->createMock(ObjectServiceInterface::class);
		$objectService->method('find')->willReturnCallback(
			function (mixed $id, mixed $register = null, mixed $schema = null) use ($minutesRow, $meetingRow): ?ObjectEntity {
				$row = null;
				if ((string)$id === 'min-1') {
					$row = $minutesRow;
				} elseif ((string)$id === 'meet-1') {
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

		return $objectService;
	}//end makeObjectService()
}//end class
