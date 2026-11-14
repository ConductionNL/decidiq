<?php

/**
 * Contract of OriFeedService
 *
 * The public surface of OriFeedService that other components call, so they depend
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
 * Contract of OriFeedService.
 *
 * @category Kernel
 * @package  OCA\Decidiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/decidiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface OriFeedServiceInterface {

	/**
	 * The media type every feed is served with.
	 *
	 * @var string
	 */
	public const CONTENT_TYPE = 'application/rss+xml; charset=utf-8';

	/**
	 * The RSS feed of the public meetings.
	 *
	 * @return string
	 */
	public function meetings(): string;

	/**
	 * The RSS feed of the public agenda items.
	 *
	 * @return string
	 */
	public function agendaItems(): string;

	/**
	 * The RSS feed of the public documents.
	 *
	 * @return string
	 */
	public function documents(): string;
}//end interface
