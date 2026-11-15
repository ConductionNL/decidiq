<?php

/**
 * Unit tests for ConflictDeclaration.
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
 * @spec openspec/specs/conflict-of-interest/spec.md
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service;

use OCA\Decidiq\Service\ConflictDeclaration;
use PHPUnit\Framework\TestCase;

/**
 * Tests for ConflictDeclaration.
 *
 * @covers \OCA\Decidiq\Service\ConflictDeclaration
 */
class ConflictDeclarationTest extends TestCase {

	/**
	 * Only a recusal from the vote is recorded as an action at declaration
	 * time; every other initial action asks none, and assessed() leaves the
	 * original as it was.
	 *
	 * @return void
	 */
	public function testOnlyARecusalFromTheVoteIsRecordedAtOnce(): void {
		$bare = new ConflictDeclaration(membershipId: 'M-anna', agendaItemId: 'ai-1', type: 'financial-interest', description: 'Aandelen');

		self::assertSame('material', $bare->severity());
		self::assertSame('no-action-needed', $bare->actionTaken());
		self::assertSame('recused-from-vote', $bare->assessed(severity: 'material', initialAction: 'recused-from-vote')->actionTaken());
		foreach (['recused-from-discussion', 'disclosed-and-participated', 'no-action-needed', '', 'RECUSED-FROM-VOTE'] as $action) {
			self::assertSame('no-action-needed', $bare->assessed(severity: 'material', initialAction: $action)->actionTaken(), $action);
		}

		$recused = $bare->assessed(severity: 'non-material', initialAction: 'recused-from-vote');
		self::assertSame('non-material', $recused->severity());
		self::assertSame('material', $bare->severity());
		self::assertSame('no-action-needed', $bare->actionTaken());
	}//end testOnlyARecusalFromTheVoteIsRecordedAtOnce()
}//end class
