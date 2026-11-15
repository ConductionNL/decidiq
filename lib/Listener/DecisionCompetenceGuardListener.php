<?php

/**
 * Refuses a decision whose body is not competent to take it, before it is saved.
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
 * @spec openspec/changes/configurable-types-domain-model/specs/configurable-types-domain-model/spec.md#requirement-req-ctm-004-competence-is-enforced-at-the-write-path-not-merely-declared
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Decidiq\Listener;

use OCA\Decidiq\Exception\DecisionCompetenceRefusedException;
use OCA\Decidiq\Service\DecisionCompetenceGuard;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The decision write path's call into DecisionCompetenceGuard.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/configurable-types-domain-model/specs/configurable-types-domain-model/spec.md#requirement-req-ctm-004-competence-is-enforced-at-the-write-path-not-merely-declared
 */
class DecisionCompetenceGuardListener implements IEventListener {

	/**
	 * The schema this guard fronts.
	 *
	 * @var string
	 */
	public const SCHEMA = 'decision';

	/**
	 * Constructor.
	 *
	 * @param DecisionCompetenceGuard $guard  The competence check.
	 * @param LoggerInterface         $logger The log.
	 */
	public function __construct(
		private readonly DecisionCompetenceGuard $guard,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Stop the save of a decision the guard refuses.
	 *
	 * Any failure refuses: an exception from the guard is never swallowed into
	 * a save that goes ahead.
	 *
	 * @param Event $event The before-save event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/configurable-types-domain-model/specs/configurable-types-domain-model/spec.md#requirement-req-ctm-004-competence-is-enforced-at-the-write-path-not-merely-declared
	 */
	public function handle(Event $event): void {
		if ($event instanceof ObjectCreatingEvent === false && $event instanceof ObjectUpdatingEvent === false) {
			return;
		}

		$entity = null;
		if ($event instanceof ObjectUpdatingEvent) {
			$entity = $event->getNewObject();
		} else {
			$entity = $event->getObject();
		}

		if (is_object($entity) === false || $this->isAnotherSchema(entity: $entity) === true) {
			return;
		}

		try {
			$this->guard->assertCompetent(decision: (new EntityPayloadReader())->payloadOf(entity: $entity));
		} catch (DecisionCompetenceRefusedException $e) {
			$this->refuse(event: $event, message: $e->getMessage());
		} catch (Throwable $e) {
			$this->logger->warning(message: 'Decidiq: decision competence could not be checked', context: ['error' => $e->getMessage()]);
			$this->refuse(event: $event, message: 'The decision was not saved: who may take it could not be checked. Try again in a moment.');
		}
	}//end handle()

	/**
	 * Stop a save, telling the person why.
	 *
	 * @param ObjectCreatingEvent|ObjectUpdatingEvent $event   The before-save event.
	 * @param string                                  $message The refusal.
	 *
	 * @return void
	 */
	private function refuse(ObjectCreatingEvent|ObjectUpdatingEvent $event, string $message): void {
		$event->setErrors(['message' => $message]);
		$event->stopPropagation();
	}//end refuse()

	/**
	 * Whether the entity names a schema other than decision.
	 *
	 * The subscription routes only decision saves here; an entity whose
	 * schema cannot be read is therefore treated as the decision it was routed
	 * as, so an unreadable slug never lets a decision past the guard.
	 *
	 * @param object $entity The entity being saved.
	 *
	 * @return bool True when it is clearly not a decision.
	 */
	private function isAnotherSchema(object $entity): bool {
		$row = (new EntityPayloadReader())->payloadOf(entity: $entity);
		foreach (['_schemaSlug', '_schema', 'schema'] as $key) {
			$candidate = ($row[$key] ?? null);
			if (is_string($candidate) === true && $candidate !== '' && ctype_digit($candidate) === false) {
				return strtolower($candidate) !== self::SCHEMA;
			}
		}

		return false;
	}//end isAnotherSchema()
}//end class
