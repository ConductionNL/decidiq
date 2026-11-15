<?php

/**
 * Decidiq outcome subscription request
 *
 * One validated outcome-callback subscription as IntegrationController hands
 * it to DecisionIntegrationService: the decision, the callback URL and the
 * subscribing account.
 *
 * @category Controller
 * @package  OCA\Decidiq\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/decidesk-contract-decision-hub/tasks.md#phase-2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Controller;

/**
 * A validated outcome-callback subscription.
 *
 * @spec openspec/changes/decidesk-contract-decision-hub/tasks.md#phase-2
 */
final class OutcomeSubscriptionRequest {

	/**
	 * Constructor.
	 *
	 * @param string $decisionId  The decision to subscribe to.
	 * @param string $callbackUrl The non-empty callback URL.
	 * @param string $actorId     The subscribing account's UID.
	 */
	public function __construct(
		public readonly string $decisionId,
		public readonly string $callbackUrl,
		public readonly string $actorId,
	) {
	}//end __construct()
}//end class
