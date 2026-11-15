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
	 * Who owns the subject, for the rule that reads it.
	 *
	 * @var string
	 */
	public string $subjectOwner = '';

	/**
	 * Whether a person id has an account on this instance; null skips the check.
	 *
	 * @var (Closure(string): bool)|null
	 */
	public ?Closure $hasAccount = null;

	/**
	 * When, as an ISO-8601 instant; now when empty.
	 *
	 * @var string
	 */
	public string $resolvedAt = '';

	/**
	 * Describe one resolution; what the rule may need besides is set beside it.
	 *
	 * @param array<string, mixed>             $step   The step carrying the rule.
	 * @param array<int, array<string, mixed>> $people The person records readable from the organisation register.
	 */
	public function __construct(
		public readonly array $step,
		public readonly array $people,
	) {
	}//end __construct()
}//end class
