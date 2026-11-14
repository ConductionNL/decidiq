<?php

/**
 * Queues the accessibility scan of a PDF added to a meeting or an agenda item.
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
 * @spec openspec/changes/document-accessibility-check/specs/document-accessibility-check/spec.md#requirement-req-006-admin-settings-for-enforcement-and-scanning
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Decidiq\Listener;

use OCA\Decidiq\BackgroundJob\DocumentAccessibilityScanJob;
use OCA\Decidiq\Kernel\AppIdentity;
use OCA\Decidiq\Service\PaperFolderOwner;
use OCP\BackgroundJob\IJobList;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Files\Events\Node\NodeCreatedEvent;
use OCP\Files\Events\Node\NodeWrittenEvent;
use OCP\Files\File;
use OCP\IAppConfig;

/**
 * A PDF created or rewritten in the folder of a decidiq meeting or agenda
 * item queues one DocumentAccessibilityScanJob, unless the administrator
 * switched scan-on-upload off. The listener scans nothing itself: a Files
 * event runs inside the upload request.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/document-accessibility-check/specs/document-accessibility-check/spec.md#requirement-req-006-admin-settings-for-enforcement-and-scanning
 */
class DocumentScanOnUploadListener implements IEventListener {

	/**
	 * The app config key that switches scan-on-upload on and off.
	 *
	 * @var string
	 */
	public const CONFIG_KEY = 'accessibility_scan_on_upload';

	/**
	 * Constructor.
	 *
	 * @param PaperFolderOwner $owners    Finds the meeting or agenda item a folder belongs to.
	 * @param IJobList         $jobList   The background job list.
	 * @param IAppConfig       $appConfig The app config.
	 */
	public function __construct(
		private readonly PaperFolderOwner $owners,
		private readonly IJobList $jobList,
		private readonly IAppConfig $appConfig,
	) {
	}//end __construct()

	/**
	 * Queue a scan for a PDF in a meeting or agenda item folder.
	 *
	 * @param Event $event The event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/document-accessibility-check/specs/document-accessibility-check/spec.md#scenario-scan-on-upload-queues-a-background-scan
	 */
	public function handle(Event $event): void {
		if ($event instanceof NodeCreatedEvent === false && $event instanceof NodeWrittenEvent === false) {
			return;
		}

		$node = $event->getNode();
		if ($node instanceof File === false || self::isPdf(node: $node) === false || $this->switchedOn() === false) {
			return;
		}

		$owner = $this->owners->ownerOf(node: $node);
		if ($owner === null) {
			return;
		}

		$argument = ['fileId' => (int)$node->getId(), 'objectId' => $owner['objectId'], 'schema' => $owner['schema']];
		if ($owner['schema'] === 'meeting') {
			$argument['governanceBody'] = (string)($owner['object']['governanceBody'] ?? '');
		}

		if ($owner['schema'] === 'agenda-item') {
			$argument['meeting'] = (string)($owner['object']['meeting'] ?? '');
		}

		$this->jobList->add(DocumentAccessibilityScanJob::class, $argument);
	}//end handle()

	/**
	 * Whether scan-on-upload is on; anything but an explicit off is on.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/document-accessibility-check/specs/document-accessibility-check/spec.md#requirement-req-006-admin-settings-for-enforcement-and-scanning
	 */
	private function switchedOn(): bool {
		return OfficePaperAddedListener::isSwitchedOn(value: $this->appConfig->getValueString(AppIdentity::APP_ID, self::CONFIG_KEY, 'true'));
	}//end switchedOn()

	/**
	 * Whether a file is a PDF, by its type or else its name.
	 *
	 * @param File $node The file.
	 *
	 * @return bool
	 */
	private static function isPdf(File $node): bool {
		if ($node->getMimetype() === 'application/pdf') {
			return true;
		}

		return strtolower((string)pathinfo($node->getName(), PATHINFO_EXTENSION)) === 'pdf';
	}//end isPdf()
}//end class
