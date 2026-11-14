<?php

/**
 * Contract of CaseDocumentService
 *
 * The public surface of CaseDocumentService that other components call, so they depend
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
 * Contract of CaseDocumentService.
 *
 * @category Kernel
 * @package  OCA\Decidiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/decidiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface CaseDocumentServiceInterface {

	/**
	 * Copy the chosen case documents into the item's files, skipping any
	 * fetched before.
	 *
	 * @param string       $itemId The agenda item.
	 * @param list<string> $urls   The documents' addresses.
	 * @param string       $userId Who fetched.
	 *
	 * @spec openspec/specs/case-system-exchange/spec.md#requirement-req-csdx-003-the-griffier-fetches-case-documents-onto-the-item-once-each
	 *
	 * @throws CaseSystemException When the item has no case, or no document was chosen.
	 *
	 * @return array<string,mixed> The exchange record.
	 */
	public function fetch(string $itemId, array $urls, string $userId): array;

	/**
	 * Link an agenda item to a case, after reading the case once.
	 *
	 * @param string $itemId    The agenda item.
	 * @param string $reference The case number or address.
	 *
	 * @spec openspec/specs/case-system-exchange/spec.md#requirement-req-csdx-002-an-agenda-item-links-to-its-case
	 *
	 * @throws CaseSystemException When the case does not exist or the case system refuses.
	 *
	 * @return array{url:string,identification:string,title:string} The stored case reference.
	 */
	public function link(string $itemId, string $reference): array;

	/**
	 * The case's documents, each marked when it was fetched before.
	 *
	 * @param string $itemId The agenda item.
	 *
	 * @spec openspec/specs/case-system-exchange/spec.md#requirement-req-csdx-003-the-griffier-fetches-case-documents-onto-the-item-once-each
	 *
	 * @throws CaseSystemException When the item has no case or the case system refuses.
	 *
	 * @return list<array{url:string,name:string,fetched:bool}>
	 */
	public function listDocuments(string $itemId): array;
}//end interface
