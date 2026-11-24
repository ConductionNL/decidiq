<?php

/**
 * Contract of MeetingCommandService
 *
 * The public surface of MeetingCommandService that other components call, so they depend
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
 * Contract of MeetingCommandService.
 *
 * @category Kernel
 * @package  OCA\Decidiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/decidiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface MeetingCommandServiceInterface {

	/**
	 * Hold a meeting for a consumer's object, or update the one already held for it.
	 *
	 * @param array{register: string, schema: string, id: string} $subject The consumer's object.
	 * @param array<string, mixed>                                $meeting Meeting fields; MUST carry title and scheduledDate.
	 *
	 * @return array{id: string, created: bool} The resolved id and whether it was minted here.
	 *
	 * @throws RuntimeException When the command is incomplete or a write fails.
	 *
	 * @spec openspec/specs/meeting-requested-event/spec.md
	 */
	public function upsert(array $subject, array $meeting): array;
}//end interface
