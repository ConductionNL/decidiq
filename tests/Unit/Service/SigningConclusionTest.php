<?php

/**
 * Unit tests for SigningConclusion.
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/sign-through-the-filinq-rail/tasks.md#task-3
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service;

use OCA\Decidiq\Service\EIDASSignatureService;
use OCA\Decidiq\Service\SigningConclusion;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Tests for SigningConclusion.
 *
 * @spec openspec/changes/sign-through-the-filinq-rail/tasks.md#task-3
 */
class SigningConclusionTest extends TestCase {

	/**
	 * The record saves, as schema, object and rbac.
	 *
	 * @var array<int, array{schema: string, object: array<string, mixed>, rbac: bool}>
	 */
	private array $saved = [];

	/**
	 * The stage resolver double.
	 *
	 * @var EIDASSignatureService&MockObject
	 */
	private EIDASSignatureService $signatures;

	/**
	 * Build the service around one stored record.
	 *
	 * @param string               $schema The record's schema
	 * @param array<string, mixed> $data   The record's data
	 *
	 * @return SigningConclusion
	 */
	private function conclusion(string $schema, array $data): SigningConclusion {
		$record = new ObjectEntity();
		$record->setUuid('rec-1');
		$record->setObject($data);

		$objects = $this->createMock(originalClassName: ObjectServiceInterface::class);
		$objects->method('find')->willReturnCallback(
			static function (int|string $id, ?ObjectFindOptions $options = null) use ($record, $schema): ?ObjectEntity {
				if ($id === 'rec-1' && $options?->schema === $schema) {
					return $record;
				}

				return null;
			}
		);
		$objects->method('saveObject')->willReturnCallback(
			function (array $object, ObjectSaveOptions $options, bool $_rbac = true) use ($record): ObjectEntity {
				$this->saved[] = ['schema' => (string)$options->target->schema, 'object' => $object, 'rbac' => $_rbac];
				return $record;
			}
		);

		$this->signatures = $this->createMock(originalClassName: EIDASSignatureService::class);

		return new SigningConclusion(objectService: $objects, signatures: $this->signatures);
	}//end conclusion()

	/**
	 * Signed: the record links the signed document, stamps its signers, and
	 * the stages resolve on the request id; written in system context.
	 *
	 * @spec openspec/changes/sign-through-the-filinq-rail/specs/decidesk-contract-decision-hub/spec.md#scenario-completed-filinq-signing-resolves-the-stage
	 *
	 * @return void
	 */
	public function testASignedRequestLinksTheDocumentAndResolvesTheStage(): void {
		$service = $this->conclusion(
			schema: 'minutes',
			data: [
				'title' => 'Notulen 14 oktober',
				'signers' => [['participant' => 'anna', 'order' => 1], 'pieter'],
				'signingRequestId' => 'sr-9',
				'signingStatus' => 'sent',
			]
		);
		$this->signatures->expects($this->once())->method('resolveSignatureStage')->with('rec-1', 'sr-9');
		$this->signatures->expects($this->never())->method('recordUnsignedOutcome');

		$status = $service->record(
			schema: 'minutes',
			subjectId: 'rec-1',
			conclusion: ['requestId' => 'sr-9', 'status' => 'signed', 'signedDocumentRef' => 'notulen-getekend.pdf', 'signedAt' => '2026-11-26T09:00:00Z']
		);

		$this->assertSame(expected: 'signed', actual: $status);
		$written = $this->saved[0]['object'];
		$this->assertSame(expected: 'signed', actual: $written['signingStatus']);
		$this->assertSame(expected: 'notulen-getekend.pdf', actual: $written['signedCopy']);
		$this->assertSame(expected: '2026-11-26T09:00:00Z', actual: $written['signedAt']);
		$this->assertSame(expected: ['anna', 'pieter'], actual: array_column(array: $written['signers'], column_key: 'participant'));
		$stamps = array_column(array: $written['signers'], column_key: 'signedAt');
		$this->assertSame(expected: ['2026-11-26T09:00:00Z', '2026-11-26T09:00:00Z'], actual: $stamps);
		$this->assertFalse(condition: $this->saved[0]['rbac'], message: 'filinq concludes in a signer\'s session; the record is written in system context');
	}//end testASignedRequestLinksTheDocumentAndResolvesTheStage()

	/**
	 * Declined, expired and cancelled mark the round failed and leave the
	 * stage open with the reason.
	 *
	 * @spec openspec/changes/sign-through-the-filinq-rail/specs/decidesk-contract-decision-hub/spec.md#scenario-a-declined-signing-leaves-the-stage-open
	 *
	 * @return void
	 */
	public function testAnUnsignedOutcomeMarksTheRoundFailed(): void {
		foreach (['declined', 'expired', 'cancelled'] as $outcome) {
			$this->saved = [];
			$service     = $this->conclusion(schema: 'decision', data: ['signingRequestId' => 'sr-9', 'signingStatus' => 'sent']);
			$this->signatures->expects($this->never())->method('resolveSignatureStage');
			$this->signatures->expects($this->once())->method('recordUnsignedOutcome')->with('sr-9', $outcome);

			$status = $service->record(schema: 'decision', subjectId: 'rec-1', conclusion: ['requestId' => 'sr-9', 'status' => $outcome]);
			$this->assertSame(expected: 'failed', actual: $status);
			$this->assertSame(expected: 'failed', actual: $this->saved[0]['object']['signingStatus']);
			$this->assertArrayNotHasKey(key: 'signedCopy', array: $this->saved[0]['object']);
		}
	}//end testAnUnsignedOutcomeMarksTheRoundFailed()

	/**
	 * An answer for another round, an unknown status or an unsignable schema
	 * changes nothing.
	 *
	 * @return void
	 */
	public function testAnAnswerForAnotherRoundIsIgnored(): void {
		$service = $this->conclusion(schema: 'minutes', data: ['signingRequestId' => 'sr-10', 'signingStatus' => 'sent']);
		$this->signatures->expects($this->never())->method('resolveSignatureStage');
		$this->signatures->expects($this->never())->method('recordUnsignedOutcome');

		$answers = [
			['minutes', 'rec-1', 'sr-9', 'signed'],
			['minutes', 'rec-1', 'sr-10', 'pending'],
			['agenda-item', 'rec-1', 'sr-10', 'signed'],
			['minutes', 'gone', 'sr-10', 'signed'],
		];
		foreach ($answers as [$schema, $subjectId, $requestId, $status]) {
			$recorded = $service->record(schema: $schema, subjectId: $subjectId, conclusion: ['requestId' => $requestId, 'status' => $status]);
			$this->assertSame(expected: 'ignored', actual: $recorded);
		}
		$this->assertSame(expected: [], actual: $this->saved);
	}//end testAnAnswerForAnotherRoundIsIgnored()
}//end class
