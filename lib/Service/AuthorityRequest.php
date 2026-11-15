<?php

/**
 * Decidiq authority request.
 *
 * The question DelegatedAuthorityCheck answers, as one value: which account
 * wants to perform which act, for which amount, at which moment.
 *
 * @category Service
 * @package  OCA\Decidiq\Service
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
 * @spec openspec/changes/delegated-authority-check/specs/delegatie-mandaatregister/spec.md
 */

declare(strict_types=1);

namespace OCA\Decidiq\Service;

use DateTimeImmutable;

/**
 * One authority question: actor, act, amount and moment.
 *
 * @spec openspec/changes/delegated-authority-check/specs/delegatie-mandaatregister/spec.md
 */
final class AuthorityRequest {

	/**
	 * Constructor.
	 *
	 * @param string            $actor  The Nextcloud account that wants to act (trimmed).
	 * @param string            $act    The act key (trimmed).
	 * @param float|null        $amount The amount in euro the act concerns, or null when it has none.
	 * @param DateTimeImmutable $moment The moment of the act.
	 */
	public function __construct(
		public readonly string $actor,
		public readonly string $act,
		public readonly ?float $amount,
		public readonly DateTimeImmutable $moment,
	) {
	}//end __construct()
}//end class
