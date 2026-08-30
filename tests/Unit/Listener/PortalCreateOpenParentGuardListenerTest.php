<?php

/**
 * Unit tests for PortalCreateOpenParentGuardListener.
 *
 * Pins the fail-closed open-parent invariant for portal-citizen-create-actions
 * (REQ-DKPCA-001/002): a `consultation-reaction` create is rejected unless its
 * parent `PublicConsultation` is `status: open`; a `budget-proposal` create is
 * rejected unless its parent `ParticipatoryBudget` is `status: submission`.
 * Covers both write-path shapes (the portaliq create action's scalar
 * reference field, and Decidiq's own `ReactionIntakeService`/
 * `BudgetVotingService` `relations` array shape), unrelated schemas being
 * ignored, a missing parent, and an infrastructure failure — deliberately
 * fail-CLOSED (a documented contrast with `SubmissionDeadlineListener`'s
 * fail-soft business-rule posture, since this is a security invariant).
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/portal-citizen-create-actions/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Listener;

use OCA\Decidiq\Listener\PortalCreateOpenParentGuardListener;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/**
 * Open-parent guard matrix for the two citizen create actions.
 *
 * @spec openspec/specs/portal-citizen-create-actions/spec.md
 */
class PortalCreateOpenParentGuardListenerTest extends TestCase {

	/**
	 * Build a listener over an in-memory object store double (mirrors
	 * SubmissionDeadlineListenerTest's fixture pattern).
	 *
	 * @param array<string, array<string, mixed>> $store Seed parent objects by id.
	 *
	 * @return PortalCreateOpenParentGuardListener
	 */
	private function buildListener(array $store): PortalCreateOpenParentGuardListener {
		$storeRef = new \ArrayObject($store);

		$objectService = new class($storeRef) {

			/**
			 * Constructor.
			 *
			 * @param \ArrayObject $store In-memory object store.
			 */
			public function __construct(
				private \ArrayObject $store,
			) {
			}//end __construct()

			/**
			 * Find an object by id, returning an entity-like wrapper.
			 *
			 * @param int|string $id Object id.
			 * @param string|int|null $register Register slug.
			 * @param string|int|null $schema Schema slug.
			 *
			 * @return object|null
			 */
			public function find(int|string $id, string|int|null $register = null, string|int|null $schema = null): ?object {
				$payload = ($this->store[(string)$id] ?? null);
				if ($payload === null) {
					return null;
				}

				return new class($payload) {
					/**
					 * Constructor.
					 *
					 * @param array<string, mixed> $object The payload.
					 */
					public function __construct(
						private array $object,
					) {
					}//end __construct()

					/**
					 * Serialize like an ObjectEntity.
					 *
					 * @return array<string, mixed>
					 */
					public function jsonSerialize(): array {
						return $this->object;
					}//end jsonSerialize()
				};

			}//end find()
		};

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($objectService): object {
				if ($id === 'OCA\OpenRegister\Service\ObjectService') {
					return $objectService;
				}

				throw new \RuntimeException('not wired in test: ' . $id);
			}
		);

		return new PortalCreateOpenParentGuardListener(
			container: $container,
			logger: new NullLogger(),
		);

	}//end buildListener()

	/**
	 * Build a listener whose container always throws (infrastructure failure).
	 *
	 * @return PortalCreateOpenParentGuardListener
	 */
	private function buildBrokenListener(): PortalCreateOpenParentGuardListener {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willThrowException(new \RuntimeException('DI is down'));

		return new PortalCreateOpenParentGuardListener(
			container: $container,
			logger: new NullLogger(),
		);

	}//end buildBrokenListener()

	/**
	 * Build an ObjectCreatingEvent carrying an entity that serialises to $row.
	 *
	 * @param array<string, mixed> $row The object payload being created.
	 *
	 * @return ObjectCreatingEvent
	 */
	private function eventFor(array $row): ObjectCreatingEvent {
		$entity = $this->createMock(ObjectEntity::class);
		$entity->method('getObject')->willReturn($row);
		$entity->method('jsonSerialize')->willReturn($row);

		return new ObjectCreatingEvent($entity);
	}//end eventFor()

	/**
	 * A reaction on an OPEN consultation (scalar `consultation` reference
	 * field, the portaliq create-action shape) is allowed.
	 *
	 * @return void
	 */
	public function testReactionOnOpenConsultationAllowed(): void {
		$listener = $this->buildListener(['c-1' => ['id' => 'c-1', 'status' => 'open']]);

		$event = $this->eventFor(
			[
				'consultation' => 'c-1',
				'body' => 'I support this',
				'moderationStatus' => 'pending',
				'submitterId' => 'anon-token',
			]
		);
		$listener->handle($event);

		self::assertFalse($event->isPropagationStopped());

	}//end testReactionOnOpenConsultationAllowed()

	/**
	 * A reaction on a CLOSED consultation fails closed (REQ-DKPCA-001
	 * Scenario: "against a consultation whose status is not open fails
	 * closed").
	 *
	 * @return void
	 */
	public function testReactionOnClosedConsultationRejected(): void {
		$listener = $this->buildListener(['c-1' => ['id' => 'c-1', 'status' => 'closed']]);

		$event = $this->eventFor(
			[
				'consultation' => 'c-1',
				'body' => 'Too late?',
				'moderationStatus' => 'pending',
				'submitterId' => 'anon-token',
			]
		);
		$listener->handle($event);

		self::assertTrue($event->isPropagationStopped());
		self::assertNotSame('', $event->getErrors()['message'] ?? '');

	}//end testReactionOnClosedConsultationRejected()

	/**
	 * A reaction resolved through the generic `relations` array (Decidiq's
	 * own ReactionIntakeService shape, no scalar `consultation` key) on an
	 * open consultation is allowed.
	 *
	 * @return void
	 */
	public function testReactionViaRelationsShapeOnOpenConsultationAllowed(): void {
		$listener = $this->buildListener(['c-1' => ['id' => 'c-1', 'status' => 'open']]);

		$event = $this->eventFor(
			[
				'body' => 'Support',
				'moderationStatus' => 'pending',
				'submitterId' => 'user-1',
				'relations' => [
					['register' => 'decidiq', 'schema' => 'public-consultation', 'id' => 'c-1'],
				],
			]
		);
		$listener->handle($event);

		self::assertFalse($event->isPropagationStopped());

	}//end testReactionViaRelationsShapeOnOpenConsultationAllowed()

	/**
	 * A budget proposal into a submission-phase round is allowed.
	 *
	 * @return void
	 */
	public function testBudgetProposalOnSubmissionRoundAllowed(): void {
		$listener = $this->buildListener(['b-1' => ['id' => 'b-1', 'status' => 'submission']]);

		$event = $this->eventFor(
			[
				'participatoryBudget' => 'b-1',
				'title' => 'New playground',
				'requestedAmount' => 5000,
				'submitter' => 'anon-token',
				'status' => 'submitted',
			]
		);
		$listener->handle($event);

		self::assertFalse($event->isPropagationStopped());

	}//end testBudgetProposalOnSubmissionRoundAllowed()

	/**
	 * A budget proposal into a round NOT in the submission phase fails closed
	 * (REQ-DKPCA-002 Scenario).
	 *
	 * @return void
	 */
	public function testBudgetProposalOnDraftRoundRejected(): void {
		$listener = $this->buildListener(['b-1' => ['id' => 'b-1', 'status' => 'draft']]);

		$event = $this->eventFor(
			[
				'participatoryBudget' => 'b-1',
				'title' => 'Too early',
				'requestedAmount' => 1000,
				'submitter' => 'anon-token',
				'status' => 'submitted',
			]
		);
		$listener->handle($event);

		self::assertTrue($event->isPropagationStopped());

	}//end testBudgetProposalOnDraftRoundRejected()

	/**
	 * A create whose parent id does not resolve to any stored object fails
	 * closed (no existence oracle; same posture as a status mismatch).
	 *
	 * @return void
	 */
	public function testMissingParentRejected(): void {
		$listener = $this->buildListener([]);

		$event = $this->eventFor(
			[
				'consultation' => 'does-not-exist',
				'body' => 'x',
				'moderationStatus' => 'pending',
				'submitterId' => 'anon-token',
			]
		);
		$listener->handle($event);

		self::assertTrue($event->isPropagationStopped());

	}//end testMissingParentRejected()

	/**
	 * An unrelated schema (neither field signature matches) is ignored.
	 *
	 * @return void
	 */
	public function testUnrelatedSchemaIgnored(): void {
		$listener = $this->buildListener([]);

		$event = $this->eventFor(['title' => 'Q3 Meeting', 'startDate' => '2026-01-01']);
		$listener->handle($event);

		self::assertFalse($event->isPropagationStopped());

	}//end testUnrelatedSchemaIgnored()

	/**
	 * Non-ObjectCreatingEvent events are ignored.
	 *
	 * @return void
	 */
	public function testNonCreatingEventsIgnored(): void {
		$listener = $this->buildListener([]);

		// A generic Event has no isPropagationStopped()/getErrors() API to
		// assert against; reaching this point without an exception is the
		// assertion (the instanceof guard at the top of handle() returns
		// immediately for any non-ObjectCreatingEvent).
		$listener->handle($this->createMock(Event::class));
		self::assertTrue(condition: true);

	}//end testNonCreatingEventsIgnored()

	/**
	 * An infrastructure failure (container/ObjectService unavailable) fails
	 * CLOSED — the deliberate contrast with SubmissionDeadlineListener's
	 * fail-soft posture, because this is a security invariant (write-IDOR /
	 * open-parent), not an opt-in business rule.
	 *
	 * @return void
	 */
	public function testInfrastructureFailureRejectsClosed(): void {
		$listener = $this->buildBrokenListener();

		$event = $this->eventFor(
			[
				'consultation' => 'c-1',
				'body' => 'x',
				'moderationStatus' => 'pending',
				'submitterId' => 'anon-token',
			]
		);
		$listener->handle($event);

		self::assertTrue($event->isPropagationStopped());

	}//end testInfrastructureFailureRejectsClosed()
}//end class
