<?php

/**
 * Contract of MinutesDocumentService
 *
 * The public surface of MinutesDocumentService that other components call, so they depend
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

use InvalidArgumentException;
use OCA\Decidiq\Exception\MissingObjectException;
use OCA\Decidiq\Exception\MissingRelationException;
use RuntimeException;

/**
 * Contract of MinutesDocumentService.
 *
 * @category Kernel
 * @package  OCA\Decidiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/decidiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface MinutesDocumentServiceInterface {

	/**
	 * Generate a minutes document and persist it into the meeting folder.
	 *
	 * Content resolution: the minutes' own `content` when non-empty, otherwise
	 * the generated draft (which auto-inserts voting results from the voting
	 * system); the live `itemNotes` captured during the meeting are appended
	 * as a "Live notes" section either way.
	 *
	 * Format `pdf` uses Docudesk's PdfService when resolvable; when Docudesk
	 * is absent (or rendering fails) the markdown document is persisted
	 * instead and the result carries `docudesk: false` plus an honest note.
	 *
	 * @param string $minutesId UUID of the Minutes object
	 * @param string $format Requested format ('markdown' or 'pdf')
	 * @param string $displayName Display name of the requesting user (server session)
	 *
	 * @throws MissingObjectException When the Minutes object is not found
	 * @throws MissingRelationException When no Meeting is linked to the Minutes
	 * @throws InvalidArgumentException When the format is not supported
	 * @throws RuntimeException When OpenRegister or Files is unavailable
	 *
	 * @return array<string,mixed> Keys: path, format, docudesk, and optionally note.
	 *
	 * @spec openspec/specs/resolution-minutes/spec.md
	 */
	public function generate(string $minutesId, string $format, string $displayName): array;
}//end interface
