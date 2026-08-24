<?php

/**
 * Unit tests for PublicationPayloadService.
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
 * @spec openspec/changes/publish-decisions-via-opencatalogi/specs/public-publication/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service;

use OCA\Decidiq\Service\PublicationConfigService;
use OCA\Decidiq\Service\PublicationPayloadService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests allow-list payload construction, ORI mapping, PII stripping.
 *
 * @spec openspec/changes/publish-decisions-via-opencatalogi/specs/public-publication/spec.md
 */
class PublicationPayloadServiceTest extends TestCase {

	/**
	 * Build a payload service with a config service backed by the given blob.
	 *
	 * @param string $configBlob JSON publication config.
	 *
	 * @return PublicationPayloadService
	 */
	private function makeService(string $configBlob = ''): PublicationPayloadService {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn($configBlob);
		$configService = new PublicationConfigService($appConfig);

		$container = $this->createMock(ContainerInterface::class);
		$logger = $this->createMock(LoggerInterface::class);

		return new PublicationPayloadService($container, $logger, $configService);
	}//end makeService()

	/**
	 * Decision payload carries totals and ORI Besluit mapping, never voters.
	 *
	 * @return void
	 */
	public function testDecisionPayloadTotalsOnlyAndBesluitMapping(): void {
		$service = $this->makeService();
		$source = [
			'title' => 'Vaststelling begroting',
			'text' => 'De raad besluit...',
			'outcome' => 'adopted',
			'decisionDate' => '2025-04-10T20:00:00Z',
			'legalBasis' => 'Gemeentewet art. 189',
			'bodyName' => 'Gemeenteraad Amsterdam',
			'votesFor' => 23,
			'votesAgainst' => 12,
			'votesAbstain' => 2,
			// PII that MUST NOT leak.
			'votes' => [['voter' => 'u123', 'choice' => 'for']],
			'nextcloudUserId' => 'u123',
		];

		$payload = $service->build('decision', $source, null, 1);

		$this->assertSame('Besluit', $payload['oriType']);
		$this->assertSame(23, $payload['voteTotals']['for']);
		$this->assertSame(12, $payload['voteTotals']['against']);
		$this->assertSame(2, $payload['voteTotals']['abstain']);

		$encoded = json_encode($payload);
		$this->assertStringNotContainsString('u123', $encoded);
		$this->assertArrayNotHasKey('votes', $payload);
		$this->assertArrayNotHasKey('nextcloudUserId', $payload);

	}//end testDecisionPayloadTotalsOnlyAndBesluitMapping()

	/**
	 * Agenda payload strips confidential items and preserves order.
	 *
	 * @return void
	 */
	public function testAgendaPayloadStripsConfidentialItems(): void {
		$service = $this->makeService();
		$source = [
			'title' => 'Raadsvergadering',
			'scheduledDate' => '2025-04-10T19:00:00Z',
			'meetingType' => 'regular',
			'agendaItems' => [
				['title' => 'Opening', 'orderNumber' => 1],
				['title' => 'Geheim personeelsdossier', 'orderNumber' => 2, 'isConfidential' => true, 'documents' => ['secret.pdf']],
				['title' => 'Rondvraag', 'orderNumber' => 3],
			],
		];

		$payload = $service->build('agenda', $source, null, 1);

		$this->assertSame('Vergadering', $payload['oriType']);
		$this->assertCount(2, $payload['agendaItems']);
		$this->assertSame('Opening', $payload['agendaItems'][0]['title']);
		$this->assertSame('Rondvraag', $payload['agendaItems'][1]['title']);
		$this->assertSame('AgendaPunt', $payload['agendaItems'][0]['oriType']);

		$encoded = json_encode($payload);
		$this->assertStringNotContainsString('Geheim', $encoded);
		$this->assertStringNotContainsString('secret.pdf', $encoded);

	}//end testAgendaPayloadStripsConfidentialItems()

	/**
	 * Minutes payload with 'counts' attendance policy returns only a count.
	 *
	 * @return void
	 */
	public function testMinutesAttendanceCountsPolicy(): void {
		$config = json_encode(['body-1' => ['catalog' => 'cat', 'policy' => [], 'attendance' => 'counts']]);
		$service = $this->makeService($config);
		$source = [
			'title' => 'Notulen',
			'content' => 'Inhoud',
			'attendees' => [
				['displayName' => 'Femke', 'role' => 'chair', 'nextcloudUserId' => 'f.halsema'],
				['displayName' => 'Jan', 'role' => 'member'],
			],
		];

		$payload = $service->build('minutes', $source, 'body-1', 1);

		$this->assertSame('Verslag', $payload['oriType']);
		$this->assertSame('counts', $payload['attendance']['policy']);
		$this->assertSame(2, $payload['attendance']['presentCount']);
		$this->assertArrayNotHasKey('roleHolders', $payload['attendance']);

		$encoded = json_encode($payload);
		$this->assertStringNotContainsString('f.halsema', $encoded);

	}//end testMinutesAttendanceCountsPolicy()

	/**
	 * Minutes payload with 'role-holders' policy lists role-holder names only.
	 *
	 * @return void
	 */
	public function testMinutesAttendanceRoleHoldersPolicy(): void {
		$config = json_encode(['body-1' => ['catalog' => 'cat', 'policy' => [], 'attendance' => 'role-holders']]);
		$service = $this->makeService($config);
		$source = [
			'title' => 'Notulen',
			'content' => 'Inhoud',
			'attendees' => [
				['displayName' => 'Femke', 'role' => 'chair', 'nextcloudUserId' => 'f.halsema'],
				['displayName' => 'Roos', 'role' => 'secretary'],
				['displayName' => 'Jan', 'role' => 'member'],
			],
		];

		$payload = $service->build('minutes', $source, 'body-1', 1);

		$this->assertSame('role-holders', $payload['attendance']['policy']);
		$this->assertContains('Femke', $payload['attendance']['roleHolders']);
		$this->assertContains('Roos', $payload['attendance']['roleHolders']);
		$this->assertNotContains('Jan', $payload['attendance']['roleHolders']);

		$encoded = json_encode($payload);
		$this->assertStringNotContainsString('f.halsema', $encoded);

	}//end testMinutesAttendanceRoleHoldersPolicy()

	/**
	 * Payload version is carried through (immutability via versioning).
	 *
	 * @return void
	 */
	public function testPayloadCarriesVersion(): void {
		$service = $this->makeService();
		$payload = $service->build('decision', ['title' => 'x', 'lifecycle' => 'enacted'], null, 3);
		$this->assertSame(3, $payload['payloadVersion']);

	}//end testPayloadCarriesVersion()
}//end class
