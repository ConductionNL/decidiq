<?php

/**
 * Decidiq FullExportJob
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
 * @spec openspec/specs/openregister-integration/spec.md#requirement-req-pfe-001-an-administrator-exports-all-data
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Decidiq\BackgroundJob;

use DateTime;
use OCA\Decidiq\AppInfo\Application;
use OCA\Decidiq\Service\FullExportService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\QueuedJob;
use OCP\Notification\IManager as INotificationManager;
use Psr\Log\LoggerInterface;

/**
 * Builds the full data export once and tells the administrator who asked
 * for it where to download it, or that it failed.
 *
 * @spec openspec/specs/openregister-integration/spec.md#requirement-req-pfe-001-an-administrator-exports-all-data
 */
class FullExportJob extends QueuedJob {
	/**
	 * Constructor.
	 *
	 * @param ITimeFactory         $time          Clock.
	 * @param FullExportService    $exportService Builds the archive.
	 * @param INotificationManager $notifications Nextcloud notifications.
	 * @param LoggerInterface      $logger        Logger.
	 *
	 * @spec openspec/specs/openregister-integration/spec.md#requirement-req-pfe-001-an-administrator-exports-all-data
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly FullExportService $exportService,
		private readonly INotificationManager $notifications,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(time: $time);
	}//end __construct()

	/**
	 * Build the export and notify the administrator.
	 *
	 * @param mixed $argument Array with `uid`, the administrator who asked.
	 *
	 * @spec openspec/specs/openregister-integration/spec.md#requirement-req-pfe-001-an-administrator-exports-all-data
	 *
	 * @return void
	 */
	protected function run($argument): void {
		$uid = '';
		if (is_array($argument) === true) {
			$uid = (string)($argument['uid'] ?? '');
		}

		if ($uid === '') {
			return;
		}

		$subject = 'full_export_ready';
		$name    = '';
		try {
			$name = $this->exportService->build();
		} catch (\Throwable $e) {
			$this->logger->error('Decidiq export: the full data export failed', ['exception' => $e]);
			$subject = 'full_export_failed';
		}

		$objectId = $name;
		if ($objectId === '') {
			$objectId = 'failed';
		}

		$notification = $this->notifications->createNotification();
		$notification->setApp(Application::APP_ID)
			->setUser($uid)
			->setDateTime(new DateTime())
			->setObject('export', $objectId)
			->setSubject($subject, ['title' => $name]);
		$this->notifications->notify($notification);
	}//end run()
}//end class
