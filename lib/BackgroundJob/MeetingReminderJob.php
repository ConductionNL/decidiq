<?php

/**
 * Decidiq Meeting Reminder Job
 *
 * Hourly: meeting scheduled notices, reminders before a meeting starts and
 * before its submission deadline (change meeting-reminders-before-deadlines).
 *
 * @category BackgroundJob
 * @package  OCA\Decidiq\BackgroundJob
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/decidesk-notifications/spec.md#requirement-req-mrd-001-meeting-notices-follow-the-member-switches
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2
declare(strict_types=1);

namespace OCA\Decidiq\BackgroundJob;

use OCA\Decidiq\Service\MeetingReminderService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * Runs MeetingReminderService once an hour.
 *
 * @spec openspec/specs/decidesk-notifications/spec.md#requirement-req-mrd-001-meeting-notices-follow-the-member-switches
 */
class MeetingReminderJob extends TimedJob {

	/**
	 * One hour.
	 *
	 * @var int
	 */
	private const INTERVAL_SECONDS = 3600;

	/**
	 * Constructor.
	 *
	 * @param ITimeFactory           $time      Time factory
	 * @param MeetingReminderService $reminders The reminder service
	 * @param LoggerInterface        $logger    Logger
	 *
	 * @return void
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly MeetingReminderService $reminders,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(time: $time);
		$this->setInterval(seconds: self::INTERVAL_SECONDS);

	}//end __construct()

	/**
	 * Send the notices that are due.
	 *
	 * @param mixed $argument Unused job argument
	 *
	 * @return void
	 *
	 * @spec openspec/specs/decidesk-notifications/spec.md#requirement-req-mrd-001-meeting-notices-follow-the-member-switches
	 */
	protected function run(mixed $argument): void {
		try {
			$sent = $this->reminders->run(now: $this->time->getTime());
			if ($sent > 0) {
				$this->logger->info('Decidiq: meeting reminders sent', ['sent' => $sent]);
			}
		} catch (\Throwable $e) {
			$this->logger->error('Decidiq: meeting reminder job failed', ['exception' => $e->getMessage()]);
		}

	}//end run()
}//end class
