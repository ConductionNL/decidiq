<?php

/**
 * Decidiq approval-task decision listener.
 *
 * Closes the loop from the task inbox: when an approver answers a PROJECTED
 * approval-stage task (marked `decidiq:approval-stage` by the projector), the
 * answer becomes an engine action, so signing from the inbox and signing from
 * the route surface are the same signature through the same rules.
 *
 * 🔴 THE ENGINE STILL JUDGES. The task's completer is handed to
 * `ApprovalRouteService::record()` as the actor, with the task's onBehalfOf, and
 * every refusal the engine would give the REST path it gives here too. A task
 * completion the engine refuses stays a completed task — the task surface
 * already committed it and this listener only observed — so the refusal is
 * logged loudly and the stage keeps waiting, which mirrors dossiq's
 * ParaafResumeListener posture: the write stands, the ADVANCE is withheld.
 *
 * The OpenRegister event class is registered by FQN string and only when it
 * exists, so decidiq carries no hard compile-time dependency on a release that
 * ships the task surface.
 *
 * @category Listener
 * @package  OCA\Decidiq\Listener
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
 * @spec openspec/changes/parafering-route-runtime/specs/parafering-route-runtime/spec.md
 */

declare(strict_types=1);

namespace OCA\Decidiq\Listener;

use OCA\Decidiq\Service\ApprovalRouteConclusionAnnouncer;
use OCA\Decidiq\Service\ApprovalRouteService;
use OCA\Decidiq\Service\ApprovalStageTaskProjector;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Turns an answered approval-stage task into an engine action.
 *
 * @implements IEventListener<Event>
 *
 * @spec openspec/changes/parafering-route-runtime/specs/parafering-route-runtime/spec.md
 */
class ApprovalTaskDecisionListener implements IEventListener {

	/**
	 * Task outcomes that send the subject back rather than onward.
	 *
	 * Mirrors the task surface's own rejecting-outcome vocabulary; matched
	 * case-insensitively the way the surface matches them.
	 *
	 * @var array<int, string>
	 */
	private const REJECTING_OUTCOMES = ['rejected', 'returned', 'refused', 'denied'];

	/**
	 * Constructor.
	 *
	 * @param ApprovalRouteService $engine The route engine every rule lives in.
	 * @param ApprovalRouteConclusionAnnouncer $announcer The one door a conclusion leaves by.
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct(
		private readonly ApprovalRouteService $engine,
		private readonly ApprovalRouteConclusionAnnouncer $announcer,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Stage types and the completing verb each one's signature uses.
	 *
	 * A type not listed here completes with `approved`.
	 */
	private const STAGE_VERBS = [
		'advisory' => 'advised',
		'endorsement' => 'endorsed',
		'decisive' => 'approved',
	];

	/**
	 * Record the engine action an answered projected task means.
	 *
	 * @param Event $event The dispatched OpenRegister TaskTerminalEvent.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/parafering-route-runtime/specs/parafering-route-runtime/spec.md
	 */
	public function handle(Event $event): void {
		$answered = $this->answeredTaskFrom(event: $event);
		if ($answered === null) {
			return;
		}

		$action = $this->actionFrom(answered: $answered);
		if ($action === null || $this->recorded(answered: $answered, action: $action) === false) {
			return;
		}

		$this->announcer->announceIfConcluded(subject: $answered->subject);
	}//end handle()

	/**
	 * The answered task this event reports, with its subject and completer.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return AnsweredApprovalTask|null The task, or null when this event is not ours or lacks either.
	 */
	private function answeredTaskFrom(Event $event): ?AnsweredApprovalTask {
		$task = $this->projectedDecisionFrom(event: $event);
		if ($task === null) {
			return null;
		}

		$answered = new AnsweredApprovalTask(
			task: $task,
			subject: $this->read(object: $task, getter: 'getObjectUuid'),
			actor: $this->read(object: $task, getter: 'getCompletedBy'),
		);
		if ($answered->subject === '' || $answered->actor === '') {
			return null;
		}

		return $answered;
	}//end answeredTaskFrom()

	/**
	 * Hand the action to the engine; a refusal is logged and withholds the advance.
	 *
	 * @param AnsweredApprovalTask $answered The answered task.
	 * @param array<string, mixed> $action   The engine action.
	 *
	 * @return bool Whether the engine recorded the action.
	 */
	private function recorded(AnsweredApprovalTask $answered, array $action): bool {
		try {
			$this->engine->record(action: $action);
		} catch (Throwable $e) {
			// The task is already terminal; what is withheld is the ADVANCE.
			$this->logRefusal(answered: $answered, reason: $e);
			return false;
		}

		return true;
	}//end recorded()

	/**
	 * Log the engine's refusal of an answered task.
	 *
	 * @param AnsweredApprovalTask $answered The answered task.
	 * @param Throwable            $reason   The engine's refusal.
	 *
	 * @return void
	 */
	private function logRefusal(AnsweredApprovalTask $answered, Throwable $reason): void {
		$this->logger->warning(
			'Decidiq: a completed approval task did not advance the route — the engine refused it',
			[
				'subject' => $answered->subject,
				'actor' => $answered->actor,
				'reason' => $reason->getMessage(),
			]
		);
	}//end logRefusal()

	/**
	 * The task this event reports, when it is a projected stage DECISION.
	 *
	 * Duck-typed throughout: the event and task classes are OpenRegister's and
	 * optional at runtime. Filters, in order: the event carries a task; the
	 * task carries the projector's template marker (the contract that makes it
	 * ours); the task ended as a completed decision — a consumed, cancelled or
	 * terminated task decided nothing, and the projector's own consume() run
	 * lands here too and must not echo back into the engine.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return object|null The task, or null when this event is not ours.
	 */
	private function projectedDecisionFrom(Event $event): ?object {
		$task = $this->taskOf(event: $event);
		if ($task === null || $this->isCompletedProjection(task: $task) === false) {
			return null;
		}

		return $task;
	}//end projectedDecisionFrom()

	/**
	 * The task an event carries, when it carries one.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return object|null The task.
	 */
	private function taskOf(Event $event): ?object {
		if (method_exists($event, 'getTask') === false) {
			return null;
		}

		$task = $event->getTask();
		if (is_object($task) === false) {
			return null;
		}

		return $task;
	}//end taskOf()

	/**
	 * Whether a task is the projector's and ended as a completed decision.
	 *
	 * @param object $task The task.
	 *
	 * @return bool
	 */
	private function isCompletedProjection(object $task): bool {
		return $this->read(object: $task, getter: 'getTemplateId') === ApprovalStageTaskProjector::TEMPLATE_ID
			&& $this->read(object: $task, getter: 'getState') === 'completed';
	}//end isCompletedProjection()

	/**
	 * The engine action an answered task means.
	 *
	 * A rejecting outcome is a terugsturen: `returned` with the task's comment
	 * as the reason, concluding the route back to its sender. Any other
	 * outcome completes the stage with the verb the stage's TYPE asks for —
	 * looked up from the live stage, because the engine refuses a mismatched
	 * verb and the inbox only says "done", not which vocabulary word this
	 * stage's signature uses.
	 *
	 * @param AnsweredApprovalTask $answered The answered task.
	 *
	 * @return array<string, mixed>|null The action, or null when no stage awaits.
	 */
	private function actionFrom(AnsweredApprovalTask $answered): ?array {
		$outcome = strtolower(trim($this->read(object: $answered->task, getter: 'getOutcome')));
		$action = $this->baseActionFor(answered: $answered);

		if (in_array($outcome, self::REJECTING_OUTCOMES, true) === true) {
			return $this->returnedAction(action: $action);
		}

		return $this->completedAction(answered: $answered, action: $action);
	}//end actionFrom()

	/**
	 * The fields every action carries: subject, actor, comment and any delegate mandate.
	 *
	 * @param AnsweredApprovalTask $answered The answered task.
	 *
	 * @return array<string, mixed> The action so far.
	 */
	private function baseActionFor(AnsweredApprovalTask $answered): array {
		$comment = $this->read(object: $answered->task, getter: 'getComment');
		$onBehalfOf = $this->read(object: $answered->task, getter: 'getOnBehalfOf');

		$action = [
			'subject' => $answered->subject,
			'actor' => $answered->actor,
			'comment' => $comment,
		];

		if ($onBehalfOf !== '') {
			$action['actorType'] = 'delegate';
			$action['onBehalfOf'] = $onBehalfOf;
			$action['mandate'] = $this->read(object: $answered->task, getter: 'getMandate');
		}

		return $action;
	}//end baseActionFor()

	/**
	 * A terugsturen: `returned`, with a standard reason when none was given.
	 *
	 * @param array<string, mixed> $action The action so far.
	 *
	 * @return array<string, mixed> The action.
	 */
	private function returnedAction(array $action): array {
		$action['action'] = 'returned';
		if (trim((string) $action['comment']) === '') {
			$action['comment'] = 'Returned from the task inbox without a stated reason.';
		}

		return $action;
	}//end returnedAction()

	/**
	 * A stage completion with the verb the awaiting stage asks for, plus advice for an advisory stage.
	 *
	 * @param AnsweredApprovalTask $answered The answered task.
	 * @param array<string, mixed> $action   The action so far.
	 *
	 * @return array<string, mixed>|null The action, or null when no stage awaits.
	 */
	private function completedAction(AnsweredApprovalTask $answered, array $action): ?array {
		$verb = $this->completingVerbFor(subject: $answered->subject, taskUuid: $this->read(object: $answered->task, getter: 'getUuid'));
		if ($verb === '') {
			return null;
		}

		$action['action'] = $verb;
		if ($verb === 'advised') {
			$action['advice'] = $this->adviceFrom(task: $answered->task, comment: (string) $action['comment']);
		}

		return $action;
	}//end completedAction()

	/**
	 * The advice an advisory answer gives: its result text, else its comment.
	 *
	 * @param object $task    The completed task.
	 * @param string $comment The task's comment.
	 *
	 * @return string The advice.
	 */
	private function adviceFrom(object $task, string $comment): string {
		if (trim($this->read(object: $task, getter: 'getResultText')) !== '') {
			return $this->read(object: $task, getter: 'getResultText');
		}

		return $comment;
	}//end adviceFrom()

	/**
	 * The completing verb the awaiting stage's type asks for.
	 *
	 * Resolved from the stage the task points at (its uuid is on the stage's
	 * `taskUuid`), asking the ENGINE for the stages — never this class's own
	 * query of route state.
	 *
	 * @param string $subject The subject uuid.
	 * @param string $taskUuid The answered task's uuid.
	 *
	 * @return string The verb, or '' when no active stage carries this task.
	 */
	private function completingVerbFor(string $subject, string $taskUuid): string {
		if ($taskUuid === '') {
			return '';
		}

		foreach ($this->engine->stagesFor(subject: $subject) as $stage) {
			if ($this->isActiveStageOf(stage: $stage, taskUuid: $taskUuid) === true) {
				return (self::STAGE_VERBS[(string)($stage['stageType'] ?? '')] ?? 'approved');
			}
		}

		return '';
	}//end completingVerbFor()

	/**
	 * Whether a stage is active and carries the answered task.
	 *
	 * @param array<string, mixed> $stage    An engine stage.
	 * @param string               $taskUuid The answered task's uuid.
	 *
	 * @return bool
	 */
	private function isActiveStageOf(array $stage, string $taskUuid): bool {
		return (string)($stage['taskUuid'] ?? '') === $taskUuid
			&& (string)($stage['status'] ?? '') === 'active';
	}//end isActiveStageOf()

	/**
	 * Read a duck-typed getter, degrading to '' rather than erroring.
	 *
	 * @param object $object The task or event.
	 * @param string $getter The zero-argument getter.
	 *
	 * @return string The stringified value, or ''.
	 */
	private function read(object $object, string $getter): string {
		if (method_exists($object, $getter) === false) {
			return '';
		}

		$value = $object->$getter();
		if ($value === null || is_scalar($value) === false) {
			return '';
		}

		return (string)$value;
	}//end read()

}//end class
