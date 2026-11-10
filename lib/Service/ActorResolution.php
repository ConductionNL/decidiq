<?php

/**
 * Actor Resolution
 *
 * One request to turn a step's actor rule into a person: the step, the
 * organisation records to read, and what the rule may need besides.
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

use Closure;

/**
 * What ApprovalActorResolver::resolve() resolves.
 *
 * @spec openspec/specs/approval-routes/spec.md (REQ-AR-013)
 */
final class ActorResolution {
	/**
	 * Describe one resolution.
	 *
	 * @param array<string, mixed>             $step         The step carrying the rule.
	 * @param array<int, array<string, mixed>> $people       The person records readable from the organisation register.
	 * @param string                           $subjectOwner Who owns the subject, for the rule that reads it.
	 * @param Closure(string): bool|null       $hasAccount   Whether a person id has an account on this instance.
	 * @param string                           $resolvedAt   When, as an ISO-8601 instant; now when empty.
	 */
	public function __construct(
		public readonly array $step,
		public readonly array $people,
		public readonly string $subjectOwner = '',
		public readonly ?Closure $hasAccount = null,
		public readonly string $resolvedAt = '',
	) {
	}//end __construct()
}//end class
