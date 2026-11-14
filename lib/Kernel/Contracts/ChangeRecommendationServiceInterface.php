<?php

/**
 * Contract of ChangeRecommendationService
 *
 * The public surface of ChangeRecommendationService that other components call, so they depend
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

use OCA\Decidiq\Exception\ChangeRecommendationRefusedException;

/**
 * Contract of ChangeRecommendationService.
 *
 * @category Kernel
 * @package  OCA\Decidiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/decidiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ChangeRecommendationServiceInterface {

	/**
	 * The motion a recommendation belongs to, or null when it does not exist.
	 *
	 * @param string $recommendationId The recommendation uuid
	 *
	 * @return string|null The motion uuid.
	 *
	 * @spec openspec/changes/motion-change-recommendations/specs/motion-amendment/spec.md#requirement-req-mcr-003-the-chair-or-secretary-accepts-a-recommendation-into-the-text-without-a-vote
	 */
	public function motionIdOf(string $recommendationId): ?string;

	/**
	 * Work a pending recommendation into its motion's text and mark it accepted.
	 *
	 * @param string $recommendationId The recommendation uuid
	 * @param string $deciderUid       The chair or secretary accepting it
	 *
	 * @return array{recommendation: array<string, mixed>, motion: array<string, mixed>}
	 *
	 * @throws ChangeRecommendationRefusedException When it may not be accepted.
	 *
	 * @spec openspec/changes/motion-change-recommendations/specs/motion-amendment/spec.md#requirement-req-mcr-003-the-chair-or-secretary-accepts-a-recommendation-into-the-text-without-a-vote
	 */
	public function accept(string $recommendationId, string $deciderUid): array;

	/**
	 * Mark a pending recommendation rejected; the motion is not touched.
	 *
	 * @param string $recommendationId The recommendation uuid
	 * @param string $deciderUid       The chair or secretary rejecting it
	 *
	 * @return array<string, mixed> The rejected recommendation.
	 *
	 * @throws ChangeRecommendationRefusedException When it may not be rejected.
	 *
	 * @spec openspec/changes/motion-change-recommendations/specs/motion-amendment/spec.md#requirement-req-mcr-004-a-rejected-recommendation-changes-nothing
	 */
	public function reject(string $recommendationId, string $deciderUid): array;
}//end interface
