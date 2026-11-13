<?php

/**
 * Contract of SeedProfileService
 *
 * The public surface of SeedProfileService that other components call, so they depend
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
 * Contract of SeedProfileService.
 *
 * @category Kernel
 * @package  OCA\Decidiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/decidiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface SeedProfileServiceInterface {

	/**
	 * The answer that means "plant nothing".
	 *
	 * 🔴 NOT THE ABSENCE OF AN ANSWER. An operator who declines has FINISHED the
	 * step; a step that can never be marked done reopens the wizard over every
	 * page (nextcloud-vue#806).
	 *
	 * @var string
	 */
	public const NONE_PROFILE = 'none';

	/**
	 * Import one example set.
	 *
	 * 🔴 THROWS RATHER THAN RETURNING A QUIET FAILURE. An operator just asked for
	 * this, so "nothing happened" must not be presentable as success.
	 *
	 * Safe to run more than once: OpenRegister's seed import matches an existing
	 * object by slug before it creates one, so a repeat adds nothing.
	 *
	 * @param string $profileId The set to import.
	 *
	 * @return array{objects: integer, profile: string} What was imported.
	 *
	 * @throws RuntimeException When the id is unknown or OpenRegister is absent.
	 *
	 * @spec openspec/specs/seed-profiles/spec.md#requirement-import-one-example-set
	 */
	public function install(string $profileId): array;

	/**
	 * Whether an id names a set this app can actually import.
	 *
	 * @param string $profileId The id to test.
	 *
	 * @return boolean True when the id is importable.
	 *
	 * @spec openspec/specs/example-set-cards/spec.md#requirement-the-wizard-offers-the-sets-the-app-ships
	 */
	public function isKnown(string $profileId): bool;

	/**
	 * Every answer the wizard may offer, declining included.
	 *
	 * 🔴 SEPARATE FROM `listProfiles()` ON PURPOSE. `none` is an ANSWER, not a
	 * set: `isKnown()` must keep saying no to it, or `install()` would be asked
	 * to import a descriptor that does not exist. This list is for the choice
	 * step; that one is for the importer.
	 *
	 * @return array<int, array{id: string, label: string, description: string, objectCount: integer, icon: string}> The answers.
	 *
	 * @spec openspec/specs/example-set-cards/spec.md#requirement-the-wizard-offers-the-sets-the-app-ships
	 */
	public function listChoices(): array;
}//end interface
