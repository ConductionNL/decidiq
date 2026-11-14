<?php

/**
 * Ratification Placement Listener
 *
 * When a meeting is created, the pending ratifications of its body are placed
 * on it, if it is a regular, future, open meeting (the "retry when a
 * qualifying meeting is created" of REQ-004).
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
 * @spec openspec/changes/urgent-decision-procedure/specs/urgent-decision-procedure/spec.md#requirement-req-004-mandatory-ratification-stage-is-auto-appended
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Listener;

use OCA\Decidiq\Service\UrgentRatificationService;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Places pending ratifications on a newly created meeting of the ratifying body.
 *
 * The meeting is already saved; a failed placement is logged and the stage
 * stays pending, which the decision detail shows, so nothing is lost.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/urgent-decision-procedure/specs/urgent-decision-procedure/spec.md#requirement-req-004-mandatory-ratification-stage-is-auto-appended
 */
class RatificationPlacementListener implements IEventListener {

	/**
	 * The schema this listener handles.
	 *
	 * @var string
	 */
	public const SCHEMA_MEETING = 'meeting';

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface        $container    Resolves OpenRegister's object service.
	 * @param UrgentRatificationService $ratification Places the ratifications.
	 * @param LoggerInterface           $logger       Logs a failed placement.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly UrgentRatificationService $ratification,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Place the body's pending ratifications on the created meeting.
	 *
	 * @param Event $event The after-create event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/urgent-decision-procedure/specs/urgent-decision-procedure/spec.md#requirement-req-004-mandatory-ratification-stage-is-auto-appended
	 */
	public function handle(Event $event): void {
		if ($event instanceof ObjectCreatedEvent === false) {
			return;
		}

		$entity  = $event->getObject();
		$meeting = (new EntityPayloadReader())->payloadOf(entity: $entity);
		if (($meeting['meetingType'] ?? '') !== 'regular') {
			return;
		}

		if (isset($meeting['id']) === false && method_exists($entity, 'getUuid') === true) {
			$meeting['id'] = $entity->getUuid();
		}

		try {
			$this->ratification->placePending(objectService: $this->container->get('OCA\OpenRegister\Service\ObjectService'), meeting: $meeting);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Decidiq: pending ratifications were not placed on a new meeting',
				['meeting' => ($meeting['id'] ?? null), 'error' => $e->getMessage()]
			);
		}
	}//end handle()
}//end class
