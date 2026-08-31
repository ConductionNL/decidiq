<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category  Test
 * @package   OCA\Decidiq\Tests\Unit\Service
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 * @link      https://github.com/ConductionNL/decidiq
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service;

use OCA\Decidiq\Service\ApprovalRouteService;
use OCA\Decidiq\Service\RegisterObjectStore;
use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Covers the approval-route engine.
 *
 * The assertions are deliberately about the ADVANCE and the REFUSALS rather
 * than about rows being written. A route engine that stores an action and does
 * not move is the exact failure this capability exists to replace: the
 * consuming app had one API whose every call returned 400 and one bridge that
 * short-circuited on a property nothing ever set, and both reported success.
 */
class ApprovalRouteServiceTest extends TestCase {
	/**
	 * In-memory OpenRegister stand-in.
	 *
	 * @var object
	 */
	private object $objectService;

	/**
	 * Build the fake register.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->objectService = new class {
			/**
			 * Stored rows, keyed by schema then uuid.
			 *
			 * @var array<string, array<string, array<string, mixed>>>
			 */
			public array $rows = ['decision-stage' => [], 'approval-action' => []];

			/**
			 * @var int
			 */
			private int $counter = 0;

			/**
			 * Create or patch a row.
			 *
			 * @param array<string, mixed> $object The object or patch.
			 * @param string $register The register.
			 * @param string $schema The schema.
			 * @param string|null $uuid The uuid.
			 *
			 * @return array<string, mixed> The stored row.
			 */
			public function saveObject(array $object, string $register, string $schema, ?string $uuid = null): array {
				if ($uuid === null) {
					$this->counter++;
					$uuid = $schema . '-' . $this->counter;
					$this->rows[$schema][$uuid] = ($object + ['id' => $uuid]);

					return $this->rows[$schema][$uuid];
				}

				// A patch MERGES, as OpenRegister does — so a test that asserts a
				// field the patch did not mention is asserting persistence, not
				// the fake's forgetfulness.
				$this->rows[$schema][$uuid] = (array_merge($this->rows[$schema][$uuid] ?? [], $object) + ['id' => $uuid]);

				return $this->rows[$schema][$uuid];
			}

			/**
			 * Filter the stored rows.
			 *
			 * @param array<string, mixed> $config The query config.
			 *
			 * @return array<int, array<string, mixed>> The rows.
			 */
			public function findAll(array $config): array {
				$filters = $config['filters'];
				$schema = $filters['schema'];
				unset($filters['register'], $filters['schema']);

				$out = [];
				foreach (($this->rows[$schema] ?? []) as $row) {
					$matches = true;
					foreach ($filters as $key => $value) {
						if (($row[$key] ?? null) !== $value) {
							$matches = false;
							break;
						}
					}

					if ($matches === true) {
						$out[] = $row;
					}
				}

				return $out;
			}
		};
	}

	/**
	 * The service under test.
	 *
	 * @return ApprovalRouteService The service.
	 */
	private function service(): ApprovalRouteService {
		// The store takes OpenRegister's facade by TYPE (ADR-083 rule 1), so the
		// stateful fake cannot be passed straight in. A typed mock is backed BY
		// that fake instead: the type satisfies the constructor, and the state
		// still lives, which is what lets these tests assert the route ADVANCING
		// rather than merely that a call was made.
		$state = $this->objectService;
		$facade = $this->createMock(ObjectServiceInterface::class);
		// The callback mirrors ObjectServiceInterface::saveObject()'s REAL
		// signature, whose second parameter is `?array $extend` — not the
		// register. PHPUnit forwards every argument positionally, so a callback
		// shaped like the call site (which uses named arguments and skips
		// $extend) receives the wrong values in the wrong order.
		$facade->method('saveObject')->willReturnCallback(
			function (
				array $object,
				?array $extend = [],
				string|int|null $register = null,
				string|int|null $schema = null,
				?string $uuid = null,
			) use ($state): ObjectEntityInterface {
				$row = $state->saveObject($object, (string)$register, (string)$schema, $uuid);

				// saveObject returns an ENTITY, not an array. The store already
				// collapses it via jsonSerialize(), so the fake has to hand back
				// something that serialises — returning the raw array would type-
				// error before the store ever normalised it.
				$entity = $this->createMock(ObjectEntityInterface::class);
				$entity->method('jsonSerialize')->willReturn($row);

				return $entity;
			}
		);
		$facade->method('findAll')->willReturnCallback(
			static fn (array $config = []): array => $state->findAll($config)
		);

		return new ApprovalRouteService(new RegisterObjectStore($facade));
	}

	/**
	 * A three-step route.
	 *
	 * @param bool $thirdMandatory Whether the third step is mandatory.
	 *
	 * @return array<string, mixed> The route.
	 */
	private function route(bool $thirdMandatory = true): array {
		return [
			'name' => 'Test route',
			'steps' => [
				['order' => 1, 'stageType' => 'advisory', 'actorType' => 'role', 'actor' => 'adviseur', 'label' => 'Advies'],
				['order' => 2, 'stageType' => 'endorsement', 'actorType' => 'person', 'actor' => 'alice', 'label' => 'Parafering'],
				['order' => 3, 'stageType' => 'decisive', 'actorType' => 'role', 'actor' => 'secretaris', 'label' => 'Accordering', 'mandatory' => $thirdMandatory],
			],
		];
	}

	/**
	 * The subject's stages, ordered.
	 *
	 * @return array<int, array<string, mixed>> The stages.
	 */
	private function stages(): array {
		$rows = array_values($this->objectService->rows['decision-stage']);
		usort($rows, static fn (array $a, array $b): int => ((int)$a['sequence'] <=> (int)$b['sequence']));

		return $rows;
	}

	/**
	 * The stage statuses, in sequence order.
	 *
	 * @return array<int, string> The statuses.
	 */
	private function statuses(): array {
		return array_map(static fn (array $s): string => (string)$s['status'], $this->stages());
	}

	/**
	 * Instantiating makes the first stage live.
	 *
	 * @return void
	 */
	public function testInstantiateMakesTheFirstStageActive(): void {
		$this->service()->instantiate(route: $this->route(), subject: 'subj-1', subjectSchema: 'proposal');

		$this->assertSame(['active', 'pending', 'pending'], $this->statuses());
		$this->assertSame([1, 2, 3], array_map(static fn (array $s): int => (int)$s['sequence'], $this->stages()));
	}

	/**
	 * Instantiating twice does not produce a second route.
	 *
	 * @return void
	 */
	public function testInstantiateIsIdempotent(): void {
		$service = $this->service();
		$service->instantiate(route: $this->route(), subject: 'subj-1', subjectSchema: 'proposal');
		$service->instantiate(route: $this->route(), subject: 'subj-1', subjectSchema: 'proposal');

		$this->assertCount(3, $this->stages());
	}

	/**
	 * A route with no steps is refused rather than instantiated empty.
	 *
	 * @return void
	 */
	public function testARouteWithNoStepsIsRefused(): void {
		$this->expectException(RuntimeException::class);

		$this->service()->instantiate(route: ['name' => 'Empty', 'steps' => []], subject: 'subj-1', subjectSchema: 'proposal');
	}

	/**
	 * An approval completes the stage and activates the next.
	 *
	 * @return void
	 */
	public function testAnApprovalAdvancesTheRoute(): void {
		$service = $this->service();
		$service->instantiate(route: $this->route(), subject: 'subj-1', subjectSchema: 'proposal');

		$service->record(['subject' => 'subj-1', 'step' => 1, 'actor' => 'bob', 'action' => 'approved']);

		$this->assertSame(['decided', 'active', 'pending'], $this->statuses());
		$this->assertSame('approved', $this->stages()[0]['outcome']);
	}

	/**
	 * The final approval leaves NO stage active.
	 *
	 * A completed route still showing an active stage keeps inviting actions on
	 * a decision already taken.
	 *
	 * @return void
	 */
	public function testCompletingTheLastStageLeavesNothingActive(): void {
		$service = $this->service();
		$service->instantiate(route: $this->route(), subject: 'subj-1', subjectSchema: 'proposal');

		$service->record(['subject' => 'subj-1', 'actor' => 'bob', 'action' => 'approved']);
		$service->record(['subject' => 'subj-1', 'actor' => 'alice', 'action' => 'endorsed']);
		$service->record(['subject' => 'subj-1', 'actor' => 'carol', 'action' => 'approved']);

		$this->assertSame(['decided', 'decided', 'decided'], $this->statuses());
		$this->assertNotContains('active', $this->statuses());
	}

	/**
	 * A further action on a finished route is refused.
	 *
	 * @return void
	 */
	public function testAnActionOnAFinishedRouteIsRefused(): void {
		$service = $this->service();
		$service->instantiate(route: $this->route(), subject: 'subj-1', subjectSchema: 'proposal');
		$service->record(['subject' => 'subj-1', 'actor' => 'bob', 'action' => 'approved']);
		$service->record(['subject' => 'subj-1', 'actor' => 'alice', 'action' => 'endorsed']);
		$service->record(['subject' => 'subj-1', 'actor' => 'carol', 'action' => 'approved']);

		$this->expectException(RuntimeException::class);
		$service->record(['subject' => 'subj-1', 'actor' => 'carol', 'action' => 'approved']);
	}

	/**
	 * A return re-opens the named step and resets the ones after it.
	 *
	 * @return void
	 */
	public function testAReturnRewindsTheRoute(): void {
		$service = $this->service();
		$service->instantiate(route: $this->route(), subject: 'subj-1', subjectSchema: 'proposal');
		$service->record(['subject' => 'subj-1', 'actor' => 'bob', 'action' => 'approved']);
		$service->record(['subject' => 'subj-1', 'actor' => 'alice', 'action' => 'endorsed']);

		// Now on step 3; send it back to step 2.
		$service->record(['subject' => 'subj-1', 'actor' => 'carol', 'action' => 'returned', 'returnToStep' => 2]);

		$this->assertSame(['decided', 'active', 'pending'], $this->statuses());
		$this->assertSame('approved', $this->stages()[0]['outcome'], 'Steps before the target keep their outcome.');
		$this->assertNull($this->stages()[1]['outcome'], 'A re-opened stage must not still read as decided.');
	}

	/**
	 * A return does NOT delete the actions it undid.
	 *
	 * The stages say where the route is; the actions say what happened. A
	 * return that erased the trail would remove exactly the history that makes
	 * a sign-off auditable.
	 *
	 * @return void
	 */
	public function testAReturnPreservesTheActionTrail(): void {
		$service = $this->service();
		$service->instantiate(route: $this->route(), subject: 'subj-1', subjectSchema: 'proposal');
		$service->record(['subject' => 'subj-1', 'actor' => 'bob', 'action' => 'approved']);
		$service->record(['subject' => 'subj-1', 'actor' => 'alice', 'action' => 'endorsed']);
		$service->record(['subject' => 'subj-1', 'actor' => 'carol', 'action' => 'returned', 'returnToStep' => 2]);
		$service->record(['subject' => 'subj-1', 'actor' => 'alice', 'action' => 'endorsed']);

		$actions = array_values($this->objectService->rows['approval-action']);
		$this->assertCount(4, $actions, 'Every action is a new row; none is an edit of an earlier one.');
		$verbs = array_map(static fn (array $a): string => (string)$a['action'], $actions);
		$this->assertSame(['approved', 'endorsed', 'returned', 'endorsed'], $verbs);
	}

	/**
	 * A return may not point forwards.
	 *
	 * @return void
	 */
	public function testAReturnCannotPointForwards(): void {
		$service = $this->service();
		$service->instantiate(route: $this->route(), subject: 'subj-1', subjectSchema: 'proposal');
		$service->record(['subject' => 'subj-1', 'actor' => 'bob', 'action' => 'approved']);

		$before = $this->statuses();
		try {
			$service->record(['subject' => 'subj-1', 'actor' => 'alice', 'action' => 'returned', 'returnToStep' => 3]);
			$this->fail('A forward return must be refused.');
		} catch (RuntimeException) {
			$this->assertSame($before, $this->statuses(), 'A refused return must change nothing.');
		}
	}

	/**
	 * A mandatory stage cannot be skipped.
	 *
	 * @return void
	 */
	public function testAMandatoryStageCannotBeSkipped(): void {
		$service = $this->service();
		$service->instantiate(route: $this->route(), subject: 'subj-1', subjectSchema: 'proposal');

		$before = $this->statuses();
		try {
			$service->record(['subject' => 'subj-1', 'actor' => 'bob', 'action' => 'skipped']);
			$this->fail('Skipping a mandatory stage must be refused.');
		} catch (RuntimeException) {
			$this->assertSame($before, $this->statuses());
			$this->assertSame([], $this->objectService->rows['approval-action'], 'A refused action is not recorded.');
		}
	}

	/**
	 * An optional stage can be skipped, and the route advances past it.
	 *
	 * @return void
	 */
	public function testAnOptionalStageCanBeSkipped(): void {
		$service = $this->service();
		$service->instantiate(route: $this->route(thirdMandatory: false), subject: 'subj-1', subjectSchema: 'proposal');
		$service->record(['subject' => 'subj-1', 'actor' => 'bob', 'action' => 'approved']);
		$service->record(['subject' => 'subj-1', 'actor' => 'alice', 'action' => 'endorsed']);
		$service->record(['subject' => 'subj-1', 'actor' => 'carol', 'action' => 'skipped']);

		$this->assertSame(['decided', 'decided', 'skipped'], $this->statuses());
	}

	/**
	 * An actor the stage does not name is refused, and nothing is recorded.
	 *
	 * @return void
	 */
	public function testTheWrongActorIsRefused(): void {
		$service = $this->service();
		$service->instantiate(route: $this->route(), subject: 'subj-1', subjectSchema: 'proposal');
		$service->record(['subject' => 'subj-1', 'actor' => 'bob', 'action' => 'approved']);

		// Step 2 is assigned to alice.
		$before = $this->statuses();
		try {
			$service->record(['subject' => 'subj-1', 'actor' => 'mallory', 'action' => 'endorsed']);
			$this->fail('An unassigned actor must be refused.');
		} catch (RuntimeException) {
			$this->assertSame($before, $this->statuses());
			$this->assertCount(1, $this->objectService->rows['approval-action'], 'The refused action must not be stored.');
		}
	}

	/**
	 * A step naming a ROLE accepts any actor, and does not store the role as a person.
	 *
	 * Writing a role token into `assignedPerson` would make every actor check
	 * compare a uid against a role name — and refuse everyone.
	 *
	 * @return void
	 */
	public function testARoleStepDoesNotStoreTheRoleAsAPerson(): void {
		$this->service()->instantiate(route: $this->route(), subject: 'subj-1', subjectSchema: 'proposal');

		$this->assertSame('', $this->stages()[0]['assignedPerson']);
		$this->assertSame('alice', $this->stages()[1]['assignedPerson'], 'A person step DOES name its person.');
	}

	/**
	 * A delegate's action records the principal and the mandate.
	 *
	 * @return void
	 */
	public function testADelegateActionRecordsThePrincipal(): void {
		$service = $this->service();
		$service->instantiate(route: $this->route(), subject: 'subj-1', subjectSchema: 'proposal');

		$service->record([
			'subject' => 'subj-1',
			'actor' => 'bob',
			'action' => 'approved',
			'actorType' => 'delegate',
			'onBehalfOf' => 'dave',
			'mandate' => 'mandaat-2026-14',
		]);

		$action = array_values($this->objectService->rows['approval-action'])[0];
		$this->assertSame('delegate', $action['actorType']);
		$this->assertSame('dave', $action['onBehalfOf']);
		$this->assertSame('mandaat-2026-14', $action['mandate']);
	}

	/**
	 * An unknown verb is refused rather than treated as a completion.
	 *
	 * @return void
	 */
	public function testAnUnknownActionIsRefused(): void {
		$service = $this->service();
		$service->instantiate(route: $this->route(), subject: 'subj-1', subjectSchema: 'proposal');

		$this->expectException(RuntimeException::class);
		$service->record(['subject' => 'subj-1', 'actor' => 'bob', 'action' => 'teleported']);
	}
}
