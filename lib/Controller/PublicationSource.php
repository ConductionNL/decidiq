<?php

/**
 * Decidiq Publication Source
 *
 * The source a publish request names: its type (decision, agenda, minutes
 * or activity) and its id, validated by PublicationController.
 *
 * @category Controller
 * @package  OCA\Decidiq\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/public-publication/spec.md
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2
declare(strict_types=1);

namespace OCA\Decidiq\Controller;

/**
 * A validated publication source.
 *
 * @spec openspec/specs/public-publication/spec.md
 */
final class PublicationSource {

	/**
	 * Constructor.
	 *
	 * @param string $type The source type: decision, agenda, minutes or activity.
	 * @param string $id   The non-empty source id.
	 */
	public function __construct(
		public readonly string $type,
		public readonly string $id,
	) {
	}//end __construct()
}//end class
