<?php

/**
 * The papers of a non-public item reach the portal only while it is
 * confidential, at the declared trust, and every opening is in the audit log
 * first (portal-voting-record-and-confidential-papers task 4).
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
 * @spec openspec/changes/portal-voting-record-and-confidential-papers/specs/portal-contribution/spec.md#requirement-req-psh-004-every-portal-opening-of-a-confidential-paper-is-in-decidiqs-audit-log-first
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service;

use OCA\Decidiq\Kernel\Contracts\AuditLogServiceInterface;
use OCA\Decidiq\Service\ConfidentialPapers;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for ConfidentialPapers.
 *
 * @covers \OCA\Decidiq\Service\ConfidentialPapers
 */
class ConfidentialPapersTest extends TestCase {

	/**
	 * The audit entries appended.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $appended = [];

	/**
	 * The session of the named reader.
	 *
	 * @var array<string, string>
	 */
	private const SUBJECT = ['subjectRef' => 'portal-account-pieter-bos', 'trust' => 'substantial', 'identityType' => 'digid', 'audience' => 'citizen'];

	/**
	 * The service over the example item, its two papers and Pieter Bos.
	 *
	 * @param bool        $public  Whether the item is public.
	 * @param string      $setting The stored trust setting.
	 * @param string|null $audit   'ok', 'fail' or 'throw'.
	 *
	 * @return ConfidentialPapers
	 */
	private function papers(bool $public=false, string $setting='', ?string $audit='ok'): ConfidentialPapers {
		$rows = [
			'agenda-item' => [
				['id' => 'agendapunt-grondaankoop-lindelaan', 'title' => 'Grondaankoop Lindelaan', 'public' => $public, 'authorisedPersons' => ['pieter-bos']],
				['id' => 'agendapunt-open', 'title' => 'Open', 'public' => true],
			],
			'digital-document' => [
				['id' => 'document-taxatierapport-lindelaan', 'name' => 'Taxatierapport percelen Lindelaan.pdf', 'documentType' => 'Raadsvoorstel', 'encodingFormat' => 'application/pdf', 'fileId' => 900501, 'agendaItem' => 'agendapunt-grondaankoop-lindelaan'],
				['id' => 'document-onderhandelingsmandaat-lindelaan', 'name' => 'Onderhandelingsmandaat.pdf', 'fileId' => 900502, 'agendaItem' => 'agendapunt-grondaankoop-lindelaan'],
				['id' => 'document-elders', 'name' => 'Elders.pdf', 'fileId' => 1, 'agendaItem' => 'agendapunt-open'],
			],
			'person' => [['id' => 'pieter-bos', 'name' => 'Pieter Bos', 'portalAccountRef' => 'portal-account-pieter-bos']],
		];
		$objects = $this->getMockBuilder(ObjectServiceInterface::class)->getMock();
		$objects->method('findAll')->willReturnCallback(static fn (array $config=[]): array => ($rows[$config['filters']['schema'] ?? ''] ?? []));

		$log = $this->createMock(AuditLogServiceInterface::class);
		$log->method('append')->willReturnCallback(
			function (string $actor, string $action, array $objectUids, array $payload=[]) use ($audit): array {
				if ($audit === 'throw') {
					throw new RuntimeException('down');
				}

				$this->appended[] = ['actor' => $actor, 'action' => $action, 'objectUids' => $objectUids, 'payload' => $payload];
				return ['success' => ($audit === 'ok'), 'entry' => null, 'message' => ''];
			}
		);

		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default=''): string => ($key === 'portal_confidential_min_trust') ? $setting : $default
		);

		return new ConfidentialPapers(objectService: $objects, auditLog: $log, appConfig: $config, logger: $this->createMock(LoggerInterface::class));
	}//end papers()

	/**
	 * Unset is substantial; high and any unknown value are high.
	 *
	 * @return void
	 */
	public function testTheTrustSetting(): void {
		self::assertSame('substantial', $this->papers()->minTrust());
		self::assertSame('high', $this->papers(setting: 'high')->minTrust());
		self::assertSame('high', $this->papers(setting: 'low')->minTrust());
		self::assertSame('substantial', ConfidentialPapers::trustOf(value: ' Substantial '));
	}//end testTheTrustSetting()

	/**
	 * A non-public item lists its two papers with a file reference.
	 *
	 * @return void
	 */
	public function testANonPublicItemListsItsPapers(): void {
		$entries = $this->papers()->papers(itemId: 'agendapunt-grondaankoop-lindelaan');

		self::assertSame(['document-taxatierapport-lindelaan', 'document-onderhandelingsmandaat-lindelaan'], array_column($entries, 'id'));
		self::assertSame(
			['register' => 'decidiq', 'schema' => 'digital-document', 'id' => 'document-taxatierapport-lindelaan', 'fileId' => '900501'],
			$entries[0]['file']
		);
		self::assertSame('Taxatierapport percelen Lindelaan.pdf', $entries[0]['title']);
		self::assertSame('application/pdf', $entries[0]['mimeType']);
	}//end testANonPublicItemListsItsPapers()

	/**
	 * Once the item is public again, or for a public item, the list is empty.
	 *
	 * @return void
	 */
	public function testAPublicItemListsNothing(): void {
		self::assertSame([], $this->papers(public: true)->papers(itemId: 'agendapunt-grondaankoop-lindelaan'));
		self::assertSame([], $this->papers()->papers(itemId: 'agendapunt-open'));
		self::assertSame([], $this->papers()->papers(itemId: 'unknown'));
	}//end testAPublicItemListsNothing()

	/**
	 * An opening appends one material-access entry with channel portal and the trust, and returns true.
	 *
	 * @return void
	 */
	public function testAnOpeningIsLoggedFirst(): void {
		$opened = $this->papers()->opened(itemId: 'agendapunt-grondaankoop-lindelaan', documentId: 'document-taxatierapport-lindelaan', subject: self::SUBJECT);

		self::assertTrue($opened);
		self::assertCount(1, $this->appended);
		self::assertSame('material-access', $this->appended[0]['action']);
		self::assertSame('portal:portal-account-pieter-bos', $this->appended[0]['actor']);
		self::assertSame(['agendapunt-grondaankoop-lindelaan', 'document-taxatierapport-lindelaan'], $this->appended[0]['objectUids']);
		self::assertSame('portal', $this->appended[0]['payload']['channel']);
		self::assertSame('substantial', $this->appended[0]['payload']['trust']);
		self::assertSame('pieter-bos', $this->appended[0]['payload']['person']);
	}//end testAnOpeningIsLoggedFirst()

	/**
	 * A failed or thrown append returns false, and so does a paper of another item.
	 *
	 * @return void
	 */
	public function testAnOpeningThatIsNotLoggedIsRefused(): void {
		self::assertFalse($this->papers(audit: 'fail')->opened(itemId: 'agendapunt-grondaankoop-lindelaan', documentId: 'document-taxatierapport-lindelaan', subject: self::SUBJECT));
		self::assertFalse($this->papers(audit: 'throw')->opened(itemId: 'agendapunt-grondaankoop-lindelaan', documentId: 'document-taxatierapport-lindelaan', subject: self::SUBJECT));
		self::assertFalse($this->papers()->opened(itemId: 'agendapunt-grondaankoop-lindelaan', documentId: 'document-elders', subject: self::SUBJECT));
		self::assertFalse($this->papers(public: true)->opened(itemId: 'agendapunt-grondaankoop-lindelaan', documentId: 'document-taxatierapport-lindelaan', subject: self::SUBJECT));
	}//end testAnOpeningThatIsNotLoggedIsRefused()
}//end class
