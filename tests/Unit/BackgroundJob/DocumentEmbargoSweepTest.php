<?php

/**
 * Tests for DocumentEmbargoSweep: a document under embargo is released once
 * its embargoUntil has passed, carrying every other field, and never before
 * it or on a moment that cannot be read (REQ-EMB-005).
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\BackgroundJob
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/embargo-geheimhouding/specs/embargo-geheimhouding/spec.md#requirement-req-emb-005-member-facing-embargo-with-scheduled-timed-release
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\BackgroundJob;

use DateTimeImmutable;
use OCA\Decidiq\BackgroundJob\DocumentEmbargoSweep;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * The sweep over a stand-in object service.
 *
 * @covers \OCA\Decidiq\BackgroundJob\DocumentEmbargoSweep
 * @uses   \OCA\Decidiq\Service\SystemObjectRows
 */
class DocumentEmbargoSweepTest extends TestCase {

	/**
	 * The writes the stand-in received: [object, options].
	 *
	 * @var list<array{0: array<string, mixed>, 1: ObjectSaveOptions}>
	 */
	private array $saved = [];

	/**
	 * The filters findAll was asked with.
	 *
	 * @var list<array<string, mixed>>
	 */
	private array $asked = [];

	/**
	 * A sweep over the given documents.
	 *
	 * @param list<array<string, mixed>> $documents The documents findAll returns.
	 * @param bool                       $refuse    Whether every write is refused.
	 * @param LoggerInterface|null       $logger    The logger.
	 *
	 * @return DocumentEmbargoSweep
	 */
	private function sweepOver(array $documents, bool $refuse=false, ?LoggerInterface $logger=null): DocumentEmbargoSweep {
		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->method('findAll')->willReturnCallback(
			function (array $config = []) use ($documents): array {
				$this->asked[] = ($config['filters'] ?? []);
				return $documents;
			}
		);
		$objects->method('saveObject')->willReturnCallback(
			function (array $object, ObjectSaveOptions $options = new ObjectSaveOptions()) use ($refuse): ObjectEntity {
				if ($refuse === true) {
					throw new RuntimeException('register refused the write');
				}

				$this->saved[] = [$object, $options];
				return $this->createMock(ObjectEntity::class);
			}
		);

		return new DocumentEmbargoSweep($objects, ($logger ?? new NullLogger()));
	}//end sweepOver()

	/**
	 * A passed embargo is released, carrying every other field, through the document's own uuid.
	 *
	 * @return void
	 */
	public function testAPassedEmbargoIsReleasedWithEveryOtherField(): void {
		$sweep = $this->sweepOver(
			[
				[
					'id'              => 'doc-1',
					'name'            => 'Grondexploitatie Noord',
					'embargoActive'   => true,
					'embargoUntil'    => '2027-03-01T09:00:00+01:00',
					'embargoAudience' => 'fractievoorzitters',
					'public'          => false,
				],
			]
		);

		$result = $sweep->sweep(now: new DateTimeImmutable('2027-03-01T09:10:00+01:00'));

		self::assertSame(['released' => 1, 'failed' => 0], $result);
		self::assertSame(
			[['register' => 'decidiq', 'schema' => 'digital-document', 'embargoActive' => 'true']],
			$this->asked
		);
		self::assertCount(1, $this->saved);
		[$object, $options] = $this->saved[0];
		self::assertFalse($object['embargoActive']);
		self::assertSame('Grondexploitatie Noord', $object['name']);
		self::assertSame('fractievoorzitters', $object['embargoAudience']);
		self::assertFalse($object['public']);
		self::assertSame('doc-1', $options->uuid);
		self::assertSame('digital-document', $options->target->schema);
		self::assertSame('decidiq', $options->target->register);
	}//end testAPassedEmbargoIsReleasedWithEveryOtherField()

	/**
	 * An embargo whose moment is still ahead stays; the moment itself releases.
	 *
	 * @return void
	 */
	public function testAFutureEmbargoStaysAndTheMomentItselfReleases(): void {
		$sweep = $this->sweepOver([]);
		$document = ['id' => 'doc-2', 'embargoActive' => true, 'embargoUntil' => '2027-03-01T09:00:00+01:00'];

		self::assertFalse($sweep->isDue(document: $document, now: new DateTimeImmutable('2027-03-01T08:59:59+01:00')));
		self::assertTrue($sweep->isDue(document: $document, now: new DateTimeImmutable('2027-03-01T09:00:00+01:00')));
		self::assertTrue($sweep->isDue(document: $document, now: new DateTimeImmutable('2027-03-01T08:00:00Z')));
	}//end testAFutureEmbargoStaysAndTheMomentItselfReleases()

	/**
	 * A missing or unreadable moment, or a document no longer under embargo, is never released.
	 *
	 * @return void
	 */
	public function testNoMomentOrNoEmbargoIsNeverReleased(): void {
		$sweep = $this->sweepOver(
			[
				['id' => 'doc-3', 'embargoActive' => true],
				['id' => 'doc-4', 'embargoActive' => true, 'embargoUntil' => 'next week'],
				['id' => 'doc-5', 'embargoActive' => true, 'embargoUntil' => ''],
				['id' => 'doc-6', 'embargoActive' => false, 'embargoUntil' => '2020-01-01T00:00:00Z'],
				['id' => 'doc-7', 'embargoActive' => 'true', 'embargoUntil' => '2020-01-01T00:00:00Z'],
			]
		);

		$result = $sweep->sweep(now: new DateTimeImmutable('2027-03-01T09:00:00Z'));

		self::assertSame(['released' => 0, 'failed' => 0], $result);
		self::assertSame([], $this->saved);
	}//end testNoMomentOrNoEmbargoIsNeverReleased()

	/**
	 * A refused write is logged and counted, and the run goes on.
	 *
	 * @return void
	 */
	public function testARefusedWriteIsLoggedAndCounted(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::exactly(2))->method('error')->with(
			'Decidiq: a document could not be released from its embargo',
			self::callback(static fn (array $context): bool => $context['reason'] === 'register refused the write')
		);
		$sweep = $this->sweepOver(
			[
				['id' => 'doc-8', 'embargoActive' => true, 'embargoUntil' => '2027-01-01T00:00:00Z'],
				['id' => 'doc-9', 'embargoActive' => true, 'embargoUntil' => '2027-01-02T00:00:00Z'],
			],
			true,
			$logger
		);

		self::assertSame(['released' => 0, 'failed' => 2], $sweep->sweep(now: new DateTimeImmutable('2027-03-01T00:00:00Z')));
	}//end testARefusedWriteIsLoggedAndCounted()

	/**
	 * A due document without an id is counted as failed and not written.
	 *
	 * @return void
	 */
	public function testADueDocumentWithoutAnIdIsNotWritten(): void {
		$sweep = $this->sweepOver([['embargoActive' => true, 'embargoUntil' => '2027-01-01T00:00:00Z']]);

		self::assertSame(['released' => 0, 'failed' => 1], $sweep->sweep(now: new DateTimeImmutable('2027-03-01T00:00:00Z')));
		self::assertSame([], $this->saved);
	}//end testADueDocumentWithoutAnIdIsNotWritten()

	/**
	 * Documents that cannot be read release nothing.
	 *
	 * @return void
	 */
	public function testUnreadableDocumentsReleaseNothing(): void {
		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->method('findAll')->willThrowException(new RuntimeException('register down'));
		$objects->expects(self::never())->method('saveObject');
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::once())->method('warning')->with('Decidiq: documents under embargo could not be read for the release');

		$sweep = new DocumentEmbargoSweep($objects, $logger);

		self::assertSame(['released' => 0, 'failed' => 0], $sweep->sweep(now: new DateTimeImmutable('2027-03-01T00:00:00Z')));
	}//end testUnreadableDocumentsReleaseNothing()
}//end class
