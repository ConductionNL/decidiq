<?php

/**
 * Contract of ConfidentialPapers
 *
 * The public surface of ConfidentialPapers that other components call, so they depend
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
 * Contract of ConfidentialPapers.
 *
 * @category Kernel
 * @package  OCA\Decidiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/decidiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ConfidentialPapersInterface {

	/**
	 * The app setting that holds the trust a confidential paper needs.
	 */
	public const MIN_TRUST_KEY = 'portal_confidential_min_trust';

	/**
	 * The trust a portal session needs to see confidential papers: `substantial` unset, else `high`.
	 *
	 * @return string `substantial` or `high`.
	 *
	 * @spec openspec/changes/portal-voting-record-and-confidential-papers/specs/portal-contribution/spec.md#requirement-req-psh-003-confidential-papers-reach-only-a-named-reader-at-the-declared-trust-level
	 */
	public function minTrust(): string;

	/**
	 * The papers of a non-public agenda item, as portal document entries.
	 *
	 * @param string $itemId The agenda item id, uuid or slug.
	 *
	 * @return list<array<string, mixed>> `{id, title, kind, date, file, mimeType?}` per paper, [] once public.
	 *
	 * @spec openspec/changes/portal-voting-record-and-confidential-papers/specs/portal-contribution/spec.md#requirement-req-psh-003-confidential-papers-reach-only-a-named-reader-at-the-declared-trust-level
	 */
	public function papers(string $itemId): array;

	/**
	 * Write the `material-access` audit entry for one portal opening of a paper.
	 *
	 * @param string               $itemId     The agenda item id.
	 * @param string               $documentId The paper id.
	 * @param array<string, mixed> $subject    The portal session: subjectRef, trust, identityType, audience.
	 *
	 * @return bool True only when the entry was written.
	 *
	 * @spec openspec/changes/portal-voting-record-and-confidential-papers/specs/portal-contribution/spec.md#requirement-req-psh-004-every-portal-opening-of-a-confidential-paper-is-in-decidiqs-audit-log-first
	 */
	public function opened(string $itemId, string $documentId, array $subject): bool;
}//end interface
