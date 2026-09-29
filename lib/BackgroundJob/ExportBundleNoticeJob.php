<?php

/**
 * Decidiq ExportBundleNoticeJob
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
 * @spec openspec/specs/motion-management/spec.md#requirement-req-mxp-003-a-large-export-runs-in-the-background-and-says-when-it-is-ready
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Decidiq\BackgroundJob;

use DateTime;
use OCA\Decidiq\AppInfo\Application;
use OCA\Decidiq\Service\ExportBundleService;
use OCA\Decidiq\Support\FleetAppId;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\BackgroundJob\QueuedJob;
use OCP\Notification\IManager as INotificationManager;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Tells the clerk when a queued PDF export is ready.
 *
 * filinq runs the queued merge in its own background job and emits nothing
 * when it finishes, so this job reads the merge job's status. Still queued or
 * running: it schedules itself again. Done: the ready notice with the file.
 * Failed, gone, or still not done after a day: the failed notice. Either way
 * the rendered text pages are removed.
 *
 * @spec openspec/specs/motion-management/spec.md#requirement-req-mxp-003-a-large-export-runs-in-the-background-and-says-when-it-is-ready
 */
class ExportBundleNoticeJob extends QueuedJob {
	/**
	 * How many times the job looks before it gives up (one look per cron run,
	 * about every five minutes, so roughly a day).
	 */
	public const MAX_ATTEMPTS = 288;

	/**
	 * Constructor.
	 *
	 * @param ITimeFactory         $time          The clock.
	 * @param ContainerInterface   $container     Resolves filinq's merge job store.
	 * @param ExportBundleService  $exports       Removes the rendered text pages.
	 * @param IJobList             $jobList       Schedules the next look.
	 * @param INotificationManager $notifications Nextcloud notifications.
	 * @param LoggerInterface      $logger        Diagnostics.
	 *
	 * @spec openspec/specs/motion-management/spec.md#requirement-req-mxp-003-a-large-export-runs-in-the-background-and-says-when-it-is-ready
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly ContainerInterface $container,
		private readonly ExportBundleService $exports,
		private readonly IJobList $jobList,
		private readonly INotificationManager $notifications,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct($time);
	}//end __construct()

	/**
	 * Look at the merge once.
	 *
	 * @param mixed $argument `mergeJob`, `uid`, `name`, `pages`, `attempt`.
	 *
	 * @spec openspec/specs/motion-management/spec.md#requirement-req-mxp-003-a-large-export-runs-in-the-background-and-says-when-it-is-ready
	 *
	 * @return void
	 */
	protected function run($argument): void {
		if (is_array($argument) === false) {
			return;
		}

		$uid     = (string)($argument['uid'] ?? '');
		$name    = (string)($argument['name'] ?? '');
		$pages   = array_map('intval', (array)($argument['pages'] ?? []));
		$attempt = (int)($argument['attempt'] ?? 0);
		if ($uid === '') {
			return;
		}

		$job = $this->mergeJob(uuid: (string)($argument['mergeJob'] ?? ''));
		$status = (string)($job['status'] ?? 'missing');

		if (in_array($status, ['queued', 'running'], true) === true && $attempt < self::MAX_ATTEMPTS) {
			$argument['attempt'] = ($attempt + 1);
			$this->jobList->add(self::class, $argument);
			return;
		}

		$this->exports->removePages(uid: $uid, pages: $pages);

		$subject = 'export_bundle_failed';
		$fileId  = '';
		if ($status === 'done') {
			$subject = 'export_bundle_ready';
			$fileId  = (string)($job['resultFileId'] ?? '');
		}

		$objectId = $fileId;
		if ($objectId === '') {
			$objectId = $name;
		}

		$notification = $this->notifications->createNotification();
		$notification->setApp(Application::APP_ID)
			->setUser($uid)
			->setDateTime(new DateTime())
			->setObject('export-bundle', $objectId)
			->setSubject($subject, ['title' => $name, 'fileId' => $fileId]);
		$this->notifications->notify($notification);
	}//end run()

	/**
	 * filinq's merge job, or an empty array when it cannot be read.
	 *
	 * @param string $uuid The merge job.
	 *
	 * @spec openspec/specs/motion-management/spec.md#requirement-req-mxp-003-a-large-export-runs-in-the-background-and-says-when-it-is-ready
	 *
	 * @return array<string,mixed>
	 */
	private function mergeJob(string $uuid): array {
		if ($uuid === '') {
			return [];
		}

		try {
			$jobs = FleetAppId::getService($this->container, 'filinq', 'Service\MergeJobRepository');
			if ($jobs === null) {
				return [];
			}

			return (array)($jobs->find($uuid) ?? []);
		} catch (Throwable $e) {
			$this->logger->warning('Decidiq export: the merge job could not be read', ['job' => $uuid, 'error' => $e->getMessage()]);
			return ['status' => 'queued'];
		}
	}//end mergeJob()
}//end class
