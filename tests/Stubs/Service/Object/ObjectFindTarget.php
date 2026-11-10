<?php

// VERBATIM COPY (minus its spec-traceability tags) of openregister lib/Service/Object/ObjectFindTarget.php at ConductionNL/openregister@5b0839f6
// (openregister #9099), for standalone unit runs. OpenRegister is a sibling app,
// not a composer dependency; the real class wins whenever OpenRegister is
// loaded. Refresh by copying, never by editing.

/**
 * OpenRegister ObjectFindTarget
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

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object;

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;

/**
 * The register and schema one ObjectService::find() call names, as the caller gave them.
 */
final class ObjectFindTarget {

	/**
	 * Constructor.
	 *
	 * @param Register|string|int|null $register The register object or its ID/UUID.
	 * @param Schema|string|int|null   $schema   The schema object or its ID/UUID.
	 */
	public function __construct(
		public readonly Register|string|int|null $register=null,
		public readonly Schema|string|int|null $schema=null,
	) {
	}//end __construct()
}//end class
