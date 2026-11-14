<?php

/**
 * Contract of ExportBundleWriter
 *
 * The public surface of ExportBundleWriter that other components call, so they depend
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
 * Contract of ExportBundleWriter.
 *
 * @category Kernel
 * @package  OCA\Decidiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/decidiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ExportBundleWriterInterface {

	/**
	 * Remove the rendered text pages once the merge no longer needs them.
	 *
	 * @param string    $uid   The requester.
	 * @param list<int> $pages The page file ids.
	 *
	 * @spec openspec/specs/motion-management/spec.md#requirement-req-mxp-003-a-large-export-runs-in-the-background-and-says-when-it-is-ready
	 *
	 * @return void
	 */
	public function removePages(string $uid, array $pages): void;
}//end interface
