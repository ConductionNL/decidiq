<?php

/**
 * Unit tests for HeldRouteRequest.
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
 *
 * @spec openspec/changes/document-approval-chain-leaf/specs/approval-routes/spec.md
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service;

use OCA\Decidiq\Service\HeldRouteRequest;
use PHPUnit\Framework\TestCase;

/**
 * Tests for HeldRouteRequest.
 *
 * @covers \OCA\Decidiq\Service\HeldRouteRequest
 */
class HeldRouteRequestTest extends TestCase {

	/**
	 * Without due() a held route has no deadline and asks no substitute, and
	 * due() leaves the original as it was.
	 *
	 * @return void
	 */
	public function testDueLeavesTheOriginalUntouched(): void {
		$bare = new HeldRouteRequest(subject: 'doc-1', subjectSchema: 'decision', actors: ['a', 'b']);
		$due  = $bare->due(deadline: '2027-07-01T17:00:00+00:00', askSubstituteAfter: 0.5);

		self::assertSame('Review', $bare->name);
		self::assertSame('', $bare->deadline());
		self::assertNull($bare->askSubstituteAfter());
		self::assertSame('2027-07-01T17:00:00+00:00', $due->deadline());
		self::assertSame(0.5, $due->askSubstituteAfter());
		self::assertSame(['a', 'b'], $due->actors);
	}//end testDueLeavesTheOriginalUntouched()
}//end class
