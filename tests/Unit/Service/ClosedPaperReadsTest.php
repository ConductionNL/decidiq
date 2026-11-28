<?php

/**
 * Recording the read of a paper of a non-public agenda item.
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

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service;

use OCA\Decidiq\Kernel\Contracts\AuditLogServiceInterface;
use OCA\Decidiq\Service\ClosedPaperReads;
use PHPUnit\Framework\TestCase;

/**
 * Tests for ClosedPaperReads.
 *
 * @spec openspec/changes/archive/2026-11-26-agenda-item-confidential-papers/specs/agenda-item-confidentiality/spec.md#requirement-req-acp-006-every-read-of-a-closed-paper-is-logged
 */
class ClosedPaperReadsTest extends TestCase {

	/**
	 * Every append: [actor, action, uids, payload].
	 *
	 * @var list<array{0: string, 1: string, 2: list<string>, 3: array<string, mixed>}>
	 */
	private array $appended = [];

	/**
	 * The log over an audit trail whose appends succeed or not.
	 *
	 * @param bool $succeeds Whether an append succeeds.
	 *
	 * @return ClosedPaperReads
	 */
	private function reads(bool $succeeds=true): ClosedPaperReads {
		$audit = $this->createMock(originalClassName: AuditLogServiceInterface::class);
		$audit->method('append')->willReturnCallback(
			function (string $actor, string $action, array $objectUids, array $payload=[]) use ($succeeds): array {
				$this->appended[] = [$actor, $action, $objectUids, $payload];
				return ['success' => $succeeds, 'entry' => null, 'message' => ''];
			}
		);

		return new ClosedPaperReads(auditLog: $audit);
	}//end reads()

	/**
	 * Sanne reads a paper of closed item 7: one entry on the item, with her, the paper and the file.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive/2026-11-26-agenda-item-confidential-papers/specs/agenda-item-confidentiality/spec.md#scenario-the-griffie-checks-who-read-the-paper
	 */
	public function testAReadOfAClosedPaperIsRecordedOnTheItem(): void {
		$refusal = $this->reads()->record(
			readerUid: 'sanne.mulder',
			paper: ['agendaItemId' => 'item-7', 'item' => ['public' => false, 'title' => 'Verlichting'], 'fileId' => 42, 'name' => 'Kostenraming verlichting.pdf']
		);

		$this->assertNull($refusal);
		$this->assertSame(
			[[
				'sanne.mulder',
				'material-access',
				['item-7'],
				['kind' => 'closed-paper-read', 'channel' => 'app', 'agendaItem' => 'item-7', 'fileId' => 42, 'paper' => 'Kostenraming verlichting.pdf'],
			]],
			$this->appended
		);
	}//end testAReadOfAClosedPaperIsRecordedOnTheItem()

	/**
	 * A public item, an item that does not say, and a meeting's paper write nothing.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive/2026-11-26-agenda-item-confidential-papers/specs/agenda-item-confidentiality/spec.md#requirement-req-acp-006-every-read-of-a-closed-paper-is-logged
	 */
	public function testAnOpenPaperIsNotRecorded(): void {
		$reads = $this->reads();

		$this->assertNull($reads->record(readerUid: 'sanne.mulder', paper: ['agendaItemId' => 'item-7', 'item' => ['public' => true], 'fileId' => 42]));
		$this->assertNull($reads->record(readerUid: 'sanne.mulder', paper: ['agendaItemId' => 'item-7', 'item' => [], 'fileId' => 42]));
		$this->assertNull($reads->record(readerUid: 'sanne.mulder', paper: ['agendaItemId' => null, 'item' => ['public' => false], 'fileId' => 42]));
		$this->assertSame([], $this->appended);
	}//end testAnOpenPaperIsNotRecorded()

	/**
	 * A read that cannot be recorded is refused.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive/2026-11-26-agenda-item-confidential-papers/specs/agenda-item-confidentiality/spec.md#requirement-req-acp-006-every-read-of-a-closed-paper-is-logged
	 */
	public function testAnUnrecordedReadIsRefused(): void {
		$refusal = $this->reads(succeeds: false)->record(readerUid: 'sanne.mulder', paper: ['agendaItemId' => 'item-7', 'item' => ['public' => false], 'fileId' => 42]);

		$this->assertStringContainsString('could not be recorded', (string)$refusal);
	}//end testAnUnrecordedReadIsRefused()
}//end class
