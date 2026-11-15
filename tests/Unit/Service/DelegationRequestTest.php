<?php

/**
 * Unit tests for the DelegationRequest value object.
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service;

use OCA\Decidiq\Service\DelegationRequest;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Decidiq\Service\DelegationRequest
 */
class DelegationRequestTest extends TestCase {

	/**
	 * @return array<string, array{0: mixed, 1: mixed, 2: mixed, 3: bool, 4: bool}>
	 */
	public static function requests(): array {
		return [
			'nothing sent' => [null, null, null, true, false],
			'empty delegate clears' => ['', null, null, false, true],
			'empty delegate with dates still clears' => ['', '2026-01-01', '2026-02-01', false, true],
			'named delegate' => ['bob', null, null, false, false],
			'only a start date' => [null, '2026-01-01', null, false, false],
			'only an end date' => [null, null, '2026-02-01', false, false],
			'zero delegate is not empty' => [0, null, null, false, false],
		];

	}//end requests()

	/**
	 * @dataProvider requests
	 */
	public function testAbsentAndClearing(mixed $delegate, mixed $from, mixed $until, bool $absent, bool $clears): void {
		$request = new DelegationRequest(delegate: $delegate, from: $from, until: $until);

		$this->assertSame($absent, $request->isAbsent());
		$this->assertSame($clears, $request->clears());

	}//end testAbsentAndClearing()
}//end class
