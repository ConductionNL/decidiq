<?php

/**
 * Unit tests for the accessibility gate on the public-publication path.
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
 * @spec openspec/changes/document-accessibility-check/specs/document-accessibility-check/spec.md#requirement-req-003-publication-gate-on-failing-documents-with-recorded-override
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service;

use OCA\Decidiq\Exception\AccessibilityGateException;
use OCA\Decidiq\Service\AuditLogService;
use OCA\Decidiq\Service\DocumentPublicationGate;
use OCA\Decidiq\Service\OpenCatalogiPublisher;
use OCA\Decidiq\Service\PublicationConfigService;
use OCA\Decidiq\Service\PublicationEligibilityService;
use OCA\Decidiq\Service\PublicationEventRecorder;
use OCA\Decidiq\Service\PublicationPayloadService;
use OCA\Decidiq\Service\PublicationService;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\App\IAppManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * PublicationService::publish() consults the gate with the papers it makes
 * public before it writes anything, and keeps an override on the record.
 *
 * @covers \OCA\Decidiq\Service\PublicationService
 * @uses   \OCA\Decidiq\Service\PublicationRepository
 * @uses   \OCA\Decidiq\Service\PublicationRun
 * @uses   \OCA\Decidiq\Exception\AccessibilityGateException
 *
 * @spec openspec/changes/document-accessibility-check/specs/document-accessibility-check/spec.md#requirement-req-003-publication-gate-on-failing-documents-with-recorded-override
 */
class PublicationAccessibilityGateTest extends TestCase {

	/**
	 * Objects saved, as [schema, object].
	 *
	 * @var list<array{0: string, 1: array<string, mixed>}>
	 */
	private array $saved = [];

	/**
	 * The gate double.
	 *
	 * @var DocumentPublicationGate&MockObject
	 */
	private DocumentPublicationGate&MockObject $gate;

	/**
	 * The service publishing an agenda of body-1 with two papers, over a store that records saves.
	 *
	 * @param bool $withGate Whether the gate is wired.
	 *
	 * @return PublicationService
	 */
	private function service(bool $withGate=true): PublicationService {
		$objectService = $this->createMock(originalClassName: ObjectServiceInterface::class);
		$objectService->method('saveObject')->willReturnCallback(
			function (array $object, ?array $extend=[], string|int|null $register=null, string|int|null $schema=null, ?string $uuid=null): object {
				$this->saved[] = [(string)$schema, $object];
				$entity        = $this->createMock(originalClassName: ObjectEntity::class);
				$entity->method('jsonSerialize')->willReturn(['id' => ($uuid ?? 'saved-' . count($this->saved))] + $object);
				return $entity;
			}
		);
		$objectService->method('find')->willReturn(null);

		$eligibility = $this->createMock(originalClassName: PublicationEligibilityService::class);
		$eligibility->method('assertEligible')->willReturn(['id' => 'meeting-1', 'title' => 'Raad 14 oktober', 'governanceBody' => 'body-1']);

		$payload = $this->createMock(originalClassName: PublicationPayloadService::class);
		$payload->method('build')->willReturn(
			[
				'oriType' => 'Vergadering',
				'title' => 'Raad 14 oktober',
				'_publishedPapers' => [
					['agendaItem' => 'item-1', 'file' => 11],
					['agendaItem' => 'item-2', 'file' => 12],
				],
			]
		);

		$config = $this->createMock(originalClassName: PublicationConfigService::class);
		$config->method('getForBody')->willReturn(['catalog' => '']);

		$this->gate = $this->createMock(originalClassName: DocumentPublicationGate::class);

		$gate = null;
		if ($withGate === true) {
			$gate = $this->gate;
		}

		return new PublicationService(
			logger: $this->createMock(originalClassName: LoggerInterface::class),
			appManager: $this->createMock(originalClassName: IAppManager::class),
			eligibility: $eligibility,
			payloadService: $payload,
			configService: $config,
			catalogPublisher: $this->createMock(originalClassName: OpenCatalogiPublisher::class),
			auditLogService: $this->createMock(originalClassName: AuditLogService::class),
			objectService: $objectService,
			eventRecorder: $this->createMock(originalClassName: PublicationEventRecorder::class),
			documentGate: $gate
		);
	}//end service()

	/**
	 * The schemas of the objects saved, in order.
	 *
	 * @return list<string>
	 */
	private function savedSchemas(): array {
		return array_map(static fn (array $save): string => $save[0], $this->saved);
	}//end savedSchemas()

	/**
	 * The saved PublicationRecord.
	 *
	 * @return array<string, mixed>
	 */
	private function savedRecord(): array {
		foreach ($this->saved as [$schema, $object]) {
			if ($schema === 'publication-record') {
				return $object;
			}
		}

		self::fail(message: 'no publication record was saved');
	}//end savedRecord()

	/**
	 * Scenario: publishing past a failing document without an override is refused, and nothing is published.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/document-accessibility-check/specs/document-accessibility-check/spec.md#requirement-req-003-publication-gate-on-failing-documents-with-recorded-override
	 */
	public function testARefusalPublishesNothing(): void {
		$service = $this->service();
		$this->gate->method('check')->willThrowException(new AccessibilityGateException(mode: 'block', documents: [['fileId' => 11]]));

		try {
			$service->publish(sourceType: 'agenda', sourceId: 'meeting-1', actorId: 'griffier');
			self::fail(message: 'the gate refusal did not reach the caller');
		} catch (AccessibilityGateException $e) {
			self::assertSame(expected: 'accessibility-gate', actual: $e->payload()['error']);
		}

		self::assertSame(expected: [], actual: $this->saved);
	}//end testARefusalPublishesNothing()

	/**
	 * The gate sees each published paper with its agenda item and body, and the publisher's answer.
	 *
	 * @return void
	 */
	public function testTheGateSeesThePublishedPapers(): void {
		$service = $this->service();
		$this->gate->expects(self::once())
			->method('check')
			->with(
				[
					['fileId' => 11, 'sourceObject' => 'item-1', 'sourceSchema' => 'agenda-item', 'governanceBody' => 'body-1'],
					['fileId' => 12, 'sourceObject' => 'item-2', 'sourceSchema' => 'agenda-item', 'governanceBody' => 'body-1'],
				],
				['acknowledged' => true]
			)
			->willReturn(null);

		$service->publish(sourceType: 'agenda', sourceId: 'meeting-1', actorId: 'griffier', accessibility: ['acknowledged' => true]);

		self::assertSame(expected: ['publication-payload', 'publication-record'], actual: array_slice(array: $this->savedSchemas(), offset: 0, length: 2));
		self::assertArrayNotHasKey(key: 'accessibilityOverride', array: $this->savedRecord());
	}//end testTheGateSeesThePublishedPapers()

	/**
	 * Scenario: an override reason lets the publication through and lands on the PublicationRecord.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/document-accessibility-check/specs/document-accessibility-check/spec.md#requirement-req-003-publication-gate-on-failing-documents-with-recorded-override
	 */
	public function testAnOverrideIsRecordedOnThePublicationRecord(): void {
		$service  = $this->service();
		$override = ['reason' => 'Wettelijke termijn', 'actor' => 'griffier', 'overriddenAt' => '2026-06-02T10:00:00+00:00', 'reports' => ['r-11']];
		$this->gate->expects(self::once())
			->method('check')
			->with(self::anything(), ['overrideReason' => 'Wettelijke termijn'])
			->willReturn($override);

		$result = $service->publish(sourceType: 'agenda', sourceId: 'meeting-1', actorId: 'griffier', accessibility: ['overrideReason' => 'Wettelijke termijn']);

		self::assertSame(expected: $override, actual: $this->savedRecord()['accessibilityOverride']);
		self::assertSame(expected: $override, actual: $result['record']['accessibilityOverride']);
	}//end testAnOverrideIsRecordedOnThePublicationRecord()

	/**
	 * Without a gate the publication runs as before the change.
	 *
	 * @return void
	 */
	public function testWithoutAGateNothingChanges(): void {
		$service = $this->service(withGate: false);
		$this->gate->expects(self::never())->method('check');

		$result = $service->publish(sourceType: 'agenda', sourceId: 'meeting-1', actorId: 'griffier');

		self::assertSame(expected: 'published', actual: $result['record']['status']);
		self::assertArrayNotHasKey(key: 'accessibilityOverride', array: $this->savedRecord());
	}//end testWithoutAGateNothingChanges()
}//end class
