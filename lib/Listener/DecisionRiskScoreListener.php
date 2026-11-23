<?php

/**
 * Decidiq Decision Risk Score Listener
 *
 * Writes a decision's risk assessment from its type's `riskScore` expression
 * on every save (the-decision-as-a-walked-process, REQ-DWP-008, task 8.2).
 *
 * @category Listener
 * @package  OCA\Decidiq\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/the-decision-as-a-walked-process/specs/decision-as-a-walked-process/spec.md#requirement-req-dwp-008-a-risk-score-is-computed-before-approval
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Listener;

use OCA\Decidiq\Service\RiskScoreEvaluator;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Scores a decision before approval.
 *
 * Sending a decision for approval writes the decision (the flow starter
 * records its route and run), so the score is computed when the route opens;
 * a later save that changes a property the expression reads scores it again.
 * The server always writes the assessment, so a client cannot set its own.
 * A type without an expression, or a decision without a type, clears it. A type that cannot be read, or an
 * expression that is malformed, leaves the save alone and is logged: the
 * score informs the approver, it does not guard the save.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/the-decision-as-a-walked-process/specs/decision-as-a-walked-process/spec.md#requirement-req-dwp-008-a-risk-score-is-computed-before-approval
 */
class DecisionRiskScoreListener implements IEventListener {

	/**
	 * The decision property the assessment is written to.
	 */
	public const FIELD = 'riskAssessment';

	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface $objectService Reads the decision type.
	 * @param LoggerInterface        $logger        The log.
	 *
	 * @spec openspec/changes/the-decision-as-a-walked-process/specs/decision-as-a-walked-process/spec.md#requirement-req-dwp-008-a-risk-score-is-computed-before-approval
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Write `riskAssessment` into the save from the decision's type.
	 *
	 * @param Event $event The before-save event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/the-decision-as-a-walked-process/specs/decision-as-a-walked-process/spec.md#requirement-req-dwp-008-a-risk-score-is-computed-before-approval
	 */
	public function handle(Event $event): void {
		if ($event instanceof ObjectCreatingEvent === false && $event instanceof ObjectUpdatingEvent === false) {
			return;
		}

		$decision = array_merge($this->payloadOf(event: $event), $event->getModifiedData());
		if (self::reference(value: ($decision['type'] ?? null)) === '') {
			// A decision without a type has nothing to be scored by, so an
			// assessment left from an earlier type goes.
			if (($decision[self::FIELD] ?? null) !== null) {
				$event->setModifiedData(array_merge($event->getModifiedData(), [self::FIELD => null]));
			}

			return;
		}

		$type = $this->typeOf(decision: $decision);
		if ($type === null) {
			return;
		}

		try {
			$assessment = (new RiskScoreEvaluator())->evaluate(expression: ($type['riskScore'] ?? null), subject: $decision);
		} catch (Throwable $e) {
			$this->logger->warning(
				message: 'Decidiq: the decision type\'s risk score is malformed, so the decision was not scored',
				context: ['type' => self::reference(value: ($decision['type'] ?? null)), 'reason' => $e->getMessage()]
			);
			return;
		}

		$event->setModifiedData(array_merge($event->getModifiedData(), [self::FIELD => $assessment]));
	}//end handle()

	/**
	 * The decision as the event carries it: the new object on an update.
	 *
	 * @param ObjectCreatingEvent|ObjectUpdatingEvent $event The before-save event.
	 *
	 * @return array<string, mixed>
	 */
	private function payloadOf(ObjectCreatingEvent|ObjectUpdatingEvent $event): array {
		if ($event instanceof ObjectUpdatingEvent) {
			return (new EntityPayloadReader())->payloadOf(entity: $event->getNewObject());
		}

		return (new EntityPayloadReader())->payloadOf(entity: $event->getObject());
	}//end payloadOf()

	/**
	 * The decision's type, or null when it is not there or cannot be read.
	 *
	 * @param array<string, mixed> $decision The merged decision payload.
	 *
	 * @return array<string, mixed>|null
	 */
	private function typeOf(array $decision): ?array {
		$typeId = self::reference(value: ($decision['type'] ?? null));

		try {
			$entity = $this->objectService->find(
				id: $typeId,
				options: new ObjectFindOptions(register: 'decidiq', schema: 'decision-template'),
				_rbac: false
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				message: 'Decidiq: the decision type could not be read, so the decision was not scored',
				context: ['type' => $typeId, 'reason' => $e->getMessage()]
			);
			return null;
		}

		if ($entity === null) {
			return null;
		}

		return (new EntityPayloadReader())->payloadOf(entity: $entity);
	}//end typeOf()

	/**
	 * The id a reference holds: a uuid or slug string, or an expanded object.
	 *
	 * @param mixed $value The stored reference.
	 *
	 * @return string
	 */
	private static function reference(mixed $value): string {
		if (is_array($value) === true) {
			$value = ($value['id'] ?? ($value['uuid'] ?? null));
		}

		if (is_string($value) === false) {
			return '';
		}

		return trim($value);
	}//end reference()
}//end class
