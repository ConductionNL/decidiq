<?php

/**
 * Reading the id a relation value holds.
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Listener;

use OCA\Decidiq\Listener\RelationReference;
use PHPUnit\Framework\TestCase;

/**
 * Tests for RelationReference.
 *
 * @spec openspec/changes/urgent-decisions-register/specs/urgent-decision-procedure/spec.md#requirement-req-021-the-report-to-the-ratifying-body-is-recorded-once
 */
class RelationReferenceTest extends TestCase {

	/**
	 * A uuid, an expanded object by id or by uuid all give the id; anything
	 * else gives null.
	 *
	 * @return void
	 */
	public function testTheIdOfARelation(): void {
		$reference = new RelationReference();

		self::assertSame(expected: 'dec-1', actual: $reference->idOf(value: 'dec-1'));
		self::assertSame(expected: 'dec-1', actual: $reference->idOf(value: ['id' => 'dec-1', 'title' => 'x']));
		self::assertSame(expected: 'dec-1', actual: $reference->idOf(value: ['uuid' => 'dec-1']));
		self::assertNull(actual: $reference->idOf(value: ''));
		self::assertNull(actual: $reference->idOf(value: null));
		self::assertNull(actual: $reference->idOf(value: ['title' => 'x']));
		self::assertNull(actual: $reference->idOf(value: 42));
	}//end testTheIdOfARelation()
}//end class
