<?php

/**
 * Decidiq request-decision flow node.
 *
 * @category Flow
 * @package  OCA\Decidiq\Flow
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/flow-request-decision-node/specs/flow-request-decision/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Flow;

use DateTime;
use OCA\Decidiq\Service\FlowDecisionService;
use OCA\OpenRegister\Service\Flow\FlowNodeResumeState;
use OCA\OpenRegister\Service\Flow\FlowRunService;
use OCA\OpenRegister\Service\Flow\FlowSuspension;
use OCA\OpenRegister\Service\Flow\IFlowNode;
use OCA\OpenRegister\Service\Flow\IFlowNodeConfigKeys;
use OCP\IL10N;
use OCP\WorkflowEngine\IManager;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;
use UnexpectedValueException;

/**
 * Ask decidiq for a decision about the flow's object, and wait for it.
 *
 * The successor of dossiq's `dossiq.requestDecision`, contributed by the app
 * that owns deciding. It accepts that node's config keys name for name and
 * writes the same output, so dossiq's flows move onto it one to one. What it
 * does differently is listed in the change's proposal: the subject comes from
 * the item rather than a hardcoded case, and each step gets its own decision.
 *
 * 🔴 IT FAILS CLOSED. A raise that does not produce a decision fails the step.
 * Carrying on would let a later step assume an approval nobody gave.
 *
 * 🔴 THE DECISION DECIDES, NOT THE SIGNAL. Every re-entry, whether a wake from
 * {@see \OCA\Decidiq\Listener\FlowDecisionConcludedListener} or a heartbeat
 * with nothing in hand, reads the decision back and routes on its state. A
 * conclusion whose wake was lost is therefore recovered on the next heartbeat
 * instead of wedging the run, and a wake can never answer for an open decision.
 *
 * 🔴 UNREADABLE IS NOT GONE. A read that could not be answered buys another
 * heartbeat; only an answered "no such decision" fails the step.
 *
 * @spec openspec/changes/flow-request-decision-node/specs/flow-request-decision/spec.md
 */
class DecidiqRequestDecisionNode implements IFlowNode, IFlowNodeConfigKeys {

	/**
	 * The catalogue id. Fixed: dossiq's repair step rewrites flows onto it.
	 *
	 * @var string
	 */
	public const NODE_ID = 'decidiq.request-decision';

	/**
	 * The item key the outcome lands under when the step names none.
	 *
	 * @var string
	 */
	public const DEFAULT_SIGNAL_KEY = 'decisionOutcome';

	/**
	 * The decision type when the step names none.
	 *
	 * @var string
	 */
	public const DEFAULT_DECISION_TYPE = 'advice';

	/**
	 * Minutes between heartbeats when the step names none. A decision convenes
	 * people, so a half-hourly safety net would be noise.
	 *
	 * @var integer
	 */
	private const DEFAULT_HEARTBEAT_MINUTES = 120;

	/**
	 * The shortest heartbeat honoured.
	 *
	 * @var integer
	 */
	private const MIN_HEARTBEAT_MINUTES = 15;

	/**
	 * The config vocabulary, identical to `dossiq.requestDecision`'s.
	 *
	 * @var array<int, string>
	 */
	private const CONFIG_KEYS = ['question', 'decisionType', 'advisor', 'signalKey', 'heartbeatMinutes'];

	/**
	 * Constructor.
	 *
	 * @param FlowDecisionService $decisions Raises and reads back the decision
	 * @param IL10N $l10n The localisation service
	 * @param LoggerInterface $logger The logger
	 *
	 * @spec openspec/changes/flow-request-decision-node/specs/flow-request-decision/spec.md#requirement-req-frd-001-decidiq-contributes-a-request-decision-node
	 */
	public function __construct(
		private readonly FlowDecisionService $decisions,
		private readonly IL10N $l10n,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * This node's catalogue id.
	 *
	 * @return string The namespaced node id
	 *
	 * @spec openspec/changes/flow-request-decision-node/specs/flow-request-decision/spec.md#requirement-req-frd-001-decidiq-contributes-a-request-decision-node
	 */
	public function getId(): string {
		return self::NODE_ID;
	}//end getId()

	/**
	 * The node's display name.
	 *
	 * @return string The translated name
	 *
	 * @spec openspec/changes/flow-request-decision-node/specs/flow-request-decision/spec.md#requirement-req-frd-001-decidiq-contributes-a-request-decision-node
	 */
	public function getDisplayName(): string {
		return $this->l10n->t('Request a decision');
	}//end getDisplayName()

	/**
	 * What the node does.
	 *
	 * @return string The translated description
	 *
	 * @spec openspec/changes/flow-request-decision-node/specs/flow-request-decision/spec.md#requirement-req-frd-001-decidiq-contributes-a-request-decision-node
	 */
	public function getDescription(): string {
		return $this->l10n->t('Ask Decidiq to decide about this item, and pause the flow until it has.');
	}//end getDescription()

	/**
	 * The node's icon.
	 *
	 * @return string The icon name
	 *
	 * @spec openspec/changes/flow-request-decision-node/specs/flow-request-decision/spec.md#requirement-req-frd-001-decidiq-contributes-a-request-decision-node
	 */
	public function getIcon(): string {
		return 'gavel';
	}//end getIcon()

	/**
	 * Where this node may be offered.
	 *
	 * @param int $scope The Nextcloud workflow scope
	 *
	 * @return bool True when available in this scope
	 *
	 * @spec openspec/changes/flow-request-decision-node/specs/flow-request-decision/spec.md#requirement-req-frd-001-decidiq-contributes-a-request-decision-node
	 */
	public function isAvailableForScope(int $scope): bool {
		return in_array($scope, [IManager::SCOPE_ADMIN, IManager::SCOPE_USER], true);
	}//end isAvailableForScope()

	/**
	 * Every config key this node reads.
	 *
	 * @return array<int, string> The accepted top-level config keys
	 *
	 * @spec openspec/changes/flow-request-decision-node/specs/flow-request-decision/spec.md#requirement-req-frd-001-decidiq-contributes-a-request-decision-node
	 */
	public function configKeys(): array {
		return self::CONFIG_KEYS;
	}//end configKeys()

	/**
	 * Refuse a decision request with no question.
	 *
	 * @param array $config The step configuration
	 *
	 * @return void
	 *
	 * @throws UnexpectedValueException When the question is missing
	 *
	 * @spec openspec/changes/flow-request-decision-node/specs/flow-request-decision/spec.md#requirement-req-frd-001-decidiq-contributes-a-request-decision-node
	 */
	public function validateConfig(array $config): void {
		if (trim((string)($config['question'] ?? '')) === '') {
			throw new UnexpectedValueException(
				$this->l10n->t('Say what is being decided, or the decision cannot be presented.')
			);
		}
	}//end validateConfig()

	/**
	 * Raise the decision on the first pass; on every later pass, read it back.
	 *
	 * @param array $items The input items
	 * @param array $config The step configuration
	 * @param array $context Run-level metadata
	 *
	 * @return array The items, each carrying the outcome
	 *
	 * @throws FlowSuspension While the decision is outstanding, or unreadable
	 * @throws RuntimeException When the node has no resume slot, the raise
	 *                          fails, or the decision was refused, gone or withdrawn
	 *
	 * @spec openspec/changes/flow-request-decision-node/specs/flow-request-decision/spec.md#requirement-req-frd-002-the-decision-is-about-the-object-the-flow-carries
	 * @spec openspec/changes/flow-request-decision-node/specs/flow-request-decision/spec.md#requirement-req-frd-004-the-step-advances-on-its-decision-not-on-a-signal
	 */
	public function execute(array $items, array $config, array $context): array {
		$this->validateConfig(config: $config);

		$resume = ($context[FlowNodeResumeState::CONTEXT_KEY] ?? null);
		if (($resume instanceof FlowNodeResumeState) === false) {
			// No slot means nowhere to record the ref: every heartbeat would
			// raise ANOTHER decision and nothing could name the one to read.
			throw new RuntimeException(self::NODE_ID . ' requires a node resume slot');
		}

		$ref = trim((string)$resume->get(key: 'decisionRef', default: ''));
		if ($ref === '') {
			$this->raise(items: $items, config: $config, context: $context, resume: $resume);

			throw $this->suspension(config: $config);
		}

		return $this->onReEntry(items: $items, config: $config, context: $context, resume: $resume, ref: $ref);
	}//end execute()

	/**
	 * Read the decision back and act on its state.
	 *
	 * DECIDED advances; OPEN and UNREADABLE suspend without touching the slot;
	 * WITHDRAWN, GONE and REFUSED fail, because no number of heartbeats changes
	 * any of them.
	 *
	 * @param array $items The input items
	 * @param array $config The step configuration
	 * @param array $context Run-level metadata
	 * @param FlowNodeResumeState $resume This node's resume slot
	 * @param string $ref The decision this node raised
	 *
	 * @return array The items, each carrying the outcome
	 *
	 * @throws FlowSuspension While the decision is outstanding, or unreadable
	 * @throws RuntimeException When the read is refused, or the decision is gone or withdrawn
	 *
	 * @spec openspec/changes/flow-request-decision-node/specs/flow-request-decision/spec.md#requirement-req-frd-004-the-step-advances-on-its-decision-not-on-a-signal
	 */
	private function onReEntry(array $items, array $config, array $context, FlowNodeResumeState $resume, string $ref): array {
		$actor = $this->actorFor(context: $context, resume: $resume);
		if ($actor === '') {
			$this->logger->warning(
				'Decidiq request-decision: this run names no acting identity, so decision ' . $ref
					. ' cannot be read back; waiting for its conclusion instead',
				['node' => $resume->nodeId()]
			);

			throw $this->suspension(config: $config);
		}

		$read = $this->decisions->readState(decisionId: $ref, actorId: $actor);
		$state = $read['state'];

		if ($state === FlowDecisionService::STATE_UNREADABLE) {
			$this->logger->warning(
				'Decidiq request-decision: could not read decision ' . $ref . '; waiting another heartbeat',
				['node' => $resume->nodeId(), 'actor' => $actor]
			);

			throw $this->suspension(config: $config);
		}

		$this->failOnDeadEnd(state: $state, ref: $ref, actor: $actor);

		if ($state !== FlowDecisionService::STATE_DECIDED) {
			// Still open. Suspend again, and do NOT touch the slot.
			throw $this->suspension(config: $config);
		}

		return $this->placeOutcome(
			items: $items,
			config: $config,
			outcome: $this->outcomeFor(read: $read, ref: $ref, context: $context, resume: $resume)
		);
	}//end onReEntry()

	/**
	 * Fail the step on a state no heartbeat can change.
	 *
	 * @param string $state The read state
	 * @param string $ref The decision this node raised
	 * @param string $actor The identity the read was scoped to
	 *
	 * @return void
	 *
	 * @throws RuntimeException When the state is refused, gone or withdrawn
	 *
	 * @spec openspec/changes/flow-request-decision-node/specs/flow-request-decision/spec.md#requirement-req-frd-004-the-step-advances-on-its-decision-not-on-a-signal
	 */
	private function failOnDeadEnd(string $state, string $ref, string $actor): void {
		if ($state === FlowDecisionService::STATE_REFUSED) {
			throw new RuntimeException(
				sprintf(
					'Decidiq refused to report decision %s to "%s", the identity this run raised it as, '
						. 'so %s cannot tell whether it was taken.',
					$ref,
					$actor,
					self::NODE_ID
				)
			);
		}

		if ($state === FlowDecisionService::STATE_GONE) {
			throw new RuntimeException(
				sprintf('Decision %s, which %s was waiting on, no longer exists.', $ref, self::NODE_ID)
			);
		}

		if ($state === FlowDecisionService::STATE_WITHDRAWN) {
			throw new RuntimeException(
				sprintf('Decision %s was withdrawn, so the question %s asked will never be answered.', $ref, self::NODE_ID)
			);
		}
	}//end failOnDeadEnd()

	/**
	 * The identity the read back is scoped to: the one that raised it.
	 *
	 * Decidiq stamps a decision's owner from the uid that saved it, so reading
	 * as anybody else is refused. The run's current identity is the fallback
	 * for a slot that recorded none.
	 *
	 * @param array $context Run-level metadata
	 * @param FlowNodeResumeState $resume This node's resume slot
	 *
	 * @return string The uid, or '' when this run names none
	 *
	 * @spec openspec/changes/flow-request-decision-node/specs/flow-request-decision/spec.md#requirement-req-frd-004-the-step-advances-on-its-decision-not-on-a-signal
	 */
	private function actorFor(array $context, FlowNodeResumeState $resume): string {
		$recorded = trim((string)$resume->get(key: 'raisedBy', default: ''));
		if ($recorded !== '') {
			return $recorded;
		}

		return trim((string)($context[FlowRunService::RUN_AS_CONTEXT_KEY] ?? ''));
	}//end actorFor()

	/**
	 * The outcome the flow routes on, taken from the decision.
	 *
	 * The decision's fields are written LAST so a wake's payload can add
	 * detail and never override the answer. `recovered` records that no wake
	 * was in hand, so a run advanced by its heartbeat can be found afterwards.
	 *
	 * @param array{state: string, status: string, envelope: array<string, mixed>} $read The read answer
	 * @param string $ref The decision this node raised
	 * @param array $context Run-level metadata
	 * @param FlowNodeResumeState $resume This node's resume slot
	 *
	 * @return array<string, mixed> The outcome bag
	 *
	 * @spec openspec/changes/flow-request-decision-node/specs/flow-request-decision/spec.md#requirement-req-frd-005-the-output-matches-dossiqrequestdecision
	 */
	private function outcomeFor(array $read, string $ref, array $context, FlowNodeResumeState $resume): array {
		$signal = ($context[FlowRunService::SIGNAL_CONTEXT_KEY] ?? null);
		if (is_array($signal) === false) {
			$signal = [];
		}

		$status = $read['status'];
		$envelope = $read['envelope'];
		$recovered = ($signal === []);
		if ($recovered === true) {
			$this->logger->info(
				'Decidiq request-decision: a heartbeat delivered the outcome of decision ' . $ref
					. '; its conclusion never reached the run',
				['node' => $resume->nodeId(), 'status' => $status]
			);
		}

		return array_merge(
			$signal,
			[
				'decision' => $status,
				'status' => $status,
				'decisionRef' => $ref,
				'node' => $resume->nodeId(),
				'decidedAt' => (string)($envelope['decidedAt'] ?? ''),
				'signed' => (bool)($envelope['signed'] ?? false),
				'recovered' => $recovered,
			]
		);
	}//end outcomeFor()

	/**
	 * Write the outcome onto every item, under the configured key.
	 *
	 * @param array $items The items to pass on
	 * @param array $config The step configuration
	 * @param array<string, mixed> $outcome The outcome bag
	 *
	 * @return array The items, each carrying the outcome
	 *
	 * @spec openspec/changes/flow-request-decision-node/specs/flow-request-decision/spec.md#requirement-req-frd-005-the-output-matches-dossiqrequestdecision
	 */
	private function placeOutcome(array $items, array $config, array $outcome): array {
		$key = trim((string)($config['signalKey'] ?? ''));
		if ($key === '') {
			$key = self::DEFAULT_SIGNAL_KEY;
		}

		$out = [];
		foreach ($items as $item) {
			if (is_array($item) === true) {
				$json = (array)($item['json'] ?? []);
				$json[$key] = $outcome;
				$item['json'] = $json;
			}

			$out[] = $item;
		}

		return $out;
	}//end placeOutcome()

	/**
	 * The suspension this node parks on, naming what it waits for.
	 *
	 * @param array $config The step configuration
	 *
	 * @return FlowSuspension The suspension to throw
	 *
	 * @spec openspec/changes/flow-request-decision-node/specs/flow-request-decision/spec.md#requirement-req-frd-004-the-step-advances-on-its-decision-not-on-a-signal
	 */
	private function suspension(array $config): FlowSuspension {
		$minutes = (int)($config['heartbeatMinutes'] ?? self::DEFAULT_HEARTBEAT_MINUTES);
		if ($minutes < self::MIN_HEARTBEAT_MINUTES) {
			$minutes = self::MIN_HEARTBEAT_MINUTES;
		}

		return new FlowSuspension(
			resumeAt: (new DateTime())->modify('+' . $minutes . ' minutes'),
			reason: sprintf('waiting for a decision: %s', trim((string)($config['question'] ?? '')))
		);
	}//end suspension()

	/**
	 * Raise the decision once, and remember which one it is.
	 *
	 * @param array $items The input items; the first carries the object
	 * @param array $config The step configuration
	 * @param array $context Run-level metadata
	 * @param FlowNodeResumeState $resume This node's resume slot
	 *
	 * @return void
	 *
	 * @throws RuntimeException When there is no object, or the decision cannot be raised
	 *
	 * @spec openspec/changes/flow-request-decision-node/specs/flow-request-decision/spec.md#requirement-req-frd-002-the-decision-is-about-the-object-the-flow-carries
	 * @spec openspec/changes/flow-request-decision-node/specs/flow-request-decision/spec.md#requirement-req-frd-003-the-step-fails-closed
	 */
	private function raise(array $items, array $config, array $context, FlowNodeResumeState $resume): void {
		$subject = $this->subjectOf(items: $items);
		if ($subject['subjectId'] === '') {
			throw new RuntimeException(self::NODE_ID . ' had no object to decide on');
		}

		$decisionType = trim((string)($config['decisionType'] ?? ''));
		if ($decisionType === '') {
			$decisionType = self::DEFAULT_DECISION_TYPE;
		}

		$raisedBy = trim((string)($context[FlowRunService::RUN_AS_CONTEXT_KEY] ?? ''));
		$question = trim((string)($config['question'] ?? ''));

		try {
			// Runs under the run's `runAs`: OpenRegister's RegistryStepDispatcher
			// executes every contributed node inside ObjectService::runAs(), so
			// the decision's owner is the run's acting identity, which is the
			// identity the read back names.
			$ref = $this->decisions->raise(
				decisionType: $decisionType,
				externalReference: $this->externalReference(context: $context, resume: $resume, subjectId: $subject['subjectId']),
				subject: $subject,
				context: [
					'question' => $question,
					'advisor' => trim((string)($config['advisor'] ?? '')),
				],
				actorId: $raisedBy
			);
		} catch (Throwable $e) {
			// NOT swallowed: softening a failed raise would let the run proceed
			// past a decision nobody made.
			$this->logger->error(
				'Decidiq request-decision: could not raise the decision; the run stops here',
				['subject' => $subject['subjectId'], 'error' => $e->getMessage()]
			);

			throw new RuntimeException('decision_could_not_be_raised: ' . $e->getMessage(), 0, $e);
		}//end try

		$slot = [
			'decisionRef' => $ref,
			'askedAt' => (new DateTime())->format('c'),
			'question' => $question,
		];

		// Recorded only when the run names one, so a run naming none falls
		// back to its live `runAs` rather than to a stored empty string.
		if ($raisedBy !== '') {
			$slot['raisedBy'] = $raisedBy;
		}

		$resume->merge(values: $slot);

		$this->logger->info(
			'Decidiq request-decision: raised decision ' . $ref . ' and suspended the run',
			['subject' => $subject['subjectId'], 'node' => $resume->nodeId()]
		);
	}//end raise()

	/**
	 * The reference that makes this step's decision its own.
	 *
	 * `flow-run:<runUuid>:<nodeId>`. It is part of decidiq's idempotency key,
	 * so two decision steps on one object each get their own decision, and a
	 * raise retried after a crash finds the one it already made. It also names
	 * the run for {@see \OCA\Decidiq\Listener\FlowDecisionConcludedListener}.
	 * Without a run uuid (an engine that does not hand one) it falls back to
	 * the object id, which is what dossiq's node used.
	 *
	 * @param array $context Run-level metadata
	 * @param FlowNodeResumeState $resume This node's resume slot
	 * @param string $subjectId The object the decision is about
	 *
	 * @return string The external reference
	 *
	 * @spec openspec/changes/flow-request-decision-node/specs/flow-request-decision/spec.md#requirement-req-frd-002-the-decision-is-about-the-object-the-flow-carries
	 */
	private function externalReference(array $context, FlowNodeResumeState $resume, string $subjectId): string {
		$runUuid = trim((string)($context['runUuid'] ?? ''));
		if ($runUuid === '') {
			return $subjectId;
		}

		return $this->decisions->runReference(runUuid: $runUuid, nodeId: $resume->nodeId());
	}//end externalReference()

	/**
	 * The object the decision is about, read from the first item.
	 *
	 * Register and schema come from the item's `@self`, which is how
	 * OpenRegister serialises an object; either may arrive expanded as an
	 * array, in which case its slug (or id) names it. The id prefers `@self.id`
	 * and falls back to `id` and `uuid`, the order OpenRegister's own item
	 * identity uses. The label prefers the object's own title or name over
	 * `@self.name`, which defaults to the uuid.
	 *
	 * @param array $items The input items
	 *
	 * @return array{subjectRegister: string, subjectSchema: string, subjectId: string, subjectLabel: string} The subject
	 *
	 * @spec openspec/changes/flow-request-decision-node/specs/flow-request-decision/spec.md#requirement-req-frd-002-the-decision-is-about-the-object-the-flow-carries
	 */
	private function subjectOf(array $items): array {
		$first = ($items[0] ?? null);
		$json = [];
		if (is_array($first) === true) {
			$json = (array)($first['json'] ?? []);
		}

		$self = ($json['@self'] ?? []);
		if (is_array($self) === false) {
			$self = [];
		}

		return [
			'subjectRegister' => $this->nameOf(value: ($self['register'] ?? '')),
			'subjectSchema' => $this->nameOf(value: ($self['schema'] ?? '')),
			'subjectId' => $this->firstString(candidates: [($self['id'] ?? null), ($json['id'] ?? null), ($json['uuid'] ?? null)]),
			'subjectLabel' => $this->firstString(candidates: [($json['title'] ?? null), ($json['name'] ?? null), ($self['name'] ?? null)]),
		];
	}//end subjectOf()

	/**
	 * A register or schema reference as a string, expanded or not.
	 *
	 * @param mixed $value A slug, an id, or an expanded object
	 *
	 * @return string The slug or id, or '' when there is none
	 */
	private function nameOf(mixed $value): string {
		if (is_array($value) === true) {
			return $this->firstString(candidates: [($value['slug'] ?? null), ($value['id'] ?? null)]);
		}

		return $this->firstString(candidates: [$value]);
	}//end nameOf()

	/**
	 * The first non-empty scalar among the candidates, trimmed.
	 *
	 * @param array<int, mixed> $candidates Values in order of preference
	 *
	 * @return string The first usable value, or ''
	 */
	private function firstString(array $candidates): string {
		foreach ($candidates as $candidate) {
			if (is_scalar($candidate) === true && trim((string)$candidate) !== '') {
				return trim((string)$candidate);
			}
		}

		return '';
	}//end firstString()
}//end class
