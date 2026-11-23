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
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Scores a decision before approval.
 *
 * Sending a decision for approval writes the decision (the flow starter
 * records its route and run), so the route opens on a scored decision; a
 * later save that changes a property the expression reads scores it again.
 * The server always writes the assessment, so a client cannot set its own.
 * A type without an expression, or a decision without a type, clears it. A
 * type that cannot be read, or a malformed expression, leaves the save alone
 * and is logged: the score informs the approver, it does not guard the save.
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
	 * Reads the decision and its type.
	 *
	 * @var SavedObjectLookup
	 */
	private readonly SavedObjectLookup $lookup;

	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface $objectService Reads the decision type.
	 * @param LoggerInterface        $logger        Logs a decision that could not be scored.
	 *
	 * @spec openspec/changes/the-decision-as-a-walked-process/specs/decision-as-a-walked-process/spec.md#requirement-req-dwp-008-a-risk-score-is-computed-before-approval
	 */
	public function __construct(
		ObjectServiceInterface $objectService,
		private readonly LoggerInterface $logger,
	) {
		$this->lookup = new SavedObjectLookup(objectService: $objectService);
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

		$decision = SavedObjectLookup::saved(event: $event);
		$typeId   = SavedObjectLookup::reference(value: ($decision['type'] ?? null));
		if ($typeId === '') {
			// Nothing to be scored by, so a score left from an earlier type goes.
			if (($decision[self::FIELD] ?? null) !== null) {
				$event->setModifiedData(array_merge($event->getModifiedData(), [self::FIELD => null]));
			}

			return;
		}

		$assessment = $this->assess(decision: $decision, typeId: $typeId);
		if ($assessment !== false) {
			$event->setModifiedData(array_merge($event->getModifiedData(), [self::FIELD => $assessment]));
		}
	}//end handle()

	/**
	 * The decision's assessment under its type, or false when it cannot be worked out.
	 *
	 * @param array<string, mixed> $decision The decision as it will be saved.
	 * @param string               $typeId   Its type's id.
	 *
	 * @return array<string, mixed>|null|false Null when the type declares no expression.
	 */
	private function assess(array $decision, string $typeId): array|null|false {
		$type = $this->lookup->load(id: $typeId, schema: 'decision-template');
		if ($type === null) {
			$this->logger->warning(
				message: 'Decidiq: the decision type was not found or could not be read, so the decision was not scored',
				context: ['type' => $typeId]
			);
			return false;
		}

		try {
			return (new RiskScoreEvaluator())->evaluate(expression: ($type['riskScore'] ?? null), subject: $decision);
		} catch (Throwable $e) {
			$this->logger->warning(
				message: 'Decidiq: the decision type\'s risk score is malformed, so the decision was not scored',
				context: ['type' => $typeId, 'reason' => $e->getMessage()]
			);
			return false;
		}
	}//end assess()
}//end class
