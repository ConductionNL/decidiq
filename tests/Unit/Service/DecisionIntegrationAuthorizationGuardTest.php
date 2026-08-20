<?php

/**
 * Unit tests for DecisionIntegrationAuthorizationGuard.
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
 * @spec openspec/changes/signature-and-outcome-authorization-guard/specs/signature-and-outcome-authorization/spec.md#requirement-req-dcdh-101-only-the-raising-consumer-an-admin-or-any-caller-of-a-published-decision-may-read-an-outcome-envelope
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidesk\Tests\Unit\Service;

use OCA\Decidesk\Service\DecisionIntegrationAuthorizationGuard;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Allow/deny matrix for the two contract-decision-hub authorization rules:
 * the outcome READ (REQ-DCDH-101) and the callback WRITE (REQ-DCDH-102).
 *
 * The two rules part company on exactly one arm — a PUBLISHED Decision is
 * readable by anyone and writable by nobody but its owner — and that
 * divergence is asserted explicitly below, because collapsing it would let an
 * admin's act of widening READ silently widen WRITE.
 *
 * @spec openspec/changes/signature-and-outcome-authorization-guard/specs/signature-and-outcome-authorization/spec.md#requirement-req-dcdh-101-only-the-raising-consumer-an-admin-or-any-caller-of-a-published-decision-may-read-an-outcome-envelope
 */
class DecisionIntegrationAuthorizationGuardTest extends TestCase {

	/**
	 * Guard under test.
	 *
	 * @var DecisionIntegrationAuthorizationGuard
	 */
	private DecisionIntegrationAuthorizationGuard $guard;

	/**
	 * Mock DI container.
	 *
	 * @var ContainerInterface&MockObject
	 */
	private ContainerInterface&MockObject $container;

	/**
	 * Mock OpenRegister ObjectService.
	 *
	 * @var ObjectServiceInterface&MockObject
	 */
	private ObjectServiceInterface&MockObject $objectService;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->container = $this->createMock(ContainerInterface::class);
		$this->objectService = $this->createMock(ObjectServiceInterface::class);

		$this->container->method('get')
			->willReturnCallback(function (string $id) {
				if ($id === 'OCA\\OpenRegister\\Service\\ObjectService') {
					return $this->objectService;
				}

				throw new \RuntimeException("Service not found: {$id}");
			});

		$this->guard = new DecisionIntegrationAuthorizationGuard(
			container: $this->container,
			logger: $this->createMock(LoggerInterface::class),
		);

	}//end setUp()

	/**
	 * Build an ObjectEntity double that serializes to the given array.
	 *
	 * Must be an ObjectEntity double, not a stdClass one: ObjectService::find()
	 * is typed `?ObjectEntity` in production, so a stdClass mock is a value the
	 * guard can never be handed (#399).
	 *
	 * @param array<string, mixed> $data Object payload
	 *
	 * @return ObjectEntity&MockObject
	 */
	private function entity(array $data): ObjectEntity&MockObject {
		$entity = $this->createMock(ObjectEntity::class);
		$entity->method('jsonSerialize')->willReturn($data);
		return $entity;
	}//end entity()

	// ─── isAuthorizedToReadOutcome (REQ-DCDH-101) ────────────────────────────

	/**
	 * ALLOW: the caller is the Decision's OpenRegister owner — the identity that
	 * raised it through POST /api/v1/decisions. This is the consumer
	 * REQ-DCDH-003 exists to serve, so the guard must let it through.
	 *
	 * @spec openspec/changes/signature-and-outcome-authorization-guard/specs/signature-and-outcome-authorization/spec.md#requirement-req-dcdh-101-only-the-raising-consumer-an-admin-or-any-caller-of-a-published-decision-may-read-an-outcome-envelope
	 *
	 * @return void
	 */
	public function testOutcomeReadAllowsTheRaisingOwner(): void {
		$this->objectService->method('find')->willReturn(
			$this->entity([
				'id' => 'dec-1',
				'isPublished' => 'internal',
				'@self' => ['owner' => 'svc-shillinq'],
			])
		);

		self::assertTrue(
			$this->guard->isAuthorizedToReadOutcome(decisionId: 'dec-1', callerUid: 'svc-shillinq')
		);

	}//end testOutcomeReadAllowsTheRaisingOwner()

	/**
	 * ALLOW: a published Decision (isPublished=public) is a public governance
	 * record, readable by any authenticated caller regardless of who raised it.
	 *
	 * @spec openspec/changes/signature-and-outcome-authorization-guard/specs/signature-and-outcome-authorization/spec.md#requirement-req-dcdh-101-only-the-raising-consumer-an-admin-or-any-caller-of-a-published-decision-may-read-an-outcome-envelope
	 *
	 * @return void
	 */
	public function testOutcomeReadAllowsAnyCallerOnAPublishedDecision(): void {
		$this->objectService->method('find')->willReturn(
			$this->entity([
				'id' => 'dec-1',
				'isPublished' => 'public',
				'@self' => ['owner' => 'svc-shillinq'],
			])
		);

		self::assertTrue(
			$this->guard->isAuthorizedToReadOutcome(decisionId: 'dec-1', callerUid: 'mallory')
		);

	}//end testOutcomeReadAllowsAnyCallerOnAPublishedDecision()

	/**
	 * DENY: an authenticated caller who neither raised the Decision nor is
	 * looking at a published one. This is the IDOR the guard exists to close —
	 * the envelope discloses the cross-app subject coordinates, the consumer's
	 * externalReference and the signers.
	 *
	 * @spec openspec/changes/signature-and-outcome-authorization-guard/specs/signature-and-outcome-authorization/spec.md#requirement-req-dcdh-101-only-the-raising-consumer-an-admin-or-any-caller-of-a-published-decision-may-read-an-outcome-envelope
	 *
	 * @return void
	 */
	public function testOutcomeReadDeniesAnUnrelatedCaller(): void {
		$this->objectService->method('find')->willReturn(
			$this->entity([
				'id' => 'dec-1',
				'isPublished' => 'internal',
				'@self' => ['owner' => 'svc-shillinq'],
			])
		);

		self::assertFalse(
			$this->guard->isAuthorizedToReadOutcome(decisionId: 'dec-1', callerUid: 'mallory')
		);

	}//end testOutcomeReadDeniesAnUnrelatedCaller()

	/**
	 * A Decision that does not exist is ALLOWED through the guard so
	 * getOutcomeEnvelope() still answers 404. A 403 here would turn the guard
	 * into an existence oracle for UUIDs the app never issued.
	 *
	 * @spec openspec/changes/signature-and-outcome-authorization-guard/specs/signature-and-outcome-authorization/spec.md#requirement-req-dcdh-101-only-the-raising-consumer-an-admin-or-any-caller-of-a-published-decision-may-read-an-outcome-envelope
	 *
	 * @return void
	 */
	public function testOutcomeReadLetsAMissFallThroughToNotFound(): void {
		$this->objectService->method('find')->willReturn(null);

		self::assertTrue(
			$this->guard->isAuthorizedToReadOutcome(decisionId: 'dec-404', callerUid: 'mallory')
		);

	}//end testOutcomeReadLetsAMissFallThroughToNotFound()

	/**
	 * FAIL-CLOSED: when the Decision cannot be resolved (OpenRegister throwing,
	 * the app unavailable), the guard DENIES. A resolver that answers "allow" on
	 * its own failure is the gate-8 unsafe-auth-resolver defect.
	 *
	 * @spec openspec/changes/signature-and-outcome-authorization-guard/specs/signature-and-outcome-authorization/spec.md#requirement-req-dcdh-101-only-the-raising-consumer-an-admin-or-any-caller-of-a-published-decision-may-read-an-outcome-envelope
	 *
	 * @return void
	 */
	public function testOutcomeReadFailsClosedWhenResolutionThrows(): void {
		$this->objectService->method('find')
			->willThrowException(new \RuntimeException('OpenRegister unavailable'));

		self::assertFalse(
			$this->guard->isAuthorizedToReadOutcome(decisionId: 'dec-1', callerUid: 'svc-shillinq')
		);

	}//end testOutcomeReadFailsClosedWhenResolutionThrows()

	/**
	 * An empty caller uid never authorizes — an unowned object ('' owner) must
	 * not match an unidentified caller.
	 *
	 * @spec openspec/changes/signature-and-outcome-authorization-guard/specs/signature-and-outcome-authorization/spec.md#requirement-req-dcdh-101-only-the-raising-consumer-an-admin-or-any-caller-of-a-published-decision-may-read-an-outcome-envelope
	 *
	 * @return void
	 */
	public function testOutcomeReadDeniesAnEmptyCallerUid(): void {
		self::assertFalse(
			$this->guard->isAuthorizedToReadOutcome(decisionId: 'dec-1', callerUid: '')
		);

	}//end testOutcomeReadDeniesAnEmptyCallerUid()

	// ─── isAuthorizedToSubscribe (REQ-DCDH-102) ──────────────────────────────

	/**
	 * ALLOW: the caller is the Decision's OpenRegister owner — the consumer that
	 * raised it through POST /api/v1/decisions and is now attaching its own
	 * delivery target. The guard must not close the endpoint's only real use
	 * case (ADR-044).
	 *
	 * @spec openspec/changes/signature-and-outcome-authorization-guard/specs/signature-and-outcome-authorization/spec.md#requirement-req-dcdh-102-only-the-raising-consumer-or-an-admin-may-attach-an-outcome-callback-to-a-decision
	 *
	 * @return void
	 */
	public function testSubscribeGuardAllowsTheRaisingOwner(): void {
		$this->objectService->method('find')->willReturn(
			$this->entity([
				'id' => 'dec-1',
				'isPublished' => 'internal',
				'@self' => ['owner' => 'svc-shillinq'],
			])
		);

		self::assertTrue(
			$this->guard->isAuthorizedToSubscribe(decisionId: 'dec-1', callerUid: 'svc-shillinq')
		);

	}//end testSubscribeGuardAllowsTheRaisingOwner()

	/**
	 * DENY: an authenticated caller who did not raise the Decision. This is the
	 * IDOR the guard exists to close — registerOutcomeCallback() OVERWRITES the
	 * single `outcomeCallbackUrl` scalar, so an unrelated caller both redirects
	 * the outcome envelope to a consumer of its choosing and denies the
	 * legitimate consumer its callback.
	 *
	 * @spec openspec/changes/signature-and-outcome-authorization-guard/specs/signature-and-outcome-authorization/spec.md#requirement-req-dcdh-102-only-the-raising-consumer-or-an-admin-may-attach-an-outcome-callback-to-a-decision
	 *
	 * @return void
	 */
	public function testSubscribeGuardDeniesAnUnrelatedCaller(): void {
		$this->objectService->method('find')->willReturn(
			$this->entity([
				'id' => 'dec-1',
				'isPublished' => 'internal',
				'@self' => ['owner' => 'svc-shillinq'],
			])
		);

		self::assertFalse(
			$this->guard->isAuthorizedToSubscribe(decisionId: 'dec-1', callerUid: 'mallory')
		);

	}//end testSubscribeGuardDeniesAnUnrelatedCaller()

	/**
	 * DENY, and this is the arm where the WRITE rule deliberately parts company
	 * with the READ rule: on a PUBLISHED Decision an unrelated caller may read
	 * the envelope (testOutcomeReadAllowsAnyCallerOnAPublishedDecision) but may
	 * NOT attach a callback.
	 *
	 * `isPublished` is an admin-set read-visibility enum — only
	 * DecisionController::publish(), an #[AuthorizedAdminSetting] endpoint,
	 * moves it to 'public', and OriController reads it to gate the anonymous
	 * feed. Honouring it here would mean an admin publishing a decision also
	 * opens its delivery target to every authenticated user: widening READ
	 * would silently widen WRITE.
	 *
	 * @spec openspec/changes/signature-and-outcome-authorization-guard/specs/signature-and-outcome-authorization/spec.md#requirement-req-dcdh-102-only-the-raising-consumer-or-an-admin-may-attach-an-outcome-callback-to-a-decision
	 *
	 * @return void
	 */
	public function testSubscribeGuardDeniesAnUnrelatedCallerOnAPublishedDecision(): void {
		$this->objectService->method('find')->willReturn(
			$this->entity([
				'id' => 'dec-1',
				'isPublished' => 'public',
				'@self' => ['owner' => 'svc-shillinq'],
			])
		);

		self::assertFalse(
			$this->guard->isAuthorizedToSubscribe(decisionId: 'dec-1', callerUid: 'mallory')
		);

	}//end testSubscribeGuardDeniesAnUnrelatedCallerOnAPublishedDecision()

	/**
	 * A Decision that does not exist is ALLOWED through the guard so
	 * registerOutcomeCallback() still answers not_found (404). A 403 here would
	 * turn the guard into an existence oracle for UUIDs the app never issued.
	 *
	 * @spec openspec/changes/signature-and-outcome-authorization-guard/specs/signature-and-outcome-authorization/spec.md#requirement-req-dcdh-102-only-the-raising-consumer-or-an-admin-may-attach-an-outcome-callback-to-a-decision
	 *
	 * @return void
	 */
	public function testSubscribeGuardLetsAMissFallThroughToNotFound(): void {
		$this->objectService->method('find')->willReturn(null);

		self::assertTrue(
			$this->guard->isAuthorizedToSubscribe(decisionId: 'dec-404', callerUid: 'mallory')
		);

	}//end testSubscribeGuardLetsAMissFallThroughToNotFound()

	/**
	 * FAIL-CLOSED: when the Decision cannot be resolved, the write guard DENIES.
	 * A resolver that answers "allow" on its own failure is the gate-8
	 * unsafe-auth-resolver defect.
	 *
	 * @spec openspec/changes/signature-and-outcome-authorization-guard/specs/signature-and-outcome-authorization/spec.md#requirement-req-dcdh-102-only-the-raising-consumer-or-an-admin-may-attach-an-outcome-callback-to-a-decision
	 *
	 * @return void
	 */
	public function testSubscribeGuardFailsClosedWhenResolutionThrows(): void {
		$this->objectService->method('find')
			->willThrowException(new \RuntimeException('OpenRegister unavailable'));

		self::assertFalse(
			$this->guard->isAuthorizedToSubscribe(decisionId: 'dec-1', callerUid: 'svc-shillinq')
		);

	}//end testSubscribeGuardFailsClosedWhenResolutionThrows()

	/**
	 * An empty caller uid never authorizes a write — an unowned object ('' owner)
	 * must not match an unidentified caller.
	 *
	 * @spec openspec/changes/signature-and-outcome-authorization-guard/specs/signature-and-outcome-authorization/spec.md#requirement-req-dcdh-102-only-the-raising-consumer-or-an-admin-may-attach-an-outcome-callback-to-a-decision
	 *
	 * @return void
	 */
	public function testSubscribeGuardDeniesAnEmptyCallerUid(): void {
		self::assertFalse(
			$this->guard->isAuthorizedToSubscribe(decisionId: 'dec-1', callerUid: '')
		);

	}//end testSubscribeGuardDeniesAnEmptyCallerUid()
}//end class
