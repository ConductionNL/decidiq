<?php

/**
 * Test stub for OpenRegister LeafIdentity.
 *
 * Stands in for OCA\OpenRegister\Service\Integration\LeafIdentity when
 * OpenRegister is not installed (standalone CI). Mirrors the real value
 * object's constructor signature. The real class ships with OpenRegister
 * (lib/Service/Integration/LeafIdentity.php).
 *
 * Checked against openregister `development` when written: same 8 constants, same
 * 10 parameters in the same order with the same defaults, same 11 public methods.
 * A stub that has drifted from the class it doubles reports on code that does not
 * exist — verify it against the real file before trusting a green run here.
 *
 * @category Test
 * @package  OCA\OpenRegister\Service\Integration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Integration;

final class LeafIdentity {
	public function __construct(
		public readonly string $id,
		public readonly string $label,
		public readonly string $icon,
		public readonly ?string $group = null,
	) {
	}
}
