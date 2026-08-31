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
 */

declare(strict_types=1);

namespace OCA\Decidiq\Service;

use DateTimeImmutable;
use OCA\Decidiq\AppInfo\Application;
use RuntimeException;

/**
 * Instantiates approval routes and advances them.
 *
 * @spec openspec/changes/approval-routes/specs/approval-routes/spec.md
 */
class ApprovalRouteService {
	/**
	 * Actions that COMPLETE the active stage, mapped to the outcome they record.
	 *
	 * `returned` is deliberately absent: it does not complete a stage, it
	 * re-opens an earlier one, and treating it as a completion is precisely the
	 * mistake `rejected` and `deferred` invite.
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
	 * Constructor.
	 *
	 * @param RegisterObjectStore $store Reads and writes the objects a route is made of.
	 */
	public function __construct(
		private readonly RegisterObjectStore $store,
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
	 * @param array<string, mixed> $route The ApprovalRoute object.
	 * @param string $subject The subject's uuid.
	 * @param string $subjectSchema The subject's schema.
	 *
	 * @return array<int, array<string, mixed>> The stages, existing or created.
	 *
	 * @throws RuntimeException When the route declares no usable steps.
	 *
	 * @spec openspec/changes/approval-routes/specs/approval-routes/spec.md
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

		$created = [];
		foreach ($steps as $index => $step) {
			$created[] = $this->store->save(
				schema: 'decision-stage',
				object: [
					'sequence' => ($index + 1),
					'stageType' => (string)$step['stageType'],
					// The FIRST stage is active immediately. A route whose every
					// stage is pending is indistinguishable from one nobody has
					// started, and nothing would ever start it.
					'status' => $this->initialStatus(index: $index),
					'decisionMakerType' => $this->decisionMakerType(step: $step),
					'label' => (string)($step['label'] ?? ''),
					'mandatory' => (bool)($step['mandatory'] ?? true),
					'decision' => $subject,
					'assignedPerson' => $this->assignedPerson(step: $step),
					'assignedBody' => $this->assignedBody(step: $step),
					'note' => $subjectSchema,
				],
			);
		}

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
	 */
	public function record(array $action): array {
		$subject = (string)($action['subject'] ?? '');
		$actor = (string)($action['actor'] ?? '');
		$verb = (string)($action['action'] ?? '');
		if ($subject === '' || $actor === '' || $verb === '') {
			throw new RuntimeException('An action needs a subject, an actor and a verb.');
		}

		$stages = $this->stagesFor(subject: $subject);
		$active = $this->activeStage(stages: $stages);
		if ($active === null) {
			throw new RuntimeException('This subject has no active stage; there is nothing to act on.');
		}

		$this->assertActorMayAct(stage: $active, actor: $actor);

		if ($verb === 'returned') {
			$recorded = $this->appendAction(action: $action, stage: $active);
			$this->applyReturn(action: $action, stages: $stages, active: $active);
			return $recorded;
		}

		if (isset(self::COMPLETING_ACTIONS[$verb]) === false) {
			throw new RuntimeException('Unknown action: ' . $verb);
		}

		if ($verb === 'skipped' && (bool)($active['mandatory'] ?? true) === true) {
			throw new RuntimeException('This stage is mandatory and cannot be skipped.');
		}

		$recorded = $this->appendAction(action: $action, stage: $active);
		$this->completeAndAdvance(stages: $stages, active: $active, verb: $verb);

		return $recorded;
	}//end record()

	/**
	 * Refuse an actor the active stage does not name.
	 *
	 * A stage naming nobody accepts any authenticated actor — that is a
	 * deliberate route design, not a gap.
	 *
	 * @param array<string, mixed> $stage The active stage.
	 * @param string $actor The acting user.
	 *
	 * @return void
	 *
	 * @throws RuntimeException When the actor may not act.
	 */
	private function assertActorMayAct(array $stage, string $actor): void {
		$assigned = (string)($stage['assignedPerson'] ?? '');
		if ($assigned === '' || $assigned === $actor) {
			return;
		}

		throw new RuntimeException('This stage is assigned to someone else.');
	}//end assertActorMayAct()

	/**
	 * Complete the active stage and make the next pending one active.
	 *
	 * When no later stage remains the route is finished, and NO stage is left
	 * active — a completed route that still shows an active stage would keep
	 * inviting actions on a decision already taken.
	 *
	 * @param array<int, array<string, mixed>> $stages All stages, in order.
	 * @param array<string, mixed> $active The active stage.
	 * @param string $verb The action verb.
	 *
	 * @return void
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

		$next = $this->nextPending(stages: $stages, after: (int)$active['sequence']);
		if ($next === null) {
			return;
		}

		$this->store->save(schema: 'decision-stage', object: ['status' => 'active'], uuid: (string)$next['id']);
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
	 * @param string $subject The subject uuid.
	 *
	 * @return array<int, array<string, mixed>> The stages.
	 */
	private function stagesFor(string $subject): array {
		$rows = $this->store->findAll(schema: 'decision-stage', filters: ['decision' => $subject]);
		usort($rows, static fn (array $a, array $b): int => ((int)$a['sequence'] <=> (int)$b['sequence']));

		return $rows;
	}//end stagesFor()

	/**
	 * The one active stage, or null.
	 *
	 * @param array<int, array<string, mixed>> $stages The stages.
	 *
	 * @return array<string, mixed>|null The active stage.
	 */
	private function activeStage(array $stages): ?array {
		foreach ($stages as $stage) {
			if ((string)($stage['status'] ?? '') === 'active') {
				return $stage;
			}
		}

		return null;
	}//end activeStage()

	/**
	 * The first pending stage after the given sequence.
	 *
	 * @param array<int, array<string, mixed>> $stages The stages.
	 * @param int $after The sequence to search past.
	 *
	 * @return array<string, mixed>|null The next stage.
	 */
	private function nextPending(array $stages, int $after): ?array {
		foreach ($stages as $stage) {
			if ((int)$stage['sequence'] > $after && (string)($stage['status'] ?? '') === 'pending') {
				return $stage;
			}
		}

		return null;
	}//end nextPending()

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
	 * The status the stage at this index starts in.
	 *
	 * A method rather than a ternary: phpcs.xml forbids inline IF, and this
	 * decides whether a freshly instantiated route can be acted on at all.
	 *
	 * @param int $index The zero-based step index.
	 *
	 * @return string Either `active` or `pending`.
	 */
	private function initialStatus(int $index): string {
		if ($index === 0) {
			return 'active';
		}

		return 'pending';
	}//end initialStatus()
}//end class
