<?php

/**
 * Decidiq Document Publication Gate
 *
 * Holds a publication back on its documents' accessibility scan, per the
 * admin's enforcement mode: off, warn (acknowledge the failing and unscanned
 * documents) or block (refuse failing documents unless overridden with a
 * reason). Documents without a report are scanned first.
 *
 * @category Service
 * @package  OCA\Decidiq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/document-accessibility-check/specs/document-accessibility-check/spec.md#requirement-req-003-publication-gate-on-failing-documents-with-recorded-override
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2
declare(strict_types=1);

namespace OCA\Decidiq\Service;

use DateTimeInterface;
use OCA\Decidiq\Exception\AccessibilityGateException;
use OCA\Decidiq\Kernel\AppIdentity;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use OCP\IUserSession;

/**
 * The accessibility gate both publication paths consult, server-side.
 *
 * @spec openspec/changes/document-accessibility-check/specs/document-accessibility-check/spec.md#requirement-req-003-publication-gate-on-failing-documents-with-recorded-override
 */
class DocumentPublicationGate {

	/**
	 * The app config key holding the enforcement mode.
	 *
	 * @var string
	 */
	public const MODE_KEY = 'accessibility_enforcement_mode';

	/**
	 * The mode when none, or an unknown one, is stored.
	 *
	 * @var string
	 */
	public const DEFAULT_MODE = 'warn';

	/**
	 * The modes an admin can choose.
	 *
	 * @var list<string>
	 */
	public const MODES = ['off', 'warn', 'block'];

	/**
	 * Constructor.
	 *
	 * @param DocumentAccessibilityScanService $scanner     Scans and reads reports.
	 * @param IAppConfig                       $appConfig   The app config.
	 * @param IUserSession                     $userSession The user who publishes.
	 * @param ITimeFactory                     $time        The clock.
	 *
	 * @spec openspec/changes/document-accessibility-check/specs/document-accessibility-check/spec.md#requirement-req-003-publication-gate-on-failing-documents-with-recorded-override
	 */
	public function __construct(
		private readonly DocumentAccessibilityScanService $scanner,
		private readonly IAppConfig $appConfig,
		private readonly IUserSession $userSession,
		private readonly ITimeFactory $time,
	) {
	}//end __construct()

	/**
	 * The enforcement mode; an unknown stored value reads as the default.
	 *
	 * @return string `off`, `warn` or `block`.
	 *
	 * @spec openspec/changes/document-accessibility-check/specs/document-accessibility-check/spec.md#requirement-req-006-admin-settings-for-enforcement-and-scanning
	 */
	public function mode(): string {
		$mode = $this->appConfig->getValueString(AppIdentity::APP_ID, self::MODE_KEY, self::DEFAULT_MODE);
		if (in_array($mode, self::MODES, true) === false) {
			return self::DEFAULT_MODE;
		}

		return $mode;
	}//end mode()

	/**
	 * Let a publication of these documents through, or refuse it.
	 *
	 * Each document is `{fileId, fileName?, sourceObject, sourceSchema, governanceBody?}`.
	 *
	 * @param list<array<string, mixed>> $documents      The documents published.
	 * @param bool                       $acknowledged   Whether the publisher acknowledged the listed documents.
	 * @param string|null                $overrideReason The override reason, when one is given.
	 *
	 * @return array<string, mixed>|null The override to record (reason, actor, overriddenAt, reports), or null when none is needed.
	 *
	 * @throws AccessibilityGateException When the publication is held back.
	 *
	 * @spec openspec/changes/document-accessibility-check/specs/document-accessibility-check/spec.md#requirement-req-003-publication-gate-on-failing-documents-with-recorded-override
	 */
	public function check(array $documents, bool $acknowledged=false, ?string $overrideReason=null): ?array {
		$mode = $this->mode();
		if ($mode === 'off' || $documents === []) {
			return null;
		}

		$holding = [];
		foreach ($documents as $document) {
			$report = $this->reportFor(document: $document);
			if ($this->holdsBack(mode: $mode, status: (string)($report['status'] ?? 'not-scanned')) === true) {
				$holding[] = $report;
			}
		}

		if ($holding === []) {
			return null;
		}

		$reason = trim((string)$overrideReason);
		if ($reason !== '') {
			return $this->override(reason: $reason, reports: $holding);
		}

		if ($mode === 'warn' && $acknowledged === true) {
			return null;
		}

		throw new AccessibilityGateException(mode: $mode, documents: array_map(fn (array $report): array => $this->summary(report: $report), $holding));
	}//end check()

	/**
	 * Whether a status holds a publication back in a mode.
	 *
	 * Block refuses failing documents only; warn lists failing and unscanned ones.
	 *
	 * @param string $mode   `warn` or `block`.
	 * @param string $status The report status.
	 *
	 * @return bool
	 */
	private function holdsBack(string $mode, string $status): bool {
		if ($mode === 'block') {
			return $status === 'fail';
		}

		return in_array($status, ['fail', 'not-scanned'], true);
	}//end holdsBack()

	/**
	 * The document's current report, scanning it first when it has none.
	 *
	 * @param array<string, mixed> $document The document.
	 *
	 * @return array<string, mixed>
	 */
	private function reportFor(array $document): array {
		$fileId = (int)$document['fileId'];
		$report = $this->scanner->latestReport(fileId: $fileId);
		if ($report === null || ($report['status'] ?? '') === 'not-scanned') {
			$report = $this->scanner->scanFile(
				fileId: $fileId,
				sourceObject: (string)$document['sourceObject'],
				sourceSchema: (string)$document['sourceSchema'],
				governanceBody: ($document['governanceBody'] ?? null)
			);
		}

		if ((string)($report['fileName'] ?? '') === '') {
			$report['fileName'] = (string)($document['fileName'] ?? '');
		}

		return $report;
	}//end reportFor()

	/**
	 * The override to record.
	 *
	 * @param string                           $reason  The reason given.
	 * @param list<array<string, mixed>>       $reports The reports overridden.
	 *
	 * @return array{reason: string, actor: string, overriddenAt: string, reports: list<string>}
	 */
	private function override(string $reason, array $reports): array {
		$user = $this->userSession->getUser();
		$ids  = [];
		foreach ($reports as $report) {
			$id = (string)($report['id'] ?? '');
			if ($id !== '') {
				$ids[] = $id;
			}
		}

		return [
			'reason' => $reason,
			'actor' => ($user?->getUID() ?? ''),
			'overriddenAt' => $this->time->getDateTime()->format(DateTimeInterface::ATOM),
			'reports' => $ids,
		];
	}//end override()

	/**
	 * What the refusal shows of a report.
	 *
	 * @param array<string, mixed> $report The report.
	 *
	 * @return array<string, mixed>
	 */
	private function summary(array $report): array {
		return [
			'fileId' => (int)($report['fileId'] ?? 0),
			'fileName' => (string)($report['fileName'] ?? ''),
			'status' => (string)($report['status'] ?? 'not-scanned'),
			'findings' => (array)($report['findings'] ?? []),
			'report' => ($report['id'] ?? null),
		];
	}//end summary()
}//end class
