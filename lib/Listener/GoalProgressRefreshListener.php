<?php

/**
 * Decidiq GoalProgressRefreshListener
 *
 * A goal's progress rates are materialised calculations over the objects that
 * point at it, and OpenRegister materialises them only when the goal itself is
 * saved. When a commitment, action item or child goal that points at a goal is
 * created, changed or deleted, or moves to another goal, this listener re-saves
 * every goal it pointed at before and after, so the save path recomputes the
 * rates. A change that cannot move a rate (no counted field changed, or a
 * planned agenda item or decision, which no rate reads) saves nothing.
 *
 * @category Listener
 * @package  OCA\Decidiq\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/goals-linked-to-decisions/specs/organisation-goals/spec.md#requirement-req-013-progress-stays-current-when-a-contribution-changes
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Listener;

use OCA\Decidiq\Kernel\Contracts\ListenerSchemaResolverInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectDeletedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Re-saves the goals a changed contribution points at, so their rates are current.
 *
 * @implements IEventListener<Event>
 *
 * @spec openspec/changes/goals-linked-to-decisions/specs/organisation-goals/spec.md#requirement-req-013-progress-stays-current-when-a-contribution-changes
 */
class GoalProgressRefreshListener implements IEventListener {

	public const SCHEMA_GOAL = 'goal';

	/**
	 * Per contributing schema: the property naming the goal, and the field a rate counts on.
	 *
	 * Planned agenda items and decisions point at a goal too, but no
	 * materialised rate reads them, so their changes need no refresh.
	 */
	public const CONTRIBUTORS = [
		'governance-commitment' => ['link' => 'goal', 'counted' => 'lifecycle'],
		'action-item'           => ['link' => 'goal', 'counted' => 'taskStatus'],
		self::SCHEMA_GOAL       => ['link' => 'parentGoal', 'counted' => 'status'],
	];

	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface          $objectService  Reads and re-saves the goals.
	 * @param ListenerSchemaResolverInterface $schemaResolver Tells which schema an object belongs to.
	 * @param LoggerInterface                 $logger         Logs a refresh that fails.
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		private readonly ListenerSchemaResolverInterface $schemaResolver,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Refresh the goals the saved or deleted contribution points at, before and after.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/goals-linked-to-decisions/specs/organisation-goals/spec.md#requirement-req-013-progress-stays-current-when-a-contribution-changes
	 */
	public function handle(Event $event): void {
		[$new, $old] = $this->rowsOf(event: $event);
		$entity      = $this->entityOf(event: $event);
		if ($entity === null) {
			return;
		}

		foreach (self::CONTRIBUTORS as $slug => $fields) {
			if ($this->schemaResolver->matchesSchema(entity: $entity, expectedSlug: $slug, row: ($new ?? $old ?? [])) === true) {
				foreach ($this->affectedGoals(new: $new, old: $old, link: $fields['link'], counted: $fields['counted']) as $goalId) {
					$this->refresh(goalId: $goalId);
				}

				return;
			}
		}
	}//end handle()

	/**
	 * The goals whose rates a change can move: none when neither the link nor the counted field changed.
	 *
	 * @param array<string, mixed>|null $new     The object after the change; null when deleted.
	 * @param array<string, mixed>|null $old     The object before the change; null when created.
	 * @param string                    $link    The property naming the goal.
	 * @param string                    $counted The field a rate counts on.
	 *
	 * @return array<int, string> The goal ids, without duplicates.
	 *
	 * @spec openspec/changes/goals-linked-to-decisions/specs/organisation-goals/spec.md#requirement-req-013-progress-stays-current-when-a-contribution-changes
	 */
	public function affectedGoals(?array $new, ?array $old, string $link, string $counted): array {
		$after  = $this->goalIdOf(row: $new, link: $link);
		$before = $this->goalIdOf(row: $old, link: $link);
		if ($new !== null && $old !== null && $after === $before && ($new[$counted] ?? null) === ($old[$counted] ?? null)) {
			return [];
		}

		return array_values(array_unique(array_filter([$before, $after], static fn (string $id): bool => $id !== '')));
	}//end affectedGoals()

	/**
	 * Re-save one goal as stored, so OpenRegister materialises its rates again.
	 *
	 * @param string $goalId The goal UUID.
	 *
	 * @return void
	 */
	private function refresh(string $goalId): void {
		try {
			$found = $this->objectService->find(id: $goalId, register: 'decidiq', schema: self::SCHEMA_GOAL, _rbac: false, _multitenancy: false);
			if ($found === null) {
				return;
			}

			// The row as read carries OpenRegister's `@self` block; saving it
			// back would make OpenRegister check the acting user's folder rights.
			$goal = (new EntityPayloadReader())->payloadOf(entity: $found);
			unset($goal['@self']);
			$this->objectService->saveObject(
				object: $goal,
				register: 'decidiq',
				schema: self::SCHEMA_GOAL,
				uuid: $goalId,
				_rbac: false,
				_multitenancy: false
			);
		} catch (Throwable $exception) {
			$this->logger->warning(
				'Decidiq: the progress of goal {goal} could not be refreshed: {msg}',
				['goal' => $goalId, 'msg' => $exception->getMessage()]
			);
		}
	}//end refresh()

	/**
	 * The goal id a row points at, or ''.
	 *
	 * @param array<string, mixed>|null $row  The row.
	 * @param string                    $link The property naming the goal.
	 *
	 * @return string The goal UUID.
	 */
	private function goalIdOf(?array $row, string $link): string {
		$goalId = ($row[$link] ?? null);
		if (is_string($goalId) === false) {
			return '';
		}

		return trim($goalId);
	}//end goalIdOf()

	/**
	 * The new and old payloads of an event; null for the side that does not exist.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return array{0: array<string, mixed>|null, 1: array<string, mixed>|null}
	 */
	private function rowsOf(Event $event): array {
		$reader = new EntityPayloadReader();
		if ($event instanceof ObjectUpdatedEvent === true) {
			$old = $event->getOldObject();
			if ($old === null) {
				return [$reader->payloadOf(entity: $event->getNewObject()), null];
			}

			return [$reader->payloadOf(entity: $event->getNewObject()), $reader->payloadOf(entity: $old)];
		}

		if ($event instanceof ObjectCreatedEvent === true && $event->getObject() !== null) {
			return [$reader->payloadOf(entity: $event->getObject()), null];
		}

		if ($event instanceof ObjectDeletedEvent === true && $event->getObject() !== null) {
			return [null, $reader->payloadOf(entity: $event->getObject())];
		}

		return [null, null];
	}//end rowsOf()

	/**
	 * The entity an event is about, for the schema check.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return object|null The entity, or null for another event.
	 */
	private function entityOf(Event $event): ?object {
		if ($event instanceof ObjectUpdatedEvent === true) {
			return $event->getNewObject();
		}

		if ($event instanceof ObjectCreatedEvent === true || $event instanceof ObjectDeletedEvent === true) {
			return $event->getObject();
		}

		return null;
	}//end entityOf()
}//end class
