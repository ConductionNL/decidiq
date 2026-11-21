<?php

/**
 * Decidiq ConfidentialityImposeRefusedException
 *
 * A refused imposition of a confidentiality restriction, carrying the HTTP
 * status to answer.
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
 * @spec openspec/changes/embargo-geheimhouding/specs/embargo-geheimhouding/spec.md#requirement-req-emb-001-geheimhouding-record-with-structured-legal-ground
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Exception;

/**
 * A refused imposition and its HTTP status.
 *
 * @spec openspec/changes/embargo-geheimhouding/specs/embargo-geheimhouding/spec.md#requirement-req-emb-001-geheimhouding-record-with-structured-legal-ground
 */
class ConfidentialityImposeRefusedException extends \RuntimeException {
	/**
	 * Constructor.
	 *
	 * @param string $message The reason, shown to the caller
	 * @param int    $status  The HTTP status to answer
	 *
	 * @return void
	 *
	 * @spec openspec/changes/embargo-geheimhouding/specs/embargo-geheimhouding/spec.md#requirement-req-emb-001-geheimhouding-record-with-structured-legal-ground
	 */
	public function __construct(string $message, private readonly int $status=422) {
		parent::__construct(message: $message);
	}//end __construct()

	/**
	 * The HTTP status to answer.
	 *
	 * @return int The status.
	 *
	 * @spec openspec/changes/embargo-geheimhouding/specs/embargo-geheimhouding/spec.md#requirement-req-emb-001-geheimhouding-record-with-structured-legal-ground
	 */
	public function getStatus(): int {
		return $this->status;
	}//end getStatus()
}//end class
