<?php

/**
 * Decidiq answered approval task.
 *
 * The completed projected task ApprovalTaskDecisionListener turns into an
 * engine action, with the subject it decides and the identity that answered
 * it, read once from the duck-typed task.
 *
 * @category Listener
 * @package  OCA\Decidiq\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/parafering-route-runtime/specs/parafering-route-runtime/spec.md
 */

declare(strict_types=1);

namespace OCA\Decidiq\Listener;

/**
 * A completed projected approval task with its subject and completer.
 *
 * @spec openspec/specs/parafering-route-runtime/spec.md
 */
final class AnsweredApprovalTask {

	/**
	 * Constructor.
	 *
	 * @param object $task    The completed OpenRegister task.
	 * @param string $subject The subject uuid the task decides.
	 * @param string $actor   The identity that completed the task.
	 */
	public function __construct(
		public readonly object $task,
		public readonly string $subject,
		public readonly string $actor,
	) {
	}//end __construct()
}//end class
