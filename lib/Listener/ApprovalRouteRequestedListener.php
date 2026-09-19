<?php

/**
 * Decidiq ApprovalRouteRequestedListener
 *
 * Maps an inbound cross-app ApprovalRouteRequestedEvent onto
 * ApprovalRouteCommandService and writes the result back onto the dispatched
 * instance for the synchronous producer.
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
 * @spec openspec/changes/approval-route-events/specs/approval-route-events/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Listener;

use OCA\Decidiq\Event\ApprovalRouteRequestedEvent;
use OCA\Decidiq\Service\ApprovalRouteCommandService;
use OCA\Decidiq\Service\ApprovalRouteService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Handles ApprovalRouteRequestedEvent by delegating to the command service.
 *
 * @implements IEventListener<Event>
 *
 * @spec openspec/changes/approval-route-events/specs/approval-route-events/spec.md
 */
class ApprovalRouteRequestedListener implements IEventListener {

	/**
	 * Constructor.
	 *
	 * @param ApprovalRouteCommandService $commandService The idempotent upsert engine.
	 * @param LoggerInterface             $logger         Logger.
	 * @param ApprovalRouteService        $engine         The route engine, for a
	 *        route held from named people rather than from a template.
	 */
	public function __construct(
		private readonly ApprovalRouteCommandService $commandService,
		private readonly LoggerInterface $logger,
		private readonly ApprovalRouteService $engine,
	) {
	}//end __construct()

	/**
	 * Handle an ApprovalRouteRequestedEvent.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/approval-route-events/specs/approval-route-events/spec.md
	 */
	public function handle(Event $event): void {
		if ($event instanceof ApprovalRouteRequestedEvent === false) {
			return;
		}

		try {
			// A producer that names PEOPLE rather than steps gets the ad-hoc
			// path: no template row is written, because a template nobody
			// reuses on every document review is a register full of them.
			if ($event->getActors() !== [] && $event->getSubject() !== '') {
				$this->handleAdhoc(event: $event);
				return;
			}

			$result = $this->commandService->holdRoute(
				sourceApp: $event->getSourceApp(),
				externalReference: $event->getExternalReference(),
				template: [
					'name' => $event->getName(),
					'steps' => $event->getSteps(),
					'subjectType' => $event->getSubjectType(),
					'description' => $event->getDescription(),
					'isDefault' => $event->isDefault(),
				],
				subject: $event->getSubject(),
				subjectSchema: $event->getSubjectSchema(),
			);
		} catch (Throwable $e) {
			// Never rethrown: an exception out of handle() aborts the whole
			// dispatch, so an unrelated listener on the same event would stop
			// running because this one failed. The producer sees isHandled().
			$this->logger->error(
				'Decidiq: ApprovalRouteRequestedEvent not handled',
				[
					'sourceApp' => $event->getSourceApp(),
					'externalReference' => $event->getExternalReference(),
					'exception' => $e,
				]
			);
			return;
		}//end try

		$event->setRouteId($result['id']);
		$event->setCreated($result['created']);
		$event->setStageCount($result['stageCount']);
		$event->setHandled(true);

		$this->logger->info(
			'Decidiq: handled ApprovalRouteRequestedEvent',
			[
				'sourceApp' => $event->getSourceApp(),
				'externalReference' => $event->getExternalReference(),
				'routeId' => $result['id'],
				'created' => $result['created'],
				'stageCount' => $result['stageCount'],
			]
		);

	}//end handle()

	/**
	 * Hold a route from the people the producer named, with no template.
	 *
	 * @param ApprovalRouteRequestedEvent $event The command.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/document-approval-chain-leaf/specs/approval-routes/spec.md (REQ-AR-008)
	 */
	private function handleAdhoc(ApprovalRouteRequestedEvent $event): void {
		$routeName = $event->getName();
		if ($routeName === '') {
			$routeName = 'Review';
		}

		$stages = $this->engine->holdFor(
			subject: $event->getSubject(),
			actors: $event->getActors(),
			subjectSchema: $event->getSubjectSchema(),
			deadline: $event->getDeadline(),
			name: $routeName,
		);

		// No route id, because there is no route row. Carrying the producer's
		// own reference back would read as an id this app can be asked about,
		// and it cannot.
		$event->setRouteId('');
		$event->setCreated(true);
		$event->setStageCount(count($stages));
		$event->setHandled(true);

		$this->logger->info(
			'Decidiq: held an ad-hoc approval route from named people',
			[
				'sourceApp' => $event->getSourceApp(),
				'externalReference' => $event->getExternalReference(),
				'stageCount' => count($stages),
			]
		);

	}//end handleAdhoc()

}//end class
