<?php

/**
 * Decidiq Decision Term Listener
 *
 * Writes `termEndsAt` on a decision whose type declares a term, on every
 * create and update: the type's term, counted from the moment the type
 * names (DecisionTermCalculator). A decision whose type declares none, or
 * whose moment is not known yet, is saved with the field empty.
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
 * @spec openspec/changes/objection-advisory-committee/specs/objection-advisory-committee/spec.md#requirement-req-oac-008-an-objection-runs-as-a-decision-through-its-configured-route
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Listener;

use OCA\Decidiq\Service\DecisionTermCalculator;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Stamps the day a decision has to be taken by.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/objection-advisory-committee/specs/objection-advisory-committee/spec.md#requirement-req-oac-008-an-objection-runs-as-a-decision-through-its-configured-route
 */
class DecisionTermListener implements IEventListener {

	/**
	 * Reads the decision, its type and the decision it contests.
	 *
	 * @var SavedObjectLookup
	 */
	private readonly SavedObjectLookup $lookup;

	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface $objectService Reads the decision type and the contested decision.
	 * @param LoggerInterface        $logger        The log.
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
	 * Write the decision's `termEndsAt` from its type.
	 *
	 * A failure to read the type or the contested decision leaves the save
	 * alone: the term is information for the clerk, not a guard.
	 *
	 * @param Event $event The before-save event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/objection-advisory-committee/specs/objection-advisory-committee/spec.md#requirement-req-oac-008-an-objection-runs-as-a-decision-through-its-configured-route
	* @spec openspec/changes/the-decision-as-a-walked-process/specs/decision-as-a-walked-process/spec.md#requirement-req-dwp-008-a-risk-score-is-computed-before-approval
	 */
	public function handle(Event $event): void {
		if ($event instanceof ObjectCreatingEvent === false && $event instanceof ObjectUpdatingEvent === false) {
			return;
		}

		try {
			$decision = $this->lookup->saved(event: $event);
			$type     = $this->lookup->load(id: $this->lookup->reference(value: ($decision['type'] ?? null)), schema: 'decision-template');
			if ($type === null) {
				return;
			}

			$contested = $this->lookup->load(id: $this->lookup->reference(value: ($decision['contests'] ?? null)), schema: 'decision');
			$termEnd = (new DecisionTermCalculator())->termEnd(decision: $decision, type: $type, contested: $contested);
			$event->setModifiedData(array_merge($event->getModifiedData(), ['termEndsAt' => $termEnd]));
		} catch (Throwable $e) {
			$this->logger->warning('Decidiq: the decision term was not worked out', ['exception' => $e->getMessage()]);
		}
	}//end handle()
}//end class
