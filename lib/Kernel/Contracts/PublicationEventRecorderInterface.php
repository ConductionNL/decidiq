<?php

/**
 * Contract of PublicationEventRecorder
 *
 * The public surface of PublicationEventRecorder that other components call, so they depend
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
 * Contract of PublicationEventRecorder.
 *
 * @category Kernel
 * @package  OCA\Decidiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/decidiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface PublicationEventRecorderInterface {

	/**
	 * A paper was added to a meeting or agenda item whose meeting has a published agenda.
	 *
	 * Before the agenda is published nothing is recorded: the publish itself
	 * is the news then. Residents do not hear of a paper here; the public
	 * agenda publication carries its public papers.
	 *
	 * @param string $schema   meeting or agenda-item, the object whose folder holds the paper
	 * @param string $objectId That object
	 * @param string $fileName The paper's file name
	 *
	 * @return void
	 *
	 * @spec openspec/specs/public-publication/spec.md#requirement-req-psd-002-agendas-papers-decisions-and-minutes-are-recorded-as-events
	 */
	public function paperAdded(string $schema, string $objectId, string $fileName): void;
}//end interface
