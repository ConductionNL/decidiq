<?php

/**
 * Decidiq GovernanceBodyStateRequestedListener
 *
 * Answers a GovernanceBodyStateRequestedEvent from GovernanceBodyQueryService
 * and writes the body onto the dispatched instance, so the consuming app reads
 * it straight after dispatchTyped().
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
 * @spec openspec/specs/governance-body-events/spec.md#requirement-req-gbe-007-a-consumer-reads-its-governance-body-back-through-a-typed-event
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Listener;

use OCA\Decidiq\Event\GovernanceBodyStateRequestedEvent;
use OCA\Decidiq\Service\GovernanceBodyQueryService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Handles GovernanceBodyStateRequestedEvent by delegating to the query service.
 *
 * @implements IEventListener<Event>
 *
 * @spec openspec/specs/governance-body-events/spec.md#requirement-req-gbe-007-a-consumer-reads-its-governance-body-back-through-a-typed-event
 */
class GovernanceBodyStateRequestedListener implements IEventListener {

	/**
	 * Constructor.
	 *
	 * @param GovernanceBodyQueryService $queryService The keyed read.
	 * @param LoggerInterface            $logger       Logger.
	 */
	public function __construct(
		private readonly GovernanceBodyQueryService $queryService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Handle a GovernanceBodyStateRequestedEvent.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/governance-body-events/spec.md#requirement-req-gbe-007-a-consumer-reads-its-governance-body-back-through-a-typed-event
	 */
	public function handle(Event $event): void {
		if ($event instanceof GovernanceBodyStateRequestedEvent === false) {
			return;
		}

		try {
			$body = $this->queryService->lookup(
				sourceApp: $event->getSourceApp(),
				externalReference: $event->getExternalReference(),
				governanceBodyId: $event->getGovernanceBodyId(),
			);
		} catch (Throwable $e) {
			// Left UNHANDLED on purpose: the consumer reads that as "could not
			// read" and uses its own copy. Reading a failed lookup as "no such
			// body" would make a live committee look disbanded. Nothing is
			// rethrown, so other listeners on the event still run.
			$this->logger->error(
				'Decidiq: GovernanceBodyStateRequestedEvent not answered',
				[
					'sourceApp' => $event->getSourceApp(),
					'externalReference' => $event->getExternalReference(),
					'governanceBodyId' => $event->getGovernanceBodyId(),
					'exception' => $e->getMessage(),
				]
			);
			return;
		}//end try

		if ($body !== null) {
			$event->setGovernanceBody($body);
		}

		$event->setHandled(true);

	}//end handle()
}//end class
