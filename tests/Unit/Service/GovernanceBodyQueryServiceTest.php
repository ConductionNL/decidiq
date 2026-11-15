<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category  Test
 * @package   OCA\Decidiq\Tests\Unit\Service
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 * @link      https://github.com/ConductionNL/decidiq
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service;

use OCA\Decidiq\Service\GovernanceBodyCommandService;
use OCA\Decidiq\Service\GovernanceBodyQueryService;
use OCA\Decidiq\Service\RegisterObjectStore;
use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use PHPUnit\Framework\TestCase;

/**
 * REQ-GBE-007: a consumer reads back the governance body it raised.
 *
 * The bodies are WRITTEN by the real GovernanceBodyCommandService, so the read
 * is tested against the exact rows the write seam produces, not a hand-made
 * shape that could drift from it.
 */
class GovernanceBodyQueryServiceTest extends TestCase {

	/**
	 * Rows per schema, keyed by id.
	 *
	 * @var array<string, array<string, array<string, mixed>>>
	 */
	private array $rows = ['governance-body' => [], 'person' => [], 'membership' => []];

	private int $counter = 0;

	private RegisterObjectStore $store;

	protected function setUp(): void {
		parent::setUp();

		$facade = $this->createMock(ObjectServiceInterface::class);
		$facade->method('saveObject')->willReturnCallback(
			function (array $object, ?array $extend = [], string|int|null $register = null, string|int|null $schema = null, ?string $uuid = null): ObjectEntityInterface {
				$schema = (string)$schema;
				if ($uuid === null) {
					$this->counter++;
					$uuid = $schema . '-' . $this->counter;
				}

				$this->rows[$schema][$uuid] = (array_merge(($this->rows[$schema][$uuid] ?? []), $object) + ['id' => $uuid]);

				return $this->entity($this->rows[$schema][$uuid]);
			}
		);
		$facade->method('findAll')->willReturnCallback(
			function (array $config = []): array {
				$filters = $config['filters'];
				$schema = $filters['schema'];
				unset($filters['register'], $filters['schema']);
				$out = [];
				foreach (($this->rows[$schema] ?? []) as $row) {
					foreach ($filters as $key => $value) {
						if (($row[$key] ?? null) !== $value) {
							continue 2;
						}
					}

					$out[] = $this->entity($row);
				}

				return $out;
			}
		);
		$facade->method('find')->willReturnCallback(
			function (int|string $id, ?array $_extend = [], bool $files = false, mixed $register = null, mixed $schema = null): ?ObjectEntityInterface {
				$row = ($this->rows[(string)$schema][(string)$id] ?? null);

				return ($row === null) ? null : $this->entity($row);
			}
		);

		$this->store = new RegisterObjectStore($facade);

	}//end setUp()

	/**
	 * Wrap a row as OpenRegister returns it.
	 *
	 * @param array<string, mixed> $row The row.
	 *
	 * @return ObjectEntityInterface
	 */
	private function entity(array $row): ObjectEntityInterface {
		$entity = $this->createMock(ObjectEntityInterface::class);
		$entity->method('jsonSerialize')->willReturn($row);

		return $entity;

	}//end entity()

	/**
	 * Raise a committee through the real write seam.
	 *
	 * @param string $sourceApp The producing app.
	 * @param string $reference The producer's reference.
	 * @param bool   $active    The active flag.
	 *
	 * @return string The body id.
	 */
	private function raise(string $sourceApp = 'dossiq', string $reference = 'cmte-1', bool $active = true): string {
		$result = (new GovernanceBodyCommandService($this->store))->upsert(
			$sourceApp,
			$reference,
			[
				'name' => 'Bezwaarcommissie sociaal domein',
				'bodyType' => 'advisory-body',
				'domain' => 'social_domain',
				'active' => $active,
				'quorum' => 3,
				'jurisdiction' => 'Gemeente Zuiddrecht',
			],
			[
				['uid' => 'alice', 'role' => 'chair', 'name' => 'Alice Jansen'],
				['uid' => 'carol', 'role' => 'secretary', 'external' => true, 'label' => 'Extern secretaris'],
			]
		);

		return $result['id'];

	}//end raise()

	public function testLooksUpByExternalReferenceWithTheRoster(): void {
		$id = $this->raise();

		$body = (new GovernanceBodyQueryService($this->store))->lookup('dossiq', 'cmte-1', '');

		$this->assertNotNull($body);
		$this->assertSame($id, $body['id']);
		$this->assertTrue($body['active']);
		$this->assertSame(3, $body['quorum']);
		$this->assertSame('Gemeente Zuiddrecht', $body['jurisdiction']);
		$this->assertSame('cmte-1', $body['externalReference']);
		$this->assertCount(2, $body['members']);

		$byUid = array_column($body['members'], null, 'uid');
		$this->assertSame('chair', $byUid['alice']['role']);
		$this->assertSame('Alice Jansen', $byUid['alice']['name']);
		$this->assertFalse($byUid['alice']['external']);
		$this->assertSame('secretary', $byUid['carol']['role']);
		$this->assertTrue($byUid['carol']['external']);
		$this->assertSame('Extern secretaris', $byUid['carol']['label']);

	}//end testLooksUpByExternalReferenceWithTheRoster()

	public function testLooksUpByGovernanceBodyIdAndCarriesAnInactiveFlag(): void {
		$id = $this->raise(active: false);

		$body = (new GovernanceBodyQueryService($this->store))->lookup('dossiq', '', $id);

		$this->assertNotNull($body);
		$this->assertFalse($body['active']);

	}//end testLooksUpByGovernanceBodyIdAndCarriesAnInactiveFlag()

	public function testAnotherAppsBodyIsNotReturnedByIdOrReference(): void {
		$id = $this->raise(sourceApp: 'pipelinq');
		$query = new GovernanceBodyQueryService($this->store);

		$this->assertNull($query->lookup('dossiq', '', $id));
		$this->assertNull($query->lookup('dossiq', 'cmte-1', ''));

	}//end testAnotherAppsBodyIsNotReturnedByIdOrReference()

	public function testAnUnknownBodyOrAnEmptyKeyReadsAsNotFound(): void {
		$this->raise();
		$query = new GovernanceBodyQueryService($this->store);

		$this->assertNull($query->lookup('dossiq', 'cmte-unknown', ''));
		$this->assertNull($query->lookup('dossiq', '', ''));
		$this->assertNull($query->lookup('', 'cmte-1', ''));

	}//end testAnUnknownBodyOrAnEmptyKeyReadsAsNotFound()

	public function testAMembershipOfAnotherBodyIsNotOnTheRoster(): void {
		$this->raise(reference: 'cmte-1');
		$this->raise(reference: 'cmte-2');

		$body = (new GovernanceBodyQueryService($this->store))->lookup('dossiq', 'cmte-2', '');

		$this->assertCount(2, $body['members']);
		foreach ($body['members'] as $member) {
			$this->assertNotSame('', $member['uid']);
		}

		$this->assertSame(4, count($this->rows['membership']));

	}//end testAMembershipOfAnotherBodyIsNotOnTheRoster()
}//end class
