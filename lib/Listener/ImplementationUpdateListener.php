<?php

/**
 * Decidiq ImplementationUpdateListener
 *
 * Progress updates on an adopted decision (followup-implementation-progress).
 * Before an update is saved it is refused unless its decision was adopted,
 * and it records who reported it. Once saved, the newest update by
 * `reportedOn` sets the decision's `implementationStatus` and
 * `implementationStatusDate`; a back-dated update leaves them as they are.
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
 * @spec openspec/changes/followup-implementation-progress/specs/decision-management/spec.md#requirement-req-fup-002-the-decision-shows-its-current-implementation-status
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Listener;

use OCA\Decidiq\Kernel\Contracts\ListenerSchemaResolverInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Guards and projects implementation updates.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/followup-implementation-progress/specs/decision-management/spec.md#requirement-req-fup-002-the-decision-shows-its-current-implementation-status
 */
class ImplementationUpdateListener implements IEventListener {

	/**
	 * Schema slug of an implementation update.
	 */
	public const SCHEMA_UPDATE = 'implementation-update';

	/**
	 * Lifecycle states in which a decision has been taken.
	 */
	private const DECIDED_STATES = ['decided', 'enacted'];

	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface          $objectService  Reads and writes the decision
	 * @param ListenerSchemaResolverInterface $schemaResolver Resolves the entity's schema slug
	 * @param IUserSession                    $userSession    The caller who reports
	 * @param LoggerInterface                 $logger         Logger
	 *
	 * @spec openspec/changes/followup-implementation-progress/specs/decision-management/spec.md#requirement-req-fup-002-the-decision-shows-its-current-implementation-status
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		private readonly ListenerSchemaResolverInterface $schemaResolver,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Handle an implementation update being created or just created.
	 *
	 * @param Event $event The event
	 *
	 * @return void
	 *
	 * @listener-placement inline cheap-bounded — one find of the decision per
	 *     update, plus one save when the update is the newest. Deferred, the
	 *     secretariat would see the decision's old status right after reporting.
	 *
	 * @spec openspec/changes/followup-implementation-progress/specs/decision-management/spec.md#requirement-req-fup-002-the-decision-shows-its-current-implementation-status
	 */
	public function handle(Event $event): void {
		if ($event instanceof ObjectCreatingEvent) {
			$this->guard(event: $event);
			return;
		}

		if ($event instanceof ObjectCreatedEvent) {
			$this->project(event: $event);
		}
	}//end handle()

	/**
	 * Refuse an update on a decision that was not adopted; record the reporter.
	 *
	 * @param ObjectCreatingEvent $event The event
	 *
	 * @return void
	 *
	 * @spec openspec/changes/followup-implementation-progress/specs/decision-management/spec.md#requirement-req-fup-001-an-adopted-decision-takes-dated-progress-updates
	 */
	private function guard(ObjectCreatingEvent $event): void {
		$entity = $event->getObject();
		$row    = $this->row(entity: $entity);
		if ($this->schemaResolver->matchesSchema(entity: $entity, expectedSlug: self::SCHEMA_UPDATE, row: $row) === false) {
			return;
		}

		try {
			$decision = $this->decision(decisionId: trim((string)($row['decision'] ?? '')));
		} catch (\Throwable $e) {
			$this->logger->warning('Decidiq: the decision of an implementation update could not be read', ['exception' => $e->getMessage()]);
			$event->setErrors(['message' => 'The update was not saved: its decision could not be read. Try again in a moment.']);
			$event->stopPropagation();
			return;
		}

		if ($decision === null || $this->isAdopted(decision: $decision) === false) {
			$event->setErrors(['message' => 'Progress can only be reported on an adopted decision.']);
			$event->stopPropagation();
			return;
		}

		$user = $this->userSession->getUser();
		if ($user !== null) {
			$event->setModifiedData(array_merge($event->getModifiedData(), ['reportedBy' => $user->getUID()]));
		}
	}//end guard()

	/**
	 * Write the newest update's status onto its decision.
	 *
	 * @param ObjectCreatedEvent $event The event
	 *
	 * @return void
	 *
	 * @spec openspec/changes/followup-implementation-progress/specs/decision-management/spec.md#requirement-req-fup-002-the-decision-shows-its-current-implementation-status
	 */
	private function project(ObjectCreatedEvent $event): void {
		try {
			$entity = $event->getObject();
			$row    = $this->row(entity: $entity);
			if ($this->schemaResolver->matchesSchema(entity: $entity, expectedSlug: self::SCHEMA_UPDATE, row: $row) === false) {
				return;
			}

			$decisionId = trim((string)($row['decision'] ?? ''));
			$reportedOn = substr(trim((string)($row['reportedOn'] ?? '')), 0, 10);
			$status     = trim((string)($row['status'] ?? ''));
			$decision   = $this->decision(decisionId: $decisionId);
			if ($decision === null || $reportedOn === '' || $status === '') {
				return;
			}

			$current = substr(trim((string)($decision['implementationStatusDate'] ?? '')), 0, 10);
			if ($current !== '' && strcmp($reportedOn, $current) < 0) {
				return;
			}

			$decision['implementationStatus']     = $status;
			$decision['implementationStatusDate'] = $reportedOn;
			$this->objectService->saveObject(
				object: $decision,
				register: 'decidiq',
				schema: 'decision',
				uuid: $decisionId,
				_rbac: false,
				_multitenancy: false
			);
		} catch (\Throwable $e) {
			$this->logger->warning('Decidiq: the implementation status of a decision could not be updated', ['exception' => $e->getMessage()]);
		}//end try
	}//end project()

	/**
	 * Whether a decision has been taken and adopted.
	 *
	 * @param array<string, mixed> $decision The decision
	 *
	 * @return bool
	 */
	private function isAdopted(array $decision): bool {
		return in_array(($decision['lifecycle'] ?? null), self::DECIDED_STATES, true) === true
			&& ($decision['outcome'] ?? null) === 'adopted';
	}//end isAdopted()

	/**
	 * The decision's data, or null when there is none.
	 *
	 * @param string $decisionId The decision
	 *
	 * @return array<string, mixed>|null
	 */
	private function decision(string $decisionId): ?array {
		if ($decisionId === '') {
			return null;
		}

		$found = $this->objectService->find(id: $decisionId, register: 'decidiq', schema: 'decision', _rbac: false, _multitenancy: false);
		if ($found === null) {
			return null;
		}

		return $this->row(entity: $found);
	}//end decision()

	/**
	 * The object data of an entity.
	 *
	 * @param object|null $entity The entity
	 *
	 * @return array<string, mixed>
	 */
	private function row(?object $entity): array {
		if ($entity === null) {
			return [];
		}

		return (new EntityPayloadReader())->payloadOf(entity: $entity);
	}//end row()
}//end class
