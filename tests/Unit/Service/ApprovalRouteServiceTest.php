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

use OCA\Decidiq\Service\ApprovalPrincipal;
use OCA\Decidiq\Service\ApprovalRouteAdvancer;
use OCA\Decidiq\Service\ApprovalRouteService;
use OCA\Decidiq\Service\ApprovalRouteStepMapper;
use OCA\Decidiq\Service\ApprovalStageGuard;
use OCA\Decidiq\Service\DecisionStageLabelRepair;
use OCA\Decidiq\Service\HeldRouteRequest;
use OCA\Decidiq\Service\MandateDirectory;
use OCA\Decidiq\Service\RegisterObjectStore;
use OCA\Decidiq\Service\StepThresholdRecomputer;
use OCA\Decidiq\Service\WorkingDayDeadlineSplitter;
use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectPatch;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;

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
			 * Required properties per schema, mirroring lib/Settings — the fake
			 * refuses a write exactly where live OpenRegister does. The previous
			 * fake MERGED a uuid-bearing save, which live OR does not: it
			 * validates the payload as a FULL REPLACE and 400s on every missing
			 * required property. That fake could not fail, and the suite stayed
			 * green while every route advance 400'd in production.
			 *
			 * @var array<string, array<int, string>>
			 */
			private const REQUIRED = [
				'decision-stage' => ['sequence', 'stageType', 'status', 'decisionMakerType', 'label'],
				'approval-action' => ['subject', 'step', 'actor', 'action'],
				'bevoegdheidstoedeling' => ['type', 'subject', 'decision', 'validFrom', 'status'],
			];

			/**
			 * Stored rows, keyed by schema then uuid.
			 *
			 * @var array<string, array<string, array<string, mixed>>>
			 */
			public array $rows = ['decision-stage' => [], 'approval-action' => [], 'bevoegdheidstoedeling' => []];

			/**
			 * @var int
			 */
			private int $counter = 0;

			/**
			 * Create or fully replace a row, validating as live OR does.
			 *
			 * @param array<string, mixed> $object The COMPLETE object.
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
				}

				// A uuid-bearing save REPLACES, as live OpenRegister does, and
				// validates the payload whole — a partial payload must blow up
				// here the way it blows up on the rig, or this fake cannot fail.
				$this->assertRequired(schema: $schema, object: $object);
				$this->rows[$schema][$uuid] = ($object + ['id' => $uuid]);

				return $this->rows[$schema][$uuid];
			}

			/**
			 * Merge a partial payload, as OR's patchObject() does (RFC 7386
			 * shaped): absent keys are preserved, an explicit null removes the
			 * key, and the MERGED result is validated against the schema.
			 *
			 * @param string $objectId The uuid.
			 * @param array<string, mixed> $data The partial data.
			 * @param string $register The register.
			 * @param string $schema The schema.
			 *
			 * @return array<string, mixed> The patched row.
			 */
			public function patchObject(string $objectId, array $data, string $register, string $schema): array {
				$merged = ($this->rows[$schema][$objectId] ?? []);
				foreach ($data as $key => $value) {
					if ($value === null) {
						unset($merged[$key]);
						continue;
					}

					$merged[$key] = $value;
				}

				$this->assertRequired(schema: $schema, object: $merged);
				$this->rows[$schema][$objectId] = ($merged + ['id' => $objectId]);

				return $this->rows[$schema][$objectId];
			}

			/**
			 * Refuse a payload missing required schema properties.
			 *
			 * @param string $schema The schema.
			 * @param array<string, mixed> $object The payload to validate.
			 *
			 * @return void
			 *
			 * @throws RuntimeException When required properties are missing.
			 */
			private function assertRequired(string $schema, array $object): void {
				$missing = [];
				foreach ((self::REQUIRED[$schema] ?? []) as $property) {
					if (array_key_exists($property, $object) === false) {
						$missing[] = $property;
					}
				}

				if ($missing !== []) {
					throw new RuntimeException('required properties (' . implode(', ', $missing) . ') are missing');
				}
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

				if (isset($filters['id']) === true || isset($filters['uuid']) === true) {
					// Live OpenRegister matches filters against the object's own
					// JSON properties; identity lives in @self, so a top-level
					// id/uuid filter matches NOTHING. A fake resolving it would
					// agree with the caller's bug and could not fail (dossiq#1686).
					return [];
				}

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
		$store = $this->store();

		// NAMED arguments, not positional. The two nullable tail parameters were
		// added by two different changes, and the second to merge takes the
		// position the first one had: a positional call would then hand the
		// splitter to the activator's slot and fail on a type nobody changed.
		return new ApprovalRouteService(
			store: $store,
			guard: new ApprovalStageGuard(new MandateDirectory($store)),
			mapper: new ApprovalRouteStepMapper(),
			projector: null,
			activator: null,
			splitter: new WorkingDayDeadlineSplitter(),
		);
	}

	/**
	 * Three colleagues, in order, on a document with a deadline.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/document-approval-chain-leaf/specs/approval-routes/spec.md (REQ-AR-008)
	 */
	public function testARouteIsHeldFromNamedPeopleInOrder(): void {
		$stages = $this->service()->holdFor(request: new HeldRouteRequest(subject: 'document-1', subjectSchema: 'decision', actors: ['j.jansen', 'd.devries', 'b.bakker']));

		$this->assertCount(3, $stages);
		$this->assertSame(
			['j.jansen', 'd.devries', 'b.bakker'],
			array_map(static fn (array $stage): string => (string)$stage['assignedPerson'], $stages)
		);
		$this->assertSame([1, 2, 3], array_map(static fn (array $stage): int => (int)$stage['sequence'], $stages));

		// Only the first step is live: a route whose every step is live is not a
		// sequence, it is three people asked at once.
		$this->assertSame('active', (string)$stages[0]['status']);
		$this->assertSame('pending', (string)$stages[1]['status']);
		$this->assertSame('pending', (string)$stages[2]['status']);
	}//end testARouteIsHeldFromNamedPeopleInOrder()

	/**
	 * A held route says it had no template, so a surface that finds no route row
	 * can tell that from one somebody deleted.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/document-approval-chain-leaf/specs/approval-routes/spec.md (REQ-AR-008)
	 */
	public function testAHeldRouteIsMarkedAdhoc(): void {
		$stages = $this->service()->holdFor(request: new HeldRouteRequest(subject: 'document-2', subjectSchema: 'decision', actors: ['j.jansen']));

		$this->assertSame('adhoc', (string)$stages[0]['origin']);
	}//end testAHeldRouteIsMarkedAdhoc()

	/**
	 * One deadline is divided over the steps, the last landing on it.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/document-approval-chain-leaf/specs/approval-routes/spec.md (REQ-AR-009)
	 */
	public function testOneDeadlineIsDividedOverTheSteps(): void {
		$deadline = (new \DateTimeImmutable('+30 days'))->format(\DateTimeImmutable::ATOM);

		$stages = $this->service()->holdFor(request: (new HeldRouteRequest(subject: 'document-3', subjectSchema: 'decision', actors: ['j.jansen', 'd.devries', 'b.bakker']))->due(deadline: $deadline, askSubstituteAfter: null));

		$dueDates = array_map(static fn (array $stage): string => (string)$stage['dueAt'], $stages);

		$this->assertCount(3, array_filter($dueDates));
		$this->assertSame($deadline, $dueDates[2], 'The last step must land on the deadline itself.');
		$this->assertLessThan($dueDates[1], $dueDates[0]);
		$this->assertLessThan($dueDates[2], $dueDates[1]);
	}//end testOneDeadlineIsDividedOverTheSteps()

	/**
	 * A route held from named people can ask each approver's substitute part
	 * way through the step, which the lapse sweep reads off the stage (#1397).
	 *
	 * @return void
	 *
	 * @spec openspec/specs/approval-routes/spec.md (REQ-AR-016)
	 */
	public function testAHeldRouteCanAskTheSubstitute(): void {
		$stages = $this->service()->holdFor(request: (new HeldRouteRequest(subject: 'document-3b', subjectSchema: 'decision', actors: ['j.jansen', 'd.devries']))->due(deadline: (new \DateTimeImmutable('+30 days'))->format(\DateTimeImmutable::ATOM), askSubstituteAfter: 0.5));

		foreach ($this->stages() as $stage) {
			$this->assertSame(0.5, $stage['askSubstituteAfter'] ?? null, 'Every held step must carry askSubstituteAfter');
		}

		$this->assertCount(2, $stages);
	}//end testAHeldRouteCanAskTheSubstitute()

	/**
	 * No deadline means no due dates, which means a route that never lapses.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/document-approval-chain-leaf/specs/approval-routes/spec.md (REQ-AR-009)
	 */
	public function testNoDeadlineWritesNoDueDates(): void {
		$stages = $this->service()->holdFor(request: new HeldRouteRequest(subject: 'document-4', subjectSchema: 'decision', actors: ['j.jansen', 'd.devries']));

		foreach ($stages as $stage) {
			$this->assertSame('', (string)($stage['dueAt'] ?? ''));
		}
	}//end testNoDeadlineWritesNoDueDates()

	/**
	 * A person named twice is asked once. The second ask would be refused by the
	 * guard anyway, because the first sign-off already advanced past their step.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/document-approval-chain-leaf/specs/approval-routes/spec.md (REQ-AR-008)
	 */
	public function testAPersonNamedTwiceIsAskedOnce(): void {
		$stages = $this->service()->holdFor(request: new HeldRouteRequest(subject: 'document-5', subjectSchema: 'decision', actors: ['j.jansen', 'd.devries', 'j.jansen', '']));

		$this->assertCount(2, $stages);
	}//end testAPersonNamedTwiceIsAskedOnce()

	/**
	 * A route with nobody on it is refused, not created empty.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/document-approval-chain-leaf/specs/approval-routes/spec.md (REQ-AR-008)
	 */
	public function testAHeldRouteWithNobodyOnItIsRefused(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('needs at least one person');

		$this->service()->holdFor(request: new HeldRouteRequest(subject: 'document-6', subjectSchema: 'decision', actors: ['', '  ']));
	}//end testAHeldRouteWithNobodyOnItIsRefused()

	/**
	 * An unreadable deadline refuses rather than inventing a term.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/document-approval-chain-leaf/specs/approval-routes/spec.md (REQ-AR-009)
	 */
	public function testAnUnreadableDeadlineIsRefused(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('could not be read as a date');

		$this->service()->holdFor(request: (new HeldRouteRequest(subject: 'document-7', subjectSchema: 'decision', actors: ['j.jansen']))->due(deadline: 'end of the month', askSubstituteAfter: null));
	}//end testAnUnreadableDeadlineIsRefused()

	/**
	 * Overdue is COMPUTED, and only for a step that is still waiting.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/document-approval-chain-leaf/specs/approval-routes/spec.md (REQ-AR-009)
	 */
	public function testOverdueIsComputedAndOnlyForAWaitingStep(): void {
		$service = $this->service();
		$past = ['status' => 'active', 'dueAt' => '2026-01-01T09:00:00+00:00'];

		$this->assertTrue($service->isOverdue($past));
		$this->assertFalse($service->isOverdue(['status' => 'decided', 'dueAt' => '2026-01-01T09:00:00+00:00']));
		$this->assertFalse($service->isOverdue(['status' => 'active', 'dueAt' => '']));
		$this->assertFalse($service->isOverdue(['status' => 'active', 'dueAt' => 'not a date']));
		$this->assertFalse($service->isOverdue(['status' => 'active', 'dueAt' => '2099-01-01T09:00:00+00:00']));
	}//end testOverdueIsComputedAndOnlyForAWaitingStep()

	/**
	 * A store over the stateful fake.
	 *
	 * @return RegisterObjectStore The store.
	 */
	private function store(): RegisterObjectStore {
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
			function (array $object, ObjectSaveOptions $options = new ObjectSaveOptions()) use ($state): ObjectEntityInterface {
				$extend = $options->extend ?? [];
				$register = $options->target->register;
				$schema = $options->target->schema;
				$uuid = $options->uuid;
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
		// patchObject() mirrors the contract's real signature for the same
		// positional-forwarding reason as saveObject() above.
		$facade->method('patchObject')->willReturnCallback(
			function (ObjectPatch $patch) use ($state): ObjectEntityInterface {
				$objectId = $patch->objectId;
				$data = $patch->data;
				$register = $patch->target->register;
				$schema = $patch->target->schema;
				$row = $state->patchObject($objectId, $data, (string)$register, (string)$schema);

				$entity = $this->createMock(ObjectEntityInterface::class);
				$entity->method('jsonSerialize')->willReturn($row);

				return $entity;
			}
		);
		$facade->method('findAll')->willReturnCallback(
			static fn (array $config = []): array => $state->findAll($config)
		);
		// find() resolves by uuid, as live OpenRegister does — the resolving
		// form a top-level 'id' filter never was.
		$facade->method('find')->willReturnCallback(
			function (int|string $id, ?ObjectFindOptions $options = null) use ($state): ?ObjectEntityInterface {
				$_extend = $options?->extend ?? [];
				$files = $options?->files ?? false;
				$register = $options?->register;
				$schema = $options?->schema;
				$row = ($state->rows[(string)$schema][(string)$id] ?? null);
				if ($row === null) {
					return null;
				}

				$entity = $this->createMock(ObjectEntityInterface::class);
				$entity->method('jsonSerialize')->willReturn($row);

				return $entity;
			}
		);

		return new RegisterObjectStore($facade);
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

		$service->record(['subject' => 'subj-1', 'step' => 1, 'actor' => 'bob', 'action' => 'advised', 'advice' => 'Akkoord']);

		$this->assertSame(['decided', 'active', 'pending'], $this->statuses());
		$this->assertSame('advised', $this->stages()[0]['outcome']);
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

		$service->record(['subject' => 'subj-1', 'actor' => 'bob', 'action' => 'advised', 'advice' => 'Akkoord']);
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
		$service->record(['subject' => 'subj-1', 'actor' => 'bob', 'action' => 'advised', 'advice' => 'Akkoord']);
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
		$service->record(['subject' => 'subj-1', 'actor' => 'bob', 'action' => 'advised', 'advice' => 'Akkoord']);
		$service->record(['subject' => 'subj-1', 'actor' => 'alice', 'action' => 'endorsed']);

		// Now on step 3; send it back to step 2.
		$service->record(['subject' => 'subj-1', 'actor' => 'carol', 'action' => 'returned', 'returnToStep' => 2, 'comment' => 'Onvolledig']);

		$this->assertSame(['decided', 'active', 'pending'], $this->statuses());
		$this->assertSame('advised', $this->stages()[0]['outcome'], 'Steps before the target keep their outcome.');
		$this->assertNull($this->stages()[1]['outcome'] ?? null, 'A re-opened stage must not still read as decided.');
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
		$service->record(['subject' => 'subj-1', 'actor' => 'bob', 'action' => 'advised', 'advice' => 'Akkoord']);
		$service->record(['subject' => 'subj-1', 'actor' => 'alice', 'action' => 'endorsed']);
		$service->record(['subject' => 'subj-1', 'actor' => 'carol', 'action' => 'returned', 'returnToStep' => 2, 'comment' => 'Onvolledig']);
		$service->record(['subject' => 'subj-1', 'actor' => 'alice', 'action' => 'endorsed']);

		$actions = array_values($this->objectService->rows['approval-action']);
		$this->assertCount(4, $actions, 'Every action is a new row; none is an edit of an earlier one.');
		$verbs = array_map(static fn (array $a): string => (string)$a['action'], $actions);
		$this->assertSame(['advised', 'endorsed', 'returned', 'endorsed'], $verbs);
	}

	/**
	 * A return may not point forwards.
	 *
	 * @return void
	 */
	public function testAReturnCannotPointForwards(): void {
		$service = $this->service();
		$service->instantiate(route: $this->route(), subject: 'subj-1', subjectSchema: 'proposal');
		$service->record(['subject' => 'subj-1', 'actor' => 'bob', 'action' => 'advised', 'advice' => 'Akkoord']);

		$before = $this->statuses();
		try {
			$service->record(['subject' => 'subj-1', 'actor' => 'alice', 'action' => 'returned', 'returnToStep' => 3, 'comment' => 'Terug']);
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
		$service->record(['subject' => 'subj-1', 'actor' => 'bob', 'action' => 'advised', 'advice' => 'Akkoord']);
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
		$service->record(['subject' => 'subj-1', 'actor' => 'bob', 'action' => 'advised', 'advice' => 'Akkoord']);

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
			'action' => 'advised',
			'advice' => 'Namens Dave: akkoord',
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

	/**
	 * A route whose steps carry no labels still instantiates valid stages.
	 *
	 * The cross-app case: dossiq's held routes declare no step labels, and the
	 * decision-stage schema REQUIRES one. Writing '' stored NULL, and the
	 * patch recording the FIRST sign-off then 400'd — every cross-app route
	 * wedged on its first signature. The label is derived instead, and the
	 * route must actually advance.
	 *
	 * @return void
	 */
	public function testAnUnlabeledRouteGetsDerivedLabelsAndAdvances(): void {
		$route = [
			'name' => 'Cross-app route',
			'steps' => [
				['order' => 1, 'stageType' => 'endorsement', 'actorType' => 'person', 'actor' => 'alice'],
				['order' => 2, 'stageType' => 'decisive', 'actorType' => 'person', 'actor' => 'carol'],
			],
		];

		$service = $this->service();
		$service->instantiate(route: $route, subject: 'subj-1', subjectSchema: 'proposal');

		$this->assertSame(
			['Endorsement (step 1)', 'Decisive (step 2)'],
			array_map(static fn (array $s): string => (string)$s['label'], $this->stages()),
			'A step without a label gets one derived from its stage type and step number.'
		);

		// The first sign-off is the one that 400'd; it must advance now.
		$service->record(['subject' => 'subj-1', 'actor' => 'alice', 'action' => 'endorsed']);
		$this->assertSame(['decided', 'active'], $this->statuses());
	}

	/**
	 * A stage write that fails leaves NO action row behind.
	 *
	 * The old order appended the action FIRST and patched the stage after, so
	 * a refused stage write left an orphan action row claiming a sign-off the
	 * route never took — and every retry appended another. The stage write
	 * comes first now; this pins the no-orphan property on exactly the shape
	 * that produced the orphans: a legacy stage stored without its required
	 * label, whose re-validation refuses the patch.
	 *
	 * @return void
	 */
	public function testAFailedStageWriteAppendsNoActionRow(): void {
		$service = $this->service();
		$service->instantiate(route: $this->route(), subject: 'subj-1', subjectSchema: 'proposal');

		// A legacy NULL-label stage: stored before the label fix.
		foreach ($this->objectService->rows['decision-stage'] as $uuid => $row) {
			unset($this->objectService->rows['decision-stage'][$uuid]['label']);
		}

		$before = $this->statuses();
		try {
			$service->record(['subject' => 'subj-1', 'actor' => 'bob', 'action' => 'advised', 'advice' => 'Akkoord']);
			$this->fail('The stage patch must refuse a stage that no longer validates.');
		} catch (RuntimeException) {
			$this->assertSame(
				[],
				$this->objectService->rows['approval-action'],
				'A failed stage write must leave no orphan action row.'
			);
			$this->assertSame($before, $this->statuses(), 'The refused advance changes no stage.');
		}
	}

	/**
	 * The label repair backfills legacy NULL-label stages, once.
	 *
	 * Idempotent: the second run finds every stage labeled and repairs
	 * nothing. Existing action rows are never touched — they are the audit
	 * record. The derivation is the mapper's own labelOf(), so the repaired
	 * label and a freshly instantiated one cannot differ.
	 *
	 * @return void
	 */
	public function testTheLabelRepairBackfillsAndIsIdempotent(): void {
		$service = $this->service();
		$service->instantiate(route: $this->route(), subject: 'subj-1', subjectSchema: 'proposal');
		$service->record(['subject' => 'subj-1', 'actor' => 'bob', 'action' => 'advised', 'advice' => 'Akkoord']);

		// Two legacy NULL-label stages among a healthy one.
		unset($this->objectService->rows['decision-stage']['decision-stage-2']['label']);
		unset($this->objectService->rows['decision-stage']['decision-stage-3']['label']);

		$repair = new DecisionStageLabelRepair($this->store(), new ApprovalRouteStepMapper());

		$this->assertSame(2, $repair->repair(), 'Exactly the unlabeled stages are repaired.');
		$this->assertSame(
			['Advies', 'Endorsement (step 2)', 'Decisive (step 3)'],
			array_map(static fn (array $s): string => (string)$s['label'], $this->stages()),
			'A stored label survives; a missing one is derived.'
		);
		$this->assertCount(1, $this->objectService->rows['approval-action'], 'The action trail is never edited by the repair.');

		$this->assertSame(0, $repair->repair(), 'A re-run repairs nothing.');
	}

	/**
	 * A route step whose silence APPROVES is refused when the person asking is
	 * not an administrator.
	 *
	 * REQ-AR-015 says silence that approves is a signature nobody gave. The
	 * policy that says so has always been right and, until the engine called it,
	 * enforced nothing: this asserts the WIRING, so deleting the call in
	 * instantiate() reddens here rather than somewhere nobody looks.
	 *
	 * The principal is an ordinary signed-in user, which is the least privileged
	 * one that should be refused. An anonymous caller never reaches the engine.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/approval-routes/spec.md (REQ-AR-015)
	 */
	public function testAnOrdinaryUserCannotDeclareThatSilenceApproves(): void {
		$route = $this->route();
		$route['steps'][1]['onSilence'] = 'approve';

		// expectException, NOT try/fail/catch: PHPUnit's AssertionFailedError
		// extends RuntimeException, so a catch of RuntimeException swallows the
		// fail() that is supposed to end the test and reports the wrong thing.
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessageMatches('/administrator/');

		$this->service()->instantiate(
			route: $route,
			subject: 'subj-1',
			subjectSchema: 'proposal',
			principal: ApprovalPrincipal::Ordinary,
		);
	}

	/**
	 * And the refusal lands BEFORE any stage is written, so the subject is not
	 * left carrying half a route.
	 *
	 * The offending step is the SECOND one deliberately: a guard inside the
	 * write loop would already have written the first stage by the time it threw,
	 * and this is the assertion that tells those two designs apart.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/approval-routes/spec.md (REQ-AR-015)
	 */
	public function testTheRefusedRouteWritesNoStageAtAll(): void {
		$route = $this->route();
		$route['steps'][1]['onSilence'] = 'approve';

		try {
			$this->service()->instantiate(
				route: $route,
				subject: 'subj-1',
				subjectSchema: 'proposal',
				principal: ApprovalPrincipal::Ordinary,
			);
		} catch (RuntimeException) {
			// The refusal is the other test's subject; this one is about what
			// it left behind.
		}

		$this->assertSame([], $this->stages(), 'A refused route left stages behind.');
	}

	/**
	 * An administrator may declare it, and the stage carries it.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/approval-routes/spec.md (REQ-AR-015)
	 */
	public function testAnAdministratorMayDeclareThatSilenceApproves(): void {
		$route = $this->route();
		$route['steps'][1]['onSilence'] = 'approve';

		$this->service()->instantiate(
			route: $route,
			subject: 'subj-1',
			subjectSchema: 'proposal',
			principal: ApprovalPrincipal::Administrator,
		);

		$this->assertSame('approve', (string)($this->stages()[1]['onSilence'] ?? ''));
	}

	/**
	 * Every stored route is unaffected: a step that declares no silence, or
	 * declares one anybody may set, still instantiates for an ordinary user.
	 *
	 * This is the compatibility half. A guard that also refused `hold` would
	 * have stopped every route already in flight, and nothing would have said so
	 * until somebody tried to sign one.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/approval-routes/spec.md (REQ-AR-015)
	 */
	public function testAStoredRouteWithoutAnApprovingSilenceStillInstantiates(): void {
		$route = $this->route();
		$route['steps'][1]['onSilence'] = 'escalate';

		$this->service()->instantiate(
			route: $route,
			subject: 'subj-1',
			subjectSchema: 'proposal',
			principal: ApprovalPrincipal::Ordinary,
		);

		$this->assertCount(3, $this->stages(), 'An ordinary route stopped instantiating.');
	}


	/**
	 * A stage carries the step configuration it is running under.
	 *
	 * These four lived only on the ApprovalRoute step, which is the template, so
	 * the three services that read a step's configuration had nothing to read at
	 * runtime even once they were called. The stage is what the engine and the
	 * sweeps actually hold, so the configuration has to travel onto it.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/the-decision-as-a-walked-process/specs/decision-as-a-walked-process/spec.md (REQ-DWP-002, REQ-DWP-003, REQ-DWP-004)
	 */
	public function testAStageCarriesItsStepConfiguration(): void {
		$route = $this->route();
		$route['steps'][0]['thresholdKind'] = 'share';
		$route['steps'][0]['thresholdValue'] = 0.6;
		$route['steps'][0]['approvalBasis'] = ['body', 'attachments'];
		$route['steps'][0]['stepKind'] = 'intake';

		$this->service()->instantiate(route: $route, subject: 'subj-1', subjectSchema: 'proposal');

		$stage = $this->stages()[0];
		$this->assertSame('share', $stage['thresholdKind']);
		$this->assertSame(0.6, $stage['thresholdValue']);
		$this->assertSame(['body', 'attachments'], $stage['approvalBasis']);
		$this->assertSame('intake', $stage['stepKind']);
	}

	/**
	 * A step that declares none of them writes none of them, so every stored
	 * route keeps its meaning and each schema default stands.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/the-decision-as-a-walked-process/specs/decision-as-a-walked-process/spec.md (REQ-DWP-002)
	 */
	public function testAStageDeclaringNoStepConfigurationCarriesNone(): void {
		$this->service()->instantiate(route: $this->route(), subject: 'subj-1', subjectSchema: 'proposal');

		$stage = $this->stages()[0];
		foreach (['thresholdKind', 'thresholdValue', 'approvalBasis', 'stepKind'] as $key) {
			$this->assertArrayNotHasKey($key, $stage, sprintf('%s was written for a step that declared nothing.', $key));
		}
	}

	/**
	 * An EMPTY approval basis is dropped rather than stored.
	 *
	 * An empty basis never withdraws anything, which is what keeps staleness
	 * opt-in. Storing `[]` would read as declared-and-empty to the next person,
	 * which is a different claim from "this step named no basis".
	 *
	 * @return void
	 *
	 * @spec openspec/changes/the-decision-as-a-walked-process/specs/decision-as-a-walked-process/spec.md (REQ-DWP-003)
	 */
	public function testAnEmptyApprovalBasisIsNotStored(): void {
		$route = $this->route();
		$route['steps'][0]['approvalBasis'] = [];

		$this->service()->instantiate(route: $route, subject: 'subj-1', subjectSchema: 'proposal');

		$this->assertArrayNotHasKey('approvalBasis', $this->stages()[0]);
	}


	/**
	 * Seed an approval action row straight into the fake, as a stored row.
	 *
	 * @param string               $uuid The row's uuid.
	 * @param array<string, mixed> $row  The row.
	 *
	 * @return void
	 */
	private function seedAction(string $uuid, array $row): void {
		$this->objectService->rows['approval-action'][$uuid] = ($row + ['id' => $uuid]);
	}

	/**
	 * Every recorded act carries the state it ends in, so none reads as open.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/the-decision-as-a-walked-process/specs/decision-as-a-walked-process/spec.md (REQ-DWP-001)
	 */
	public function testARecordedActCarriesItsTerminalState(): void {
		$service = $this->service();
		$service->instantiate(route: $this->route(), subject: 'subj-1', subjectSchema: 'proposal');
		$service->record(['subject' => 'subj-1', 'actor' => 'bob', 'action' => 'advised', 'advice' => 'Akkoord']);
		$service->record(['subject' => 'subj-1', 'actor' => 'alice', 'action' => 'endorsed']);
		$service->record(['subject' => 'subj-1', 'actor' => 'carol', 'action' => 'returned', 'returnToStep' => 2, 'comment' => 'Onvolledig']);

		$states = array_map(
			static fn (array $a): string => (string)$a['action'] . ':' . (string)$a['state'],
			array_values($this->objectService->rows['approval-action'])
		);
		$this->assertSame(['advised:granted', 'endorsed:granted', 'returned:refused'], $states);
	}

	/**
	 * An open mandatory approval assigned to somebody else holds the step, and
	 * the refusal names it; nothing is written.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/the-decision-as-a-walked-process/specs/decision-as-a-walked-process/spec.md (REQ-DWP-001)
	 */
	public function testAnOpenMandatoryApprovalOfSomebodyElseBlocksTheStep(): void {
		$service = $this->service();
		$service->instantiate(route: $this->route(), subject: 'subj-1', subjectSchema: 'proposal');
		$this->seedAction(uuid: 'open-1', row: ['subject' => 'subj-1', 'step' => 1, 'actor' => '', 'action' => '', 'state' => 'open', 'assignee' => 'j.jansen']);

		$before = $this->statuses();
		try {
			$service->record(['subject' => 'subj-1', 'actor' => 'bob', 'action' => 'advised', 'advice' => 'Akkoord']);
			$this->fail('An open mandatory approval must hold the step.');
		} catch (RuntimeException $e) {
			$this->assertSame('This subject is waiting on an approval: j.jansen at step 1.', $e->getMessage());
		}

		$this->assertSame($before, $this->statuses(), 'A refused advance changes no stage.');
		$this->assertSame(['open-1'], array_keys($this->objectService->rows['approval-action']), 'A refused advance records no action.');
	}

	/**
	 * The actor's own open approval does not hold them up; their act ends it.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/the-decision-as-a-walked-process/specs/decision-as-a-walked-process/spec.md (REQ-DWP-001)
	 */
	public function testTheActorsOwnOpenApprovalIsEndedByTheirAct(): void {
		$service = $this->service();
		$service->instantiate(route: $this->route(), subject: 'subj-1', subjectSchema: 'proposal');
		$this->seedAction(uuid: 'open-1', row: ['subject' => 'subj-1', 'step' => 1, 'actor' => '', 'action' => '', 'state' => 'open', 'assignee' => 'bob']);

		$service->record(['subject' => 'subj-1', 'actor' => 'bob', 'action' => 'advised', 'advice' => 'Akkoord']);

		$this->assertSame(['decided', 'active', 'pending'], $this->statuses());
		$this->assertSame('granted', $this->objectService->rows['approval-action']['open-1']['state']);
	}

	/**
	 * Open approvals on another step, on an optional step, or rows that record
	 * an act but still read `open` (stored before the state was written) never
	 * hold the step.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/the-decision-as-a-walked-process/specs/decision-as-a-walked-process/spec.md (REQ-DWP-001)
	 */
	public function testOnlyAnOpenWorkItemOnTheMandatoryStepBlocks(): void {
		$service = $this->service();
		$service->instantiate(route: $this->route(thirdMandatory: false), subject: 'subj-1', subjectSchema: 'proposal');
		$this->seedAction(uuid: 'later', row: ['subject' => 'subj-1', 'step' => 2, 'actor' => '', 'action' => '', 'state' => 'open', 'assignee' => 'j.jansen']);
		$this->seedAction(uuid: 'legacy', row: ['subject' => 'subj-1', 'step' => 1, 'actor' => 'x', 'action' => 'advised', 'state' => 'open', 'assignee' => 'j.jansen']);
		$this->seedAction(uuid: 'other-subject', row: ['subject' => 'subj-2', 'step' => 1, 'actor' => '', 'action' => '', 'state' => 'open', 'assignee' => 'j.jansen']);

		$service->record(['subject' => 'subj-1', 'actor' => 'bob', 'action' => 'advised', 'advice' => 'Akkoord']);
		$this->assertSame(['decided', 'active', 'pending'], $this->statuses());

		try {
			$service->record(['subject' => 'subj-1', 'actor' => 'alice', 'action' => 'endorsed']);
			$this->fail('The open approval on step 2 must hold step 2.');
		} catch (RuntimeException $e) {
			$this->assertStringContainsString('j.jansen at step 2', $e->getMessage());
		}

		// An optional step is never held: skipping it is already allowed.
		$this->seedAction(uuid: 'later', row: ['subject' => 'subj-1', 'step' => 2, 'actor' => 'alice', 'action' => 'endorsed', 'state' => 'granted']);
		$service->record(['subject' => 'subj-1', 'actor' => 'alice', 'action' => 'endorsed']);
		$this->seedAction(uuid: 'optional', row: ['subject' => 'subj-1', 'step' => 3, 'actor' => '', 'action' => '', 'state' => 'open', 'assignee' => 'j.jansen']);
		$service->record(['subject' => 'subj-1', 'actor' => 'carol', 'action' => 'skipped']);
		$this->assertSame(['decided', 'decided', 'skipped'], $this->statuses());
	}

	/**
	 * A route opening with an intake step, on a decision.
	 *
	 * @return array<string, mixed> The route.
	 */
	private function intakeRoute(): array {
		return [
			'name' => 'Intake route',
			'steps' => [
				['order' => 1, 'stageType' => 'decisive', 'stepKind' => 'intake', 'actorType' => 'person', 'actor' => 'alice', 'label' => 'Ontvankelijkheid'],
				['order' => 2, 'stageType' => 'endorsement', 'actorType' => 'person', 'actor' => 'bob', 'label' => 'Parafering'],
			],
		];
	}

	/**
	 * A niet-ontvankelijk verdict closes the route at intake and no later step becomes live.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/the-decision-as-a-walked-process/specs/decision-as-a-walked-process/spec.md (REQ-DWP-004)
	 */
	public function testAnInadmissibleRequestEndsTheRouteAtIntake(): void {
		$service = $this->service();
		$service->instantiate(route: $this->intakeRoute(), subject: 'dec-1', subjectSchema: 'decision');

		$recorded = $service->record(['subject' => 'dec-1', 'actor' => 'alice', 'action' => 'niet-ontvankelijk', 'ground' => 'Artikel 4:5 Awb']);

		$this->assertSame(['decided', 'pending'], $this->statuses());
		$this->assertSame('ended-at-intake', $this->stages()[0]['outcome']);
		$this->assertArrayNotHasKey('outcome', $this->stages()[1]);
		$this->assertSame('niet-ontvankelijk', $recorded['action']);
		$this->assertSame('refused', $recorded['state']);
		$this->assertSame('Artikel 4:5 Awb', $recorded['comment']);
		$this->assertSame(
			['ontvankelijkheid' => 'niet-ontvankelijk', 'ontvankelijkheidGround' => 'Artikel 4:5 Awb', 'ontvankelijkheidDecidedBy' => 'alice', 'id' => 'dec-1'],
			$this->objectService->rows['decision']['dec-1']
		);
	}

	/**
	 * An ontvankelijk verdict completes the intake step and the route walks on.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/the-decision-as-a-walked-process/specs/decision-as-a-walked-process/spec.md (REQ-DWP-004)
	 */
	public function testAnAdmissibleRequestWalksOn(): void {
		$service = $this->service();
		$service->instantiate(route: $this->intakeRoute(), subject: 'dec-1', subjectSchema: 'decision');

		$recorded = $service->record(['subject' => 'dec-1', 'actor' => 'alice', 'action' => 'ontvankelijk']);

		$this->assertSame(['decided', 'active'], $this->statuses());
		$this->assertSame('ontvankelijk', $this->stages()[0]['outcome']);
		$this->assertSame('granted', $recorded['state']);
		$this->assertSame('ontvankelijk', $this->objectService->rows['decision']['dec-1']['ontvankelijkheid']);
	}

	/**
	 * The verdict is written onto the subject only when the subject is a decision.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/the-decision-as-a-walked-process/specs/decision-as-a-walked-process/spec.md (REQ-DWP-004)
	 */
	public function testAVerdictOnAnotherSubjectStaysOnTheRoute(): void {
		$service = $this->service();
		$service->instantiate(route: $this->intakeRoute(), subject: 'case-1', subjectSchema: 'proposal');

		$service->record(['subject' => 'case-1', 'actor' => 'alice', 'action' => 'niet-ontvankelijk', 'comment' => 'Te laat ingediend']);

		$this->assertSame('ended-at-intake', $this->stages()[0]['outcome']);
		$this->assertArrayNotHasKey('decision', $this->objectService->rows);
	}

	/**
	 * A niet-ontvankelijk verdict without a ground is refused and leaves no trace.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/the-decision-as-a-walked-process/specs/decision-as-a-walked-process/spec.md (REQ-DWP-004)
	 */
	public function testAVerdictWithoutAGroundIsRefusedAndWritesNothing(): void {
		$service = $this->service();
		$service->instantiate(route: $this->intakeRoute(), subject: 'dec-1', subjectSchema: 'decision');

		try {
			$service->record(['subject' => 'dec-1', 'actor' => 'alice', 'action' => 'niet-ontvankelijk', 'ground' => '  ']);
			$this->fail('A verdict without a ground was accepted.');
		} catch (RuntimeException $e) {
			$this->assertStringContainsString('needs the ground it rests on', $e->getMessage());
		}

		$this->assertSame(['active', 'pending'], $this->statuses());
		$this->assertSame([], $this->objectService->rows['approval-action']);
		$this->assertArrayNotHasKey('decision', $this->objectService->rows);
	}

	/**
	 * An intake step takes a verdict, not a sign-off.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/the-decision-as-a-walked-process/specs/decision-as-a-walked-process/spec.md (REQ-DWP-004)
	 */
	public function testASignOffOnAnIntakeStepIsRefused(): void {
		$service = $this->service();
		$service->instantiate(route: $this->intakeRoute(), subject: 'dec-1', subjectSchema: 'decision');

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Unknown verdict "approved"');

		$service->record(['subject' => 'dec-1', 'actor' => 'alice', 'action' => 'approved']);
	}

	/**
	 * A verdict on an approval step is no action that step knows.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/the-decision-as-a-walked-process/specs/decision-as-a-walked-process/spec.md (REQ-DWP-004)
	 */
	public function testAVerdictOnAnApprovalStepIsRefused(): void {
		$service = $this->service();
		$service->instantiate(route: $this->route(), subject: 'subj-1', subjectSchema: 'proposal');

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Unknown action: ontvankelijk');

		$service->record(['subject' => 'subj-1', 'actor' => 'bob', 'action' => 'ontvankelijk']);
	}

	/**
	 * A route whose first step is decided by a governance body.
	 *
	 * @return array<string, mixed> The route.
	 */
	private function committeeRoute(): array {
		return [
			'name' => 'Committee route',
			'steps' => [
				[
					'order' => 1, 'stageType' => 'decisive', 'stepKind' => 'committee', 'committeeBody' => 'body-college',
					'actorType' => 'person', 'actor' => 'alice', 'label' => 'College',
				],
				['order' => 2, 'stageType' => 'endorsement', 'actorType' => 'person', 'actor' => 'bob', 'label' => 'Parafering'],
			],
		];
	}

	/**
	 * A committee stage carries the body that decides it, copied from the step.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/the-decision-as-a-walked-process/specs/decision-as-a-walked-process/spec.md (REQ-DWP-010)
	 */
	public function testACommitteeStageCarriesItsBody(): void {
		$this->service()->instantiate(route: $this->committeeRoute(), subject: 'dec-1', subjectSchema: 'decision');

		$stages = $this->stages();
		$this->assertSame(expected: 'committee', actual: $stages[0]['stepKind']);
		$this->assertSame(expected: 'body-college', actual: $stages[0]['committeeBody']);
		$this->assertArrayNotHasKey(key: 'committeeBody', array: $stages[1]);
	}

	/**
	 * One actor's grant on a committee step is refused, names the body, and writes nothing.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/the-decision-as-a-walked-process/specs/decision-as-a-walked-process/spec.md (REQ-DWP-010)
	 */
	public function testOneActorCannotCloseACommitteeStep(): void {
		$service = $this->service();
		$service->instantiate(route: $this->committeeRoute(), subject: 'dec-1', subjectSchema: 'decision');

		foreach (['approved', 'skipped'] as $verb) {
			try {
				$service->record(['subject' => 'dec-1', 'actor' => 'alice', 'action' => $verb]);
				$this->fail(message: 'A single actor closed a committee step with "' . $verb . '".');
			} catch (RuntimeException $e) {
				$this->assertStringContainsString(needle: 'committee step', haystack: $e->getMessage());
				$this->assertStringContainsString(needle: 'body-college', haystack: $e->getMessage());
			}
		}

		$this->assertSame(expected: ['active', 'pending'], actual: $this->statuses());
		$this->assertSame(expected: [], actual: $this->objectService->rows['approval-action']);
	}

	/**
	 * A route whose first step is one parallel group of approvers under a threshold.
	 *
	 * @param array<string, mixed> $threshold The step's thresholdKind and thresholdValue.
	 * @param int                  $approvers How many approvers the step has.
	 *
	 * @return array<string, mixed> The route.
	 */
	private function thresholdRoute(array $threshold, int $approvers = 5): array {
		$steps = [];
		foreach (array_slice(['anna', 'bram', 'cor', 'dina', 'eva'], 0, $approvers) as $person) {
			$steps[] = $threshold + [
				'order' => 1, 'stageType' => 'decisive', 'actorType' => 'person', 'actor' => $person, 'label' => 'Board',
			];
		}

		$steps[] = ['order' => 2, 'stageType' => 'endorsement', 'actorType' => 'person', 'actor' => 'secretary', 'label' => 'Sign-off'];

		return ['name' => 'Threshold route', 'steps' => $steps];
	}

	/**
	 * The stages of the board step, keyed by the person each one waits on.
	 *
	 * @return array<string, array<string, mixed>> The stages.
	 */
	private function boardStages(): array {
		$out = [];
		foreach ($this->stages() as $stage) {
			if ((int)$stage['sequence'] === 1) {
				$out[(string)$stage['assignedPerson']] = $stage;
			}
		}

		return $out;
	}

	/**
	 * A share of 0.6 of five approvers closes the step on the third grant (REQ-DWP-002).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/the-decision-as-a-walked-process/specs/decision-as-a-walked-process/spec.md (REQ-DWP-002)
	 */
	public function testAStepClosesOnceItsShareIsReached(): void {
		$service = $this->service();
		$service->instantiate(
			route: $this->thresholdRoute(threshold: ['thresholdKind' => 'share', 'thresholdValue' => 0.6]),
			subject: 'dec-1',
			subjectSchema: 'decision'
		);

		$service->record(['subject' => 'dec-1', 'actor' => 'anna', 'action' => 'approved']);
		$service->record(['subject' => 'dec-1', 'actor' => 'bram', 'action' => 'approved']);

		$board = $this->boardStages();
		$this->assertSame(expected: 'active', actual: $board['cor']['status'], message: 'Two of five is below a share of 0.6.');
		$this->assertSame(expected: 'open', actual: $board['cor']['thresholdResult']['outcome']);
		$this->assertSame(expected: 2, actual: $board['cor']['thresholdResult']['granted']);
		$this->assertSame(expected: 3, actual: $board['cor']['thresholdResult']['required']);

		$service->record(['subject' => 'dec-1', 'actor' => 'cor', 'action' => 'approved']);

		$board = $this->boardStages();
		foreach (['dina', 'eva'] as $person) {
			$this->assertSame(expected: 'skipped', actual: $board[$person]['status'], message: $person . ' was still asked after the share was reached.');
			$this->assertTrue(condition: $board[$person]['closedByThreshold']);
		}

		$this->assertArrayNotHasKey(key: 'closedByThreshold', array: $board['cor'], message: 'An approver who answered was not closed by the threshold.');
		$expected = ['granted' => 3, 'refused' => 0, 'required' => 3, 'approvers' => 5, 'thresholdKind' => 'share', 'thresholdValue' => 0.6];
		foreach ($board as $stage) {
			$this->assertSame(expected: 'granted', actual: $stage['thresholdResult']['outcome']);
			$this->assertSame(
				expected: $expected,
				actual: array_intersect_key($stage['thresholdResult'], $expected)
			);
		}

		$this->assertSame(expected: 'active', actual: $this->stages()[5]['status'], message: 'The route did not walk on to the next step.');
	}

	/**
	 * A count threshold closes the step on that many grants (REQ-DWP-002).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/the-decision-as-a-walked-process/specs/decision-as-a-walked-process/spec.md (REQ-DWP-002)
	 */
	public function testAStepClosesOnItsCount(): void {
		$service = $this->service();
		$service->instantiate(
			route: $this->thresholdRoute(threshold: ['thresholdKind' => 'count', 'thresholdValue' => 2], approvers: 3),
			subject: 'dec-1',
			subjectSchema: 'decision'
		);

		$service->record(['subject' => 'dec-1', 'actor' => 'anna', 'action' => 'approved']);
		$this->assertSame(expected: ['decided', 'active', 'active', 'pending'], actual: $this->statuses());

		$service->record(['subject' => 'dec-1', 'actor' => 'cor', 'action' => 'approved']);
		$this->assertSame(expected: ['decided', 'skipped', 'decided', 'active'], actual: $this->statuses());
		$this->assertTrue(condition: $this->boardStages()['bram']['closedByThreshold']);
	}

	/**
	 * A step without a threshold still waits for every approver, and caches nothing.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/the-decision-as-a-walked-process/specs/decision-as-a-walked-process/spec.md (REQ-DWP-002)
	 */
	public function testAStepWithoutAThresholdWaitsForEveryone(): void {
		$service = $this->service();
		$service->instantiate(route: $this->thresholdRoute(threshold: [], approvers: 3), subject: 'dec-1', subjectSchema: 'decision');

		$service->record(['subject' => 'dec-1', 'actor' => 'anna', 'action' => 'approved']);
		$service->record(['subject' => 'dec-1', 'actor' => 'bram', 'action' => 'approved']);

		$this->assertSame(expected: ['decided', 'decided', 'active', 'pending'], actual: $this->statuses());
		foreach ($this->boardStages() as $stage) {
			$this->assertArrayNotHasKey(key: 'thresholdResult', array: $stage);
		}
	}

	/**
	 * A threshold the calculator cannot read falls back to waiting for everyone.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/the-decision-as-a-walked-process/specs/decision-as-a-walked-process/spec.md (REQ-DWP-002)
	 */
	public function testAnUnreadableThresholdWaitsForEveryone(): void {
		$service = $this->service();
		$service->instantiate(
			route: $this->thresholdRoute(threshold: ['thresholdKind' => 'share'], approvers: 2),
			subject: 'dec-1',
			subjectSchema: 'decision'
		);

		$service->record(['subject' => 'dec-1', 'actor' => 'anna', 'action' => 'approved']);

		$this->assertSame(expected: ['decided', 'active', 'pending'], actual: $this->statuses());
	}

	/**
	 * Change the threshold of one board stage as an administrator would, and
	 * hand the update to the recomputer as the listener does.
	 *
	 * @param string $person The person whose stage is edited.
	 * @param float  $value  The new share or count.
	 *
	 * @return array<string, mixed>|null The recorded action.
	 */
	private function changeThreshold(string $person, float $value): ?array {
		$before = $this->boardStages()[$person];
		$after = (['thresholdValue' => $value] + $before);
		$this->objectService->rows['decision-stage'][(string)$before['id']] = $after;

		$recomputer = new StepThresholdRecomputer(store: $this->store(), advancer: new ApprovalRouteAdvancer(store: $this->store()));

		return $recomputer->stageChanged(before: $before, after: $after, actor: 'admin');
	}

	/**
	 * Lowering a share closes a waiting step and records the change (REQ-DWP-002).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/the-decision-as-a-walked-process/specs/decision-as-a-walked-process/spec.md (REQ-DWP-002)
	 */
	public function testLoweringTheThresholdClosesAnOpenStep(): void {
		$service = $this->service();
		$service->instantiate(
			route: $this->thresholdRoute(threshold: ['thresholdKind' => 'share', 'thresholdValue' => 0.8]),
			subject: 'dec-1',
			subjectSchema: 'decision'
		);
		foreach (['anna', 'bram', 'cor'] as $person) {
			$service->record(['subject' => 'dec-1', 'actor' => $person, 'action' => 'approved']);
		}

		$this->assertSame(expected: 'active', actual: $this->boardStages()['dina']['status'], message: 'Three of five is below 0.8.');

		$action = $this->changeThreshold(person: 'anna', value: 0.6);

		$board = $this->boardStages();
		foreach (['dina', 'eva'] as $person) {
			$this->assertSame(expected: 'skipped', actual: $board[$person]['status']);
			$this->assertTrue(condition: $board[$person]['closedByThreshold']);
		}

		foreach ($board as $stage) {
			$this->assertSame(expected: 0.6, actual: $stage['thresholdValue'], message: 'The new share was not copied onto the whole step.');
			$this->assertSame(expected: 'granted', actual: $stage['thresholdResult']['outcome']);
		}

		$this->assertSame(expected: 'active', actual: $this->stages()[5]['status'], message: 'The route did not walk on.');
		$this->assertSame(expected: 'threshold-changed', actual: $action['action']);
		$this->assertSame(expected: 0.8, actual: $action['thresholdBefore']);
		$this->assertSame(expected: 0.6, actual: $action['thresholdAfter']);
		$this->assertSame(expected: 'admin', actual: $action['actor']);
		$this->assertSame(expected: 'dec-1', actual: $action['subject']);
		$this->assertSame(expected: 1, actual: $action['step']);
		$this->assertSame(expected: 'Threshold changed from share 0.8 to share 0.6.', actual: $action['comment']);
	}

	/**
	 * Raising a share reopens a step the threshold closed, and the subject is blocked again (REQ-DWP-002).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/the-decision-as-a-walked-process/specs/decision-as-a-walked-process/spec.md (REQ-DWP-002)
	 */
	public function testRaisingTheThresholdReopensAClosedStep(): void {
		$service = $this->service();
		$service->instantiate(
			route: $this->thresholdRoute(threshold: ['thresholdKind' => 'share', 'thresholdValue' => 0.5]),
			subject: 'dec-1',
			subjectSchema: 'decision'
		);
		foreach (['anna', 'bram', 'cor'] as $person) {
			$service->record(['subject' => 'dec-1', 'actor' => $person, 'action' => 'approved']);
		}

		$this->assertSame(expected: 'active', actual: $this->stages()[5]['status'], message: 'Three of five closes a share of 0.5.');

		$action = $this->changeThreshold(person: 'anna', value: 0.8);

		$board = $this->boardStages();
		foreach (['dina', 'eva'] as $person) {
			$this->assertSame(expected: 'active', actual: $board[$person]['status'], message: $person . ' was not asked again.');
			$this->assertArrayNotHasKey(key: 'closedByThreshold', array: $board[$person]);
		}

		$this->assertSame(expected: 'decided', actual: $board['anna']['status'], message: 'A grant already given stays given.');
		$this->assertSame(expected: 'open', actual: $board['anna']['thresholdResult']['outcome']);
		$this->assertSame(expected: 'pending', actual: $this->stages()[5]['status'], message: 'The subject was not blocked again.');
		$this->assertSame(expected: 0.5, actual: $action['thresholdBefore']);
		$this->assertSame(expected: 0.8, actual: $action['thresholdAfter']);
	}

	/**
	 * An update that does not change the threshold, and the recomputer's own copy onto a sibling, record nothing.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/the-decision-as-a-walked-process/specs/decision-as-a-walked-process/spec.md (REQ-DWP-002)
	 */
	public function testOnlyAChangedThresholdIsRecomputedAndRecorded(): void {
		$service = $this->service();
		$service->instantiate(
			route: $this->thresholdRoute(threshold: ['thresholdKind' => 'count', 'thresholdValue' => 3]),
			subject: 'dec-1',
			subjectSchema: 'decision'
		);
		$recomputer = new StepThresholdRecomputer(store: $this->store(), advancer: new ApprovalRouteAdvancer(store: $this->store()));
		$stage = $this->boardStages()['anna'];

		$this->assertNull(actual: $recomputer->stageChanged(before: $stage, after: (['label' => 'Renamed'] + $stage), actor: 'admin'));

		$siblingBefore = $this->boardStages()['bram'];
		$this->changeThreshold(person: 'anna', value: 2);
		$siblingAfter = $this->boardStages()['bram'];

		$this->assertSame(expected: 2.0, actual: (float)$siblingAfter['thresholdValue']);
		$this->assertNull(
			actual: $recomputer->stageChanged(before: $siblingBefore, after: $siblingAfter, actor: 'admin'),
			message: 'The copy onto a sibling recorded the change a second time.'
		);
		$recorded = array_filter(
			$this->objectService->rows['approval-action'],
			static fn (array $row): bool => $row['action'] === 'threshold-changed'
		);
		$this->assertCount(expectedCount: 1, haystack: $recorded);
	}

	/**
	 * A threshold changed to one the calculator cannot read is recorded once and closes nothing.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/the-decision-as-a-walked-process/specs/decision-as-a-walked-process/spec.md (REQ-DWP-002)
	 */
	public function testAnUnreadableNewThresholdIsRecordedOnceAndClosesNothing(): void {
		$service = $this->service();
		$service->instantiate(
			route: $this->thresholdRoute(threshold: ['thresholdKind' => 'count', 'thresholdValue' => 3], approvers: 3),
			subject: 'dec-1',
			subjectSchema: 'decision'
		);
		$service->record(['subject' => 'dec-1', 'actor' => 'anna', 'action' => 'approved']);
		$recomputer = new StepThresholdRecomputer(store: $this->store(), advancer: new ApprovalRouteAdvancer(store: $this->store()));

		$before = $this->boardStages()['anna'];
		$after = (['thresholdKind' => 'share', 'thresholdValue' => 1.5] + $before);
		$this->objectService->rows['decision-stage'][(string)$before['id']] = $after;
		$siblingBefore = $this->boardStages()['bram'];

		$action = $recomputer->stageChanged(before: $before, after: $after, actor: 'admin');

		$this->assertSame(expected: 'Threshold changed from count 3 to share 1.5.', actual: $action['comment']);
		$this->assertSame(expected: ['decided', 'active', 'active', 'pending'], actual: $this->statuses());
		$sibling = $this->boardStages()['bram'];
		$this->assertSame(expected: 'open', actual: $sibling['thresholdResult']['outcome']);
		$this->assertNull(actual: $recomputer->stageChanged(before: $siblingBefore, after: $sibling, actor: 'admin'));
	}
}
