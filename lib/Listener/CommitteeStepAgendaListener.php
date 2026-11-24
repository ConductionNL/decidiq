<?php

/**
 * Committee Step Agenda Listener
 *
 * Hands the saves a committee step depends on to CommitteeAgendaPlacement:
 * a committee stage that is active and not yet on an agenda is placed on its
 * body's next regular meeting; a regular meeting created for a body takes the
 * committee stages that were waiting for it; a decision recorded on such a
 * stage's agenda item closes the stage (REQ-DWP-010).
 *
 * Fail-soft: a placement or close that cannot be applied is logged and never
 * breaks the save that triggered it.
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
 * @spec openspec/changes/the-decision-as-a-walked-process/specs/decision-as-a-walked-process/spec.md (REQ-DWP-010)
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Listener;

use OCA\Decidiq\Kernel\Contracts\ListenerSchemaResolverInterface;
use OCA\Decidiq\Service\CommitteeAgendaPlacement;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Places committee stages on the agenda and closes them from the body's decision.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/the-decision-as-a-walked-process/specs/decision-as-a-walked-process/spec.md (REQ-DWP-010)
 */
class CommitteeStepAgendaListener implements IEventListener {

	/**
	 * The schemas this listener reads.
	 *
	 * @var list<string>
	 */
	public const SCHEMAS = ['decision-stage', 'meeting', 'decision'];

	/**
	 * Constructor.
	 *
	 * @param CommitteeAgendaPlacement        $placement      Places and closes the committee stages.
	 * @param ListenerSchemaResolverInterface $schemaResolver Resolves the entity's schema slug.
	 * @param LoggerInterface                 $logger         Logger.
	 */
	public function __construct(
		private readonly CommitteeAgendaPlacement $placement,
		private readonly ListenerSchemaResolverInterface $schemaResolver,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Route the saved object to the placement it concerns; never break the save.
	 *
	 * @param Event $event The event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/the-decision-as-a-walked-process/specs/decision-as-a-walked-process/spec.md (REQ-DWP-010)
	 */
	public function handle(Event $event): void {
		if (($event instanceof ObjectCreatedEvent) === false && ($event instanceof ObjectUpdatedEvent) === false) {
			return;
		}

		$entity = $event->getObject();
		if ($entity === null) {
			return;
		}

		$row = (new EntityPayloadReader())->payloadOf(entity: $entity);
		if (isset($row['id']) === false && method_exists($entity, 'getUuid') === true) {
			$row['id'] = (string)$entity->getUuid();
		}

		$schema = $this->schemaResolver->schemaSlug(entity: $entity, row: $row);
		try {
			$this->apply(schema: $schema, row: $row, created: ($event instanceof ObjectCreatedEvent));
		} catch (Throwable $e) {
			$this->logger->warning(
				'Decidiq: a committee step was not placed on or closed from the agenda',
				['schema' => $schema, 'id' => ($row['id'] ?? null), 'exception' => $e->getMessage()]
			);
		}
	}//end handle()

	/**
	 * Apply the save to the committee stages it concerns.
	 *
	 * @param string               $schema  The saved object's schema slug.
	 * @param array<string, mixed> $row     The saved object.
	 * @param bool                 $created Whether the object was just created.
	 *
	 * @return void
	 */
	private function apply(string $schema, array $row, bool $created): void {
		if ($schema === 'decision-stage') {
			$this->placement->placeStage(stage: $row);
			return;
		}

		if ($schema === 'meeting' && $created === true) {
			$this->placement->placePending(meeting: $row);
			return;
		}

		if ($schema === 'decision') {
			$this->placement->closeFromDecision(decision: $row);
		}
	}//end apply()
}//end class
