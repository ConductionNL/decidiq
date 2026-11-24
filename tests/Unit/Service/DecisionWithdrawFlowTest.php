<?php

/**
 * Unit tests for DecisionWithdrawFlow.
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
 * @spec openspec/specs/decision-management/spec.md
 * @spec openspec/changes/the-decision-as-a-walked-process/specs/decision-as-a-walked-process/spec.md (REQ-DWP-005)
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service;

use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use OCA\Decidiq\Lifecycle\DecisionTransitionGuard;
use OCA\Decidiq\Service\AuditLogService;
use OCA\Decidiq\Service\DecisionIntegrationService;
use OCA\Decidiq\Service\DecisionLifecycleService;
use OCA\Decidiq\Service\DecisionWithdrawFlow;
use OCA\Decidiq\Service\ProcessTemplateService;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\EventDispatcher\IEventDispatcher;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for withdrawing a decision (#1380, REQ-DWP-005): the actor kind is
 * required, the `lifecycle → withdrawn` edge must be declared, the chair-only
 * gate fails closed, the outcome is kept and the withdrawal is persisted with
 * the shared audit append.
 *
 * The flow runs against a real DecisionLifecycleService (with mocked
 * collaborators) so the shared chair gate and post-transition effects are
 * exercised, not stubbed.
 *
 * @spec openspec/specs/decision-management/spec.md
 */
class DecisionWithdrawFlowTest extends TestCase {

	/**
	 * Flow under test.
	 *
	 * @var DecisionWithdrawFlow
	 */
	private DecisionWithdrawFlow $flow;

	/**
	 * Mock OpenRegister ObjectService.
	 *
	 * @var ObjectServiceInterface&MockObject
	 */
	private ObjectServiceInterface&MockObject $objectService;

	/**
	 * Mock audit log service.
	 *
	 * @var AuditLogService&MockObject
	 */
	private AuditLogService&MockObject $auditLogService;

	/**
	 * Process-template policy handed back for any body (null = built-in policy).
	 *
	 * @var array<string, mixed>|null
	 */
	private ?array $policyOverride = null;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->objectService = $this->createMock(originalClassName: ObjectServiceInterface::class);
		$this->auditLogService = $this->createMock(originalClassName: AuditLogService::class);

		$templateService = $this->createMock(originalClassName: ProcessTemplateService::class);
		$templateService->method('resolvePolicyForBody')->willReturnCallback(
			fn (): ?array => $this->policyOverride
		);

		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		$transitionGuard = new DecisionTransitionGuard();

		$lifecycleService = new DecisionLifecycleService(
			logger: $logger,
			transitionGuard: $transitionGuard,
			auditLogService: $this->auditLogService,
			templateService: $templateService,
			integrationService: $this->createMock(originalClassName: DecisionIntegrationService::class),
			eventDispatcher: $this->createMock(originalClassName: IEventDispatcher::class),
			objectService: $this->objectService,
		);

		$this->flow = new DecisionWithdrawFlow(
			logger: $logger,
			transitionGuard: $transitionGuard,
			lifecycleService: $lifecycleService,
			objectService: $this->objectService,
		);

	}//end setUp()

	/**
	 * Build an ObjectEntity mock that serializes to the given array.
	 *
	 * @param array<string, mixed> $data Object payload
	 *
	 * @return ObjectEntity&MockObject
	 */
	private function entity(array $data): ObjectEntity&MockObject {
		$entity = $this->createMock(originalClassName: ObjectEntity::class);
		$entity->method('jsonSerialize')->willReturn($data);
		return $entity;
	}//end entity()

	/**
	 * Wire `saveObject()` to record the payload it was handed.
	 *
	 * @return object A holder whose `value` is the saved payload, or null when nothing was written
	 */
	private function captureSaves(): object {
		$holder = new class {
			/**
			 * The payload handed to saveObject(), or null when never called.
			 *
			 * @var array<string, mixed>|null
			 */
			public ?array $value = null;
		};

		$this->objectService->method('saveObject')->willReturnCallback(
			function (array $object, ObjectSaveOptions $options = new ObjectSaveOptions()) use ($holder) {
				$holder->value = $object;
				return $this->entity(data: $object);
			}
		);
		$this->auditLogService->method('append')->willReturn(['success' => true, 'entry' => [], 'message' => 'OK']);

		return $holder;
	}//end captureSaves()

	/**
	 * A decision in deliberation is withdrawn: it reaches `withdrawn`, says
	 * who withdrew it and why, and acquires no outcome (#1380).
	 *
	 * @spec openspec/specs/decision-management/spec.md
	 *
	 * @return void
	 */
	public function testWithdrawMovesADecisionInFlightToWithdrawn(): void {
		$this->objectService->method('find')
			->willReturn($this->entity(data: ['id' => 'dec-1', 'lifecycle' => 'deliberating', 'title' => 'Motie']));

		$saved = $this->captureSaves();

		$result = $this->flow->withdraw(
			decisionId: 'dec-1',
			withdrawnBy: 'belanghebbende',
			reason: 'Voorstel ingetrokken door indiener',
			currentUserId: 'alice'
		);

		self::assertTrue(condition: $result['success'], message: $result['message']);
		self::assertIsArray(actual: $saved->value);
		self::assertSame(expected: 'withdrawn', actual: $saved->value['lifecycle']);
		self::assertTrue(condition: $saved->value['withdrawn']);
		self::assertSame(expected: 'belanghebbende', actual: $saved->value['withdrawnBy']);
		self::assertSame(expected: 'Voorstel ingetrokken door indiener', actual: $saved->value['withdrawnReason']);
		self::assertNotSame(expected: '', actual: $saved->value['withdrawnAt']);
		self::assertArrayNotHasKey(key: 'outcome', array: $saved->value);
		self::assertArrayNotHasKey(key: 'history', array: $saved->value);

	}//end testWithdrawMovesADecisionInFlightToWithdrawn()

	/**
	 * A decided decision keeps its outcome when it is withdrawn (REQ-DWP-005).
	 *
	 * @spec openspec/specs/decision-management/spec.md
	 *
	 * @return void
	 */
	public function testWithdrawKeepsTheOutcomeOfATakenDecision(): void {
		$this->objectService->method('find')->willReturn(
			$this->entity(
				data: [
					'id' => 'dec-1',
					'lifecycle' => 'decided',
					'outcome' => 'adopted',
					'decisionDate' => '2026-04-10T21:00:00Z',
				]
			)
		);

		$saved = $this->captureSaves();

		$result = $this->flow->withdraw(decisionId: 'dec-1', withdrawnBy: 'bestuursorgaan', currentUserId: 'alice');

		self::assertTrue(condition: $result['success'], message: $result['message']);
		self::assertSame(expected: 'withdrawn', actual: $saved->value['lifecycle']);
		self::assertSame(expected: 'adopted', actual: $saved->value['outcome']);

	}//end testWithdrawKeepsTheOutcomeOfATakenDecision()

	/**
	 * An enacted decision has no `enacted → withdrawn` edge, so it is refused
	 * before anything is written.
	 *
	 * @spec openspec/specs/decision-management/spec.md
	 *
	 * @return void
	 */
	public function testWithdrawRefusesAnEnactedDecision(): void {
		$this->objectService->method('find')
			->willReturn($this->entity(data: ['id' => 'dec-1', 'lifecycle' => 'enacted', 'outcome' => 'adopted']));

		$saved = $this->captureSaves();

		$result = $this->flow->withdraw(decisionId: 'dec-1', withdrawnBy: 'bestuursorgaan', currentUserId: 'alice');

		self::assertFalse(condition: $result['success']);
		self::assertNull(actual: $saved->value);
		self::assertStringContainsString(needle: 'cannot be withdrawn', haystack: $result['message']);

	}//end testWithdrawRefusesAnEnactedDecision()

	/**
	 * A withdrawal that does not say who withdrew it is refused (REQ-DWP-005).
	 *
	 * @spec openspec/specs/decision-management/spec.md
	 *
	 * @return void
	 */
	public function testWithdrawRequiresAnActorKind(): void {
		$saved = $this->captureSaves();

		$result = $this->flow->withdraw(decisionId: 'dec-1', withdrawnBy: '', currentUserId: 'alice');

		self::assertFalse(condition: $result['success']);
		self::assertNull(actual: $saved->value);
		self::assertStringContainsString(needle: 'bestuursorgaan or belanghebbende', haystack: $result['message']);

	}//end testWithdrawRequiresAnActorKind()

	/**
	 * The withdrawal writes the shared hash-chained audit entry with the
	 * actor kind and reason as its comment.
	 *
	 * @spec openspec/specs/decision-management/spec.md
	 *
	 * @return void
	 */
	public function testWithdrawAppendsTheAuditEntry(): void {
		$this->objectService->method('find')
			->willReturn($this->entity(data: ['id' => 'dec-1', 'lifecycle' => 'proposed']));
		$this->objectService->method('saveObject')->willReturnCallback(
			fn (array $object): ObjectEntity => $this->entity(data: $object)
		);

		$this->auditLogService->expects($this->once())->method('append')->with(
			'alice',
			'decision-transition',
			['dec-1'],
			[
				'transition' => 'withdraw',
				'from' => 'proposed',
				'to' => 'withdrawn',
				'comment' => 'bestuursorgaan: Ingetrokken',
			]
		)->willReturn(['success' => true, 'entry' => [], 'message' => 'OK']);

		$result = $this->flow->withdraw(
			decisionId: 'dec-1',
			withdrawnBy: 'bestuursorgaan',
			reason: 'Ingetrokken',
			currentUserId: 'alice'
		);

		self::assertTrue(condition: $result['success'], message: $result['message']);

	}//end testWithdrawAppendsTheAuditEntry()

	/**
	 * When the body's policy makes `→ withdrawn` chair-only and no chair can
	 * be resolved, the withdrawal FAILS CLOSED before anything is written.
	 *
	 * @spec openspec/specs/decision-management/spec.md
	 *
	 * @return void
	 */
	public function testWithdrawChairGateFailsClosedWithoutResolvableChair(): void {
		$this->policyOverride = ['chairOnlyTransitions' => ['deliberating:withdrawn']];
		$this->objectService->method('find')->willReturnCallback(
			function (int|string $id, ?ObjectFindOptions $options = null) {
				$schema = $options?->schema;
				if ($schema !== 'decision') {
					return null;
				}

				return $this->entity(data: ['id' => 'dec-1', 'lifecycle' => 'deliberating', 'governanceBody' => 'body-1']);
			}
		);

		$saved = $this->captureSaves();

		$result = $this->flow->withdraw(decisionId: 'dec-1', withdrawnBy: 'bestuursorgaan', currentUserId: 'alice');

		self::assertFalse(condition: $result['success']);
		self::assertNull(actual: $saved->value);
		self::assertSame(expected: 'Only the meeting chair may perform this transition.', actual: $result['message']);

	}//end testWithdrawChairGateFailsClosedWithoutResolvableChair()

	/**
	 * A decision the caller cannot read is reported as not found.
	 *
	 * @spec openspec/specs/decision-management/spec.md
	 *
	 * @return void
	 */
	public function testWithdrawNotFound(): void {
		$this->objectService->method('find')->willReturn(null);

		$result = $this->flow->withdraw(decisionId: 'dec-404', withdrawnBy: 'bestuursorgaan', currentUserId: 'alice');

		self::assertFalse(condition: $result['success']);
		self::assertNull(actual: $result['decision']);
		self::assertSame(expected: "Decision 'dec-404' not found.", actual: $result['message']);

	}//end testWithdrawNotFound()
}//end class
