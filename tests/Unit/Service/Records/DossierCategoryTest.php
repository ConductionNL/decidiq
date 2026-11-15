<?php

/**
 * Unit tests for DossierCategory in isolation: the archivist check runs
 * before the dossier is read, a dossier on any list keeps its category, a
 * category needs both a pointer property and a Selectielijst row, and the
 * write goes to OpenRegister in system context with exactly the dossier's
 * data.
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\Service\Records
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/records-management-archiving/specs/records-management-archiving/spec.md#requirement-req-rma-003-retention-via-openregister-selectielijst-and-retentionservice
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service\Records;

use OCA\Decidiq\Exception\AccessDeniedException;
use OCA\Decidiq\Exception\DossierRefusedException;
use OCA\Decidiq\Exception\MissingObjectException;
use OCA\Decidiq\Service\Records\ArchivistGuard;
use OCA\Decidiq\Service\Records\DossierCategory;
use OCA\Decidiq\Service\Records\SelectionCategoryReader;
use OCA\Decidiq\Service\SettingsService;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;

/**
 * Tests for setting one dossier's Selectielijst category.
 *
 * @covers \OCA\Decidiq\Service\Records\DossierCategory
 * @uses   \OCA\Decidiq\Service\Records\SelectionCategoryReader
 * @uses   \OCA\Decidiq\Service\Records\ArchivistGuard
 * @uses   \OCA\Decidiq\Exception\DossierRefusedException
 * @uses   \OCA\Decidiq\Exception\AccessDeniedException
 * @uses   \OCA\Decidiq\Exception\MissingObjectException
 */
class DossierCategoryTest extends TestCase {

	private const DOSSIER = 'dossier-uuid-1';

	/**
	 * The dossier OpenRegister holds, or null for none.
	 *
	 * @var array<string, mixed>|null
	 */
	private ?array $stored = ['title' => 'Raadsdossier 2025', 'lifecycle' => 'closed'];

	/**
	 * The ArchivalDossier archive block of the merged register.
	 *
	 * @var array<string, mixed>
	 */
	private array $archive = ['enabled' => true, 'classification' => '2.1', 'classificationProperty' => 'selectielijstCategorie'];

	/**
	 * Every find call: [id, register, schema, rbac, multitenancy].
	 *
	 * @var list<array<int, mixed>>
	 */
	private array $finds = [];

	/**
	 * Every save call: [object, register, schema, uuid, rbac, multitenancy].
	 *
	 * @var list<array<int, mixed>>
	 */
	private array $saves = [];

	/**
	 * Whether the signed-in user is in the archivist group.
	 *
	 * @var bool
	 */
	private bool $archivist = true;

	/**
	 * Whether anyone is signed in.
	 *
	 * @var bool
	 */
	private bool $signedIn = true;

	/**
	 * The setter over the fakes of this test.
	 *
	 * @return DossierCategory
	 */
	private function category(): DossierCategory {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('mergedRegisterConfig')->willReturnCallback(
			fn (): array => [
				'components' => [
					'schemas' => ['ArchivalDossier' => ['archive' => $this->archive]],
					'selectionLists' => [
						['category' => '2.1', 'action' => 'bewaren', 'description' => 'Raadsbesluiten'],
						['category' => '11.1', 'action' => 'vernietigen', 'retentionYears' => 10, 'description' => 'Correspondentie'],
					],
				],
			]
		);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('archivaris1');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturnCallback(fn (): ?IUser => $this->signedIn === true ? $user : null);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturn(false);
		$groups->method('isInGroup')->willReturnCallback(fn (string $uid, string $group): bool => $this->archivist === true && $group === ArchivistGuard::GROUP);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $params = []): string => vsprintf($text, $params));

		$objectService = $this->createMock(ObjectServiceInterface::class);
		$objectService->method('find')->willReturnCallback(
			function (int|string $id, ?ObjectFindOptions $options = null, bool $_rbac = true, bool $_multitenancy = true): ?ObjectEntity {
				$_extend = $options?->extend ?? [];
				$files = $options?->files ?? false;
				$register = $options?->register;
				$schema = $options?->schema;
				$this->finds[] = [$id, $register, $schema, $_rbac, $_multitenancy];
				if ($this->stored === null) {
					return null;
				}

				return self::entity(id: (string)$id, data: $this->stored);
			}
		);
		$objectService->method('saveObject')->willReturnCallback(
			function (array $object, ObjectSaveOptions $options = new ObjectSaveOptions(), bool $_rbac = true, bool $_multitenancy = true): ObjectEntity {
				$extend = $options->extend ?? [];
				$register = $options->target->register;
				$schema = $options->target->schema;
				$uuid = $options->uuid;
				$this->saves[] = [$object, $register, $schema, $uuid, $_rbac, $_multitenancy];
				return self::entity(id: (string)$uuid, data: $object);
			}
		);

		return new DossierCategory(
			objectService: $objectService,
			categories: new SelectionCategoryReader(settings: $settings),
			guard: new ArchivistGuard(userSession: $session, groupManager: $groups),
			l10n: $l10n,
		);
	}//end category()

	/**
	 * An OpenRegister entity holding this dossier.
	 *
	 * @param string               $id   The uuid
	 * @param array<string, mixed> $data The dossier
	 *
	 * @return ObjectEntity
	 */
	private static function entity(string $id, array $data): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid($id);
		$entity->setSchema('archival-dossier');
		$entity->setRegister('decidiq');
		$entity->setObject($data);
		return $entity;
	}//end entity()

	/**
	 * A category other than the schema's is written on the pointer property,
	 * in system context, on the dossier's own uuid, without its id or
	 * metadata; the answer is the stored dossier with its id first.
	 *
	 * @return void
	 */
	public function testADifferentCategoryIsWrittenInSystemContext(): void {
		$dossier = $this->category()->set(dossierId: self::DOSSIER, category: '11.1');

		self::assertSame([[self::DOSSIER, 'decidiq', 'archival-dossier', false, false]], $this->finds);
		self::assertSame(
			[[['title' => 'Raadsdossier 2025', 'lifecycle' => 'closed', 'selectielijstCategorie' => '11.1'], 'decidiq', 'archival-dossier', self::DOSSIER, false, false]],
			$this->saves
		);
		self::assertSame(
			['id' => self::DOSSIER, 'title' => 'Raadsdossier 2025', 'lifecycle' => 'closed', 'selectielijstCategorie' => '11.1'],
			$dossier
		);
	}//end testADifferentCategoryIsWrittenInSystemContext()

	/**
	 * Choosing the schema's own category removes an earlier override rather
	 * than writing the schema's value on the dossier.
	 *
	 * @return void
	 */
	public function testTheSchemasCategoryRemovesTheOverride(): void {
		$this->stored['selectielijstCategorie'] = '11.1';

		$dossier = $this->category()->set(dossierId: self::DOSSIER, category: '2.1');

		self::assertCount(1, $this->saves);
		self::assertSame(['title' => 'Raadsdossier 2025', 'lifecycle' => 'closed'], $this->saves[0][0]);
		self::assertArrayNotHasKey('selectielijstCategorie', $dossier);
		self::assertSame(self::DOSSIER, $dossier['id']);
	}//end testTheSchemasCategoryRemovesTheOverride()

	/**
	 * A dossier on a transfer list keeps its category, the same as one on a
	 * destruction list, and nothing is written.
	 *
	 * @param string $list The list property
	 *
	 * @return void
	 *
	 * @dataProvider lists
	 */
	public function testADossierOnAListKeepsItsCategory(string $list): void {
		$this->stored[$list] = 'list-uuid';

		try {
			$this->category()->set(dossierId: self::DOSSIER, category: '11.1');
			self::fail('A dossier on a list keeps its category.');
		} catch (DossierRefusedException $e) {
			self::assertSame(DossierRefusedException::ALREADY_PROPOSED, $e->getReason());
			self::assertSame('The dossier is already on a list in OpenRegister, so its category is fixed.', $e->getMessage());
		}

		self::assertSame([], $this->saves);
	}//end testADossierOnAListKeepsItsCategory()

	/**
	 * The two lists a dossier can be on.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function lists(): array {
		return [
			'transfer list' => ['transferList'],
			'destruction list' => ['destructionList'],
		];
	}//end lists()

	/**
	 * A schema without a pointer property has nowhere to write an override,
	 * so even a known category is refused, naming the category.
	 *
	 * @return void
	 */
	public function testWithoutAPointerPropertyEvenAKnownCategoryIsRefused(): void {
		unset($this->archive['classificationProperty']);

		try {
			$this->category()->set(dossierId: self::DOSSIER, category: '11.1');
			self::fail('Without a pointer the category is refused.');
		} catch (DossierRefusedException $e) {
			self::assertSame(DossierRefusedException::NO_CATEGORY, $e->getReason());
			self::assertSame('The register ships no Selectielijst category 11.1 for dossiers.', $e->getMessage());
		}

		self::assertSame([], $this->saves);
	}//end testWithoutAPointerPropertyEvenAKnownCategoryIsRefused()

	/**
	 * A category the register ships no row for is refused and not written.
	 *
	 * @return void
	 */
	public function testAnUnknownCategoryIsRefused(): void {
		try {
			$this->category()->set(dossierId: self::DOSSIER, category: '42.7');
			self::fail('An unknown category is refused.');
		} catch (DossierRefusedException $e) {
			self::assertSame(DossierRefusedException::NO_CATEGORY, $e->getReason());
			self::assertSame('The register ships no Selectielijst category 42.7 for dossiers.', $e->getMessage());
		}

		self::assertSame([], $this->saves);
	}//end testAnUnknownCategoryIsRefused()

	/**
	 * Someone who is not an archivist is refused before the dossier is even read.
	 *
	 * @return void
	 */
	public function testANonArchivistIsRefusedBeforeTheDossierIsRead(): void {
		$this->archivist = false;

		try {
			$this->category()->set(dossierId: self::DOSSIER, category: '11.1');
			self::fail('A non-archivist is refused.');
		} catch (AccessDeniedException $e) {
			self::assertStringContainsString('archivist', $e->getMessage());
		}

		self::assertSame([], $this->finds);
		self::assertSame([], $this->saves);
	}//end testANonArchivistIsRefusedBeforeTheDossierIsRead()

	/**
	 * Nobody signed in is refused the same way.
	 *
	 * @return void
	 */
	public function testNobodySignedInIsRefused(): void {
		$this->signedIn = false;

		$this->expectException(AccessDeniedException::class);
		$this->expectExceptionMessage('Not signed in.');
		$this->category()->set(dossierId: self::DOSSIER, category: '11.1');
	}//end testNobodySignedInIsRefused()

	/**
	 * A dossier OpenRegister does not hold is missing, after one lookup.
	 *
	 * @return void
	 */
	public function testAnUnknownDossierIsMissing(): void {
		$this->stored = null;

		try {
			$this->category()->set(dossierId: 'gone', category: '11.1');
			self::fail('An unknown dossier is missing.');
		} catch (MissingObjectException $e) {
			self::assertSame('Dossier not found.', $e->getMessage());
		}

		self::assertSame([['gone', 'decidiq', 'archival-dossier', false, false]], $this->finds);
		self::assertSame([], $this->saves);
	}//end testAnUnknownDossierIsMissing()
}//end class
