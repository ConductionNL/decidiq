<?php

/**
 * Decidiq Decision Agenda Clearance Guard
 *
 * A decision is placed on a meeting agenda by naming an agenda item. While a
 * required approval route on it still has an open step, that placement is
 * refused, with a message naming the step (change
 * routes-send-proposal-along-route, REQ-RSPR-001). Held at the save, so the
 * agenda page, the decision page and the API are refused alike.
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
 * @spec openspec/changes/routes-send-proposal-along-route/specs/approval-routes/spec.md#requirement-req-rspr-001-a-proposal-is-sent-along-a-route-from-its-page
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Listener;

use OCA\Decidiq\Kernel\Contracts\ApprovalRouteServiceInterface;
use OCA\Decidiq\Kernel\Contracts\SubjectClearanceServiceInterface;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Refuses placing a decision on an agenda item while its approval route has an open step.
 *
 * Only a change of `agendaItem` to a new item is checked: a new decision has
 * no route yet, and taking a decision off the agenda is never refused.
 *
 * @implements IEventListener<Event>
 */
class DecisionAgendaClearanceGuard implements IEventListener {

	public const SCHEMA_DECISION = 'decision';

	public const UNCHECKED_MESSAGE = 'This proposal cannot go on the agenda now: its approval could not be checked. Try again.';

	/**
	 * Constructor.
	 *
	 * @param ApprovalRouteServiceInterface    $routes    The routes started on a subject, with their stages.
	 * @param SubjectClearanceServiceInterface $clearance Whether every required route is through.
	 * @param LoggerInterface                  $logger    Logs a check that failed.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ApprovalRouteServiceInterface $routes,
		private readonly SubjectClearanceServiceInterface $clearance,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Refuse the save when it places a decision with an open approval step on an agenda item.
	 *
	 * @param Event $event The before-update event of a decision.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/routes-send-proposal-along-route/specs/approval-routes/spec.md#scenario-not-on-the-agenda-before-approval
	 */
	public function handle(Event $event): void {
		if ($event instanceof ObjectUpdatingEvent === false) {
			return;
		}

		$entity = $event->getNewObject();
		$decision = array_merge((array)$entity->getObject(), $event->getModifiedData());
		if (strtolower((string)($decision['_schemaSlug'] ?? self::SCHEMA_DECISION)) !== self::SCHEMA_DECISION) {
			return;
		}

		$item = self::referenceId(value: ($decision['agendaItem'] ?? null));
		$old = $event->getOldObject();
		$before = null;
		if ($old !== null) {
			$before = self::referenceId(value: (((array)$old->getObject())['agendaItem'] ?? null));
		}

		if ($item === null || $item === $before) {
			return;
		}

		$refusal = $this->refusal(subject: (string)($entity->getUuid() ?? ($decision['id'] ?? '')));
		if ($refusal !== null) {
			$event->setErrors(['message' => $refusal]);
			$event->stopPropagation();
		}
	}//end handle()

	/**
	 * Why the decision may not go on the agenda yet, or null when it may.
	 *
	 * A failed check refuses: a clearance question that cannot be answered must
	 * not let a proposal past its sign-off.
	 *
	 * @param string $subject The decision's uuid.
	 *
	 * @return string|null
	 *
	 * @spec openspec/changes/routes-send-proposal-along-route/specs/approval-routes/spec.md#scenario-not-on-the-agenda-before-approval
	 */
	private function refusal(string $subject): ?string {
		if ($subject === '') {
			return null;
		}

		try {
			$answer = $this->clearance->clearanceFor(routes: $this->routes->routesWithStagesFor(subject: $subject));
		} catch (Throwable $e) {
			$this->logger->warning('Decidiq: agenda placement clearance could not be checked', ['exception' => $e->getMessage()]);
			return self::UNCHECKED_MESSAGE;
		}

		if (($answer['cleared'] ?? false) === true) {
			return null;
		}

		return self::message(waitingOn: ($answer['waitingOn'] ?? []));
	}//end refusal()

	/**
	 * The refusal naming the open step of each route still waiting.
	 *
	 * @param array<int,array<string,mixed>> $waitingOn The open steps.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/routes-send-proposal-along-route/specs/approval-routes/spec.md#scenario-not-on-the-agenda-before-approval
	 */
	public static function message(array $waitingOn): string {
		$steps = [];
		foreach ($waitingOn as $open) {
			$step = (string)($open['stageName'] ?? '');
			if ($step === '') {
				$step = 'step ' . (int)($open['stage'] ?? 0);
			}

			$route = (string)($open['routeName'] ?? '');
			if ($route !== '') {
				$step .= ' of ' . $route;
			}

			$steps[] = $step;
		}

		if ($steps === []) {
			return 'This proposal cannot go on the agenda before it is approved.';
		}

		return 'This proposal cannot go on the agenda before it is approved. Still open: ' . implode('; ', $steps) . '.';
	}//end message()

	/**
	 * The id of a reference, which is a string or an object with id or uuid.
	 *
	 * @param mixed $value The reference.
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
