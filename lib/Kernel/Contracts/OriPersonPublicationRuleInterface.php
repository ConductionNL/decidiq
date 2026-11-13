<?php

/**
 * Contract of OriPersonPublicationRule
 *
 * The public surface of OriPersonPublicationRule that other components call, so they depend
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
 * Contract of OriPersonPublicationRule.
 *
 * @category Kernel
 * @package  OCA\Decidiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/decidiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface OriPersonPublicationRuleInterface {

	/**
	 * One public person by id, or null when the rule withholds them.
	 *
	 * @param string $personId The person id
	 *
	 * @return array{id: string, name: ?string, image: ?string, biography: ?string}|null
	 *
	 * @spec openspec/specs/ori-api/spec.md#requirement-req-ori-007-the-public-ori-api-names-public-role-holders-only
	 */
	public function person(string $personId): ?array;

	/**
	 * The public people.
	 *
	 * @return list<array{id: string, name: ?string, image: ?string, biography: ?string}>
	 *
	 * @spec openspec/specs/ori-api/spec.md#requirement-req-ori-007-the-public-ori-api-names-public-role-holders-only
	 */
	public function persons(): array;
}//end interface
