<?php

/**
 * Decidiq RouteStagesRun
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
 * @spec openspec/specs/approval-routes/spec.md
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Decidiq\Service;

/**
 * One route being laid onto a subject as stages: the subject and its schema,
 * the route the stages come from, the first parallel group's sequence, and
 * the sequence of the step whose stage is being written. Built by
 * ApprovalRouteService::instantiate and never handed out.
 *
 * @spec openspec/specs/approval-routes/spec.md
 */
final class RouteStagesRun {

	/**
	 * The route's id, '' for an ad-hoc route.
	 *
	 * @var string
	 */
	public string $routeId = '';

	/**
	 * The sequence of the first parallel group; its stages start active.
	 *
	 * @var int
	 */
	public int $firstSequence = 0;

	/**
	 * The sequence of the step whose stage is being written.
	 *
	 * @var int
	 */
	public int $sequence = 0;

	/**
	 * Start laying a route onto a subject.
	 *
	 * @param string $subject       The subject the route travels.
	 * @param string $subjectSchema The subject's schema.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly string $subject,
		public readonly string $subjectSchema,
	) {
	}//end __construct()
}//end class
