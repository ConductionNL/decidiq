<?php

/**
 * Decidiq Accessibility Override Log
 *
 * Keeps one `accessibility-override` record per publication that went ahead
 * past the document accessibility gate, so the aggregate report counts the
 * overrides per body and period declaratively (an aggregation over these
 * records) instead of unpacking the override fields of meetings and
 * publication records.
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
 * @spec openspec/changes/document-accessibility-check/specs/document-accessibility-check/spec.md#requirement-req-005-aggregate-accessibility-report-per-body-and-period
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2
declare(strict_types=1);

namespace OCA\Decidiq\Service;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Writes the countable record of an accessibility override.
 *
 * @spec openspec/changes/document-accessibility-check/specs/document-accessibility-check/spec.md#requirement-req-005-aggregate-accessibility-report-per-body-and-period
 */
class AccessibilityOverrideLog {

	/**
	 * The schema slug of an override record.
	 *
	 * @var string
	 */
	public const SCHEMA = 'accessibility-override';

	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface $objectService The OpenRegister object service.
	 * @param LoggerInterface        $logger        The logger.
	 *
	 * @spec openspec/changes/document-accessibility-check/specs/document-accessibility-check/spec.md#requirement-req-005-aggregate-accessibility-report-per-body-and-period
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The record an override is counted by.
	 *
	 * The body and the source are those of the first overridden report that
	 * names one: one publication belongs to one meeting, so to one body.
	 *
	 * @param array<string, mixed>       $override The override (reason, actor, overriddenAt, reports).
	 * @param list<array<string, mixed>> $reports  The overridden reports.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/document-accessibility-check/specs/document-accessibility-check/spec.md#requirement-req-005-aggregate-accessibility-report-per-body-and-period
	 */
	public function recordOf(array $override, array $reports): array {
		$record = [
			'reason' => (string)($override['reason'] ?? ''),
			'actor' => (string)($override['actor'] ?? ''),
			'overriddenAt' => (string)($override['overriddenAt'] ?? ''),
			'reports' => array_values(array_map('strval', (array)($override['reports'] ?? []))),
			'documentCount' => count($reports),
		];

		foreach (['governanceBody', 'sourceObject', 'sourceSchema'] as $field) {
			$value = $this->firstOf(reports: $reports, field: $field);
			if ($value !== null) {
				$record[$field] = $value;
			}
		}

		return $record;
	}//end recordOf()

	/**
	 * Store the record of an override.
	 *
	 * A failed write is logged and does not hold the publication back: the
	 * override itself is kept on the meeting or the publication record.
	 *
	 * @param array<string, mixed>       $override The override.
	 * @param list<array<string, mixed>> $reports  The overridden reports.
	 *
	 * @return bool Whether the record was stored.
	 *
	 * @spec openspec/changes/document-accessibility-check/specs/document-accessibility-check/spec.md#requirement-req-005-aggregate-accessibility-report-per-body-and-period
	 */
	public function record(array $override, array $reports): bool {
		$record = $this->recordOf(override: $override, reports: $reports);
		try {
			$this->objectService->saveObject(
				object: $record,
				register: 'decidiq',
				schema: self::SCHEMA,
				_rbac: false,
				_multitenancy: false
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Decidiq: an accessibility override could not be logged for the report',
				['sourceObject' => ($record['sourceObject'] ?? null), 'error' => $e->getMessage()]
			);
			return false;
		}

		return true;
	}//end record()

	/**
	 * The first non-empty value of a field across the reports.
	 *
	 * @param list<array<string, mixed>> $reports The reports.
	 * @param string                     $field   The field.
	 *
	 * @return string|null
	 */
	private function firstOf(array $reports, string $field): ?string {
		foreach ($reports as $report) {
			$value = trim((string)($report[$field] ?? ''));
			if ($value !== '') {
				return $value;
			}
		}

		return null;
	}//end firstOf()
}//end class
