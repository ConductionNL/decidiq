<?php

/**
 * The ORI wire adapter's readers: bodies, persons and the value coercions every reader uses.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/archive/2026-11-25-ori-adoption/specs/ori-adoption/spec.md
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service\Ori;

use OCA\Decidiq\Service\Ori\OriExportMapper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(OriExportMapper::class)]
final class OriExportMapperReadTest extends TestCase {

	private OriExportMapper $mapper;

	protected function setUp(): void {
		$this->mapper = new OriExportMapper();
	}

	public function testABodyReadsNameSeatsAndCoalitionRole(): void {
		$this->assertSame(
			['name' => 'Groen Links', 'seatCount' => 7, 'coalitionRole' => 'coalition', 'coalitionRoleSource' => 'coalitiepartij'],
			$this->mapper->readBody(record: ['name' => ' Groen Links ', 'zetels' => ' 7 ', 'classification' => 'coalitiepartij'])
		);
	}

	public function testABodyWithUnknownOrMissingValuesKeepsTheSourceForTheReport(): void {
		$this->assertSame(
			['name' => '', 'seatCount' => null, 'coalitionRole' => null, 'coalitionRoleSource' => 'gedoogpartij'],
			$this->mapper->readBody(record: ['zetels' => 'zeven', 'classification' => 'gedoogpartij'])
		);
		$this->assertNull($this->mapper->readBody(record: ['zetels' => 7.5])['seatCount']);
		$this->assertNull($this->mapper->readBody(record: [])['coalitionRoleSource']);
	}

	public function testANonScalarOptionalValueReadsAsAbsent(): void {
		$person = $this->mapper->readPerson(record: ['name' => 'A. Jansen', 'role' => 'councilMember', 'fractie' => ['groen']]);

		$this->assertNull($person['party']);
		$this->assertSame(['member', 'council'], [$person['role'], $person['body']]);
		$this->assertTrue($person['active']);
		$this->assertNull($this->mapper->readPerson(record: ['name' => 'B', 'fractie' => '   '])['party']);
	}

	public function testAnUnknownRoleHasNoRoleOrBodyButKeepsItsSource(): void {
		$person = $this->mapper->readPerson(record: ['name' => 'X', 'role' => 'wethouder', 'actief' => false]);

		$this->assertSame([null, null, 'wethouder', false], [$person['role'], $person['body'], $person['roleSource'], $person['active']]);
	}

	public function testANegativeWholeNumberStringIsAnInteger(): void {
		$this->assertSame(-2, $this->mapper->readBody(record: ['zetels' => '-2'])['seatCount']);
		$this->assertSame(0, $this->mapper->readAgendaItem(record: ['sortOrder' => 'eerste'])['orderNumber']);
	}
}
