<?php

// VERBATIM COPY (minus its spec-traceability tags) of openregister lib/Service/Object/SavePolicy.php at ConductionNL/openregister@0168f4ab
// (openregister #9123), for standalone unit runs. OpenRegister is a sibling app,
// not a composer dependency; the real class wins whenever OpenRegister is
// loaded. Refresh by copying, never by editing.

/**
 * OpenRegister SavePolicy
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Object
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 */

namespace OCA\OpenRegister\Service\Object;

/**
 * The policy switches of one object save. Every default is the behaviour a caller gets without asking.
 */
final class SavePolicy {

	/**
	 * Constructor.
	 *
	 * @param bool $silent        Skip audit trail creation and events.
	 * @param bool $validation    Validate the object against its schema. A bulk importer with
	 *                            pre-validated rows may decline re-validating every one of them.
	 * @param bool $failIfExists  Insert-only: throw ObjectExistsException rather than update when the
	 *                            identifier is taken. False is an upsert.
	 * @param bool $dedupOverride Save through a blocking duplicate match, when the caller is entitled to.
	 *                            ObjectsController reads it from the raw request, because the body filter
	 *                            there strips `_`-prefixed keys.
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) Each flag is a documented save policy with a safe default.
	 */
	public function __construct(
		public readonly bool $silent=false,
		public readonly bool $validation=true,
		public readonly bool $failIfExists=false,
		public readonly bool $dedupOverride=false,
	) {
	}//end __construct()
}//end class
