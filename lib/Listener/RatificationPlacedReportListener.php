<?php

/**
 * Ratification Placed Report Listener
 *
 * When an agenda item that ratifies a decision is saved on a meeting, the
 * urgent decision counts as reported to the ratifying body: its report date
 * is set, unless an earlier step already set it. Covers every way the item
 * gets there (the urgency flow placing it, the secretariat moving it, a
 * later placement sweep) because it listens to the item, not to the caller.
 * The write is an invariant of the procedure, not the saver's right, so it
 * runs without RBAC, under the urgency write grant.
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
 * @spec openspec/changes/urgent-decisions-register/specs/urgent-decision-procedure/spec.md#requirement-req-021-the-report-to-the-ratifying-body-is-recorded-once
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Listener;

use OCA\Decidiq\Kernel\Contracts\ListenerSchemaResolverInterface;
use OCA\Decidiq\Service\RatifyingBodyReport;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindTarget;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Sets an urgent decision's report date when its ratification item is on a meeting.
 *
 * @spec openspec/changes/urgent-decisions-register/specs/urgent-decision-procedure/spec.md#requirement-req-021-the-report-to-the-ratifying-body-is-recorded-once
 *
 * @template-implements IEventListener<Event>
 */
class RatificationPlacedReportListener implements IEventListener {

	/**
	 * The schema whose saves this listener reads.
	 *
	 * @var string
	 */
	public const SCHEMA_AGENDA_ITEM = 'agenda-item';

	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface          $objectService  OpenRegister's object service.
	 * @param ListenerSchemaResolverInterface $schemaResolver Tells an agenda item from other objects.
	 * @param RatifyingBodyReport             $report         Sets the report date once.
	 * @param LoggerInterface                 $logger         Logs a failed write.
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		private readonly ListenerSchemaResolverInterface $schemaResolver,
		private readonly RatifyingBodyReport $report,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Handle an agenda item's after-save event.
	 *
	 * @param Event $event The event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/urgent-decisions-register/specs/urgent-decision-procedure/spec.md#requirement-req-021-the-report-to-the-ratifying-body-is-recorded-once
	 */
	public function handle(Event $event): void {
		if ($event instanceof ObjectCreatedEvent === false && $event instanceof ObjectUpdatedEvent === false) {
			return;
		}

		$entity = $event->getObject();
		if ($entity === null) {
			return;
		}

		$item = (new EntityPayloadReader())->payloadOf(entity: $entity);
		if ($this->schemaResolver->matchesSchema(entity: $entity, expectedSlug: self::SCHEMA_AGENDA_ITEM, row: $item) === false) {
			return;
		}

		$decisionId = self::referenceId(value: ($item['ratifiesDecision'] ?? null));
		if ($decisionId === null || self::referenceId(value: ($item['meeting'] ?? null)) === null) {
			return;
		}

		try {
			$this->stamp(decisionId: $decisionId);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Decidiq: the report date of an urgent decision was not set when its ratification was placed',
				['decision' => $decisionId, 'exception' => $e->getMessage()]
			);
		}
	}//end handle()

	/**
	 * Set the decision's report date when it is urgent and not yet reported.
	 *
	 * @param string $decisionId The decision the item ratifies.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/urgent-decisions-register/specs/urgent-decision-procedure/spec.md#requirement-req-021-the-report-to-the-ratifying-body-is-recorded-once
	 */
	private function stamp(string $decisionId): void {
		$entity = $this->objectService->find(
			id: $decisionId,
			options: new ObjectFindOptions(register: 'decidiq', schema: 'decision'),
			_rbac: false,
			_multitenancy: false
		);
		if ($entity === null) {
			return;
		}

		$decision = (new EntityPayloadReader())->payloadOf(entity: $entity);
		if ($this->report->isDue(decision: $decision) === false) {
			return;
		}

		$stamped = $this->report->stamped(decision: $decision);
		$this->report->granted(
			decisionId: $decisionId,
			save: fn (): mixed => $this->objectService->saveObject(
				object: $stamped,
				options: new ObjectSaveOptions(target: new ObjectFindTarget(register: 'decidiq', schema: 'decision'), uuid: $decisionId),
				_rbac: false,
				_multitenancy: false
			)
		);
	}//end stamp()

	/**
	 * A relation value (a uuid or `{id}`) as a uuid, or null.
	 *
	 * @param mixed $value The relation value.
	 *
	 * @return string|null
	 */
	private static function referenceId(mixed $value): ?string {
		if (is_array($value) === true) {
			$value = ($value['id'] ?? ($value['uuid'] ?? null));
		}

		if (is_string($value) === false || $value === '') {
			return null;
		}

		return $value;
	}//end referenceId()
}//end class
