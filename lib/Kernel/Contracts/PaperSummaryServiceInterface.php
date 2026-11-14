<?php

/**
 * Contract of PaperSummaryService
 *
 * The public surface of PaperSummaryService that other components call, so they depend
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

use OCA\Decidiq\Exception\PaperSummaryRefusedException;

/**
 * Contract of PaperSummaryService.
 *
 * @category Kernel
 * @package  OCA\Decidiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/decidiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface PaperSummaryServiceInterface {

	/**
	 * Which kinds an installed TaskProcessing provider can answer.
	 *
	 * @return array{available: bool, summary: bool, comparison: bool} Per kind, and whether any.
	 *
	 * @spec openspec/changes/agenda-ai-paper-summaries/specs/agenda-ai-paper-summaries/spec.md#requirement-req-aps-002-a-clerk-asks-for-a-summary-or-a-comparison-of-a-paper
	 */
	public function availability(): array;

	/**
	 * Whether the signed-in user may ask for summaries and review them.
	 *
	 * @return bool True for a clerk.
	 *
	 * @spec openspec/changes/agenda-ai-paper-summaries/specs/agenda-ai-paper-summaries/spec.md#requirement-req-aps-005-members-see-a-summary-only-after-a-clerk-shows-it
	 */
	public function canRequest(): bool;

	/**
	 * Ask for a summary of a paper, or a comparison of it with a second paper.
	 *
	 * @param string $agendaItemId The agenda item the papers are attached to
	 * @param int $fileId The paper's Nextcloud file id
	 * @param string $kind `summary` or `comparison`
	 * @param int|null $comparedFileId The second paper of a comparison
	 *
	 * @return array<string, mixed> The saved summary, status `requested`, with its id.
	 *
	 * @throws PaperSummaryRefusedException 401, 403, 422 or 503 with the reason
	 *
	 * @spec openspec/changes/agenda-ai-paper-summaries/specs/agenda-ai-paper-summaries/spec.md#requirement-req-aps-003-a-confidential-paper-is-not-summarised-outside-its-circle
	 */
	public function request(string $agendaItemId, int $fileId, string $kind, ?int $comparedFileId=null): array;
}//end interface
