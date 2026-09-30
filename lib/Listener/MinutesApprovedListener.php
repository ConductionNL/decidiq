<?php

/**
 * Decidiq MinutesApprovedListener
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
 * @spec openspec/specs/case-system-exchange/spec.md#requirement-req-csdx-004-approving-the-minutes-produces-a-decision-list-document
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Decidiq\Listener;

use OCA\Decidiq\AppInfo\Application;
use OCA\Decidiq\Service\CaseSystemExchangeService;
use OCA\Decidiq\Service\DecisionListService;
use OCA\Decidiq\Service\ListenerSchemaResolver;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * When minutes reach approved, and again at signed, renders the decision
 * list into the meeting folder. At approved, when an administrator turned on
 * case_system_send_on_approval, also queues the meeting file for the case
 * system. Listening on the object event catches every path a lifecycle
 * change takes, the minutes controller and a direct object save alike.
 *
 * @spec openspec/specs/case-system-exchange/spec.md#requirement-req-csdx-004-approving-the-minutes-produces-a-decision-list-document
 *
 * @template-implements IEventListener<Event>
 */
class MinutesApprovedListener implements IEventListener {
	/**
	 * The schema this listener handles.
	 */
	public const SCHEMA_MINUTES = 'minutes';

	/**
	 * The app-config switch for sending on approval.
	 */
	public const SEND_ON_APPROVAL = 'case_system_send_on_approval';

	/**
	 * Constructor.
	 *
	 * @param DecisionListService       $decisionList   The decision list.
	 * @param CaseSystemExchangeService $exchange       The meeting file send.
	 * @param ListenerSchemaResolver    $schemaResolver Schema matching.
	 * @param IAppConfig                $appConfig      App config.
	 * @param LoggerInterface           $logger         Logger.
	 *
	 * @spec openspec/specs/case-system-exchange/spec.md#requirement-req-csdx-004-approving-the-minutes-produces-a-decision-list-document
	 */
	public function __construct(
		private readonly DecisionListService $decisionList,
		private readonly CaseSystemExchangeService $exchange,
		private readonly ListenerSchemaResolver $schemaResolver,
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Handle a minutes update.
	 *
	 * @param Event $event The event.
	 *
	 * @spec openspec/specs/case-system-exchange/spec.md#requirement-req-csdx-004-approving-the-minutes-produces-a-decision-list-document
	 *
	 * @return void
	 */
	public function handle(Event $event): void {
		$minutes = $this->approvedOrSigned(event: $event);
		if ($minutes === null) {
			return;
		}

		$meetingId = (string)$minutes['meeting'];
		try {
			$this->decisionList->render(meetingId: $meetingId, minutes: $minutes);
		} catch (Throwable $e) {
			$this->logger->warning('Decidiq: the decision list could not be rendered', ['meeting' => $meetingId, 'exception' => $e->getMessage()]);
		}

		if ($minutes['lifecycle'] === 'approved' && $this->appConfig->getValueString(Application::APP_ID, self::SEND_ON_APPROVAL, '') === 'true') {
			$this->sendOnApproval(meetingId: $meetingId);
		}
	}//end handle()

	/**
	 * The minutes of this event when they just reached approved or signed, with a meeting.
	 *
	 * @param Event $event The event.
	 *
	 * @spec openspec/specs/case-system-exchange/spec.md#requirement-req-csdx-004-approving-the-minutes-produces-a-decision-list-document
	 *
	 * @return array<string,mixed>|null
	 */
	private function approvedOrSigned(Event $event): ?array {
		if (($event instanceof ObjectUpdatedEvent) === false || $event->getNewObject() === null) {
			return null;
		}

		$entity  = $event->getNewObject();
		$minutes = $entity->getObject();
		if ($this->schemaResolver->matchesSchema(entity: $entity, expectedSlug: self::SCHEMA_MINUTES, row: $minutes) === false) {
			return null;
		}

		$lifecycle = (string)($minutes['lifecycle'] ?? '');
		$before    = (string)(($event->getOldObject()?->getObject() ?? [])['lifecycle'] ?? '');
		if ($lifecycle === $before || in_array($lifecycle, ['approved', 'signed'], true) === false || (string)($minutes['meeting'] ?? '') === '') {
			return null;
		}

		return $minutes;
	}//end approvedOrSigned()

	/**
	 * Queue the meeting file for the case system, logging a refusal.
	 *
	 * @param string $meetingId The meeting.
	 *
	 * @spec openspec/specs/case-system-exchange/spec.md#requirement-req-csdx-005-the-meeting-file-goes-back-to-the-case-system-after-approval
	 *
	 * @return void
	 */
	private function sendOnApproval(string $meetingId): void {
		try {
			$this->exchange->requestSend(meetingId: $meetingId, userId: 'system');
		} catch (Throwable $e) {
			$this->logger->warning('Decidiq: the meeting file was not sent on approval', ['meeting' => $meetingId, 'exception' => $e->getMessage()]);
		}
	}//end sendOnApproval()
}//end class
