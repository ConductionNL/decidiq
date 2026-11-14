<?php

/**
 * Decidiq Decision Remedy Stamp Listener
 *
 * Stamps a decision's legal remedy clause onto it at the moment it is taken
 * (the-decision-as-a-walked-process, REQ-DWP-007, task 7.2).
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
 * @spec openspec/changes/the-decision-as-a-walked-process/tasks.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Listener;

use OCA\Decidiq\Service\LegalRemedyResolver;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Resolve the type's remedy clause into a decision's save once it is taken.
 *
 * A decision counts as taken once it carries an outcome or its lifecycle has
 * reached `decided` or later. The clause is resolved then, not when the
 * decision is published, so what the decision says about contesting it is
 * what held on the day it was taken. A decision already carrying a clause is
 * left alone (LegalRemedyResolver::stamp()), and so is one without a type or
 * whose type declares no remedy: publication refuses that one (task 7.3).
 * This listener never refuses a save; a type it cannot read is logged.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/the-decision-as-a-walked-process/tasks.md
 */
class DecisionRemedyStampListener implements IEventListener {

	/**
	 * The lifecycle states of a decision that has been taken.
	 */
	public const TAKEN_STATES = ['decided', 'enacted', 'archived'];

	/**
	 * Constructor.
	 *
	 * @param LegalRemedyResolver    $remedies      Resolves and stamps the clause.
	 * @param ObjectServiceInterface $objectService Reads the decision type.
	 * @param LoggerInterface        $logger        Logs a type that could not be read.
	 *
	 * @spec openspec/changes/the-decision-as-a-walked-process/tasks.md
	 */
	public function __construct(
		private readonly LegalRemedyResolver $remedies,
		private readonly ObjectServiceInterface $objectService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Stamp the clause into the save of a decision that is taken and carries none.
	 *
	 * @param Event $event The before-save event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/the-decision-as-a-walked-process/tasks.md
	 */
	public function handle(Event $event): void {
		if ($event instanceof ObjectUpdatingEvent) {
			$this->stampInto(event: $event, entity: $event->getNewObject());
			return;
		}

		if ($event instanceof ObjectCreatingEvent) {
			$this->stampInto(event: $event, entity: $event->getObject());
		}
	}//end handle()

	/**
	 * Merge the clause into the save when the decision is taken, typed and not yet stamped.
	 *
	 * @param ObjectCreatingEvent|ObjectUpdatingEvent $event  The before-save event.
	 * @param mixed                                   $entity The decision as it will be saved.
	 *
	 * @return void
	 */
	private function stampInto(ObjectCreatingEvent|ObjectUpdatingEvent $event, mixed $entity): void {
		if (is_object($entity) === false) {
			return;
		}

		$decision = array_merge((new EntityPayloadReader())->payloadOf(entity: $entity), $event->getModifiedData());
		if (self::isDecision(decision: $decision) === false || self::isTaken(decision: $decision) === false) {
			return;
		}

		$update = $this->clauseUpdate(decision: $decision);
		if ($update !== null) {
			$event->setModifiedData(array_merge($event->getModifiedData(), $update));
		}
	}//end stampInto()

	/**
	 * The `legalRemedyClause` change to write, or null when the stamp leaves
	 * the clause as it is or nothing is stamped.
	 *
	 * @param array<string, mixed> $decision The merged decision payload.
	 *
	 * @return array{legalRemedyClause: mixed}|null
	 */
	private function clauseUpdate(array $decision): ?array {
		$stamped = $this->stamped(decision: $decision);
		if ($stamped === null || ($stamped['legalRemedyClause'] ?? null) === ($decision['legalRemedyClause'] ?? null)) {
			return null;
		}

		return ['legalRemedyClause' => $stamped['legalRemedyClause']];
	}//end clauseUpdate()

	/**
	 * The decision as the resolver stamps it, or null when its type cannot be
	 * read or is not publishable, in which case nothing is stamped.
	 *
	 * @param array<string, mixed> $decision The merged decision payload.
	 *
	 * @return array<string, mixed>|null
	 */
	private function stamped(array $decision): ?array {
		$type = $this->typeOf(decision: $decision);
		if ($type === null) {
			return null;
		}

		try {
			$this->remedies->assertPublishable(type: $type);
		} catch (Throwable) {
			return null;
		}

		return $this->remedies->stamp(decision: $decision, type: $type);
	}//end stamped()

	/**
	 * Whether a decision as it will be saved has been taken.
	 *
	 * @param array<string, mixed> $decision The decision.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/the-decision-as-a-walked-process/tasks.md
	 */
	public static function isTaken(array $decision): bool {
		if (trim((string)($decision['outcome'] ?? '')) !== '') {
			return true;
		}

		return in_array((string)($decision['lifecycle'] ?? ''), self::TAKEN_STATES, true);
	}//end isTaken()

	/**
	 * Whether the payload names the decision schema, or names none.
	 *
	 * @param array<string, mixed> $decision The payload.
	 *
	 * @return bool
	 */
	private static function isDecision(array $decision): bool {
		foreach (['_schemaSlug', '_schema', 'schema'] as $key) {
			$candidate = ($decision[$key] ?? null);
			if (is_string($candidate) === true && $candidate !== '' && ctype_digit($candidate) === false) {
				return strtolower($candidate) === 'decision';
			}
		}

		return true;
	}//end isDecision()

	/**
	 * The decision type the decision names, or null when it names none or it cannot be read.
	 *
	 * @param array<string, mixed> $decision The decision.
	 *
	 * @return array<string, mixed>|null
	 */
	private function typeOf(array $decision): ?array {
		$typeId = self::typeIdOf(decision: $decision);
		if ($typeId === null) {
			return null;
		}

		try {
			$found = $this->objectService->find(id: $typeId, register: 'decidiq', schema: 'decision-template');
		} catch (Throwable $e) {
			$this->logger->warning(
				message: 'Decidiq: the decision type could not be read, so no remedy clause was stamped',
				context: ['type' => $typeId, 'reason' => $e->getMessage()]
			);
			return null;
		}

		if ($found === null) {
			return null;
		}

		return (array)$found->getObject();
	}//end typeOf()

	/**
	 * The decision's type id: the `type` string, or the `id`/`uuid` of a
	 * `type` object; null when it names none.
	 *
	 * @param array<string, mixed> $decision The merged decision payload.
	 *
	 * @return string|null The trimmed id.
	 */
	private static function typeIdOf(array $decision): ?string {
		$typeId = ($decision['type'] ?? null);
		if (is_array($typeId) === true) {
			$typeId = ($typeId['id'] ?? ($typeId['uuid'] ?? null));
		}

		if (is_string($typeId) === false || trim($typeId) === '') {
			return null;
		}

		return trim($typeId);
	}//end typeIdOf()
}//end class
