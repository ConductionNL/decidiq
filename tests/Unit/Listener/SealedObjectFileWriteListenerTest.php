<?php

/**
 * Unit tests for SealedObjectFileWriteListener.
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/governing-documents-register/specs/governing-documents-register/spec.md#requirement-req-gdr-003-version-immutability-once-effective
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Listener;

use OCA\Decidiq\Kernel\Contracts\ListenerSchemaResolverInterface;
use OCA\Decidiq\Listener\FileWriteEvent;
use OCA\Decidiq\Listener\Registrar\FilesEventRegistrar;
use OCA\Decidiq\Listener\SealedObjectFileWriteListener;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\Exceptions\AbortedEventException;
use OCP\Files\Events\Node\BeforeNodeCreatedEvent;
use OCP\Files\Events\Node\BeforeNodeDeletedEvent;
use OCP\Files\Events\Node\BeforeNodeRenamedEvent;
use OCP\Files\Events\Node\BeforeNodeWrittenEvent;
use OCP\Files\Events\Node\NodeWrittenEvent;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\HintException;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * A file in the folder of a sealed governing-document version is not added,
 * replaced, renamed or removed; anything else passes untouched.
 *
 * @covers \OCA\Decidiq\Listener\SealedObjectFileWriteListener
 * @covers \OCA\Decidiq\Listener\FileWriteEvent
 * @covers \OCA\Decidiq\Listener\Registrar\FilesEventRegistrar
 *
 * @spec openspec/changes/governing-documents-register/specs/governing-documents-register/spec.md#scenario-editing-a-sealed-version-refused
 */
class SealedObjectFileWriteListenerTest extends TestCase {

	/**
	 * Version 2 of the statutes, in effect.
	 *
	 * @var string
	 */
	private const IN_EFFECT = '1a2b3c4d-5e6f-4a7b-8c9d-0e1f2a3b4c5d';

	/**
	 * Version 3 of the statutes, still a draft.
	 *
	 * @var string
	 */
	private const DRAFT = '2b3c4d5e-6f7a-4b8c-9d0e-1f2a3b4c5d6e';

	/**
	 * Version 1 of the statutes, replaced by version 2.
	 *
	 * @var string
	 */
	private const REPLACED = '3c4d5e6f-7a8b-4c9d-8e1f-2a3b4c5d6e7f';

	/**
	 * An agenda item whose status happens to read `in-effect`.
	 *
	 * @var string
	 */
	private const AGENDA_ITEM = '4d5e6f7a-8b9c-4d0e-9f2a-3b4c5d6e7f8a';

	/**
	 * A folder named by a uuid that is no decidiq object.
	 *
	 * @var string
	 */
	private const UNKNOWN = '5e6f7a8b-9c0d-4e1f-8a3b-4c5d6e7f8a9b';

	/**
	 * A folder whose record cannot be read.
	 *
	 * @var string
	 */
	private const BROKEN = '6f7a8b9c-0d1e-4f2a-9b4c-5d6e7f8a9b0c';

	/**
	 * How often the object service was asked.
	 *
	 * @var int
	 */
	private int $lookups = 0;

	/**
	 * The listener over the records above.
	 *
	 * @return SealedObjectFileWriteListener
	 */
	private function listener(): SealedObjectFileWriteListener {
		$records = [
			self::IN_EFFECT   => ['governing-document-versie', ['versionNumber' => 2, 'status' => 'in-effect']],
			self::DRAFT       => ['governing-document-versie', ['versionNumber' => 3, 'status' => 'draft']],
			self::REPLACED    => ['governing-document-versie', ['versionNumber' => 1, 'status' => 'replaced']],
			self::AGENDA_ITEM => ['agenda-item', ['title' => 'Statutenwijziging', 'status' => 'in-effect']],
		];

		$entities = [];
		$slugs    = [];
		foreach ($records as $uuid => [$slug, $row]) {
			$entity = $this->createMock(ObjectEntity::class);
			$entity->method('getObject')->willReturn($row);
			$entities[$uuid]          = $entity;
			$slugs[spl_object_id($entity)] = $slug;
		}

		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->method('find')->willReturnCallback(
			function (int|string $id) use ($entities): ObjectEntity {
				$this->lookups++;
				if ($id === self::BROKEN) {
					throw new RuntimeException('database gone');
				}

				if (isset($entities[$id]) === false) {
					throw new DoesNotExistException('no such object');
				}

				return $entities[$id];
			}
		);

		$resolver = $this->createMock(ListenerSchemaResolverInterface::class);
		$resolver->method('schemaSlug')->willReturnCallback(
			static fn (?object $entity): string => ($slugs[spl_object_id($entity)] ?? '')
		);

		return new SealedObjectFileWriteListener($objects, $resolver, new NullLogger());
	}//end listener()

	/**
	 * A folder double with a name and a parent.
	 *
	 * @param string      $name   The folder name.
	 * @param Folder|null $parent The parent, null at the top.
	 *
	 * @return Folder
	 */
	private function folder(string $name, ?Folder $parent = null): Folder {
		$folder = $this->createMock(Folder::class);
		$folder->method('getName')->willReturn($name);
		if ($parent === null) {
			$folder->method('getParent')->willThrowException(new RuntimeException('top'));
		} else {
			$folder->method('getParent')->willReturn($parent);
		}

		return $folder;
	}//end folder()

	/**
	 * A file double in the given folder.
	 *
	 * @param string $name   The file name.
	 * @param Folder $parent Its folder.
	 *
	 * @return File
	 */
	private function file(string $name, Folder $parent): File {
		$file = $this->createMock(File::class);
		$file->method('getName')->willReturn($name);
		$file->method('getParent')->willReturn($parent);
		return $file;
	}//end file()

	/**
	 * The folder OpenRegister gives an object: register, schema, uuid.
	 *
	 * @param string $uuid The object uuid.
	 *
	 * @return Folder
	 */
	private function objectFolder(string $uuid): Folder {
		return $this->folder(name: $uuid, parent: $this->folder(name: 'governing-document-versie', parent: $this->folder(name: 'decidiq')));
	}//end objectFolder()

	/**
	 * Replacing the consolidated text of a version in effect is refused, naming the state.
	 *
	 * @return void
	 */
	public function testReplacingTheTextOfAVersionInEffectIsRefused(): void {
		$text = $this->file(name: 'statuten-v2.pdf', parent: $this->objectFolder(uuid: self::IN_EFFECT));

		try {
			$this->listener()->handle(new BeforeNodeWrittenEvent($text));
			self::fail('The overwrite was not refused.');
		} catch (HintException $e) {
			self::assertStringContainsString('in-effect', $e->getMessage());
			self::assertSame(FileWriteEvent::HTTP_STATUS, $e->getCode());
		}
	}//end testReplacingTheTextOfAVersionInEffectIsRefused()

	/**
	 * Adding a file to a version in effect is refused.
	 *
	 * @return void
	 */
	public function testAddingAFileToAVersionInEffectIsRefused(): void {
		$extra = $this->file(name: 'bijlage.pdf', parent: $this->objectFolder(uuid: self::IN_EFFECT));

		$this->expectException(HintException::class);
		$this->listener()->handle(new BeforeNodeCreatedEvent($extra));
	}//end testAddingAFileToAVersionInEffectIsRefused()

	/**
	 * Removing the text of a replaced version is aborted the way Nextcloud honours.
	 *
	 * @return void
	 */
	public function testRemovingTheTextOfAReplacedVersionIsAborted(): void {
		$text = $this->file(name: 'statuten-v1.pdf', parent: $this->objectFolder(uuid: self::REPLACED));

		$this->expectException(AbortedEventException::class);
		$this->expectExceptionMessage('replaced');
		$this->listener()->handle(new BeforeNodeDeletedEvent($text));
	}//end testRemovingTheTextOfAReplacedVersionIsAborted()

	/**
	 * Moving a file into a sealed version is refused on its target.
	 *
	 * @return void
	 */
	public function testMovingAFileIntoASealedVersionIsAborted(): void {
		$source = $this->file(name: 'nieuw.pdf', parent: $this->objectFolder(uuid: self::DRAFT));
		$target = $this->file(name: 'nieuw.pdf', parent: $this->objectFolder(uuid: self::IN_EFFECT));

		$this->expectException(AbortedEventException::class);
		$this->listener()->handle(new BeforeNodeRenamedEvent($source, $target));
	}//end testMovingAFileIntoASealedVersionIsAborted()

	/**
	 * Removing the folder of a sealed version itself is aborted.
	 *
	 * @return void
	 */
	public function testRemovingTheFolderOfASealedVersionIsAborted(): void {
		$this->expectException(AbortedEventException::class);
		$this->listener()->handle(new BeforeNodeDeletedEvent($this->objectFolder(uuid: self::IN_EFFECT)));
	}//end testRemovingTheFolderOfASealedVersionIsAborted()

	/**
	 * A file nested below the version's folder still belongs to it.
	 *
	 * @return void
	 */
	public function testAFileInASubfolderOfASealedVersionIsRefused(): void {
		$sub  = $this->folder(name: 'bijlagen', parent: $this->objectFolder(uuid: self::IN_EFFECT));
		$file = $this->file(name: 'kaart.pdf', parent: $sub);

		self::assertSame(self::IN_EFFECT, SealedObjectFileWriteListener::ownerFolderOf(node: $file));
		self::assertNotNull($this->listener()->refusalFor(node: $file));
	}//end testAFileInASubfolderOfASealedVersionIsRefused()

	/**
	 * A draft version's text may be replaced, and OpenRegister may make a sealed version's folder.
	 *
	 * @return void
	 */
	public function testADraftVersionAndANewFolderPass(): void {
		$listener = $this->listener();

		$listener->handle(new BeforeNodeWrittenEvent($this->file(name: 'statuten-v3.pdf', parent: $this->objectFolder(uuid: self::DRAFT))));
		$listener->handle(new BeforeNodeCreatedEvent($this->objectFolder(uuid: self::IN_EFFECT)));

		self::assertSame(1, $this->lookups);
	}//end testADraftVersionAndANewFolderPass()

	/**
	 * Only a schema that seals is judged: another record's status does not seal its files.
	 *
	 * @return void
	 */
	public function testAnotherSchemaIsNotSealed(): void {
		$paper = $this->file(name: 'voorstel.pdf', parent: $this->objectFolder(uuid: self::AGENDA_ITEM));

		$this->listener()->handle(new BeforeNodeWrittenEvent($paper));

		self::assertNull(SealedObjectFileWriteListener::sealedRefusal(slug: 'agenda-item', row: ['status' => 'in-effect']));
		self::assertNotNull(SealedObjectFileWriteListener::sealedRefusal(slug: 'governing-document-versie', row: ['status' => 'lapsed']));
		self::assertNull(SealedObjectFileWriteListener::sealedRefusal(slug: 'governing-document-versie', row: ['status' => 'adopted']));
	}//end testAnotherSchemaIsNotSealed()

	/**
	 * A file outside any object folder is not looked up; an unknown uuid passes.
	 *
	 * @return void
	 */
	public function testFilesOutsideARecordPass(): void {
		$listener = $this->listener();

		$notes = $this->file(name: 'notes.txt', parent: $this->folder(name: 'Documents', parent: $this->folder(name: 'files')));
		$listener->handle(new BeforeNodeWrittenEvent($notes));
		self::assertSame(0, $this->lookups);

		$listener->handle(new BeforeNodeWrittenEvent($this->file(name: 'x.pdf', parent: $this->objectFolder(uuid: self::UNKNOWN))));
		self::assertSame(1, $this->lookups);
	}//end testFilesOutsideARecordPass()

	/**
	 * A record that cannot be read refuses the write rather than let it through unchecked.
	 *
	 * @return void
	 */
	public function testAnUnreadableRecordRefuses(): void {
		$this->expectException(HintException::class);
		$this->expectExceptionMessage('could not be checked');
		$this->listener()->handle(new BeforeNodeWrittenEvent($this->file(name: 'x.pdf', parent: $this->objectFolder(uuid: self::BROKEN))));
	}//end testAnUnreadableRecordRefuses()

	/**
	 * An after-the-fact event is not judged.
	 *
	 * @return void
	 */
	public function testAfterEventsAreIgnored(): void {
		$this->listener()->handle(new NodeWrittenEvent($this->file(name: 'statuten-v2.pdf', parent: $this->objectFolder(uuid: self::IN_EFFECT))));

		self::assertSame(0, $this->lookups);
	}//end testAfterEventsAreIgnored()

	/**
	 * The four before-write events reach the listener.
	 *
	 * @return void
	 */
	public function testTheRegistrarListensToEveryBeforeWriteEvent(): void {
		$registered = [];
		$context    = $this->createMock(IRegistrationContext::class);
		$context->method('registerEventListener')->willReturnCallback(
			static function (string $event, string $listener) use (&$registered): void {
				$registered[] = [$event, $listener];
			}
		);

		(new FilesEventRegistrar())->register(context: $context);

		foreach ([BeforeNodeCreatedEvent::class, BeforeNodeWrittenEvent::class, BeforeNodeRenamedEvent::class, BeforeNodeDeletedEvent::class] as $event) {
			self::assertContains([$event, SealedObjectFileWriteListener::class], $registered);
		}
	}//end testTheRegistrarListensToEveryBeforeWriteEvent()
}//end class
