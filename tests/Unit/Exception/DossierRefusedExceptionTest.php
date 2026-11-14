<?php

/**
 * Which dossier refusals are conflicts, and what a refusal carries.
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\Exception
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Exception;


use OCA\Decidiq\Exception\DossierRefusedException;
use PHPUnit\Framework\TestCase;

/**
 * A refusal that the dossier's state causes is a conflict; one the request causes is not.
 *
 * @covers \OCA\Decidiq\Exception\DossierRefusedException
 */
class DossierRefusedExceptionTest extends TestCase {

	/**
	 * Every reason, and whether it is a conflict.
	 *
	 * @return array<string, array{0: string, 1: bool}>
	 */
	public static function reasons(): array {
		return [
			'frozen' => [DossierRefusedException::FROZEN, true],
			'not closed' => [DossierRefusedException::NOT_CLOSED, true],
			'already proposed' => [DossierRefusedException::ALREADY_PROPOSED, true],
			'transfer unavailable' => [DossierRefusedException::TRANSFER_UNAVAILABLE, true],
			'destruction unavailable' => [DossierRefusedException::DESTRUCTION_UNAVAILABLE, true],
			'certificate missing' => [DossierRefusedException::CERTIFICATE_MISSING, true],
			'gaps' => [DossierRefusedException::GAPS, false],
			'nothing eligible' => [DossierRefusedException::NOTHING_ELIGIBLE, false],
			'no category' => [DossierRefusedException::NO_CATEGORY, false],
			'unknown reason' => ['something-else', false],
		];
	}//end reasons()

	/**
	 * Each reason is or is not a conflict.
	 *
	 * @param string $reason   The reason.
	 * @param bool   $conflict Whether it is a conflict.
	 *
	 * @return void
	 *
	 * @dataProvider reasons
	 */
	public function testWhichReasonsAreConflicts(string $reason, bool $conflict): void {
		self::assertSame($conflict, (new DossierRefusedException(message: 'Refused', reason: $reason))->isConflict());
	}//end testWhichReasonsAreConflicts()

	/**
	 * The reason strings are the ones callers and clients match on.
	 *
	 * @return void
	 */
	public function testTheReasonStringsAreStable(): void {
		self::assertSame(
			['frozen', 'gaps', 'not-closed', 'already-proposed', 'transfer-unavailable', 'destruction-unavailable', 'nothing-eligible', 'no-category', 'certificate-missing'],
			[
				DossierRefusedException::FROZEN,
				DossierRefusedException::GAPS,
				DossierRefusedException::NOT_CLOSED,
				DossierRefusedException::ALREADY_PROPOSED,
				DossierRefusedException::TRANSFER_UNAVAILABLE,
				DossierRefusedException::DESTRUCTION_UNAVAILABLE,
				DossierRefusedException::NOTHING_ELIGIBLE,
				DossierRefusedException::NO_CATEGORY,
				DossierRefusedException::CERTIFICATE_MISSING,
			]
		);
	}//end testTheReasonStringsAreStable()

	/**
	 * A refusal carries its message, reason and gaps, and no gaps by default.
	 *
	 * @return void
	 */
	public function testARefusalCarriesItsGaps(): void {
		$gaps = [['field' => 'archiveCategory', 'message' => 'missing']];
		$refusal = new DossierRefusedException(message: 'The dossier has gaps', reason: DossierRefusedException::GAPS, gaps: $gaps);

		self::assertSame('The dossier has gaps', $refusal->getMessage());
		self::assertSame('gaps', $refusal->getReason());
		self::assertSame($gaps, $refusal->getGaps());
		self::assertSame([], (new DossierRefusedException(message: 'x', reason: DossierRefusedException::FROZEN))->getGaps());
	}//end testARefusalCarriesItsGaps()
}//end class
