<?php

/**
 * Actor Rule Reading
 *
 * Which person an actor rule is read against, and which reference on their
 * organisation record it reads.
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
 * @spec openspec/specs/approval-routes/spec.md (REQ-AR-013)
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Service;

/**
 * One rule, read against one person's record.
 *
 * @spec openspec/specs/approval-routes/spec.md (REQ-AR-013)
 */
final class ActorRuleReading {
	/**
	 * The reference the rule reads: manager or substitute.
	 *
	 * @var string
	 */
	public string $property = 'manager';

	/**
	 * Describe one reading; the property it reads is set beside it.
	 *
	 * @param string $rule   The rule being resolved, for the refusals.
	 * @param string $person The person the rule is read against.
	 */
	public function __construct(
		public readonly string $rule,
		public readonly string $person,
	) {
	}//end __construct()
}//end class
