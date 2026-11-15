<?php

/**
 * Contract of TranscriptRepository
 *
 * The public surface of TranscriptRepository that other components call, so they depend
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

use OCA\Decidiq\Exception\MissingObjectException;
use RuntimeException;

/**
 * Contract of TranscriptRepository.
 *
 * @category Kernel
 * @package  OCA\Decidiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/decidiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface TranscriptRepositoryInterface {

	/**
	 * Fetch a transcript object by id (throws when absent).
	 *
	 * @param string $transcriptId Transcript UUID.
	 *
	 * @return array<string,mixed> The transcript object.
	 *
	 * @throws MissingObjectException When not found.
	 *
	 * @spec openspec/specs/meeting-transcription/spec.md
	 */
	public function fetchTranscript(string $transcriptId): array;

	/**
	 * Resolve the source File node from a Files path.
	 *
	 * @param string $path Path of the source file in the meeting folder.
	 *
	 * @return \OCP\Files\File The resolved file node.
	 *
	 * @throws RuntimeException When the file cannot be resolved.
	 *
	 * @spec openspec/specs/meeting-transcription/spec.md
	 */
	public function resolveSourceNode(string $path): \OCP\Files\File;
}//end interface
