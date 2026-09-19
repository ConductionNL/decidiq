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
	 * Where the stage rows move when an action lands.
	 *
	 * Built here rather than taken as a parameter, so every caller and test
	 * written against the seven-parameter constructor keeps working unchanged.
	 * It has no dependency this class does not already hold.
	 *
	 * @var ApprovalRouteAdvancer
	 */
	private readonly ApprovalRouteAdvancer $advancer;

	/**
	 * Constructor.
	 *
	 * @param RegisterObjectStore $store Reads and writes the objects a route is made of.
	 * @param ApprovalStageGuard $guard The fail-closed gate every action passes through.
	 * @param ApprovalRouteStepMapper $mapper Pure shaping of steps into stage fields.
	 * @param ApprovalStageTaskProjector|null $projector Mirrors active stages
	 *        onto OpenRegister's task surface. Nullable so the engine's rules
	 *        never depend on the projection: a missing task surface changes
	 *        where the ask is SEEN, never whether the route advances.
	 * @param ApprovalStageActivator|null $activator Resolves a stage's actor rule
	 *        at the moment it becomes live. Nullable, because the constructor is
	 *        reached by tests and by callers built before rules existed; a
	 *        missing activator means a step naming a rule keeps the actor it was
	 *        given, never that the rule silently resolved. It keeps position 5,
	 *        which is where #1346 put it, so nothing that already passes it
	 *        positionally starts handing it to a different parameter.
	 * @param WorkingDayDeadlineSplitter|null $splitter Divides one deadline over
	 *        the steps. Nullable and last, so a caller built before deadlines
	 *        existed keeps working: without it a held route simply carries no
	 *        due dates, which is what it carries today.
	 * @param StageLapsePolicy $policy Refuses a silence nobody may declare.
	 *        Deliberately NOT nullable, unlike its neighbours: a null collaborator
	 *        would mean the guard quietly does not run, and a guard that quietly
	 *        does not run is the state this parameter exists to end. It defaults
	 *        to a real instance because the policy is a pure value object with
	 *        no dependencies of its own, so there is nothing to inject.
	 */
	public function __construct(
		private readonly RegisterObjectStore $store,
		private readonly ApprovalStageGuard $guard,
		private readonly ApprovalRouteStepMapper $mapper,
		private readonly ?ApprovalStageTaskProjector $projector = null,
		private readonly ?ApprovalStageActivator $activator = null,
		private readonly ?WorkingDayDeadlineSplitter $splitter = null,
		private readonly StageLapsePolicy $policy = new StageLapsePolicy(),
	) {
		$this->advancer = new ApprovalRouteAdvancer(store: $store, activator: $activator);
	}//end __construct()

	/**
	 * Hold a route on a subject from a list of named people, with no stored
	 * template.
	 *
	 * The everyday case a template cannot serve: a clerk with a document in
	 * front of them and three colleagues who have to look at it, in that order,
	 * before the end of the month. Writing an `ApprovalRoute` row first would
	 * leave a template nobody reuses on every such review.
	 *
	 * The stages are the engine's ordinary ones, so everything that already
	 * works on a route works on this one: the guard, the return verbs, the
	 * parallel groups, the conclusion announcement.
	 *
	 * @param string $subject The subject's uuid.
	 * @param array<int, string> $actors The people to ask, in order.
	 * @param string $subjectSchema The subject's schema slug.
	 * @param string $deadline The deadline for the whole route, as an ISO-8601
	 *        instant. Empty means no due dates at all.
	 * @param string $kind The stage type every step carries.
	 * @param string $name What to call the route on a surface.
	 *
	 * @return array<int, array<string, mixed>> The stages.
	 *
	 * @throws RuntimeException When no actor is named.
	 *
	 * @spec openspec/changes/document-approval-chain-leaf/specs/approval-routes/spec.md (REQ-AR-008, REQ-AR-009)
	 */
	public function holdFor(
		string $subject,
		array $actors,
		string $subjectSchema,
		string $deadline = '',
		string $kind = 'endorsement',
		string $name = 'Review',
	): array {
		$named = [];
		foreach ($actors as $actor) {
			$actor = trim((string)$actor);
			if ($actor !== '' && in_array($actor, $named, true) === false) {
				// A person named twice would be asked twice on the same
				// document, and the second ask would refuse: the first sign-off
				// already advanced past their step.
				$named[] = $actor;
			}
		}

		if ($named === []) {
			throw new RuntimeException('A route held from named people needs at least one person.');
		}

		$steps = [];
		foreach ($named as $index => $actor) {
			$steps[] = [
				'order' => ($index + 1),
				'stageType' => $kind,
				'actorType' => 'person',
				'actor' => $actor,
				'mandatory' => true,
			];
		}

		$stages = $this->instantiate(
			route: [
				'id' => '',
				'name' => $name,
				// `adhoc` and not a route id: this route has no template, and a
				// surface that went looking for one would find nothing and have
				// no way to tell that from a template that was deleted.
				'origin' => 'adhoc',
				'steps' => $steps,
			],
			subject: $subject,
			subjectSchema: $subjectSchema,
		);

		return $this->stampHeldRoute(stages: $stages, deadline: $deadline);
	}//end holdFor()

	/**
	 * Mark the stages as ad-hoc and divide one deadline over them.
	 *
	 * Applied AFTER the stages exist rather than folded into their creation:
	 * the split is arithmetic over the number of stages, and the number of
	 * stages is only certain once they are written.
	 *
	 * @param array<int, array<string, mixed>> $stages The stages, in order.
	 * @param string $deadline The deadline, as an ISO-8601 instant.
	 *
	 * @return array<int, array<string, mixed>> The stages, stamped.
	 *
	 * @spec openspec/changes/document-approval-chain-leaf/specs/approval-routes/spec.md (REQ-AR-008, REQ-AR-009)
	 */
	private function stampHeldRoute(array $stages, string $deadline): array {
		$dueDates = $this->dueDatesFor(stages: $stages, deadline: $deadline);

		foreach ($stages as $index => $stage) {
			// `origin` says the route has no template, so a surface that finds
			// no ApprovalRoute row can tell that from a template somebody
			// deleted under a route still in flight.
			$patch = ['origin' => 'adhoc'];
			if (isset($dueDates[$index]) === true) {
				$patch['dueAt'] = $dueDates[$index];
			}

			$stages[$index] = $this->store->patch(
				schema: 'decision-stage',
				data: $patch,
				uuid: (string)$stage['id'],
			);
		}

		return $stages;
	}//end stampHeldRoute()

	/**
	 * One due date per stage, or none at all.
	 *
	 * @param array<int, array<string, mixed>> $stages The stages, in order.
	 * @param string $deadline The deadline, as an ISO-8601 instant.
	 *
	 * @return array<int, string> The due dates.
	 *
	 * @throws RuntimeException When the deadline cannot be read as a date.
	 *
	 * @spec openspec/changes/document-approval-chain-leaf/specs/approval-routes/spec.md (REQ-AR-009)
	 */
	private function dueDatesFor(array $stages, string $deadline): array {
		if ($this->splitter === null || trim($deadline) === '' || $stages === []) {
			return [];
		}

		try {
			$end = new DateTimeImmutable($deadline);
		} catch (\Throwable $e) {
			// An unreadable deadline leaves the route without due dates, which
			// is a route that never lapses. Inventing one would give every step
			// a term nobody asked for.
			throw new RuntimeException('That deadline could not be read as a date.');
		}

		return $this->splitter->split(
			from: new DateTimeImmutable(),
			deadline: $end,
			steps: count($stages),
		);
	}//end dueDatesFor()

	/**
	 * Whether a stage is past its due date with nothing recorded on it.
	 *
	 * A COMPUTED view, not a lifecycle state: nothing writes "overdue" anywhere,
	 * because a stored flag would need somebody to unset it the moment the step
	 * is signed, and the moment it is not unset the timeline lies.
	 *
	 * @param array<string, mixed> $stage The stage.
	 * @param DateTimeImmutable|null $now The clock; the real one when null.
	 *
	 * @return bool True when the stage renders overdue.
	 *
	 * @spec openspec/changes/document-approval-chain-leaf/specs/approval-routes/spec.md (REQ-AR-009)
	 */
	public function isOverdue(array $stage, ?DateTimeImmutable $now = null): bool {
		if ((string)($stage['status'] ?? '') !== 'active') {
			return false;
		}

		$dueAt = trim((string)($stage['dueAt'] ?? ''));
		if ($dueAt === '') {
			return false;
		}

		try {
			$due = new DateTimeImmutable($dueAt);
		} catch (\Throwable $e) {
			return false;
		}

		return ($due < ($now ?? new DateTimeImmutable()));
	}//end isOverdue()

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

		// Resolved with find(), NOT findAll() + a top-level 'id' filter: OpenRegister
		// applies filters to the object's own JSON properties and its identity
		// lives in `@self`, so the filter form matches nothing and reads as
		// "unreachable" for every subject that exists (dossiq#1686's class).
		if ($this->store->find(schema: $subjectSchema, uuid: $subject) === null) {
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
	 * @param ApprovalPrincipal $principal Who is asking. Defaults to Ordinary,
	 *        which is the fail-closed answer: a caller that says nothing about
	 *        who it is does not get to declare that silence approves.
	 *
	 * @return array<int, array<string, mixed>> The stages, existing or created.
	 *
	 * @throws RuntimeException When the route declares no usable steps, or
	 *         declares a silence this principal may not set.
	 *
	 * @spec openspec/changes/approval-routes/specs/approval-routes/spec.md
	 * @spec openspec/changes/parafering-route-runtime/specs/parafering-route-runtime/spec.md
	 * @spec openspec/changes/approval-routes-resolve-a-manager-and-declare-silence/specs/approval-routes/spec.md (REQ-AR-015)
	 */
	public function instantiate(
		array $route,
		string $subject,
		string $subjectSchema,
		ApprovalPrincipal $principal = ApprovalPrincipal::Ordinary,
	): array {
		$existing = $this->stagesFor(subject: $subject);
		if ($existing !== []) {
			return $existing;
		}

		$steps = $this->mapper->orderedSteps(route: $route);
		if ($steps === []) {
			throw new RuntimeException('This route declares no steps, so there is nothing to travel.');
		}

		// BEFORE any write, for every step at once. A route refused half way
		// through would leave the stages it had already written behind, and a
		// subject carrying half a route is worse than one carrying none.
		$this->policy->assertEverySilenceIsSettable(
			steps: $steps,
			isAdministrator: $principal->isAdministrator(),
		);

		$routeId = (string)($route['id'] ?? ($route['@self']['id'] ?? ''));
		$firstSequence = $this->mapper->sequenceOf(step: $steps[0], index: 0);
		$owner = $this->advancer->ownerOf(subject: $subject, subjectSchema: $subjectSchema);

		$created = [];
		foreach ($steps as $index => $step) {
			$sequence = $this->mapper->sequenceOf(step: $step, index: $index);
			// A step that names a RULE cannot be resolved here: it is resolved
			// when its stage becomes live, which for anything past the first
			// group is weeks away. So the rule travels onto the stage, copied
			// like every other step field, and editing the route afterwards
			// leaves a route already in flight alone.
			$declared = $this->mapper->declaredStepFields(step: $step);
			$created[] = $this->store->save(
				schema: 'decision-stage',
				object: $declared + [
					'sequence' => $sequence,
					'stageType' => (string)$step['stageType'],
					// Every stage in the FIRST parallel group is active
					// immediately. A route whose every stage is pending is
					// indistinguishable from one nobody has started, and
					// nothing would ever start it.
					'status' => $this->mapper->initialStatus(sequence: $sequence, firstSequence: $firstSequence),
					'decisionMakerType' => $this->mapper->decisionMakerType(step: $step),
					// Derived when the step carries none: the schema REQUIRES a
					// label, an empty string stores as NULL, and a NULL label
					// 400s the patch that records the FIRST sign-off — after
					// the action row was already appended. Cross-app routes
					// (dossiq) never carry step labels, so they always hit it.
					'label' => $this->mapper->labelOf(step: $step, sequence: $sequence),
					'mandatory' => (bool)($step['mandatory'] ?? true),
					'decision' => $subject,
					'assignedPerson' => $this->mapper->assignedPerson(step: $step),
					'assignedBody' => $this->mapper->assignedBody(step: $step),
					'note' => $subjectSchema,
					'route' => $routeId,
				],
			);
		}

		$created = $this->resolveLiveStages(stages: $created, owner: $owner);

		$this->projectTasks(subject: $subject);

		return $created;
	}//end instantiate()


	/**
	 * Resolve the actor rule of every stage that is already live.
	 *
	 * The first group activates at instantiation, so its rules resolve now; the
	 * rest resolve when their turn comes.
	 *
	 * @param array<int, array<string, mixed>> $stages The freshly created stages.
	 * @param string $owner Who owns the subject the route travels.
	 *
	 * @return array<int, array<string, mixed>> The stages, with resolved actors.
	 *
	 * @spec openspec/changes/approval-routes-resolve-a-manager-and-declare-silence/specs/approval-routes/spec.md (REQ-AR-012)
	 */
	private function resolveLiveStages(array $stages, string $owner): array {
		if ($this->activator === null) {
			return $stages;
		}

		foreach ($stages as $index => $stage) {
			if ((string)($stage['status'] ?? '') !== 'active') {
				continue;
			}

			// A refusal here THROWS, and that is the design: an unresolvable
			// rule must not leave a stage that anybody may sign.
			$patch = $this->activator->activationPatch(stage: $stage, subjectOwner: $owner);
			$stages[$index] = $this->store->patch(
				schema: 'decision-stage',
				data: $patch,
				uuid: (string)$stage['id'],
			);
		}

		return $stages;
	}//end resolveLiveStages()

	/**
	 * Record an action and advance the route.
	 *
	 * THE STAGE WRITE COMES FIRST, the action row after. The old order appended
	 * the action and THEN patched the stage, so a stage write that failed left
	 * an orphan action row claiming a sign-off the route never took — and the
	 * signer's retry appended another. With this order a failed stage write
	 * throws before any action row exists, so the retry starts clean. The
	 * inverse gap (stage advanced, action append failed) surfaces loudly: the
	 * caller gets the throw, and a retry is refused by the guard because the
	 * next stage names a different actor.
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
		$actives = $this->advancer->activeStages(stages: $stages);
		if ($actives === []) {
			throw new RuntimeException('This subject has no active stage; there is nothing to act on.');
		}

		$active = $this->guard->stageForAction(actives: $actives, action: $action);

		if ($verb === 'returned') {
			$this->guard->assertRequiredFields(verb: $verb, action: $action);
			// Validated BEFORE any write: a refused return must leave no
			// action row, the same promise every other refusal keeps.
			$this->guard->assertReturnTargetValid(action: $action, active: $active);
			$this->advancer->applyReturnVerb(action: $action, stages: $stages, active: $active);
			$recorded = $this->appendAction(action: $action, stage: $active);
			$this->projectTasks(subject: $subject);

			return $recorded;
		}

		if (isset(ApprovalRouteAdvancer::COMPLETING_ACTIONS[$verb]) === false) {
			throw new RuntimeException('Unknown action: ' . $verb);
		}

		if ($verb === 'skipped' && (bool)($active['mandatory'] ?? true) === true) {
			throw new RuntimeException('This stage is mandatory and cannot be skipped.');
		}

		$this->guard->assertVerbFitsStage(stage: $active, verb: $verb);
		$this->guard->assertRequiredFields(verb: $verb, action: $action);

		$this->advancer->completeAndAdvance(stages: $stages, active: $active, verb: $verb);
		$recorded = $this->appendAction(action: $action, stage: $active);
		$this->projectTasks(subject: $subject);

		return $recorded;
	}//end record()

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
	 * The routes on a subject, each with its stages, shaped for the clearance
	 * question.
	 *
	 * A stage whose `route` is empty is a route in its own right: cross-app
	 * routes are held without a stored template, and dropping them here would
	 * make a subject with only such a route read as cleared while somebody is
	 * still waiting on it.
	 *
	 * @param string $subject The subject uuid.
	 *
	 * @return array<int, array<string, mixed>> The routes with their stages.
	 *
	 * @spec openspec/changes/approval-routes-resolve-a-manager-and-declare-silence/specs/approval-routes/spec.md (REQ-AR-014)
	 */
	public function routesWithStagesFor(string $subject): array {
		$grouped = [];
		foreach ($this->stagesFor(subject: $subject) as $stage) {
			$routeId = (string)($stage['route'] ?? '');
			if (isset($grouped[$routeId]) === false) {
				$grouped[$routeId] = [];
			}

			$grouped[$routeId][] = $stage;
		}

		$routes = [];
		foreach ($grouped as $routeId => $stages) {
			$route = null;
			if ($routeId !== '') {
				$route = $this->store->find(schema: 'approval-route', uuid: $routeId);
			}

			$routes[] = [
				'id' => $routeId,
				'name' => (string)($route['name'] ?? ''),
				// Unset reads as required, here as in the clearance service: a
				// route somebody bothered to start is one somebody is waiting
				// on, and a route we could not read must not clear a subject.
				'required' => (($route['required'] ?? true) !== false),
				'stages' => $stages,
			];
		}

		return $routes;
	}//end routesWithStagesFor()

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
