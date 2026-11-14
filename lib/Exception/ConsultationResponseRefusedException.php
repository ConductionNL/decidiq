<?php

/**
 * Decidiq Consultation Response Refused Exception
 *
 * A response document that cannot be generated, with the HTTP status the
 * controller answers: 404 for a traject that does not exist, 409 for one
 * whose response is not adopted yet, 422 for an unknown format, 503 when the
 * Files backend cannot store the document.
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
 * @spec openspec/changes/works-council-consultation/specs/works-council-consultation/spec.md#requirement-req-wcc-005-formal-response-document-and-decision-linkage
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Exception;

/**
 * A refused response-document generation and its HTTP status.
 *
 * @spec openspec/changes/works-council-consultation/specs/works-council-consultation/spec.md#requirement-req-wcc-005-formal-response-document-and-decision-linkage
 */
class ConsultationResponseRefusedException extends \RuntimeException {
	/**
	 * Constructor.
	 *
	 * @param string $message The reason, shown to the secretary
	 * @param int    $status  The HTTP status to answer
	 *
	 * @return void
	 */
	public function __construct(string $message, private readonly int $status=409) {
		parent::__construct(message: $message);
	}//end __construct()

	/**
	 * The HTTP status to answer.
	 *
	 * @return int The status.
	 *
	 * @spec openspec/changes/works-council-consultation/specs/works-council-consultation/spec.md#requirement-req-wcc-005-formal-response-document-and-decision-linkage
	 */
	public function getStatus(): int {
		return $this->status;
	}//end getStatus()
}//end class
