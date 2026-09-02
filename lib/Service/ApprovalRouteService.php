<?php

/**
 * Decidiq approval-route engine.
 *
 * Turns an `ApprovalRoute` template into `DecisionStage` rows against a subject,
 * and advances them as `ApprovalAction`s arrive.
 *
 * WHY THIS CLASS EXISTS. `DecisionStage` modelled the stages of a route and
 * NOTHING in this app ever wrote one — six seeded rows, two readers, and a route
 * tab whose own header says it is read-only. A schema without an engine is a
 * description of a capability, not the capability.
 *
 * THE ENGINE IS FAIL-CLOSED. An action by an actor the active stage does not
 * name is refused; a skip of a mandatory stage is refused; a return that points
 * forwards is refused. A sign-off route is only worth anything if the sequence
 * is enforced, and a guard whose result the caller may ignore is not a guard.
 *
 * THE PARAFERING RUNTIME LIVES HERE NOW (parafering-route-runtime). dossiq's
 * pipeline owned four things this engine did not: a stage-typed action
 * vocabulary, mandated delegate signing, a return that goes back to the sender
 * rather than to an earlier step, and steps that run in parallel. All four are
 * absorbed below, so dossiq can retire its route advancement the way it retired
 * decision authoring — raise, wait for the conclusion, record.
 *
 * @category Service
 * @package  OCA\Decidiq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/approval-routes/specs/approval-routes/spec.md
 * @spec openspec/changes/parafering-route-runtime/specs/parafering-route-runtime/spec.md
 */

declare(strict_types=1);

namespace OCA\Decidiq\Service;

use DateTimeImmutable;
use RuntimeException;

/**
 * Instantiates approval routes and advances them.
 *
 * @spec openspec/changes/approval-routes/specs/approval-routes/spec.md
 * @spec openspec/changes/parafering-route-runtime/specs/parafering-route-runtime/spec.md
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) The engine owns every route
 *   rule (REQ-ARE-004 forbids a second engine), so the mandate directory and
 *   the task projector attach here rather than growing a sibling.
 */
class ApprovalRouteService {
	/**
	 * Actions that COMPLETE the active stage, mapped to the outcome they record.
	 *
	 * `returned` is deliberately absent: it does not complete a stage, it
	 * re-opens an earlier one or concludes the route back to its sender, and
	 * treating it as a completion is precisely the mistake `rejected` and
	 * `deferred` invite.
	 *
	 * @var array<string, string>
	 */
	private const COMPLETING_ACTIONS = [
		'approved' => 'approved',
		'endorsed' => 'endorsed',
		'advised' => 'advised',
		'skipped' => 'skipped',
	];

	/**
	 * The one completing verb each sign-off stage type accepts.
	 *
	 * Absorbed from dossiq's ParafeerStepGuard, where an advies step accepted
	 * only `advised` and an accordering step only `accorded`. An `approved` on
	 * an advisory stage is a stronger claim than the stage asked for, and a
	 * chain that accepts it records a decision nobody was asked to take.
	 *
	 * A stage type absent from this map (preparatory, ratifying) keeps the
	 * engine's original any-completing-verb behaviour: those stages predate the
	 * parafering vocabulary and no producer constrains them yet.
	 *
	 * @var array<string, string>
	 */
	private const STAGE_COMPLETING_VERBS = [
		'advisory' => 'advised',
		'endorsement' => 'endorsed',
		'decisive' => 'approved',
	];

	/**
	 * Constructor.
	 *
	 * @param RegisterObjectStore $store Reads and writes the objects a route is made of.
	 * @param MandateDirectory $mandates Judges a delegate's mandate reference.
	 * @param ApprovalStageTaskProjector|null $projector Mirrors active stages
	 *        onto OpenRegister's task surface. Nullable so the engine's rules
	 *        never depend on the projection: a missing task surface changes
	 *        where the ask is SEEN, never whether the route advances.
	 */
	public function __construct(
		private readonly RegisterObjectStore $store,
		private readonly MandateDirectory $mandates,
		private readonly ?ApprovalStageTaskProjector $projector = null,
	) {
	}//end __construct()

	/**
	 * Refuse a caller who cannot reach the subject.
	 *
	 * A REAL authorisation check, not an authentication one. Instantiating a
	 * route writes sign-off stages against someone else's object, so "is signed
	 * in" is not the question — "may this user see this subject" is.
	 *
	 * The check is delegated to OpenRegister: the read runs as the acting user,
	 * so OR's register RBAC and multitenancy decide. A user who cannot reach the
	 * subject gets nothing back, and nothing back is a refusal.
	 *
	 * @param string $subject The subject's uuid.
	 * @param string $subjectSchema The subject's schema slug.
	 *
	 * @return void
	 *
	 * @throws RuntimeException When the subject cannot be reached.
	 *
	 * @spec openspec/changes/approval-routes/specs/approval-routes/spec.md
	 */
	public function assertSubjectAccessible(string $subject, string $subjectSchema): void {
		if ($subject === '' || $subjectSchema === '') {
			throw new RuntimeException('A subject and its schema are required.');
		}

		$found = $this->store->findAll(schema: $subjectSchema, filters: ['id' => $subject]);
		if ($found === []) {
			throw new RuntimeException('This subject cannot be reached, so no route may be started on it.');
		}
	}//end assertSubjectAccessible()

	/**
	 * Materialise a route's steps as stages against a subject.
	 *
	 * Idempotent: a subject that already has stages is left alone, so a repeated
	 * call cannot give it a second route.
	 *
	 * A stage's `sequence` is the step's OWN `order`, not its position: the
	 * parafering surfaces read the step number and it must mean what the route
	 * meant, and two steps DECLARING the same order are a parallel group that
	 * signs side by side. Steps without an order fall back to their position.
	 *
	 * @param array<string, mixed> $route The ApprovalRoute object.
	 * @param string $subject The subject's uuid.
	 * @param string $subjectSchema The subject's schema.
	 *
	 * @return array<int, array<string, mixed>> The stages, existing or created.
	 *
	 * @throws RuntimeException When the route declares no usable steps.
	 *
	 * @spec openspec/changes/approval-routes/specs/approval-routes/spec.md
	 * @spec openspec/changes/parafering-route-runtime/specs/parafering-route-runtime/spec.md
	 */
	public function instantiate(array $route, string $subject, string $subjectSchema): array {
		$existing = $this->stagesFor(subject: $subject);
		if ($existing !== []) {
			return $existing;
		}

		$steps = $this->orderedSteps(route: $route);
		if ($steps === []) {
			throw new RuntimeException('This route declares no steps, so there is nothing to travel.');
		}

		$routeId = (string)($route['id'] ?? ($route['@self']['id'] ?? ''));
		$firstSequence = $this->sequenceOf(step: $steps[0], index: 0);

		$created = [];
		foreach ($steps as $index => $step) {
			$sequence = $this->sequenceOf(step: $step, index: $index);
			$created[] = $this->store->save(
				schema: 'decision-stage',
				object: [
					'sequence' => $sequence,
					'stageType' => (string)$step['stageType'],
					// Every stage in the FIRST parallel group is active
					// immediately. A route whose every stage is pending is
					// indistinguishable from one nobody has started, and
					// nothing would ever start it.
					'status' => $this->initialStatus(sequence: $sequence, firstSequence: $firstSequence),
					'decisionMakerType' => $this->decisionMakerType(step: $step),
					'label' => (string)($step['label'] ?? ''),
					'mandatory' => (bool)($step['mandatory'] ?? true),
					'decision' => $subject,
					'assignedPerson' => $this->assignedPerson(step: $step),
					'assignedBody' => $this->assignedBody(step: $step),
					'note' => $subjectSchema,
					'route' => $routeId,
				],
			);
		}

		$this->projectTasks(subject: $subject);

		return $created;
	}//end instantiate()

	/**
	 * Record an action and advance the route.
	 *
	 * @param array<string, mixed> $action The action: subject, step, actor, action, and optional fields.
	 *
	 * @return array<string, mixed> The recorded action.
	 *
	 * @throws RuntimeException When the action is refused.
	 *
	 * @spec openspec/changes/approval-routes/specs/approval-routes/spec.md
	 * @spec openspec/changes/parafering-route-runtime/specs/parafering-route-runtime/spec.md
	 */
	public function record(array $action): array {
		$subject = (string)($action['subject'] ?? '');
		$actor = (string)($action['actor'] ?? '');
		$verb = (string)($action['action'] ?? '');
		if ($subject === '' || $actor === '' || $verb === '') {
			throw new RuntimeException('An action needs a subject, an actor and a verb.');
		}

		$stages = $this->stagesFor(subject: $subject);
		$actives = $this->activeStages(stages: $stages);
		if ($actives === []) {
			throw new RuntimeException('This subject has no active stage; there is nothing to act on.');
		}

		$active = $this->stageForAction(actives: $actives, action: $action);

		if ($verb === 'returned') {
			$this->assertRequiredFields(verb: $verb, action: $action);
			// Validated BEFORE the append: a refused return must leave no
			// action row, the same promise every other refusal keeps.
			$this->assertReturnTargetValid(action: $action, active: $active);
			$recorded = $this->appendAction(action: $action, stage: $active);
			$this->applyReturnVerb(action: $action, stages: $stages, active: $active);
			$this->projectTasks(subject: $subject);

			return $recorded;
		}

		if (isset(self::COMPLETING_ACTIONS[$verb]) === false) {
			throw new RuntimeException('Unknown action: ' . $verb);
		}

		if ($verb === 'skipped' && (bool)($active['mandatory'] ?? true) === true) {
			throw new RuntimeException('This stage is mandatory and cannot be skipped.');
		}

		$this->assertVerbFitsStage(stage: $active, verb: $verb);
		$this->assertRequiredFields(verb: $verb, action: $action);

		$recorded = $this->appendAction(action: $action, stage: $active);
		$this->completeAndAdvance(stages: $stages, active: $active, verb: $verb);
		$this->projectTasks(subject: $subject);

		return $recorded;
	}//end record()

	/**
	 * The active stage this action addresses.
	 *
	 * One active stage is the ordinary case and is simply taken. A PARALLEL
	 * group holds several, and the action lands on the one naming this actor
	 * (or the delegate's principal) — falling back to a stage naming nobody.
	 * An actor no active stage will have is refused here, with the same
	 * refusal the single-stage actor check gives.
	 *
	 * @param array<int, array<string, mixed>> $actives The active stages.
	 * @param array<string, mixed> $action The action.
	 *
	 * @return array<string, mixed> The addressed stage.
	 *
	 * @throws RuntimeException When no active stage accepts this actor.
	 *
	 * @spec openspec/changes/parafering-route-runtime/specs/parafering-route-runtime/spec.md
	 */
	private function stageForAction(array $actives, array $action): array {
		if (count($actives) === 1) {
			$this->assertActorMayAct(stage: $actives[0], action: $action);

			return $actives[0];
		}

		$unassigned = null;
		foreach ($actives as $stage) {
			$assigned = (string)($stage['assignedPerson'] ?? '');
			if ($assigned === '') {
				$unassigned = ($unassigned ?? $stage);
				continue;
			}

			if ($this->actorMatchesAssignee(stage: $stage, action: $action) === true) {
				$this->assertActorMayAct(stage: $stage, action: $action);

				return $stage;
			}
		}

		if ($unassigned !== null) {
			$this->assertActorMayAct(stage: $unassigned, action: $action);

			return $unassigned;
		}

		throw new RuntimeException('This stage is assigned to someone else.');
	}//end stageForAction()

	/**
	 * Whether the acting identity (or their principal) is this stage's assignee.
	 *
	 * @param array<string, mixed> $stage The stage.
	 * @param array<string, mixed> $action The action.
	 *
	 * @return boolean True when the stage names this action's signer.
	 */
	private function actorMatchesAssignee(array $stage, array $action): bool {
		$assigned = (string)($stage['assignedPerson'] ?? '');
		$actor = (string)($action['actor'] ?? '');
		$onBehalfOf = (string)($action['onBehalfOf'] ?? '');

		return ($assigned === $actor || ($onBehalfOf !== '' && $assigned === $onBehalfOf));
	}//end actorMatchesAssignee()

	/**
	 * Refuse an actor the active stage does not name.
	 *
	 * A stage naming nobody accepts any authenticated actor — that is a
	 * deliberate route design, not a gap.
	 *
	 * A DELEGATE may act when the stage's person is their `onBehalfOf`
	 * principal AND they present a mandate. The mandate is judged by the
	 * {@see MandateDirectory}: a local toedeling that is not effective, out of
	 * window or issued to somebody else refuses; a reference this register
	 * cannot resolve is the producer's mandate and travels verbatim. Absorbed
	 * from dossiq's ParafeerStepGuard, which recorded the same fields but left
	 * the registry check "for the future MandaatService" — this is that future.
	 *
	 * @param array<string, mixed> $stage The active stage.
	 * @param array<string, mixed> $action The action, carrying actor / onBehalfOf / mandate.
	 *
	 * @return void
	 *
	 * @throws RuntimeException When the actor may not act.
	 *
	 * @spec openspec/changes/parafering-route-runtime/specs/parafering-route-runtime/spec.md
	 */
	private function assertActorMayAct(array $stage, array $action): void {
		$assigned = (string)($stage['assignedPerson'] ?? '');
		$actor = (string)($action['actor'] ?? '');
		if ($assigned === '' || $assigned === $actor) {
			return;
		}

		$onBehalfOf = (string)($action['onBehalfOf'] ?? '');
		$mandate = trim((string)($action['mandate'] ?? ''));
		if ($onBehalfOf !== $assigned || $mandate === '') {
			throw new RuntimeException('This stage is assigned to someone else.');
		}

		$this->mandates->assertMayActUnder(mandate: $mandate, actor: $actor);
	}//end assertActorMayAct()

	/**
	 * Refuse a completing verb the stage's type did not ask for.
	 *
	 * @param array<string, mixed> $stage The active stage.
	 * @param string $verb The completing verb.
	 *
	 * @return void
	 *
	 * @throws RuntimeException When the verb does not fit the stage type.
	 *
	 * @spec openspec/changes/parafering-route-runtime/specs/parafering-route-runtime/spec.md
	 */
	private function assertVerbFitsStage(array $stage, string $verb): void {
		if ($verb === 'skipped') {
			return;
		}

		$stageType = (string)($stage['stageType'] ?? '');
		$expected = (self::STAGE_COMPLETING_VERBS[$stageType] ?? '');
		if ($expected !== '' && $verb !== $expected) {
			throw new RuntimeException(
				'A ' . $stageType . ' stage completes with "' . $expected . '", not "' . $verb . '".'
			);
		}
	}//end assertVerbFitsStage()

	/**
	 * Refuse an action missing the free text its verb makes mandatory.
	 *
	 * A return without a reason strands the sender with a rejection nobody
	 * explained; an advisory sign-off without the advice is a signature on an
	 * empty page. Both rules are dossiq's, absorbed with the runtime.
	 *
	 * @param string $verb The verb.
	 * @param array<string, mixed> $action The action.
	 *
	 * @return void
	 *
	 * @throws RuntimeException When a mandatory field is missing.
	 *
	 * @spec openspec/changes/parafering-route-runtime/specs/parafering-route-runtime/spec.md
	 */
	private function assertRequiredFields(string $verb, array $action): void {
		if ($verb === 'returned' && trim((string)($action['comment'] ?? '')) === '') {
			throw new RuntimeException('A return needs a reason.');
		}

		if ($verb === 'advised' && trim((string)($action['advice'] ?? '')) === '') {
			throw new RuntimeException('An advisory stage needs the advice text.');
		}
	}//end assertRequiredFields()

	/**
	 * Refuse a return target at or past the active stage, before anything is written.
	 *
	 * A target of zero (or none) is the terminal return and names no step, so
	 * there is nothing to validate.
	 *
	 * @param array<string, mixed> $action The returned action.
	 * @param array<string, mixed> $active The addressed active stage.
	 *
	 * @return void
	 *
	 * @throws RuntimeException When the target does not point backwards.
	 *
	 * @spec openspec/changes/parafering-route-runtime/specs/parafering-route-runtime/spec.md
	 */
	private function assertReturnTargetValid(array $action, array $active): void {
		$target = (int)($action['returnToStep'] ?? 0);
		if ($target > 0 && $target >= (int)$active['sequence']) {
			throw new RuntimeException('A return must name a step BEFORE the active one.');
		}
	}//end assertReturnTargetValid()

	/**
	 * Route a `returned` action to its meaning.
	 *
	 * Naming a step rewinds the route to it. Naming none concludes the route
	 * back to its sender: that is what dossiq's terugsturen has always meant —
	 * the voorstel goes back to the steller, and the approvers after this one
	 * are never asked.
	 *
	 * @param array<string, mixed> $action The returned action.
	 * @param array<int, array<string, mixed>> $stages All stages, in order.
	 * @param array<string, mixed> $active The addressed active stage.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/parafering-route-runtime/specs/parafering-route-runtime/spec.md
	 */
	private function applyReturnVerb(array $action, array $stages, array $active): void {
		$target = (int)($action['returnToStep'] ?? 0);
		if ($target > 0) {
			$this->applyReturn(action: $action, stages: $stages, active: $active);

			return;
		}

		$this->applyTerminalReturn(stages: $stages, active: $active);
	}//end applyReturnVerb()

	/**
	 * Conclude the route back to its sender.
	 *
	 * The addressed stage records the `returned` outcome. Every OTHER stage
	 * that is still active or pending goes back to `pending` with its outcome
	 * cleared — never `skipped`, because nobody chose to skip it; the route
	 * simply ended before it. No stage is left active, which is the engine's
	 * own definition of a concluded route, and `finalOutcomeOf` then reads
	 * `returned` off the last decided stage.
	 *
	 * @param array<int, array<string, mixed>> $stages All stages, in order.
	 * @param array<string, mixed> $active The stage whose actor returned it.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/parafering-route-runtime/specs/parafering-route-runtime/spec.md
	 */
	private function applyTerminalReturn(array $stages, array $active): void {
		$this->store->save(
			schema: 'decision-stage',
			object: [
				'status' => 'decided',
				'outcome' => 'returned',
				'decidedAt' => (new DateTimeImmutable())->format(DateTimeImmutable::ATOM),
			],
			uuid: (string)$active['id'],
		);

		foreach ($stages as $stage) {
			if ((string)$stage['id'] === (string)$active['id']) {
				continue;
			}

			$status = (string)($stage['status'] ?? '');
			if ($status !== 'active' && $status !== 'pending') {
				continue;
			}

			$this->store->save(
				schema: 'decision-stage',
				object: ['status' => 'pending', 'outcome' => null, 'decidedAt' => null],
				uuid: (string)$stage['id'],
			);
		}
	}//end applyTerminalReturn()

	/**
	 * Complete the addressed stage and make the next group active.
	 *
	 * A PARALLEL group advances only when its last live member completes: a
	 * group with a sibling still active stays where it is. When no later stage
	 * remains the route is finished, and NO stage is left active — a completed
	 * route that still shows an active stage would keep inviting actions on a
	 * decision already taken.
	 *
	 * @param array<int, array<string, mixed>> $stages All stages, in order.
	 * @param array<string, mixed> $active The addressed stage.
	 * @param string $verb The action verb.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/parafering-route-runtime/specs/parafering-route-runtime/spec.md
	 */
	private function completeAndAdvance(array $stages, array $active, string $verb): void {
		$status = 'decided';
		if ($verb === 'skipped') {
			$status = 'skipped';
		}

		$this->store->save(
			schema: 'decision-stage',
			object: [
				'status' => $status,
				'outcome' => self::COMPLETING_ACTIONS[$verb],
				'decidedAt' => (new DateTimeImmutable())->format(DateTimeImmutable::ATOM),
			],
			uuid: (string)$active['id'],
		);

		$sequence = (int)$active['sequence'];
		foreach ($stages as $stage) {
			$sibling = ((int)$stage['sequence'] === $sequence && (string)$stage['id'] !== (string)$active['id']);
			if ($sibling === true && (string)($stage['status'] ?? '') === 'active') {
				// A parallel sibling is still signing; the group is not done.
				return;
			}
		}

		$nextSequence = $this->nextPendingSequence(stages: $stages, after: $sequence);
		if ($nextSequence === null) {
			return;
		}

		foreach ($stages as $stage) {
			if ((int)$stage['sequence'] === $nextSequence && (string)($stage['status'] ?? '') === 'pending') {
				$this->store->save(schema: 'decision-stage', object: ['status' => 'active'], uuid: (string)$stage['id']);
			}
		}
	}//end completeAndAdvance()

	/**
	 * Re-open an earlier stage and reset everything after it.
	 *
	 * The outcomes of the reset stages are CLEARED, because a stage that is
	 * pending again while still showing an outcome reads as decided to every
	 * consumer that looks at the outcome rather than the status.
	 *
	 * The ApprovalActions are NOT touched. They are what happened; the stages
	 * are where the route is.
	 *
	 * @param array<string, mixed> $action The returned action.
	 * @param array<int, array<string, mixed>> $stages All stages, in order.
	 * @param array<string, mixed> $active The active stage.
	 *
	 * @return void
	 *
	 * @throws RuntimeException When the target step is not before the active one.
	 */
	private function applyReturn(array $action, array $stages, array $active): void {
		$target = (int)($action['returnToStep'] ?? 0);
		$activeSequence = (int)$active['sequence'];
		if ($target < 1 || $target >= $activeSequence) {
			throw new RuntimeException('A return must name a step BEFORE the active one.');
		}

		foreach ($stages as $stage) {
			$sequence = (int)$stage['sequence'];
			if ($sequence < $target) {
				continue;
			}

			$status = 'pending';
			if ($sequence === $target) {
				$status = 'active';
			}

			$this->store->save(
				schema: 'decision-stage',
				object: ['status' => $status, 'outcome' => null, 'decidedAt' => null],
				uuid: (string)$stage['id'],
			);
		}
	}//end applyReturn()

	/**
	 * Append the action as a new object.
	 *
	 * @param array<string, mixed> $action The action.
	 * @param array<string, mixed> $stage The active stage.
	 *
	 * @return array<string, mixed> The stored action.
	 */
	private function appendAction(array $action, array $stage): array {
		return $this->store->save(
			schema: 'approval-action',
			object: [
				'subject' => (string)$action['subject'],
				'subjectSchema' => (string)($action['subjectSchema'] ?? ''),
				'step' => (int)($action['step'] ?? $stage['sequence']),
				'actor' => (string)$action['actor'],
				'actorType' => (string)($action['actorType'] ?? 'user'),
				'onBehalfOf' => (string)($action['onBehalfOf'] ?? ''),
				'mandate' => (string)($action['mandate'] ?? ''),
				'action' => (string)$action['action'],
				'returnToStep' => ($action['returnToStep'] ?? null),
				'comment' => (string)($action['comment'] ?? ''),
				'advice' => (string)($action['advice'] ?? ''),
				'recordedAt' => (new DateTimeImmutable())->format(DateTimeImmutable::ATOM),
			],
		);
	}//end appendAction()

	/**
	 * The subject's stages, ordered by sequence.
	 *
	 * PUBLIC because the cross-app command seam needs to answer "did that action
	 * finish the route" and must do it by asking THIS class, not by running its
	 * own query. A second reader of decision-stage rows is how the seam and the
	 * engine start to disagree about what a route's state is.
	 *
	 * @param string $subject The subject uuid.
	 *
	 * @return array<int, array<string, mixed>> The stages.
	 *
	 * @spec openspec/changes/approval-route-events/specs/approval-route-events/spec.md
	 */
	public function stagesFor(string $subject): array {
		$rows = $this->store->findAll(schema: 'decision-stage', filters: ['decision' => $subject]);
		usort($rows, static fn (array $a, array $b): int => ((int)$a['sequence'] <=> (int)$b['sequence']));

		return $rows;
	}//end stagesFor()

	/**
	 * Every active stage — one ordinarily, several in a parallel group.
	 *
	 * @param array<int, array<string, mixed>> $stages The stages.
	 *
	 * @return array<int, array<string, mixed>> The active stages.
	 */
	private function activeStages(array $stages): array {
		$actives = [];
		foreach ($stages as $stage) {
			if ((string)($stage['status'] ?? '') === 'active') {
				$actives[] = $stage;
			}
		}

		return $actives;
	}//end activeStages()

	/**
	 * The lowest pending sequence after the given one.
	 *
	 * @param array<int, array<string, mixed>> $stages The stages.
	 * @param int $after The sequence to search past.
	 *
	 * @return int|null The next sequence, or null when none remains.
	 */
	private function nextPendingSequence(array $stages, int $after): ?int {
		foreach ($stages as $stage) {
			if ((int)$stage['sequence'] > $after && (string)($stage['status'] ?? '') === 'pending') {
				return (int)$stage['sequence'];
			}
		}

		return null;
	}//end nextPendingSequence()

	/**
	 * A route's steps, ordered.
	 *
	 * @param array<string, mixed> $route The route.
	 *
	 * @return array<int, array<string, mixed>> The steps.
	 */
	private function orderedSteps(array $route): array {
		$steps = ($route['steps'] ?? []);
		if (is_string($steps) === true) {
			$decoded = json_decode($steps, true);
			if (is_array($decoded) === false) {
				return [];
			}

			$steps = $decoded;
		}

		if (is_array($steps) === false) {
			return [];
		}

		$list = [];
		foreach ($steps as $step) {
			if (is_array($step) === true && isset($step['stageType']) === true) {
				$list[] = $step;
			}
		}

		usort($list, static fn (array $a, array $b): int => ((int)($a['order'] ?? 0) <=> (int)($b['order'] ?? 0)));

		return $list;
	}//end orderedSteps()

	/**
	 * The sequence a step's stage carries: the step's own order.
	 *
	 * @param array<string, mixed> $step The route step.
	 * @param int $index The zero-based position, the fallback.
	 *
	 * @return int The sequence.
	 */
	private function sequenceOf(array $step, int $index): int {
		$order = (int)($step['order'] ?? 0);
		if ($order > 0) {
			return $order;
		}

		return ($index + 1);
	}//end sequenceOf()

	/**
	 * How a step's actor is recorded on the stage.
	 *
	 * @param array<string, mixed> $step The route step.
	 *
	 * @return string Either `person` or `body`.
	 */
	private function decisionMakerType(array $step): string {
		if ((string)($step['actorType'] ?? 'role') === 'body') {
			return 'body';
		}

		return 'person';
	}//end decisionMakerType()

	/**
	 * The stage's assignedPerson, when the step names one.
	 *
	 * A `role` actor is NOT written here: a role is resolved by the consuming
	 * context, and storing the role token in a field that means "this person"
	 * would make every actor check compare a uid against a role name and refuse
	 * everyone.
	 *
	 * @param array<string, mixed> $step The route step.
	 *
	 * @return string The person, or ''.
	 */
	private function assignedPerson(array $step): string {
		if ((string)($step['actorType'] ?? 'role') !== 'person') {
			return '';
		}

		return (string)($step['actor'] ?? '');
	}//end assignedPerson()

	/**
	 * The stage's assignedBody, when the step names one.
	 *
	 * @param array<string, mixed> $step The route step.
	 *
	 * @return string The body, or ''.
	 */
	private function assignedBody(array $step): string {
		if ((string)($step['actorType'] ?? 'role') !== 'body') {
			return '';
		}

		return (string)($step['actor'] ?? '');
	}//end assignedBody()

	/**
	 * The status a stage at this sequence starts in.
	 *
	 * A method rather than a ternary: phpcs.xml forbids inline IF, and this
	 * decides whether a freshly instantiated route can be acted on at all.
	 *
	 * @param int $sequence The stage's sequence.
	 * @param int $firstSequence The route's first (lowest) sequence.
	 *
	 * @return string Either `active` or `pending`.
	 */
	private function initialStatus(int $sequence, int $firstSequence): string {
		if ($sequence === $firstSequence) {
			return 'active';
		}

		return 'pending';
	}//end initialStatus()

	/**
	 * Mirror the subject's stages onto the task surface, best effort.
	 *
	 * The projection changes where an ask is SEEN, never whether the route
	 * advances, so a missing or failing task surface is logged by the
	 * projector and swallowed here.
	 *
	 * @param string $subject The subject uuid.
	 *
	 * @return void
	 */
	private function projectTasks(string $subject): void {
		$this->projector?->sync(subject: $subject, stages: $this->stagesFor(subject: $subject));
	}//end projectTasks()
}//end class
