<?php

/**
 * Test Suite for DocumentPublicationGate
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\Service
 *
 * @spec openspec/changes/document-accessibility-check/specs/document-accessibility-check/spec.md#requirement-req-003-publication-gate-on-failing-documents-with-recorded-override
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service;

use DateTime;
use OCA\Decidiq\Exception\AccessibilityGateException;
use OCA\Decidiq\Service\DocumentAccessibilityScanService;
use OCA\Decidiq\Service\DocumentPublicationGate;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The gate matrix: three modes against failing, warning and unscanned documents.
 *
 * @covers \OCA\Decidiq\Service\DocumentPublicationGate
 * @uses   \OCA\Decidiq\Exception\AccessibilityGateException
 *
 * @spec openspec/changes/document-accessibility-check/specs/document-accessibility-check/spec.md#requirement-req-003-publication-gate-on-failing-documents-with-recorded-override
 */
class DocumentPublicationGateTest extends TestCase {

	private DocumentAccessibilityScanService&MockObject $scanner;
	private IAppConfig&MockObject $appConfig;

	/**
	 * Set up the mocks.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->scanner   = $this->createMock(DocumentAccessibilityScanService::class);
		$this->appConfig = $this->createMock(IAppConfig::class);
	}//end setUp()

	/**
	 * A gate in a mode, published by `secretaris`.
	 *
	 * @param string $mode The stored mode.
	 *
	 * @return DocumentPublicationGate
	 */
	private function gate(string $mode): DocumentPublicationGate {
		$this->appConfig->method('getValueString')->with('decidiq', 'accessibility_enforcement_mode', 'warn')->willReturn($mode);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('secretaris');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new DateTime('2026-06-02T10:00:00+00:00'));

		return new DocumentPublicationGate(scanner: $this->scanner, appConfig: $this->appConfig, userSession: $session, time: $time);
	}//end gate()

	/**
	 * Reports by file id.
	 *
	 * @param array<int, string> $statuses Status per file id.
	 *
	 * @return void
	 */
	private function reports(array $statuses): void {
		$this->scanner->method('latestReport')->willReturnCallback(
			static fn (int $fileId): array => ['id' => 'r-' . $fileId, 'fileId' => $fileId, 'fileName' => $fileId . '.pdf', 'status' => $statuses[$fileId], 'findings' => [['code' => 'x']]]
		);
	}//end reports()

	/**
	 * The documents for file ids.
	 *
	 * @param int ...$ids The file ids.
	 *
	 * @return list<array<string, mixed>>
	 */
	private static function documents(int ...$ids): array {
		return array_map(static fn (int $id): array => ['fileId' => $id, 'sourceObject' => 'item-1', 'sourceSchema' => 'agenda-item'], $ids);
	}//end documents()

	/**
	 * Block refuses a failing document without override, listing it.
	 *
	 * @return void
	 */
	public function testBlockRefusesAFailingDocument(): void {
		$this->reports([1 => 'fail', 2 => 'warnings']);

		try {
			$this->gate(mode: 'block')->check(documents: self::documents(1, 2), acknowledged: true);
			self::fail('The gate let a failing document through');
		} catch (AccessibilityGateException $e) {
			$payload = $e->payload();
			self::assertSame(409, $e->getCode());
			self::assertSame('accessibility-gate', $payload['error']);
			self::assertSame('block', $payload['mode']);
			self::assertSame([1], array_column($payload['documents'], 'fileId'));
			self::assertSame([['code' => 'x']], $payload['documents'][0]['findings']);
		}
	}//end testBlockRefusesAFailingDocument()

	/**
	 * Block with a reason lets the publication through and returns the override to record.
	 *
	 * @return void
	 */
	public function testBlockWithAReasonRecordsTheOverride(): void {
		$this->reports([1 => 'fail']);

		$override = $this->gate(mode: 'block')->check(documents: self::documents(1), overrideReason: '  Wettelijk termijngebonden ');

		self::assertSame(
			['reason' => 'Wettelijk termijngebonden', 'actor' => 'secretaris', 'overriddenAt' => '2026-06-02T10:00:00+00:00', 'reports' => ['r-1']],
			$override
		);
	}//end testBlockWithAReasonRecordsTheOverride()

	/**
	 * A blank reason is no override.
	 *
	 * @return void
	 */
	public function testABlankReasonIsNoOverride(): void {
		$this->reports([1 => 'fail']);
		$this->expectException(AccessibilityGateException::class);

		$this->gate(mode: 'block')->check(documents: self::documents(1), overrideReason: '   ');
	}//end testABlankReasonIsNoOverride()

	/**
	 * Block lets unscanned and warning documents through.
	 *
	 * @return void
	 */
	public function testBlockOnlyGatesFailingDocuments(): void {
		$this->reports([1 => 'warnings', 2 => 'pass']);

		self::assertNull($this->gate(mode: 'block')->check(documents: self::documents(1, 2)));
	}//end testBlockOnlyGatesFailingDocuments()

	/**
	 * Warn lists failing and unscanned documents until acknowledged.
	 *
	 * @return void
	 */
	public function testWarnNeedsAcknowledgement(): void {
		$this->reports([1 => 'fail', 2 => 'pass', 3 => 'not-scanned']);
		$this->scanner->method('scanFile')->willReturn(['id' => 'r-3', 'fileId' => 3, 'fileName' => '', 'status' => 'not-scanned', 'findings' => []]);
		$documents = array_merge(self::documents(1, 2), [['fileId' => 3, 'fileName' => 'notitie.docx', 'sourceObject' => 'item-1', 'sourceSchema' => 'agenda-item']]);

		try {
			$this->gate(mode: 'warn')->check(documents: $documents);
			self::fail('The gate published without acknowledgement');
		} catch (AccessibilityGateException $e) {
			self::assertSame('warn', $e->payload()['mode']);
			self::assertSame([1, 3], array_column($e->payload()['documents'], 'fileId'));
			self::assertSame('notitie.docx', $e->payload()['documents'][1]['fileName']);
		}
	}//end testWarnNeedsAcknowledgement()

	/**
	 * Warn with acknowledgement publishes and records no override.
	 *
	 * @return void
	 */
	public function testWarnWithAcknowledgementPublishes(): void {
		$this->reports([1 => 'fail']);

		self::assertNull($this->gate(mode: 'warn')->check(documents: self::documents(1), acknowledged: true));
	}//end testWarnWithAcknowledgementPublishes()

	/**
	 * A document never scanned is scanned before the gate decides.
	 *
	 * @return void
	 */
	public function testAnUnscannedDocumentIsScannedFirst(): void {
		$this->scanner->method('latestReport')->willReturn(null);
		$this->scanner->expects(self::once())
			->method('scanFile')
			->with(9, 'item-1', 'agenda-item', null)
			->willReturn(['id' => 'r-9', 'fileId' => 9, 'status' => 'pass']);

		self::assertNull($this->gate(mode: 'block')->check(documents: self::documents(9)));
	}//end testAnUnscannedDocumentIsScannedFirst()

	/**
	 * Off never reads a report.
	 *
	 * @return void
	 */
	public function testOffChangesNothing(): void {
		$this->scanner->expects(self::never())->method('latestReport');
		$this->scanner->expects(self::never())->method('scanFile');

		self::assertNull($this->gate(mode: 'off')->check(documents: self::documents(1)));
	}//end testOffChangesNothing()

	/**
	 * An unknown stored mode reads as warn.
	 *
	 * @return void
	 */
	public function testAnUnknownModeReadsAsWarn(): void {
		self::assertSame('warn', $this->gate(mode: 'strict')->mode());
	}//end testAnUnknownModeReadsAsWarn()
}//end class
