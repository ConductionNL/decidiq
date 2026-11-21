<?php

/**
 * Recording and listing the openings of papers under an active
 * confidentiality restriction.
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

use OCA\Decidiq\Exception\ConfidentialityImposeRefusedException;
use OCA\Decidiq\Kernel\Contracts\AuditLogServiceInterface;
use OCA\Decidiq\Service\ConfidentialityImposer;
use OCA\Decidiq\Service\RestrictedPaperViews;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Tests for RestrictedPaperViews.
 *
 * @spec openspec/changes/embargo-geheimhouding/specs/embargo-geheimhouding/spec.md#requirement-req-emb-007-view-audit-trail-for-stukken-under-geheimhouding
 */
class RestrictedPaperViewsTest extends TestCase {

	/**
	 * Objects per schema, by id.
	 *
	 * @var array<string, array<string, array<string, mixed>>>
	 */
	private array $objects = [];

	/**
	 * Every append: [actor, action, uids, payload].
	 *
	 * @var list<array{0: string, 1: string, 2: list<string>, 3: array<string, mixed>}>
	 */
	private array $appended = [];

	/**
	 * Whether an append succeeds.
	 *
	 * @var bool
	 */
	private bool $appendSucceeds = true;

	/**
	 * Whether a findAll throws.
	 *
	 * @var bool
	 */
	private bool $readFails = false;

	/**
	 * The audit query answer.
	 *
	 * @var array<string, mixed>
	 */
	private array $queryAnswer = ['success' => true, 'entries' => []];

	/**
	 * Seed: a paper record for file 42, an item restriction, a document restriction, and a lifted one.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->objects = [
			'digital-document' => [
				'doc-1' => ['id' => 'doc-1', 'fileId' => 42, 'title' => 'Grondexploitatie.pdf'],
				'doc-2' => ['id' => 'doc-2', 'fileId' => 43],
			],
			'confidentiality-restriction' => [
				'r-item' => ['id' => 'r-item', 'scope' => 'item', 'targetAgendaItem' => 'item-1', 'lifecycle' => 'ratified', 'imposedByBody' => 'body-raad'],
				'r-doc' => ['id' => 'r-doc', 'scope' => 'document', 'targetDocument' => 'doc-1', 'lifecycle' => 'imposed', 'imposedByBody' => 'body-college'],
				'r-lifted' => ['id' => 'r-lifted', 'scope' => 'document', 'targetDocument' => 'doc-2', 'lifecycle' => 'dissolved', 'imposedByBody' => 'body-college'],
				'r-lapsed' => ['id' => 'r-lapsed', 'scope' => 'item', 'targetAgendaItem' => 'item-2', 'lifecycle' => 'lapsed', 'imposedByBody' => 'body-raad'],
			],
		];
	}//end setUp()

	/**
	 * The service over the register.
	 *
	 * @param list<string> $authorityOver Bodies whose staff the caller is.
	 *
	 * @return RestrictedPaperViews
	 */
	private function views(array $authorityOver=['body-college']): RestrictedPaperViews {
		$objects = $this->createMock(originalClassName: ObjectServiceInterface::class);
		$objects->method('find')->willReturnCallback(
			function (int|string $id, ?ObjectFindOptions $options=null): ?ObjectEntity {
				$row = ($this->objects[(string)($options?->schema ?? '')][(string)$id] ?? null);
				return $row === null ? null : self::entity(row: $row);
			}
		);
		$objects->method('findAll')->willReturnCallback(
			function (array $config=[]): array {
				if ($this->readFails === true) {
					throw new RuntimeException('register down');
				}

				$filters = (array)($config['filters'] ?? []);
				$schema  = (string)($filters['schema'] ?? '');
				unset($filters['register'], $filters['schema']);
				$found = [];
				foreach (($this->objects[$schema] ?? []) as $row) {
					if (array_intersect_assoc($filters, $row) == $filters) {
						$found[] = self::entity(row: $row);
					}
				}

				return $found;
			}
		);

		$audit = $this->createMock(originalClassName: AuditLogServiceInterface::class);
		$audit->method('append')->willReturnCallback(
			function (string $actor, string $action, array $objectUids, array $payload=[]): array {
				$this->appended[] = [$actor, $action, $objectUids, $payload];
				return ['success' => $this->appendSucceeds, 'entry' => null, 'message' => ''];
			}
		);
		$audit->method('query')->willReturnCallback(fn (array $filters=[]): array => $this->queryAnswer);

		$imposer = $this->createMock(originalClassName: ConfidentialityImposer::class);
		$imposer->method('mayImpose')->willReturnCallback(static fn (string $uid, string $body): bool => in_array($body, $authorityOver, true));

		return new RestrictedPaperViews(objectService: $objects, auditLog: $audit, imposer: $imposer, logger: new NullLogger());
	}//end views()

	/**
	 * An entity over a row.
	 *
	 * @param array<string, mixed> $row The object.
	 *
	 * @return ObjectEntity
	 */
	private static function entity(array $row): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid((string)($row['id'] ?? ''));
		unset($row['id']);
		$entity->setObject($row);
		return $entity;
	}//end entity()

	/**
	 * Opening a paper under a document restriction writes one entry: reader, restriction, document, file.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/embargo-geheimhouding/specs/embargo-geheimhouding/spec.md#scenario-viewing-a-geheim-document-is-audited
	 */
	public function testAnOpeningUnderARestrictionIsRecorded(): void {
		$this->assertNull($this->views()->record(readerUid: 'j.jansen', agendaItemId: 'item-9', fileId: 42));

		$this->assertCount(1, $this->appended);
		[$actor, $action, $uids, $payload] = $this->appended[0];
		$this->assertSame('j.jansen', $actor);
		$this->assertSame('material-access', $action);
		$this->assertSame(['r-doc', 'doc-1', 'item-9'], $uids);
		$this->assertSame(
			['kind' => 'restricted-paper-view', 'channel' => 'app', 'restriction' => 'r-doc', 'document' => 'doc-1', 'agendaItem' => 'item-9', 'fileId' => 42],
			$payload
		);
	}//end testAnOpeningUnderARestrictionIsRecorded()

	/**
	 * A paper on a restricted item is recorded once per restriction in force.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/embargo-geheimhouding/specs/embargo-geheimhouding/spec.md#scenario-viewing-a-geheim-document-is-audited
	 */
	public function testEachRestrictionInForceIsRecorded(): void {
		$this->assertNull($this->views()->record(readerUid: 'j.jansen', agendaItemId: 'item-1', fileId: 42));

		$this->assertSame(['r-item', 'r-doc'], array_map(static fn (array $call): string => $call[3]['restriction'], $this->appended));
	}//end testEachRestrictionInForceIsRecorded()

	/**
	 * After lifting or lapse, and on an unrestricted paper, nothing is specially recorded.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/embargo-geheimhouding/specs/embargo-geheimhouding/spec.md#requirement-req-emb-007-view-audit-trail-for-stukken-under-geheimhouding
	 */
	public function testNoEntryWithoutARestrictionInForce(): void {
		$views = $this->views();

		$this->assertNull($views->record(readerUid: 'j.jansen', agendaItemId: 'item-2', fileId: 43));
		$this->assertNull($views->record(readerUid: 'j.jansen', agendaItemId: null, fileId: 99));
		$this->assertSame([], $this->appended);
	}//end testNoEntryWithoutARestrictionInForce()

	/**
	 * Fail closed: an entry that cannot be written, or restrictions that cannot be read, refuse the opening.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/embargo-geheimhouding/specs/embargo-geheimhouding/spec.md#requirement-req-emb-007-view-audit-trail-for-stukken-under-geheimhouding
	 */
	public function testAnOpeningThatCannotBeRecordedIsRefused(): void {
		$this->appendSucceeds = false;
		$this->assertStringContainsString('could not be recorded', (string)$this->views()->record(readerUid: 'j.jansen', agendaItemId: null, fileId: 42));

		$this->readFails = true;
		$this->assertStringContainsString('could not be checked', (string)$this->views()->record(readerUid: 'j.jansen', agendaItemId: null, fileId: 42));
	}//end testAnOpeningThatCannotBeRecordedIsRefused()

	/**
	 * The staff list the openings of one restriction, newest first, and only its own.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/embargo-geheimhouding/specs/embargo-geheimhouding/spec.md#scenario-viewing-a-geheim-document-is-audited
	 */
	public function testTheStaffListTheOpenings(): void {
		$this->queryAnswer = ['success' => true, 'entries' => [
			['actorUuid' => 'j.jansen', 'timestamp' => '2026-11-20T20:15:00+01:00', 'payload' => ['kind' => 'restricted-paper-view', 'restriction' => 'r-doc', 'document' => 'doc-1', 'agendaItem' => '', 'fileId' => 42]],
			['actorUuid' => 'portal:x', 'timestamp' => '2026-11-21T09:00:00+01:00', 'payload' => ['kind' => 'portal-confidential-paper', 'restriction' => 'r-doc']],
			['actorUuid' => 'a.bakker', 'timestamp' => '2026-11-21T08:00:00+01:00', 'payload' => ['kind' => 'restricted-paper-view', 'restriction' => 'r-doc', 'document' => 'doc-1', 'agendaItem' => 'item-9', 'fileId' => 42]],
			['actorUuid' => 'k.de.vries', 'timestamp' => '2026-11-21T07:00:00+01:00', 'payload' => ['kind' => 'restricted-paper-view', 'restriction' => 'r-item']],
			'not an entry',
		]];

		$views = $this->views()->viewsOf(userId: 'griffier', restrictionId: 'r-doc');

		$this->assertSame(['a.bakker', 'j.jansen'], array_column($views, 'actor'));
		$this->assertSame(['at' => '2026-11-21T08:00:00+01:00', 'document' => 'doc-1', 'agendaItem' => 'item-9', 'fileId' => 42], array_slice($views[0], 1));
	}//end testTheStaffListTheOpenings()

	/**
	 * Non-staff get 403, an unknown restriction 404, an unreadable audit 503.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/embargo-geheimhouding/specs/embargo-geheimhouding/spec.md#requirement-req-emb-007-view-audit-trail-for-stukken-under-geheimhouding
	 */
	public function testTheListIsForStaffOnly(): void {
		$statusOf = static function (callable $call): int {
			try {
				$call();
			} catch (ConfidentialityImposeRefusedException $e) {
				return $e->getStatus();
			}

			return 0;
		};

		$this->assertSame(403, $statusOf(fn () => $this->views(authorityOver: [])->viewsOf(userId: 'member', restrictionId: 'r-doc')));
		$this->assertSame(404, $statusOf(fn () => $this->views()->viewsOf(userId: 'griffier', restrictionId: 'r-none')));
		$this->queryAnswer = ['success' => false, 'entries' => []];
		$this->assertSame(503, $statusOf(fn () => $this->views()->viewsOf(userId: 'griffier', restrictionId: 'r-doc')));
	}//end testTheListIsForStaffOnly()
}//end class
