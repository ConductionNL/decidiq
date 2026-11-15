<?php

/**
 * Contract of MinutesDraftService
 *
 * The public surface of MinutesDraftService that other components call, so they depend
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

use DomainException;
use OCA\Decidiq\Exception\MissingObjectException;

/**
 * Contract of MinutesDraftService.
 *
 * @category Kernel
 * @package  OCA\Decidiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/decidiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface MinutesDraftServiceInterface {

	/**
	 * Generate a draft from a transcript.
	 *
	 * Builds the per-agenda-item draft structure (or a flat whole-meeting
	 * section when no timeline exists), runs each prompt through the AI
	 * provider, cross-checks suggestions against the recorded record, stamps
	 * provenance, and records draftGeneration provenance back on the Transcript.
	 *
	 * @param string $transcriptId Transcript UUID.
	 *
	 * @return array<string,mixed> The draft structure for the minutes editor.
	 *
	 * @throws MissingObjectException When the Transcript cannot be found.
	 * @throws DomainException When no AI provider is available (code 503).
	 *
	 * @spec openspec/specs/meeting-transcription/spec.md
	 */
	public function generate(string $transcriptId): array;

	/**
	 * Whether a TaskProcessing/AI provider is available on this instance.
	 *
	 * Provider absence is a first-class state that HIDES the generate action.
	 *
	 * @return bool True when at least one TaskProcessing provider is registered.
	 *
	 * @spec openspec/specs/meeting-transcription/spec.md
	 */
	public function isProviderAvailable(): bool;
}//end interface
