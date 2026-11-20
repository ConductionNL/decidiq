<?php

/**
 * Decidiq AccessibilityConsent
 *
 * What a publisher said to the accessibility gate in a publish request: an
 * acknowledgement of the failing documents, and an override reason.
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
 * @spec openspec/changes/archive/2026-11-20-document-accessibility-check/specs/document-accessibility-check/spec.md#requirement-req-003-publication-gate-on-failing-documents-with-recorded-override
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Decidiq\Service;

use OCP\IRequest;

/**
 * Reads the accessibility consent both publish endpoints pass to the gate.
 *
 * @spec openspec/changes/archive/2026-11-20-document-accessibility-check/specs/document-accessibility-check/spec.md#requirement-req-003-publication-gate-on-failing-documents-with-recorded-override
 */
final class AccessibilityConsent {
	/**
	 * The acknowledgement and override reason of a publish request.
	 *
	 * `accessibilityOverride` may be the reason itself or `{reason}`; anything
	 * else is no override.
	 *
	 * @param IRequest $request The publish request.
	 *
	 * @return array{acknowledged: bool, overrideReason: string|null}
	 *
	 * @spec openspec/changes/archive/2026-11-20-document-accessibility-check/specs/document-accessibility-check/spec.md#requirement-req-003-publication-gate-on-failing-documents-with-recorded-override
	 */
	public function fromRequest(IRequest $request): array {
		$override = $request->getParam('accessibilityOverride');
		if (is_array($override) === true) {
			$override = ($override['reason'] ?? null);
		}

		if (is_string($override) === false) {
			$override = null;
		}

		return [
			'acknowledged' => filter_var($request->getParam('accessibilityAcknowledged', false), FILTER_VALIDATE_BOOLEAN),
			'overrideReason' => $override,
		];
	}//end fromRequest()
}//end class
