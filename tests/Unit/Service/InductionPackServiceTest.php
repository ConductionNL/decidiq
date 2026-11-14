<?php

/**
 * Unit tests for InductionPackService: the body's induction documents land
 * in a folder in the member's own Files, and what cannot be delivered is
 * listed, never silently dropped.
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
 * @spec openspec/changes/member-onboarding/specs/member-onboarding/spec.md#requirement-req-mob-007-induction-pack-delivered-into-the-members-files
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service;

use DateTime;
use OCA\Decidiq\Service\InductionPackService;
use OCA\Decidiq\Service\Pathway\PathwayResult;
use OCA\Decidiq\Service\Pathway\PathwaySteps;
use OCA\Decidiq\Service\RegisterObjectStore;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for InductionPackService.
 *
 * @spec openspec/changes/member-onboarding/specs/member-onboarding/spec.md#requirement-req-mob-007-induction-pack-delivered-into-the-members-files
 */
#[CoversClass(InductionPackService::class)]
#[UsesClass(PathwayResult::class)]
#[UsesClass(PathwaySteps::class)]
class InductionPackServiceTest extends TestCase {

	/**
	 * The register's objects.
	 *
	 * @var RegisterObjectStore&MockObject
	 */
	private RegisterObjectStore&MockObject $store;

	/**
	 * Nextcloud Files.
	 *
	 * @var IRootFolder&MockObject
	 */
	private IRootFolder&MockObject $root;

	/**
	 * The member's home folder.
	 *
	 * @var Folder&MockObject
	 */
	private Folder&MockObject $memberHome;

	/**
	 * The confirming secretary's home folder (where sources are read).
	 *
	 * @var Folder&MockObject
	 */
	private Folder&MockObject $actorHome;

	/**
	 * The induction folder made in the member's home.
	 *
	 * @var Folder&MockObject
	 */
	private Folder&MockObject $pack;

	/**
	 * Records by schema and uuid.
	 *
	 * @var array<string, array<string, array<string,mixed>>>
	 */
	private array $records = [];

	/**
	 * Files written into the pack, by name.
	 *
	 * @var array<string,string>
	 */
	private array $written = [];

	/**
	 * Build the Files tree and the store.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->store = $this->createMock(RegisterObjectStore::class);
		$this->store->method('find')->willReturnCallback(
			fn (string $schema, string $uuid): ?array => ($this->records[$schema][$uuid] ?? null)
		);

		$this->pack = $this->createMock(Folder::class);
		$this->pack->method('getName')->willReturn('Welcome - Council');
		$this->pack->method('nodeExists')->willReturn(false);
		$this->pack->method('newFile')->willReturnCallback(
			function (string $name, string $content): File {
				$this->written[$name] = $content;
				return $this->createMock(File::class);
			}
		);

		$this->memberHome = $this->createMock(Folder::class);
		$this->actorHome  = $this->createMock(Folder::class);
		$this->root       = $this->createMock(IRootFolder::class);
		$this->root->method('getUserFolder')->willReturnCallback(
			fn (string $uid): Folder => match ($uid) {
				'sem' => $this->memberHome,
				'griffier' => $this->actorHome,
			}
		);

		$this->records['member-onboarding']['onb-1'] = [
			'ncAccount'  => 'sem',
			'targetBody' => 'body-1',
			'steps'      => [
				['stepType' => 'assign-groups', 'status' => 'completed', 'note' => 'Groups: council'],
				['stepType' => 'induction-pack', 'status' => 'open'],
			],
		];
	}//end setUp()

	/**
	 * Files and links are delivered into a new folder, a missing file is
	 * listed as skipped, and the step completes with the folder as reference.
	 *
	 * @return void
	 */
	public function testDeliversFilesAndLinksAndListsWhatWasSkipped(): void {
		$this->givenDocuments(
			[
				['title' => 'Rules of procedure', 'fileId' => 11],
				['title' => 'Old handbook', 'fileId' => 99],
				['title' => 'Meeting schedule', 'url' => 'https://example.org/meetings'],
			]
		);
		$this->memberHome->method('nodeExists')->with('Welcome - Council')->willReturn(false);
		$this->memberHome->expects($this->once())->method('newFolder')->with('Welcome - Council')->willReturn($this->pack);
		$this->actorHome->method('getById')->willReturnCallback(
			fn (int $id): array => ($id === 11) ? [$this->file(name: 'Rules.pdf', content: 'rules')] : []
		);

		$patched = null;
		$this->store->expects($this->once())->method('patch')->willReturnCallback(
			function (string $schema, array $data, string $uuid) use (&$patched): array {
				$patched = $data['steps'];
				return [];
			}
		);

		$result = $this->service()->deliver(onboardingId: 'onb-1', actor: 'griffier');

		$this->assertTrue($result->isDone());
		$this->assertSame(['Rules of procedure', 'Links.md'], $result->details['delivered']);
		$this->assertSame(['Old handbook (no longer exists)'], $result->details['skipped']);
		$this->assertSame('rules', $this->written['Rules.pdf']);
		$this->assertSame("# Links\n\n- [Meeting schedule](https://example.org/meetings)\n", $this->written['Links.md']);
		$this->assertSame('/Welcome - Council', $patched[1]['reference']);
		$this->assertSame('completed', $patched[1]['status']);
		$this->assertSame(
			'Delivered 2 item(s) to /Welcome - Council. Skipped: Old handbook (no longer exists).',
			$patched[1]['note']
		);
		$this->assertSame(['stepType' => 'assign-groups', 'status' => 'completed', 'note' => 'Groups: council'], $patched[0]);
	}//end testDeliversFilesAndLinksAndListsWhatWasSkipped()

	/**
	 * A delivery run again reuses the folder and replaces the file.
	 *
	 * @return void
	 */
	public function testARepeatedDeliveryReusesTheFolderAndReplacesTheFile(): void {
		$this->givenDocuments([['title' => 'Rules', 'fileId' => 11]]);
		$pack     = $this->createMock(Folder::class);
		$existing = $this->createMock(File::class);
		$pack->method('getName')->willReturn('Welcome - Council');
		$pack->method('nodeExists')->with('Rules.pdf')->willReturn(true);
		$pack->method('get')->with('Rules.pdf')->willReturn($existing);
		$existing->expects($this->once())->method('putContent')->with('new rules');
		$pack->expects($this->never())->method('newFile');
		$this->memberHome->method('nodeExists')->willReturn(true);
		$this->memberHome->method('get')->with('Welcome - Council')->willReturn($pack);
		$this->memberHome->expects($this->never())->method('newFolder');
		$this->actorHome->method('getById')->willReturn([$this->file(name: 'Rules.pdf', content: 'new rules')]);

		$this->assertTrue($this->service()->deliver(onboardingId: 'onb-1', actor: 'griffier')->isDone());
	}//end testARepeatedDeliveryReusesTheFolderAndReplacesTheFile()

	/**
	 * When nothing could be delivered the step stays open and the reasons are named.
	 *
	 * @return void
	 */
	public function testNothingDeliveredKeepsTheStepOpen(): void {
		$this->givenDocuments([['title' => 'Old handbook', 'fileId' => 99], ['title' => 'Empty']]);
		$this->memberHome->method('newFolder')->willReturn($this->pack);
		$this->actorHome->method('getById')->willReturn([]);
		$this->store->expects($this->never())->method('patch');

		$result = $this->service()->deliver(onboardingId: 'onb-1', actor: 'griffier');

		$this->assertSame('failed', $result->outcome);
		$this->assertSame(
			'Nothing could be delivered. Skipped: Old handbook (no longer exists); Empty (no file or link).',
			$result->message
		);
	}//end testNothingDeliveredKeepsTheStepOpen()

	/**
	 * Without a linked account the delivery points at the account linking step.
	 *
	 * @return void
	 */
	public function testRefusesWithoutALinkedAccount(): void {
		$this->records['member-onboarding']['onb-1']['ncAccount'] = '';
		$this->root->expects($this->never())->method('getUserFolder');

		$this->assertSame(
			'Link the member to a Nextcloud account first (the account linking step).',
			$this->service()->deliver(onboardingId: 'onb-1', actor: 'griffier')->message
		);
	}//end testRefusesWithoutALinkedAccount()

	/**
	 * A body without induction documents is refused before Files is touched.
	 *
	 * @return void
	 */
	public function testRefusesWhenTheBodyHasNoDocuments(): void {
		$this->givenDocuments([]);
		$this->root->expects($this->never())->method('getUserFolder');

		$this->assertSame(
			'No induction documents are configured for this body.',
			$this->service()->deliver(onboardingId: 'onb-1', actor: 'griffier')->message
		);
	}//end testRefusesWhenTheBodyHasNoDocuments()

	/**
	 * A delivered pack is not delivered twice.
	 *
	 * @return void
	 */
	public function testRefusesACompletedStep(): void {
		$this->records['member-onboarding']['onb-1']['steps'][1]['status'] = 'completed';

		$this->assertSame(
			'The induction pack has already been delivered.',
			$this->service()->deliver(onboardingId: 'onb-1', actor: 'griffier')->message
		);
	}//end testRefusesACompletedStep()

	/**
	 * Configure the body.
	 *
	 * @param list<array<string,mixed>> $documents The induction documents.
	 *
	 * @return void
	 */
	private function givenDocuments(array $documents): void {
		$this->records['governance-body']['body-1'] = ['name' => 'Council', 'inductionDocuments' => $documents];
	}//end givenDocuments()

	/**
	 * A source file.
	 *
	 * @param string $name    The name.
	 * @param string $content The content.
	 *
	 * @return File&MockObject
	 */
	private function file(string $name, string $content): File&MockObject {
		$file = $this->createMock(File::class);
		$file->method('getName')->willReturn($name);
		$file->method('getContent')->willReturn($content);
		return $file;
	}//end file()

	/**
	 * The service under test, with the member's account present.
	 *
	 * @return InductionPackService
	 */
	private function service(): InductionPackService {
		$users = $this->createMock(IUserManager::class);
		$users->method('get')->willReturnCallback(fn (string $uid): ?IUser => ($uid === 'sem') ? $this->createMock(IUser::class) : null);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new DateTime('2026-11-14T10:00:00+00:00'));

		return new InductionPackService(
			store: $this->store,
			rootFolder: $this->root,
			userManager: $users,
			time: $time,
			logger: $this->createMock(LoggerInterface::class),
		);
	}//end service()

}//end class
