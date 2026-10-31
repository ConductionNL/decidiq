<?php

/**
 * Decidiq SystemWhenNobodySignedIn
 *
 * Runs an operation as the system when nobody is signed in, and as the
 * signed-in user otherwise. A notice that stamps a meeting runs from cron, or
 * from `occ` when an example set writes agenda items; there the acting user is
 * anonymous and OpenRegister's RBAC refuses the meeting update ("User
 * 'Anonymous' does not have permission to 'update' objects in schema
 * 'Meeting'"). With a user signed in the operation keeps that user's rights.
 *
 * @category Service
 * @package  OCA\Decidiq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/decidesk-notifications/spec.md#requirement-a-notice-sent-with-nobody-signed-in-runs-as-the-system-req-mrd-010
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Service;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCP\IUserSession;

/**
 * Elevates to the system only where there is no user to act as.
 *
 * @spec openspec/specs/decidesk-notifications/spec.md#requirement-a-notice-sent-with-nobody-signed-in-runs-as-the-system-req-mrd-010
 */
class SystemWhenNobodySignedIn {

	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface $objectService OpenRegister, which runs the operation as the system
	 * @param IUserSession           $userSession   Who is signed in, if anyone
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		private readonly IUserSession $userSession,
	) {
	}//end __construct()

	/**
	 * Run the operation, as the system when nobody is signed in.
	 *
	 * @param callable $operation The operation.
	 *
	 * @return mixed What the operation returns.
	 *
	 * @spec openspec/specs/decidesk-notifications/spec.md#requirement-a-notice-sent-with-nobody-signed-in-runs-as-the-system-req-mrd-010
	 */
	public function run(callable $operation): mixed {
		if ($this->userSession->getUser() !== null) {
			return $operation();
		}

		return $this->objectService->runAsSystem($operation);
	}//end run()
}//end class
