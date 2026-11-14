<?php

/**
 * Test Suite for the accessibility gate on agenda publication
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

use OCA\Decidiq\Exception\AccessibilityGateException;
use OCA\Decidiq\Service\AgendaNotices;
use OCA\Decidiq\Service\AgendaService;
use OCA\Decidiq\Service\DocumentPublicationGate;
use OCA\Decidiq\Service\NotificationPreferenceService;
use OCA\Decidiq\Service\ParticipantResolver;
use OCA\Decidiq\Service\PublicationEventRecorder;
use OCA\Decidiq\Support\OpenRegisterFiles;
use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCP\Files\File;
use OCP\IL10N;
use OCP\L10N\IFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * publishAgenda consults the gate with the meeting's and items' files before writing.
 *
 * @covers \OCA\Decidiq\Service\AgendaService
 * @uses   \OCA\Decidiq\Service\AgendaRules
 * @uses   \OCA\Decidiq\Service\AgendaNotices
 * @uses   \OCA\Decidiq\Exception\AccessibilityGateException
 *
 * @spec openspec/changes/document-accessibility-check/specs/document-accessibility-check/spec.md#requirement-req-003-publication-gate-on-failing-documents-with-recorded-override
 */
class AgendaPublicationAccessibilityGateTest extends TestCase {

	private ObjectServiceInterface&MockObject $objectService;
	private DocumentPublicationGate&MockObject $gate;
	private AgendaService $service;

	/**
	 * A meeting with one agenda item; one file on each.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->objectService = $this->createMock(ObjectServiceInterface::class);
		$meeting = $this->createMock(ObjectEntityInterface::class);
		$meeting->method('getObject')->willReturn(['title' => 'Raad', 'governanceBody' => 'body-1', 'lifecycle' => 'scheduled']);
		$this->objectService->method('find')->willReturn($meeting);
		$this->objectService->method('findAll')->willReturnCallback(
			static fn (array $config): array => (($config['filters']['schema'] ?? '') === 'agenda-item') ? [['id' => 'item-1', 'title' => 'Begroting']] : []
		);

		$fileService = new class ($this) {
			/**
			 * Constructor.
			 *
			 * @param TestCase $test The test, to make file mocks.
			 */
			public function __construct(private readonly TestCase $test) {
			}

			/**
			 * One file per object.
			 *
			 * @param string $id The object id.
			 *
			 * @return list<File>
			 */
			public function getFiles(string $id): array {
				$file = $this->test->getMockBuilder(File::class)->getMock();
				$file->method('getId')->willReturn(($id === 'meeting-1') ? 10 : 20);
				$file->method('getName')->willReturn($id . '.pdf');
				return [$file];
			}
		};
		$files = $this->createMock(OpenRegisterFiles::class);
		$files->method('installed')->willReturn(true);
		$files->method('fileService')->willReturn($fileService);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);
		$l10nFactory = $this->createMock(IFactory::class);
		$l10nFactory->method('get')->willReturn($l10n);

		$this->gate    = $this->createMock(DocumentPublicationGate::class);
		$this->service = new AgendaService(
			objectService: $this->objectService,
			notices: new AgendaNotices(preferences: $this->createMock(NotificationPreferenceService::class), l10nFactory: $l10nFactory, logger: $this->createMock(LoggerInterface::class)),
			logger: $this->createMock(LoggerInterface::class),
			participantResolver: $this->createMock(ParticipantResolver::class),
			eventRecorder: $this->createMock(PublicationEventRecorder::class),
			documentGate: $this->gate,
			files: $files
		);
	}//end setUp()

	/**
	 * A refusal leaves the meeting unwritten.
	 *
	 * @return void
	 */
	public function testARefusalPublishesNothing(): void {
		$this->gate->method('check')->willThrowException(new AccessibilityGateException(mode: 'block', documents: []));
		$this->objectService->expects(self::never())->method('saveObject');

		$this->expectException(AccessibilityGateException::class);
		$this->service->publishAgenda('meeting-1');
	}//end testARefusalPublishesNothing()

	/**
	 * The gate sees every file with its source, and an override is kept on the meeting.
	 *
	 * @return void
	 */
	public function testAnOverrideIsKeptOnTheMeeting(): void {
		$override = ['reason' => 'Termijn', 'actor' => 'secretaris', 'overriddenAt' => '2026-06-02T10:00:00+00:00', 'reports' => ['r-1']];
		$this->gate->expects(self::once())
			->method('check')
			->with(
				[
					['fileId' => 10, 'fileName' => 'meeting-1.pdf', 'sourceObject' => 'meeting-1', 'sourceSchema' => 'meeting', 'governanceBody' => 'body-1'],
					['fileId' => 20, 'fileName' => 'item-1.pdf', 'sourceObject' => 'item-1', 'sourceSchema' => 'agenda-item', 'governanceBody' => 'body-1'],
				],
				['acknowledged' => true, 'overrideReason' => 'Termijn']
			)
			->willReturn($override);
		$this->objectService->expects(self::once())
			->method('saveObject')
			->with(self::callback(static fn (array $meeting): bool => $meeting['accessibilityOverrides'] === [$override]));

		$this->service->publishAgenda('meeting-1', accessibility: ['acknowledged' => true, 'overrideReason' => 'Termijn']);
	}//end testAnOverrideIsKeptOnTheMeeting()

	/**
	 * Without an override nothing is added to the meeting.
	 *
	 * @return void
	 */
	public function testNoOverrideAddsNothing(): void {
		$this->gate->method('check')->willReturn(null);
		$this->objectService->expects(self::once())
			->method('saveObject')
			->with(self::callback(static fn (array $meeting): bool => array_key_exists('accessibilityOverrides', $meeting) === false));

		$this->service->publishAgenda('meeting-1');
	}//end testNoOverrideAddsNothing()
}//end class
