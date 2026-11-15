<?php

/**
 * Contract of RecordingRange
 *
 * The public surface of RecordingRange that other components call, so they depend
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
 * Contract of RecordingRange.
 *
 * @category Kernel
 * @package  OCA\Decidiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/decidiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface RecordingRangeInterface {

	/**
	 * The byte range a Range header asks for.
	 *
	 * Serves one range: "bytes=a-", "bytes=a-b" or "bytes=-n". Anything else,
	 * several ranges included, is answered with the whole file.
	 *
	 * @param string|null $header The Range header, or null when absent
	 * @param int         $size   The file size in bytes
	 *
	 * @return array{0: int, 1: int}|null|false The first and last byte; null for the whole file; false when the range cannot be served
	 *
	 * @spec openspec/specs/meeting-transcription/spec.md#requirement-req-lrj-001-jump-to-an-item-in-the-recording
	 */
	public function parse(?string $header, int $size): array|null|false;
}//end interface
