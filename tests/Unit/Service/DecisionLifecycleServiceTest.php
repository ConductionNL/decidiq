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
use OCP\EventDispatcher\IEventDispatcher;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
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
		$this->objectService = $this->createMock(ObjectServiceInterface::class);
		$this->auditLogService = $this->createMock(AuditLogService::class);

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

	/**
	 * Wire `saveObject()` to bucket its calls by schema/uuid shape so
	 * appointment-materialization tests can assert on the lifecycle write,
	 * the generated resolution record, and the created Memberships
	 * separately without over-fitting to call order.
	 *
	 * Returns an object (not an array) for the same reason captureSaves()
	 * does: the mock's `willReturnCallback` closure captures it by handle,
	 * so mutations made while the service under test runs stay visible on
	 * the SAME instance the caller already holds. Returning a plain array
	 * would hand the caller a value copied at THIS return statement — before
	 * the transition (and its saveObject calls) has even happened — so every
	 * bucket would read back empty regardless of what actually got saved.
	 *
	 * Membership saves are handed back a synthetic incrementing id
	 * ('membership-1', 'membership-2', ...) so materializeAppointmentMemberships()
	 * has something real to collect into `appointedMemberships`.
	 *
	 * @return object{decision: array<int, array<string, mixed>>, resolution: array<int, array<string, mixed>>, membership: array<int, array<string, mixed>>}
	 */
	private function captureBucketedSaves(): object {
		$buckets = new class {
			/**
			 * uuid-bearing `decision` saves (lifecycle write, appointedMemberships patch).
			 *
			 * @var array<int, array<string, mixed>>
			 */
			public array $decision = [];

			/**
			 * uuid-less `decision` saves — the generated resolution record.
			 *
			 * @var array<int, array<string, mixed>>
			 */
			public array $resolution = [];

			/**
			 * `membership` schema saves — the materialized appointment Memberships.
			 *
			 * @var array<int, array<string, mixed>>
			 */
			public array $membership = [];
		};

		$this->objectService->method('saveObject')->willReturnCallback(
			function (array $object, ?array $extend = [], string|int|null $register = null, string|int|null $schema = null, ?string $uuid = null) use ($buckets) {
				if ($schema === 'membership') {
					$id = 'membership-' . (count($buckets->membership) + 1);
					$buckets->membership[] = $object;
					return $this->entity(array_merge(['id' => $id], $object));
				}

				if ($schema === 'decision' && (string)($uuid ?? '') !== '') {
					$buckets->decision[] = $object;
					return $this->entity($object);
				}

				$buckets->resolution[] = $object;
				return $this->entity($object);
			}
		);
		$this->auditLogService->method('append')->willReturn(['success' => true, 'entry' => [], 'message' => 'OK']);

		return $buckets;
	}//end captureBucketedSaves()

	/**
	 * A mismatched posts/candidates count blocks enactment (design.md D1;
	 * fail-closed, same pattern as the outcome-before-`enact` gate).
	 *
	 * @spec openspec/changes/appointment-decision-type-membership/specs/decision-management/spec.md#requirement-the-enact-transition-rejects-an-unpairable-candidatesposts-mismatch
	 *
	 * @return void
	 */
	public function testEnactRejectsMismatchedPostsCandidatesCount(): void {
		$this->objectService->method('find')->willReturn(
			$this->entity(
				[
					'id' => 'dec-1',
					'lifecycle' => 'decided',
					'outcome' => 'adopted',
					'decisionDate' => '2026-04-10T21:00:00Z',
					'decisionType' => 'appointment',
					'targetPosts' => ['post-1', 'post-2'],
					'candidates' => [
						['person' => 'person-1'],
						['person' => 'person-2'],
						['externalName' => 'Extern C'],
					],
				]
			)
		);

		$saved = $this->captureSaves();

		$result = $this->service->transition(decisionId: 'dec-1', action: 'enact', currentUserId: 'alice');

		self::assertNull(
			actual: $saved->value,
			message: 'A decision with an unpairable posts/candidates count was written into "enacted".'
		);
		self::assertFalse(condition: $result['success']);
		self::assertStringContainsString(needle: 'targetPosts', haystack: $result['message']);
		self::assertStringContainsString(needle: 'candidates', haystack: $result['message']);

	}//end testEnactRejectsMismatchedPostsCandidatesCount()

	/**
	 * A single-candidate, role-only appointment (no `targetPosts`)
	 * materializes exactly one Membership carrying the candidate's person,
	 * the target role/body and `startDate=enactedAt`, and patches
	 * `appointedMemberships` onto the decision.
	 *
	 * @spec openspec/changes/appointment-decision-type-membership/specs/decision-management/spec.md#requirement-appointment-adoption-materializes-membership-records
	 *
	 * @return void
	 */
	public function testMaterializesSingleRoleOnlyMembershipForPersonCandidate(): void {
		$this->objectService->method('find')->willReturn(
			$this->entity(
				[
					'id' => 'dec-1',
					'lifecycle' => 'decided',
					'outcome' => 'adopted',
					'decisionDate' => '2026-04-10T21:00:00Z',
					'decisionType' => 'appointment',
					'title' => 'Benoeming lid auditcommissie',
					'targetBody' => 'body-1',
					'targetRole' => 'member',
					'targetPosts' => [],
					'candidates' => [
						['person' => 'person-1'],
					],
					'appointedMemberships' => [],
				]
			)
		);

		$buckets = $this->captureBucketedSaves();

		$result = $this->service->transition(decisionId: 'dec-1', action: 'enact', currentUserId: 'alice');

		self::assertTrue(condition: $result['success']);

		self::assertCount(expectedCount: 1, haystack: $buckets->membership);
		$membership = $buckets->membership[0];
		self::assertSame(expected: 'person-1', actual: $membership['person']);
		self::assertSame(expected: 'member', actual: $membership['role']);
		self::assertSame(expected: 'body-1', actual: $membership['governanceBody']);
		self::assertArrayNotHasKey(key: 'post', array: $membership);
		self::assertArrayNotHasKey(key: 'label', array: $membership);

		// Exactly two uuid-bearing decision saves: the lifecycle write
		// (enacted + enactedAt), then the appointedMemberships patch.
		self::assertCount(expectedCount: 2, haystack: $buckets->decision);
		self::assertSame(expected: 'enacted', actual: $buckets->decision[0]['lifecycle']);
		self::assertNotEmpty(actual: $buckets->decision[0]['enactedAt']);
		self::assertSame(expected: $buckets->decision[0]['enactedAt'], actual: $membership['startDate']);
		self::assertSame(expected: ['membership-1'], actual: $buckets->decision[1]['appointedMemberships']);

	}//end testMaterializesSingleRoleOnlyMembershipForPersonCandidate()

	/**
	 * A not-yet-registered candidate (only `externalName`, no `person`) is
	 * materialized by name: the Membership carries `label`, not `person`.
	 *
	 * @spec openspec/changes/appointment-decision-type-membership/specs/decision-management/spec.md#requirement-appointment-adoption-materializes-membership-records
	 *
	 * @return void
	 */
	public function testMaterializesExternalCandidateByLabel(): void {
		$this->objectService->method('find')->willReturn(
			$this->entity(
				[
					'id' => 'dec-1',
					'lifecycle' => 'decided',
					'outcome' => 'adopted',
					'decisionDate' => '2026-04-10T19:00:00Z',
					'decisionType' => 'appointment',
					'targetBody' => 'body-2',
					'targetRole' => 'member',
					'targetPosts' => [],
					'candidates' => [
						['externalName' => 'Mw. J. van Duin'],
					],
					'appointedMemberships' => [],
				]
			)
		);

		$buckets = $this->captureBucketedSaves();

		$result = $this->service->transition(decisionId: 'dec-1', action: 'enact', currentUserId: 'alice');

		self::assertTrue(condition: $result['success']);
		self::assertCount(expectedCount: 1, haystack: $buckets->membership);
		self::assertSame(expected: 'Mw. J. van Duin', actual: $buckets->membership[0]['label']);
		self::assertArrayNotHasKey(key: 'person', array: $buckets->membership[0]);

	}//end testMaterializesExternalCandidateByLabel()

	/**
	 * Multiple candidates paired with multiple `targetPosts` by array index.
	 *
	 * @spec openspec/changes/appointment-decision-type-membership/specs/decision-management/spec.md#requirement-appointment-adoption-materializes-membership-records
	 *
	 * @return void
	 */
	public function testMaterializesMultipleCandidatesPairedByIndex(): void {
		$this->objectService->method('find')->willReturn(
			$this->entity(
				[
					'id' => 'dec-1',
					'lifecycle' => 'decided',
					'outcome' => 'adopted',
					'decisionDate' => '2026-04-10T19:00:00Z',
					'decisionType' => 'appointment',
					'targetBody' => 'body-3',
					'targetRole' => 'member',
					'targetPosts' => ['post-a', 'post-b'],
					'candidates' => [
						['person' => 'person-a'],
						['externalName' => 'Candidate B'],
					],
					'appointedMemberships' => [],
				]
			)
		);

		$buckets = $this->captureBucketedSaves();

		$result = $this->service->transition(decisionId: 'dec-1', action: 'enact', currentUserId: 'alice');

		self::assertTrue(condition: $result['success']);
		self::assertCount(expectedCount: 2, haystack: $buckets->membership);
		self::assertSame(expected: 'person-a', actual: $buckets->membership[0]['person']);
		self::assertSame(expected: 'post-a', actual: $buckets->membership[0]['post']);
		self::assertSame(expected: 'Candidate B', actual: $buckets->membership[1]['label']);
		self::assertSame(expected: 'post-b', actual: $buckets->membership[1]['post']);
		self::assertSame(expected: ['membership-1', 'membership-2'], actual: $buckets->decision[1]['appointedMemberships']);

	}//end testMaterializesMultipleCandidatesPairedByIndex()

	/**
	 * Exactly one `targetPosts` entry is shared by every candidate — never
	 * ambiguous, so the pairing guard does not reject it either.
	 *
	 * @spec openspec/changes/appointment-decision-type-membership/specs/decision-management/spec.md#requirement-appointment-adoption-materializes-membership-records
	 *
	 * @return void
	 */
	public function testMaterializesSharedPostForAllCandidatesWhenExactlyOneTargetPost(): void {
		$this->objectService->method('find')->willReturn(
			$this->entity(
				[
					'id' => 'dec-1',
					'lifecycle' => 'decided',
					'outcome' => 'adopted',
					'decisionDate' => '2026-04-10T19:00:00Z',
					'decisionType' => 'appointment',
					'targetBody' => 'body-4',
					'targetRole' => 'member',
					'targetPosts' => ['post-shared'],
					'candidates' => [
						['person' => 'person-x'],
						['person' => 'person-y'],
					],
					'appointedMemberships' => [],
				]
			)
		);

		$buckets = $this->captureBucketedSaves();

		$result = $this->service->transition(decisionId: 'dec-1', action: 'enact', currentUserId: 'alice');

		self::assertTrue(condition: $result['success']);
		self::assertCount(expectedCount: 2, haystack: $buckets->membership);
		self::assertSame(expected: 'post-shared', actual: $buckets->membership[0]['post']);
		self::assertSame(expected: 'post-shared', actual: $buckets->membership[1]['post']);

	}//end testMaterializesSharedPostForAllCandidatesWhenExactlyOneTargetPost()

	/**
	 * A rejected appointment never reaches `enacted` (the outcome gate
	 * blocks `enact` outright), so no Membership is ever created for it —
	 * demonstrated here via the `archive` transition (also reachable from
	 * `decided`), which never runs the enact-only materialization branch.
	 *
	 * @spec openspec/changes/appointment-decision-type-membership/specs/decision-management/spec.md#requirement-appointment-adoption-materializes-membership-records
	 *
	 * @return void
	 */
	public function testRejectedOutcomeNeverMaterializesAMembership(): void {
		$this->objectService->method('find')->willReturn(
			$this->entity(
				[
					'id' => 'dec-1',
					'lifecycle' => 'decided',
					'outcome' => 'rejected',
					'decisionDate' => '2026-04-10T19:00:00Z',
					'decisionType' => 'appointment',
					'targetBody' => 'body-5',
					'targetRole' => 'member',
					'targetPosts' => [],
					'candidates' => [
						['person' => 'person-z'],
					],
					'appointedMemberships' => [],
				]
			)
		);

		$buckets = $this->captureBucketedSaves();

		$result = $this->service->transition(decisionId: 'dec-1', action: 'archive', currentUserId: 'alice');

		self::assertTrue(condition: $result['success']);
		self::assertCount(expectedCount: 0, haystack: $buckets->membership);

	}//end testRejectedOutcomeNeverMaterializesAMembership()

	/**
	 * Idempotency guard: a decision whose `appointedMemberships` is already
	 * non-empty materializes no additional Membership, even though the
	 * decision is otherwise a fresh, valid appointment adoption.
	 *
	 * @spec openspec/changes/appointment-decision-type-membership/specs/decision-management/spec.md#requirement-appointment-adoption-materializes-membership-records
	 *
	 * @return void
	 */
	public function testMaterializationDoesNotRunTwice(): void {
		$this->objectService->method('find')->willReturn(
			$this->entity(
				[
					'id' => 'dec-1',
					'lifecycle' => 'decided',
					'outcome' => 'adopted',
					'decisionDate' => '2026-04-10T19:00:00Z',
					'decisionType' => 'appointment',
					'targetBody' => 'body-6',
					'targetRole' => 'member',
					'targetPosts' => [],
					'candidates' => [
						['person' => 'person-already'],
					],
					'appointedMemberships' => ['existing-membership-1'],
				]
			)
		);

		$buckets = $this->captureBucketedSaves();

		$result = $this->service->transition(decisionId: 'dec-1', action: 'enact', currentUserId: 'alice');

		self::assertTrue(condition: $result['success']);
		self::assertCount(expectedCount: 0, haystack: $buckets->membership);

		// Only the lifecycle write — no appointedMemberships patch, since
		// materialization returned before ever calling saveObject() again.
		self::assertCount(expectedCount: 1, haystack: $buckets->decision);

	}//end testMaterializationDoesNotRunTwice()
}//end class
