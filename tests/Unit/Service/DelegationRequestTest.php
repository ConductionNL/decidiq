<?php

/**
 * Unit tests for DelegationRequest: an absent request versus one that clears the delegate.
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service;

use OCA\Decidiq\Service\DelegationRequest;
use PHPUnit\Framework\TestCase;

/**
 * A request with nothing in it changes nothing; an empty delegate clears it.
 *
 * @covers \OCA\Decidiq\Service\DelegationRequest
 */
class DelegationRequestTest extends TestCase {

	/**
	 * Only a request without delegate, from and until is absent.
	 *
	 * @return void
	 */
	public function testARequestIsAbsentOnlyWhenEveryFieldIsMissing(): void {
		$this->assertTrue((new DelegationRequest(delegate: null, from: null, until: null))->isAbsent());
		$this->assertFalse((new DelegationRequest(delegate: 'anna', from: null, until: null))->isAbsent());
		$this->assertFalse((new DelegationRequest(delegate: null, from: '2026-11-01', until: null))->isAbsent());
		$this->assertFalse((new DelegationRequest(delegate: null, from: null, until: '2026-12-01'))->isAbsent());
		$this->assertFalse((new DelegationRequest(delegate: '', from: null, until: null))->isAbsent());
	}//end testARequestIsAbsentOnlyWhenEveryFieldIsMissing()

	/**
	 * An empty delegate clears the delegation; a missing or named one does not.
	 *
	 * @return void
	 */
	public function testAnEmptyDelegateClears(): void {
		$this->assertTrue((new DelegationRequest(delegate: '', from: null, until: null))->clears());
		$this->assertFalse((new DelegationRequest(delegate: null, from: null, until: null))->clears());
		$this->assertFalse((new DelegationRequest(delegate: 'anna', from: null, until: null))->clears());
		$this->assertFalse((new DelegationRequest(delegate: 0, from: null, until: null))->clears());
	}//end testAnEmptyDelegateClears()
}//end class
