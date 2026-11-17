<?php

/**
 * Refuses an approval route that cannot run as an OpenRegister flow.
 *
 * Every route sent after approval-routes-on-flow-user-tasks runs as a flow of
 * user tasks. A route that uses a feature the installed OpenRegister does not
 * offer would fail on the first send, or worse, run without it; so the save
 * itself is refused, naming the feature and the step. decidiq never runs such
 * a step on its own engine instead.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Listener
 * @package  OCA\Decidiq\Listener
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/approval-routes-on-flow-user-tasks/specs/approval-routes/spec.md#requirement-req-arf-009-a-route-that-needs-a-missing-openregister-feature-is-refused-when-saved
 */

declare(strict_types=1);

namespace OCA\Decidiq\Listener;

use OCA\Decidiq\Service\ApprovalRouteFlowCompiler;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;

/**
 * Save guard on the approval-route schema.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/approval-routes-on-flow-user-tasks/specs/approval-routes/spec.md#requirement-req-arf-009-a-route-that-needs-a-missing-openregister-feature-is-refused-when-saved
 */
class ApprovalRouteSavedListener implements IEventListener {
	/**
	 * The schema this guard watches.
	 */
	public const SCHEMA = 'approval-route';

	/**
	 * Constructor.
	 *
	 * @param ApprovalRouteFlowCompiler $compiler Says why a route cannot compile.
	 */
	public function __construct(
		private readonly ApprovalRouteFlowCompiler $compiler,
	) {
	}//end __construct()

	/**
	 * Refuse the save of a route that cannot compile.
	 *
	 * @param Event $event The creating or updating event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/approval-routes-on-flow-user-tasks/specs/approval-routes/spec.md#requirement-req-arf-009-a-route-that-needs-a-missing-openregister-feature-is-refused-when-saved
	 */
	public function handle(Event $event): void {
		$entity = SaveGuardEvent::entityOf(event: $event);
		if ($entity === null || method_exists($entity, 'getObject') === false) {
			return;
		}

		$row = (array)$entity->getObject();
		if ($this->isRoute(row: $row) === false) {
			return;
		}

		$message = $this->refusal(row: $row);
		if ($message !== null) {
			SaveGuardEvent::refuse(event: $event, message: $message);
		}
	}//end handle()

	/**
	 * Why a route is refused, or null when it saves.
	 *
	 * An inactive route is a draft: it is checked when it is switched on, so a
	 * secretary can keep editing one that still needs a missing feature.
	 *
	 * @param array<string, mixed> $row The route.
	 *
	 * @return string|null The refusal, every problem in one message.
	 *
	 * @spec openspec/changes/approval-routes-on-flow-user-tasks/specs/approval-routes/spec.md#requirement-req-arf-009-a-route-that-needs-a-missing-openregister-feature-is-refused-when-saved
	 */
	public function refusal(array $row): ?string {
		if (($row['active'] ?? true) === false) {
			return null;
		}

		$refusals = $this->compiler->refusals(route: $row);
		if ($refusals === []) {
			return null;
		}

		return 'The approval route was not saved. ' . implode(' ', $refusals);
	}//end refusal()

	/**
	 * Whether the saved row is an approval route.
	 *
	 * @param array<string, mixed> $row The row.
	 *
	 * @return bool True for a route.
	 */
	private function isRoute(array $row): bool {
		return SaveGuardEvent::namesSchema(row: $row, schema: self::SCHEMA)
			?? (array_key_exists('steps', $row) === true && array_key_exists('subjectType', $row) === true);
	}//end isRoute()
}//end class
