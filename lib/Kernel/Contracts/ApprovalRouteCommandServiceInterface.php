<?php

/**
 * Contract of ApprovalRouteCommandService
 *
 * The public surface of ApprovalRouteCommandService that other components call, so they depend
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

use RuntimeException;

/**
 * Contract of ApprovalRouteCommandService.
 *
 * @category Kernel
 * @package  OCA\Decidiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/decidiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ApprovalRouteCommandServiceInterface {

	/**
	 * Hold a route template, and optionally start a subject travelling it.
	 *
	 * @param string $sourceApp App id of the producer.
	 * @param string $externalReference The producer's own id for the route.
	 * @param array<string, mixed> $template The template: name, steps and the optional descriptors.
	 * @param string $subject Optional subject to instantiate against.
	 * @param string $subjectSchema Schema slug of that subject.
	 *
	 * @return array{id: string, created: bool, stageCount: int} What happened.
	 *
	 * @throws RuntimeException When the command is incomplete or a write fails.
	 *
	 * @spec openspec/specs/approval-route-events/spec.md
	 */
	public function holdRoute(
		string $sourceApp,
		string $externalReference,
		array $template,
		string $subject = '',
		string $subjectSchema = '',
	): array;

	/**
	 * Record one action against a subject already travelling a route.
	 *
	 * @param array<string, mixed> $action The action, in the engine's own shape.
	 *
	 * @return array{recorded: bool, completed: bool} What happened.
	 *
	 * @throws RuntimeException When the engine refuses.
	 *
	 * @spec openspec/specs/approval-route-events/spec.md
	 */
	public function recordAction(array $action): array;
}//end interface
