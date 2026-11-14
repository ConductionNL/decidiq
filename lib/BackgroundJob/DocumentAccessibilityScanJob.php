<?php

/**
 * Scans one paper added to a meeting or an agenda item for accessibility.
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
 * @spec openspec/changes/document-accessibility-check/specs/document-accessibility-check/spec.md#requirement-req-006-admin-settings-for-enforcement-and-scanning
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Decidiq\BackgroundJob;

use OCA\Decidiq\Service\DocumentAccessibilityScanService;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\QueuedJob;
use Psr\Log\LoggerInterface;

/**
 * Runs DocumentAccessibilityScanService::scanFile() for the queued paper, so
 * the upload never waits for a scan. The report names the governance body:
 * the meeting's own, or for an agenda item that of its meeting. A scan that
 * breaks off is logged; the paper then stays `not-scanned` until it is
 * scanned on demand or at publication.
 *
 * @spec openspec/changes/document-accessibility-check/specs/document-accessibility-check/spec.md#requirement-req-006-admin-settings-for-enforcement-and-scanning
 */
class DocumentAccessibilityScanJob extends QueuedJob {

	/**
	 * Constructor.
	 *
	 * @param ITimeFactory                     $time          The time factory.
	 * @param DocumentAccessibilityScanService $scanner       The scanner.
	 * @param ObjectServiceInterface           $objectService Reads the meeting, for its body.
	 * @param LoggerInterface                  $logger        The logger.
	 *
	 * @spec openspec/changes/document-accessibility-check/specs/document-accessibility-check/spec.md#requirement-req-006-admin-settings-for-enforcement-and-scanning
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly DocumentAccessibilityScanService $scanner,
		private readonly ObjectServiceInterface $objectService,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(time: $time);
	}//end __construct()

	/**
	 * Scan the queued paper.
	 *
	 * @param mixed $argument `{fileId, objectId, schema, governanceBody?, meeting?}`.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/document-accessibility-check/specs/document-accessibility-check/spec.md#scenario-scan-on-upload-queues-a-background-scan
	 */
	protected function run($argument): void {
		if (is_array($argument) === false) {
			return;
		}

		$this->scan(argument: $argument);
	}//end run()

	/**
	 * Scan one paper and store its report.
	 *
	 * @param array<string, mixed> $argument `{fileId, objectId, schema, governanceBody?, meeting?}`.
	 *
	 * @return array<string, mixed>|null The report, or null when nothing was scanned.
	 *
	 * @spec openspec/changes/document-accessibility-check/specs/document-accessibility-check/spec.md#scenario-scan-on-upload-queues-a-background-scan
	 */
	public function scan(array $argument): ?array {
		$fileId   = (int)($argument['fileId'] ?? 0);
		$objectId = (string)($argument['objectId'] ?? '');
		$schema   = (string)($argument['schema'] ?? '');
		if ($fileId <= 0 || $objectId === '' || in_array($schema, ['meeting', 'agenda-item'], true) === false) {
			return null;
		}

		try {
			return $this->scanner->scanFile(
				fileId: $fileId,
				sourceObject: $objectId,
				sourceSchema: $schema,
				governanceBody: $this->bodyOf(argument: $argument)
			);
		} catch (\Throwable $e) {
			$this->logger->warning('Decidiq: the accessibility scan of a paper broke off', ['fileId' => $fileId, 'error' => $e->getMessage()]);
			return null;
		}
	}//end scan()

	/**
	 * The governance body of the paper's meeting, or null.
	 *
	 * @param array<string, mixed> $argument The job argument.
	 *
	 * @return string|null
	 */
	private function bodyOf(array $argument): ?string {
		$body = (string)($argument['governanceBody'] ?? '');
		if ($body !== '') {
			return $body;
		}

		$meetingId = (string)($argument['meeting'] ?? '');
		if ($meetingId === '') {
			return null;
		}

		try {
			$meeting = $this->objectService->find(id: $meetingId, register: 'decidiq', schema: 'meeting', _rbac: false, _multitenancy: false);
		} catch (\Throwable) {
			return null;
		}

		$body = (string)(((array)($meeting?->getObject() ?? []))['governanceBody'] ?? '');
		if ($body === '') {
			return null;
		}

		return $body;
	}//end bodyOf()
}//end class
