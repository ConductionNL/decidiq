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
use OCA\Decidesk\Service\DecisionIntegrationService;
use OCA\Decidesk\Service\DecisionLifecycleService;
use OCA\Decidesk\Service\ProcessTemplateService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Service\ObjectService;
use OCP\EventDispatcher\IEventDispatcher;
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
class DecisionLifecycleServiceTest extends TestCase {

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
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->container = $this->createMock(ContainerInterface::class);
		$this->objectService = $this->createMock(ObjectServiceInterface::class);
		$this->auditLogService = $this->createMock(AuditLogService::class);

		$this->container->method('get')
			->with('OCA\OpenRegister\Service\ObjectService')
			->willReturn($this->objectService);

		// Default: no body template assigned -> resolvePolicyForBody returns null,
		// so the guard falls back to the built-in hardcoded domain policy and every
		// pre-process-config test keeps its original behaviour.
		$templateService = $this->createMock(ProcessTemplateService::class);
		$templateService->method('resolvePolicyForBody')->willReturn(null);

		// Cross-app event contract (decidesk-decision-events): the service now
		// also dispatches a DecisionConcludedEvent when a *delegated* decision
		// (one carrying a sourceApp provenance) reaches a terminal state. The
		// fixtures here are internal decisions (no sourceApp), so neither
		// collaborator is invoked — they are wired as plain mocks so the
		// constructor signature is satisfied without altering the asserted
		// behaviour of the existing lifecycle scenarios.
		$integrationService = $this->createMock(DecisionIntegrationService::class);
		$eventDispatcher = $this->createMock(IEventDispatcher::class);

		$this->service = new DecisionLifecycleService(
			logger: $this->createMock(LoggerInterface::class),
			transitionGuard: new DecisionTransitionGuard(),
			auditLogService: $this->auditLogService,
			templateService: $templateService,
			integrationService: $integrationService,
			eventDispatcher: $eventDispatcher,
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
		$entity = $this->createMock(ObjectEntity::class);
		$entity->method('jsonSerialize')->willReturn($data);
		return $entity;
	}//end entity()

	/**
	 * Wire `saveObject()` to record the payload it was handed instead of
	 * asserting `never()`.
	 *
	 * A `never()` expectation makes PHPUnit throw INSIDE the service, where the
	 * catch-all turns it into the generic "Transition failed" message — so the
	 * test goes red with a message that hides what actually happened. Recording
	 * the write instead makes the failure state the defect out loud: the object
	 * that reached the terminal state.
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
			function (array $object, ?array $extend = [], string|int|null $register = null, string|int|null $schema = null, ?string $uuid = null) use ($holder) {
				$holder->value = $object;
				return $this->entity($object);
			}
		);
		$this->auditLogService->method('append')->willReturn(['success' => true, 'entry' => [], 'message' => 'OK']);

		return $holder;
	}//end captureSaves()

	/**
	 * Unknown actions are rejected before any object load.
	 *
	 * @spec openspec/specs/decision-management/spec.md
	 *
	 * @return void
	 */
	public function testUnknownActionRejected(): void {
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
	public function testNotFoundDecision(): void {
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
	public function testInvalidFromStateRejected(): void {
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
	public function testChairOnlyFailsClosedWithoutResolvableChair(): void {
		// legislative domain: deliberating → voting is chair-only.
		// The linked meeting has no chair → reject, never skip.
		$this->objectService->method('find')->willReturnCallback(
			function (int|string $id, ?array $_extend = [], bool $files = false, string|int|null $register = null, string|int|null $schema = null) {
				if ($schema === 'decision') {
					return $this->entity(['id' => 'dec-1', 'lifecycle' => 'deliberating', 'domain' => 'legislative', 'meeting' => 'meet-1']);
				}

				if ($schema === 'meeting') {
					return $this->entity(['id' => 'meet-1', 'quorumWith' => true]);
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
	public function testChairOnlyRejectsNonChair(): void {
		$this->objectService->method('find')->willReturnCallback(
			function (int|string $id, ?array $_extend = [], bool $files = false, string|int|null $register = null, string|int|null $schema = null) {
				if ($schema === 'decision') {
					return $this->entity(['id' => 'dec-1', 'lifecycle' => 'deliberating', 'domain' => 'legislative', 'meeting' => 'meet-1']);
				}

				if ($schema === 'meeting') {
					return $this->entity(['id' => 'meet-1', 'quorumWith' => true, 'chair' => 'part-1']);
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
	public function testQuorumGateBlocksOpenVoting(): void {
		$this->objectService->method('find')->willReturnCallback(
			function (int|string $id, ?array $_extend = [], bool $files = false, string|int|null $register = null, string|int|null $schema = null) {
				if ($schema === 'decision') {
					return $this->entity(['id' => 'dec-1', 'lifecycle' => 'deliberating', 'domain' => 'association', 'meeting' => 'meet-1']);
				}

				if ($schema === 'meeting') {
					return $this->entity(['id' => 'meet-1', 'quorumWith' => false, 'chair' => 'part-1']);
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
	public function testEnactRequiresAdoptedOutcome(): void {
		$this->objectService->method('find')
			->willReturn($this->entity(['id' => 'dec-1', 'lifecycle' => 'decided', 'outcome' => 'rejected']));

		$result = $this->service->transition(decisionId: 'dec-1', action: 'enact', currentUserId: 'alice');
		self::assertFalse(condition: $result['success']);
		self::assertStringContainsString(needle: 'adopted', haystack: $result['message']);

	}//end testEnactRequiresAdoptedOutcome()

	/**
	 * THE GATE THAT MUST NOT BE LOST.
	 *
	 * `outcome` and `decisionDate` are no longer unconditionally `required` on
	 * the Decision schema (an in-flight motion has no legal outcome), so the
	 * completeness rule binds HERE instead: a decision may not ENTER a terminal
	 * outcome state without both fields. Without this gate, relaxing the schema
	 * would make an outcome-less decided decision undetectable.
	 *
	 * @spec openspec/specs/decision-management/spec.md
	 *
	 * @return void
	 */
	public function testDecideRejectedWithoutOutcomeAndDecisionDate(): void {
		$this->objectService->method('find')
			->willReturn($this->entity(['id' => 'dec-1', 'lifecycle' => 'voting', 'title' => 'Motie Woonlasten']));

		$saved = $this->captureSaves();

		$result = $this->service->transition(decisionId: 'dec-1', action: 'decide', currentUserId: 'alice');
		self::assertNull(
			actual: $saved->value,
			message: 'A decision with neither outcome nor decisionDate was written into the terminal state '
				. '"decided" — the completeness rule that used to live in the schema `required[]` has been lost.'
		);
		self::assertFalse(condition: $result['success']);
		self::assertStringContainsString(needle: 'outcome', haystack: $result['message']);
		self::assertStringContainsString(needle: 'decisionDate', haystack: $result['message']);

	}//end testDecideRejectedWithoutOutcomeAndDecisionDate()

	/**
	 * A decision carrying an `outcome` value outside the schema enum
	 * (the shipped `motie-woonlasten-2025` seed used `outcome: "pending"`)
	 * is not "complete" — the terminal gate rejects it just as it rejects a
	 * missing value.
	 *
	 * @spec openspec/specs/decision-management/spec.md
	 *
	 * @return void
	 */
	public function testDecideRejectedWithOutOfVocabularyOutcome(): void {
		$this->objectService->method('find')->willReturn(
			$this->entity(
				[
					'id' => 'dec-1',
					'lifecycle' => 'voting',
					'outcome' => 'pending',
					'decisionDate' => '2026-04-10T21:00:00Z',
				]
			)
		);

		$saved = $this->captureSaves();

		$result = $this->service->transition(decisionId: 'dec-1', action: 'decide', currentUserId: 'alice');
		self::assertNull(
			actual: $saved->value,
			message: 'A decision whose outcome is outside the schema enum was written into "decided".'
		);
		self::assertFalse(condition: $result['success']);
		self::assertStringContainsString(needle: 'outcome', haystack: $result['message']);

	}//end testDecideRejectedWithOutOfVocabularyOutcome()

	/**
	 * The other direction: a decision that DOES carry both terminal fields
	 * transitions to `decided` normally.
	 *
	 * @spec openspec/specs/decision-management/spec.md
	 *
	 * @return void
	 */
	public function testDecideAcceptedWithOutcomeAndDecisionDate(): void {
		$this->objectService->method('find')->willReturn(
			$this->entity(
				[
					'id' => 'dec-1',
					'lifecycle' => 'voting',
					'outcome' => 'adopted',
					'decisionDate' => '2026-04-10T21:00:00Z',
				]
			)
		);

		$saved = null;
		$this->objectService->method('saveObject')->willReturnCallback(
			function (array $object, ?array $extend = [], string|int|null $register = null, string|int|null $schema = null, ?string $uuid = null) use (&$saved) {
				$saved = $object;
				return $this->entity($object);
			}
		);
		$this->auditLogService->method('append')->willReturn(['success' => true, 'entry' => [], 'message' => 'OK']);

		$result = $this->service->transition(decisionId: 'dec-1', action: 'decide', currentUserId: 'alice');
		self::assertTrue(condition: $result['success']);
		self::assertSame(expected: 'decided', actual: $saved['lifecycle']);

	}//end testDecideAcceptedWithOutcomeAndDecisionDate()

	/**
	 * The gate binds at EVERY terminal entry, not only at `decide`: an adopted
	 * decision with no `decisionDate` cannot be enacted either.
	 *
	 * @spec openspec/specs/decision-management/spec.md
	 *
	 * @return void
	 */
	public function testEnactRejectedWithoutDecisionDate(): void {
		$this->objectService->method('find')
			->willReturn($this->entity(['id' => 'dec-1', 'lifecycle' => 'decided', 'outcome' => 'adopted']));

		$saved = $this->captureSaves();

		$result = $this->service->transition(decisionId: 'dec-1', action: 'enact', currentUserId: 'alice');
		self::assertNull(
			actual: $saved->value,
			message: 'A decision with no decisionDate was written into the terminal state "enacted".'
		);
		self::assertFalse(condition: $result['success']);
		self::assertStringContainsString(needle: 'decisionDate', haystack: $result['message']);

	}//end testEnactRejectedWithoutDecisionDate()

	/**
	 * In flight is NOT gated: a motion moving `deliberating → voting` carries
	 * no outcome and no decisionDate, and must be allowed through. This is the
	 * behaviour the schema change restores.
	 *
	 * @spec openspec/specs/decision-management/spec.md
	 *
	 * @return void
	 */
	public function testInFlightTransitionNeedsNoOutcome(): void {
		$this->objectService->method('find')->willReturnCallback(
			function (int|string $id, ?array $_extend = [], bool $files = false, string|int|null $register = null, string|int|null $schema = null) {
				return $this->entity(['id' => 'dec-1', 'lifecycle' => 'deliberating', 'title' => 'Motie Woonlasten']);
			}
		);

		$saved = null;
		$this->objectService->method('saveObject')->willReturnCallback(
			function (array $object, ?array $extend = [], string|int|null $register = null, string|int|null $schema = null, ?string $uuid = null) use (&$saved) {
				$saved = $object;
				return $this->entity($object);
			}
		);
		$this->auditLogService->method('append')->willReturn(['success' => true, 'entry' => [], 'message' => 'OK']);

		$result = $this->service->transition(decisionId: 'dec-1', action: 'openVoting', currentUserId: 'alice');
		self::assertTrue(condition: $result['success']);
		self::assertSame(expected: 'voting', actual: $saved['lifecycle']);

	}//end testInFlightTransitionNeedsNoOutcome()

	/**
	 * Happy path: propose persists the new lifecycle and appends the
	 * hash-chained decision-transition audit entry.
	 *
	 * @spec openspec/specs/decision-management/spec.md
	 *
	 * @return void
	 */
	public function testHappyProposeWithAuditAppend(): void {
		$this->objectService->method('find')
			->willReturn($this->entity(['id' => 'dec-1', 'lifecycle' => 'draft', 'title' => 'Test']));

		$saved = null;
		$this->objectService->method('saveObject')->willReturnCallback(
			function (array $object, ?array $extend = [], string|int|null $register = null, string|int|null $schema = null, ?string $uuid = null) use (&$saved) {
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
	public function testEnactSetsEnactedAtAndGeneratesResolution(): void {
		$this->objectService->method('find')
			->willReturn(
				$this->entity(
					[
						'id' => 'dec-1',
						'lifecycle' => 'decided',
						'outcome' => 'adopted',
						// A decision already in `decided` necessarily carries a
						// decisionDate — the terminal-completeness gate is what
						// let it get there. The fixture previously omitted it,
						// which is why the generated resolution's adoptionDate
						// asserted below used to come out empty.
						'decisionDate' => '2026-04-10T21:00:00Z',
						'title' => 'Budget 2026',
						'text' => 'Adopt the budget.',
					]
				)
			);

		// Both the lifecycle update and the generated resolution record now
		// persist on the unified `decision` schema (ADR-005 folded the former
		// `resolution` schema into `decision`). They are distinguished by the
		// save shape, not the schema slug: the lifecycle update is an in-place
		// update carrying a uuid; the generated resolution is a fresh insert
		// (no uuid) carrying a `status` + `effectiveDate`.
		$updates = [];
		$resolution = null;
		$this->objectService->method('saveObject')->willReturnCallback(
			function (array $object, ?array $extend = [], string|int|null $register = null, string|int|null $schema = null, ?string $uuid = null) use (&$updates, &$resolution) {
				if ((string)($uuid ?? '') !== '') {
					$updates[] = $object;
				} else {
					$resolution = $object;
				}

				return $this->entity($object);
			}
		);
		$this->auditLogService->method('append')
			->willReturn(['success' => true, 'entry' => [], 'message' => 'OK']);

		$result = $this->service->transition(decisionId: 'dec-1', action: 'enact', currentUserId: 'alice');
		self::assertTrue(condition: $result['success']);

		// The lifecycle update (uuid-bearing) flips to enacted + stamps enactedAt.
		self::assertCount(expectedCount: 1, haystack: $updates);
		$decisionSave = $updates[0];
		self::assertSame(expected: 'enacted', actual: $decisionSave['lifecycle']);
		self::assertNotEmpty(actual: $decisionSave['enactedAt']);

		// Enacting generates the formal resolution record (resolution-minutes spec).
		self::assertNotNull(actual: $resolution);
		self::assertSame(expected: 'adopted', actual: $resolution['status']);
		self::assertSame(expected: 'Budget 2026', actual: $resolution['title']);
		self::assertSame(
			expected: $decisionSave['enactedAt'],
			actual: $resolution['effectiveDate']
		);

		// The generated resolution's adoptionDate is the decision's
		// decisionDate — non-empty precisely because terminal completeness is
		// now enforced before `enacted` can be entered.
		self::assertSame(expected: '2026-04-10T21:00:00Z', actual: $resolution['adoptionDate']);

	}//end testEnactSetsEnactedAtAndGeneratesResolution()

	/**
	 * getAvailableTransitions reports state + per-action chairOnly flags.
	 *
	 * @spec openspec/specs/decision-management/spec.md
	 *
	 * @return void
	 */
	public function testGetAvailableTransitions(): void {
		$this->objectService->method('find')->willReturnCallback(
			function (int|string $id, ?array $_extend = [], bool $files = false, string|int|null $register = null, string|int|null $schema = null) {
				if ($schema === 'decision') {
					return $this->entity(['id' => 'dec-1', 'lifecycle' => 'deliberating', 'domain' => 'association', 'meeting' => 'meet-1']);
				}

				if ($schema === 'meeting') {
					return $this->entity(['id' => 'meet-1', 'quorumWith' => true]);
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
	public function testGetAvailableTransitionsNotFound(): void {
		$this->objectService->method('find')->willReturn(null);

		$result = $this->service->getAvailableTransitions(decisionId: 'dec-404');
		self::assertFalse(condition: $result['success']);
		self::assertNull(actual: $result['lifecycle']);

	}//end testGetAvailableTransitionsNotFound()
}//end class
