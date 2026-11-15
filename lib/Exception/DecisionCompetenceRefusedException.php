<?php

/**
 * Decidiq Decision Competence Refused Exception
 *
 * A decision that may not be recorded because the body taking it is not the
 * body its decision type names as competent, or because no competent body can
 * be established at all.
 *
 * @category Exception
 * @package  OCA\Decidiq\Exception
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/configurable-types-domain-model/specs/configurable-types-domain-model/spec.md#requirement-req-ctm-004-competence-is-enforced-at-the-write-path-not-merely-declared
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Exception;

/**
 * A decision write refused for competence.
 *
 * @spec openspec/changes/configurable-types-domain-model/specs/configurable-types-domain-model/spec.md#requirement-req-ctm-004-competence-is-enforced-at-the-write-path-not-merely-declared
 */
class DecisionCompetenceRefusedException extends \RuntimeException {
}//end class
