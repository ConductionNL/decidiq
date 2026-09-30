<?php

/**
 * Decidiq SendMeetingFileJob
 *
 * @category BackgroundJob
 * @package  OCA\Decidiq\BackgroundJob
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/case-system-exchange/spec.md#requirement-req-csdx-005-the-meeting-file-goes-back-to-the-case-system-after-approval
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Decidiq\BackgroundJob;

use OCA\Decidiq\Service\CaseSystemExchangeService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\QueuedJob;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Sends a meeting file to the case system, or sends one record's failed
 * lines again. Queued once per request (ADR-069): a failed line is never
 * retried on its own, so a broken mapping does not hammer the case system.
 *
 * @spec openspec/specs/case-system-exchange/spec.md#requirement-req-csdx-005-the-meeting-file-goes-back-to-the-case-system-after-approval
 */
class SendMeetingFileJob extends QueuedJob {
	/**
	 * Constructor.
	 *
	 * @param ITimeFactory              $time     Clock.
	 * @param CaseSystemExchangeService $exchange The exchange.
	 * @param LoggerInterface           $logger   Logger.
	 *
	 * @spec openspec/specs/case-system-exchange/spec.md#requirement-req-csdx-005-the-meeting-file-goes-back-to-the-case-system-after-approval
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly CaseSystemExchangeService $exchange,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(time: $time);
	}//end __construct()

	/**
	 * Send the meeting file, or one record again.
	 *
	 * @param mixed $argument {meeting, uid} or {record, uid}.
	 *
	 * @spec openspec/specs/case-system-exchange/spec.md#requirement-req-csdx-005-the-meeting-file-goes-back-to-the-case-system-after-approval
	 *
	 * @return void
	 */
	protected function run($argument): void {
		$argument = (array)$argument;
		try {
			if ((string)($argument['record'] ?? '') !== '') {
				$this->exchange->resend(recordId: (string)$argument['record']);
				return;
			}

			if ((string)($argument['meeting'] ?? '') !== '') {
				$this->exchange->send(meetingId: (string)$argument['meeting'], userId: (string)($argument['uid'] ?? 'system'));
			}
		} catch (Throwable $e) {
			$this->logger->error('Decidiq: sending the meeting file to the case system failed', ['exception' => $e]);
		}
	}//end run()
}//end class
