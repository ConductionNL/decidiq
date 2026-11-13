<?php

/**
 * Contract of ApprovalRouteConclusionAnnouncer
 *
 * The public surface of ApprovalRouteConclusionAnnouncer that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category  Kernel
 * @package   OCA\Decidiq\Kernel\Contracts
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/decidiq
 *
 * @spec exclude Structural contract, no functional requirement.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Kernel\Contracts;

/**
 * Contract of ApprovalRouteConclusionAnnouncer.
 *
 * @category Kernel
 * @package  OCA\Decidiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/decidiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ApprovalRouteConclusionAnnouncerInterface {

	/**
	 * Announce the subject's conclusion, when it has one.
	 *
	 * Safe to call after every recorded action: a route that still holds an
	 * active stage is not concluded and announces nothing, and a subject with
	 * no stages at all never travelled a route.
	 *
	 * Never throws: the action that concluded the route is already recorded,
	 * and an announcement failure must not read as a refusal of the signature.
	 *
	 * @param string $subject The subject uuid.
	 * @param string $correlationId Correlation id to echo when the caller holds
	 *        one (the cross-app command path does); resolved from the route's
	 *        external reference otherwise.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/parafering-route-runtime/spec.md
	 */
	public function announceIfConcluded(string $subject, string $correlationId = ''): void;
}//end interface
