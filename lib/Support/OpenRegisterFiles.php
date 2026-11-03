<?php

/**
 * OpenRegisterFiles: whether OpenRegister is installed, and its file service.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Support
 * @package  OCA\Decidiq\Support
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Decidiq\Support;

use OCP\App\IAppManager;
use Psr\Container\ContainerInterface;

/**
 * The files OpenRegister keeps for its objects: whether the app is there to
 * hold them, and the file service that reads them.
 *
 * Not `final`: it is an injected collaborator, and PHPUnit cannot double a
 * final class.
 *
 * @spec exclude infrastructure utility with no feature requirement of its own; it is
 *   exercised through the exports and meeting files that call it
 */
class OpenRegisterFiles {

	/**
	 * Constructor.
	 *
	 * @param IAppManager        $appManager Whether OpenRegister is installed
	 * @param ContainerInterface $container  Resolves OpenRegister's FileService
	 *
	 * @spec exclude infrastructure utility; exercised through its callers
	 */
	public function __construct(
		private readonly IAppManager $appManager,
		private readonly ContainerInterface $container,
	) {
	}//end __construct()

	/**
	 * Whether OpenRegister, which holds the files, is installed.
	 *
	 * @return bool
	 *
	 * @spec exclude infrastructure utility; exercised through its callers
	 */
	public function installed(): bool {
		return $this->appManager->isInstalled('openregister');
	}//end installed()

	/**
	 * OpenRegister's FileService.
	 *
	 * @return object
	 *
	 * @spec exclude infrastructure utility; exercised through its callers
	 */
	public function fileService(): object {
		return $this->container->get('OCA\OpenRegister\Service\FileService');
	}//end fileService()
}//end class
