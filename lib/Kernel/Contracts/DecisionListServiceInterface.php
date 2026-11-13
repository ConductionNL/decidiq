<?php

/**
 * Contract of DecisionListService
 *
 * The public surface of DecisionListService that other components call, so they depend
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

use OCA\Decidiq\Exception\CaseSystemException;

/**
 * Contract of DecisionListService.
 *
 * @category Kernel
 * @package  OCA\Decidiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/decidiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface DecisionListServiceInterface {

	/**
	 * Render the decision list and store it in the meeting folder.
	 *
	 * Without filinq the HTML is stored instead, with a note, as the minutes
	 * document does.
	 *
	 * @param string              $meetingId The meeting.
	 * @param array<string,mixed> $minutes   The minutes (approvedAt, signedBy).
	 *
	 * @spec openspec/specs/case-system-exchange/spec.md#requirement-req-csdx-004-approving-the-minutes-produces-a-decision-list-document
	 *
	 * @throws CaseSystemException When the meeting does not exist or the file cannot be stored.
	 *
	 * @return array{name:string,path:string,format:string,content:string,note?:string}
	 */
	public function render(string $meetingId, array $minutes): array;
}//end interface
