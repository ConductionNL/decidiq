<?php

/**
 * Decidiq Accessibility Gate Exception
 *
 * A publication the document accessibility gate holds back: in warn mode
 * until the failing and unscanned documents are acknowledged, in block mode
 * until an override with a reason is given. Answered as 409 with the
 * documents and their findings, so the client can show them.
 *
 * @category Exception
 * @package  OCA\Decidiq\Exception
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/document-accessibility-check/specs/document-accessibility-check/spec.md#requirement-req-003-publication-gate-on-failing-documents-with-recorded-override
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Exception;

/**
 * A publication held back by the document accessibility gate.
 *
 * @spec openspec/changes/document-accessibility-check/specs/document-accessibility-check/spec.md#requirement-req-003-publication-gate-on-failing-documents-with-recorded-override
 */
class AccessibilityGateException extends \RuntimeException {

	/**
	 * Constructor.
	 *
	 * @param string                           $mode      The enforcement mode, `warn` or `block`.
	 * @param list<array<string, mixed>>       $documents The documents that hold the publication back.
	 *
	 * @spec openspec/changes/document-accessibility-check/specs/document-accessibility-check/spec.md#requirement-req-003-publication-gate-on-failing-documents-with-recorded-override
	 */
	public function __construct(
		private readonly string $mode,
		private readonly array $documents,
	) {
		parent::__construct(message: 'Publication held back: documents do not pass the accessibility scan', code: 409);
	}//end __construct()

	/**
	 * The response body for the refusal.
	 *
	 * @return array{error: string, mode: string, message: string, documents: list<array<string, mixed>>}
	 *
	 * @spec openspec/changes/document-accessibility-check/specs/document-accessibility-check/spec.md#requirement-req-003-publication-gate-on-failing-documents-with-recorded-override
	 */
	public function payload(): array {
		return ['error' => 'accessibility-gate', 'mode' => $this->mode, 'message' => $this->getMessage(), 'documents' => $this->documents];
	}//end payload()
}//end class
