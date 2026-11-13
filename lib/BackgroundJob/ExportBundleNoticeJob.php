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
use OCA\Decidiq\Kernel\AppIdentity;
use OCA\Decidiq\Service\ExportBundleWriter;
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
 * The filinq app runs the queued merge in its own background job and emits nothing
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
	 * @param ExportBundleWriter   $exports       Removes the rendered text pages.
	 * @param IJobList             $jobList       Schedules the next look.
	 * @param INotificationManager $notifications Nextcloud notifications.
	 * @param LoggerInterface      $logger        Diagnostics.
	 *
	 * @spec openspec/specs/motion-management/spec.md#requirement-req-mxp-003-a-large-export-runs-in-the-background-and-says-when-it-is-ready
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly ContainerInterface $container,
		private readonly ExportBundleWriter $exports,
		private readonly IJobList $jobList,
		private readonly INotificationManager $notifications,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(time: $time);
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
		if (is_array($argument) === false || (string)($argument['uid'] ?? '') === '') {
			return;
		}

		$job = $this->mergeJob(uuid: (string)($argument['mergeJob'] ?? ''));
		if ($this->lookAgainLater(argument: $argument, status: (string)($job['status'] ?? 'missing')) === true) {
			return;
		}

		$this->removeTextPages(argument: $argument);
		$this->notifyOutcome(argument: $argument, job: $job);
	}//end run()

	/**
	 * Remove the text pages the merge was made from.
	 *
	 * @param array<string,mixed> $argument The job's argument.
	 *
	 * @return void
	 */
	private function removeTextPages(array $argument): void {
		$uid   = (string)($argument['uid'] ?? '');
		$pages = array_map('intval', (array)($argument['pages'] ?? []));
		$this->exports->removePages(uid: $uid, pages: $pages);
	}//end removeTextPages()

	/**
	 * Queue another look while the merge is still waiting and attempts remain.
	 *
	 * @param array<string,mixed> $argument The job's argument.
	 * @param string              $status   The merge job's status.
	 *
	 * @return bool True when the job will look again, and this run ends.
	 */
	private function lookAgainLater(array $argument, string $status): bool {
		$attempt = (int)($argument['attempt'] ?? 0);
		if (in_array($status, ['queued', 'running'], true) === false || $attempt >= self::MAX_ATTEMPTS) {
			return false;
		}

		$argument['attempt'] = ($attempt + 1);
		$this->jobList->add(self::class, $argument);

		return true;
	}//end lookAgainLater()

	/**
	 * Tell the user the export is ready, about the merged file, or that it failed.
	 *
	 * @param array<string,mixed> $argument The job's argument.
	 * @param array<string,mixed> $job      filinq's merge job.
	 *
	 * @return void
	 */
	private function notifyOutcome(array $argument, array $job): void {
		$name = (string)($argument['name'] ?? '');
		[$subject, $fileId] = $this->subjectAndFileOf(job: $job);

		$notification = $this->notifications->createNotification();
		$notification->setApp(AppIdentity::APP_ID)
			->setUser((string)$argument['uid'])
			->setDateTime(new DateTime())
			->setObject('export-bundle', $this->objectIdOf(fileId: $fileId, name: $name))
			->setSubject($subject, ['title' => $name, 'fileId' => $fileId]);
		$this->notifications->notify($notification);
	}//end notifyOutcome()

	/**
	 * The notice's subject and merged file: ready with the file when the merge is
	 * done, failed without one otherwise.
	 *
	 * @param array<string,mixed> $job filinq's merge job.
	 *
	 * @return array{0: string, 1: string} The subject and the file id ('' when none).
	 */
	private function subjectAndFileOf(array $job): array {
		if ((string)($job['status'] ?? 'missing') === 'done') {
			return ['export_bundle_ready', (string)($job['resultFileId'] ?? '')];
		}

		return ['export_bundle_failed', ''];
	}//end subjectAndFileOf()

	/**
	 * The object the notice is about: the merged file, or else the export's name.
	 *
	 * @param string $fileId The merged file, or empty.
	 * @param string $name   The export's name.
	 *
	 * @return string
	 */
	private function objectIdOf(string $fileId, string $name): string {
		if ($fileId === '') {
			return $name;
		}

		return $fileId;
	}//end objectIdOf()

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
