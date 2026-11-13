<?php

/**
 * Contract of FullExportService
 *
 * The public surface of FullExportService that other components call, so they depend
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

use OCP\Files\NotFoundException;
use OCP\Files\SimpleFS\ISimpleFile;
use RuntimeException;

/**
 * Contract of FullExportService.
 *
 * @category Kernel
 * @package  OCA\Decidiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/decidiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface FullExportServiceInterface {

	/**
	 * Build the export and keep it as the only one in app data.
	 *
	 * @spec openspec/specs/openregister-integration/spec.md#requirement-req-pfe-001-an-administrator-exports-all-data
	 *
	 * @throws RuntimeException When the archive cannot be written.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) SettingsService::shippedRegisterDescriptor() is a pure read of the schemas this app ships.
	 *
	 * @return string The export's file name.
	 */
	public function build(): string;

	/**
	 * The newest export, or null when there is none.
	 *
	 * @spec openspec/specs/openregister-integration/spec.md#requirement-req-pfe-001-an-administrator-exports-all-data
	 *
	 * @return array{name:string,size:int,createdAt:string}|null
	 */
	public function latest(): ?array;

	/**
	 * Open an export by name.
	 *
	 * @param string $name The export's file name.
	 *
	 * @spec openspec/specs/openregister-integration/spec.md#requirement-req-pfe-001-an-administrator-exports-all-data
	 *
	 * @throws NotFoundException When the name is not an export or it is gone.
	 *
	 * @return ISimpleFile
	 */
	public function open(string $name): ISimpleFile;
}//end interface
