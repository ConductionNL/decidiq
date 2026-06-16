<?php

/**
 * Unit tests for MeetingFolderService.
 *
 * @category Test
 * @package  OCA\Decidesk\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/nextcloud-integration/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidesk\Tests\Unit\Service;

use OCA\Decidesk\Service\MeetingFolderService;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests folder path building, sanitisation, subfolders, and degradation.
 *
 * @spec openspec/specs/nextcloud-integration/spec.md
 */
class MeetingFolderServiceTest extends TestCase
{

    /**
     * Folders created by the fake FileService, in call order.
     *
     * @var \ArrayObject<int, string>
     */
    private \ArrayObject $created;

    /**
     * Files written by the fake folder node, in call order
     * ('create:<name>:<content>' / 'overwrite:<name>:<content>').
     *
     * @var \ArrayObject<int, string>
     */
    private \ArrayObject $written;

    /**
     * Build the service with a recording fake FileService and a stub
     * ObjectService returning the given governance body.
     *
     * @param array<string, mixed>|null $body Governance body payload (null = not found)
     *
     * @return MeetingFolderService
     */
    private function makeService(?array $body=null): MeetingFolderService
    {
        $this->created = new \ArrayObject();

        $fileService = new class ($this->created) {

            /**
             * @param \ArrayObject<int, string> $created Shared recording list
             */
            public function __construct(private \ArrayObject $created)
            {
            }

            /**
             * Record a created folder path.
             *
             * @param string $path Folder path
             *
             * @return void
             */
            public function createFolder(string $path): void
            {
                $this->created->append($path);

            }//end createFolder()
        };

        $objectService = new class ($body) {

            /**
             * @param array<string, mixed>|null $body Body payload
             */
            public function __construct(private ?array $body)
            {
            }

            /**
             * Return a jsonSerializable body entity or null.
             *
             * @param int|string      $id       Object id
             * @param array|null      $_extend  Unused
             * @param bool            $files    Unused
             * @param string|int|null $register Unused
             * @param string|int|null $schema   Unused
             *
             * @return object|null
             */
            public function find(int|string $id, ?array $_extend=[], bool $files=false, string|int|null $register=null, string|int|null $schema=null): ?object
            {
                if ($this->body === null) {
                    return null;
                }

                $payload = $this->body;
                return new class ($payload) implements \JsonSerializable {

                    /**
                     * @param array<string, mixed> $payload Body payload
                     */
                    public function __construct(private array $payload)
                    {
                    }

                    /**
                     * @return array<string, mixed>
                     */
                    public function jsonSerialize(): array
                    {
                        return $this->payload;

                    }//end jsonSerialize()
                };

            }//end find()
        };

        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')->willReturnCallback(
            static function (string $id) use ($fileService, $objectService) {
                if (str_contains($id, 'FileService') === true) {
                    return $fileService;
                }

                return $objectService;
            }
        );

        return new MeetingFolderService(
            container: $container,
            logger: $this->createMock(LoggerInterface::class),
        );

    }//end makeService()

    /**
     * Full tree: Decidesk/<body>/<date> <title>/ + the two subfolders.
     *
     * @spec openspec/specs/nextcloud-integration/spec.md
     *
     * @return void
     */
    public function testBuildsFullTreeWithBodyAndDate(): void
    {
        $service = $this->makeService(body: ['name' => 'Board of Directors']);

        $path = $service->ensureMeetingFolders(
            meeting: [
                'id'             => 'meet-1',
                'title'          => 'Board Meeting Q2',
                'scheduledDate'  => '2026-07-15T19:00:00Z',
                'governanceBody' => 'body-1',
            ]
        );

        $created = $this->created->getArrayCopy();
        self::assertSame(expected: 'Decidesk/Board of Directors/2026-07-15 Board Meeting Q2', actual: $path);
        self::assertContains(needle: 'Decidesk', haystack: $created);
        self::assertContains(needle: 'Decidesk/Board of Directors', haystack: $created);
        self::assertContains(needle: $path.'/Agenda Documents', haystack: $created);
        self::assertContains(needle: $path.'/Minutes', haystack: $created);

    }//end testBuildsFullTreeWithBodyAndDate()

    /**
     * Unsafe characters are sanitised out of path segments.
     *
     * @spec openspec/specs/nextcloud-integration/spec.md
     *
     * @return void
     */
    public function testSanitisesUnsafeSegments(): void
    {
        $service = $this->makeService();

        $path = $service->ensureMeetingFolders(
            meeting: [
                'id'    => 'meet-2',
                'title' => 'Budget: 2026 <final?> / *draft*',
            ]
        );

        self::assertNotNull(actual: $path);
        self::assertStringNotContainsString(needle: ':', haystack: substr($path, strlen('Decidesk/')));
        self::assertStringNotContainsString(needle: '<', haystack: $path);
        self::assertStringNotContainsString(needle: '?', haystack: $path);
        self::assertStringNotContainsString(needle: '*', haystack: $path);

    }//end testSanitisesUnsafeSegments()

    /**
     * Missing body / date degrade gracefully (no empty segments).
     *
     * @spec openspec/specs/nextcloud-integration/spec.md
     *
     * @return void
     */
    public function testDegradesWithoutBodyOrDate(): void
    {
        $service = $this->makeService();

        $path = $service->ensureMeetingFolders(meeting: ['id' => 'meet-3', 'title' => 'Quick sync']);

        self::assertSame(expected: 'Decidesk/Quick sync', actual: $path);

    }//end testDegradesWithoutBodyOrDate()

    /**
     * A meeting without title and id is skipped entirely.
     *
     * @spec openspec/specs/nextcloud-integration/spec.md
     *
     * @return void
     */
    public function testSkipsEmptyMeeting(): void
    {
        $service = $this->makeService();

        self::assertNull(actual: $service->ensureMeetingFolders(meeting: []));
        self::assertSame(expected: [], actual: $this->created->getArrayCopy());

    }//end testSkipsEmptyMeeting()

    /**
     * Build the service with a FileService fake whose createFolder returns a
     * folder node recording newFile()/putContent() writes.
     *
     * @param array<string, string> $existingFiles Map fileName => content of
     *                                             files that already exist in
     *                                             every folder node
     *
     * @return MeetingFolderService
     */
    private function makeWritableService(array $existingFiles=[]): MeetingFolderService
    {
        $this->created = new \ArrayObject();
        $this->written = new \ArrayObject();

        $written = $this->written;

        $folderNode = new class ($existingFiles, $written) {

            /**
             * @param array<string, string>     $existingFiles Pre-existing files
             * @param \ArrayObject<int, string> $written       Recording list
             */
            public function __construct(private array $existingFiles, private \ArrayObject $written)
            {
            }

            /**
             * Return an existing file node or throw NotFoundException.
             *
             * @param string $name File name
             *
             * @return object
             */
            public function get(string $name): object
            {
                if (array_key_exists($name, $this->existingFiles) === false) {
                    throw new \OCP\Files\NotFoundException($name);
                }

                $written = $this->written;
                return new class ($name, $written) {

                    /**
                     * @param string                    $name    File name
                     * @param \ArrayObject<int, string> $written Recording list
                     */
                    public function __construct(private string $name, private \ArrayObject $written)
                    {
                    }

                    /**
                     * Record an overwrite.
                     *
                     * @param string $content New content
                     *
                     * @return void
                     */
                    public function putContent(string $content): void
                    {
                        $this->written->append('overwrite:'.$this->name.':'.$content);

                    }//end putContent()
                };

            }//end get()

            /**
             * Record a new file write.
             *
             * @param string $name    File name
             * @param string $content File content
             *
             * @return void
             */
            public function newFile(string $name, string $content): void
            {
                $this->written->append('create:'.$name.':'.$content);

            }//end newFile()
        };

        $created = $this->created;

        $fileService = new class ($created, $folderNode) {

            /**
             * @param \ArrayObject<int, string> $created    Recording list
             * @param object                    $folderNode The folder node fake
             */
            public function __construct(private \ArrayObject $created, private object $folderNode)
            {
            }

            /**
             * Record a created folder and return the folder node.
             *
             * @param string $path Folder path
             *
             * @return object
             */
            public function createFolder(string $path): object
            {
                $this->created->append($path);
                return $this->folderNode;

            }//end createFolder()
        };

        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')->willReturnCallback(
            static function (string $id) use ($fileService) {
                if (str_contains($id, 'FileService') === true) {
                    return $fileService;
                }

                throw new \RuntimeException('Unexpected service: '.$id);
            }
        );

        return new MeetingFolderService(
            container: $container,
            logger: $this->createMock(LoggerInterface::class),
        );

    }//end makeWritableService()

    /**
     * writeMeetingFile creates the tree, the subfolder, and the new file.
     *
     * @spec openspec/specs/resolution-minutes/spec.md
     *
     * @return void
     */
    public function testWriteMeetingFileCreatesFileInSubfolder(): void
    {
        $service = $this->makeWritableService();

        $path = $service->writeMeetingFile(
            meeting: ['id' => 'meet-10', 'title' => 'Raadsvergadering', 'scheduledDate' => '2026-06-12T19:00:00Z'],
            subfolder: 'Minutes',
            fileName: 'Notulen v1.md',
            content: '# Notulen'
        );

        self::assertSame(
            expected: 'Decidesk/2026-06-12 Raadsvergadering/Minutes/Notulen v1.md',
            actual: $path
        );
        self::assertContains(
            needle: 'Decidesk/2026-06-12 Raadsvergadering/Minutes',
            haystack: $this->created->getArrayCopy()
        );
        self::assertContains(
            needle: 'create:Notulen v1.md:# Notulen',
            haystack: $this->written->getArrayCopy()
        );

    }//end testWriteMeetingFileCreatesFileInSubfolder()

    /**
     * writeMeetingFile overwrites an existing file in place (putContent),
     * so re-generation updates the document instead of duplicating it.
     *
     * @spec openspec/specs/resolution-minutes/spec.md
     *
     * @return void
     */
    public function testWriteMeetingFileOverwritesExistingFile(): void
    {
        $service = $this->makeWritableService(existingFiles: ['Notulen v1.md' => 'old']);

        $path = $service->writeMeetingFile(
            meeting: ['id' => 'meet-11', 'title' => 'Raadsvergadering'],
            subfolder: 'Minutes',
            fileName: 'Notulen v1.md',
            content: '# Nieuw'
        );

        self::assertNotNull(actual: $path);
        self::assertContains(
            needle: 'overwrite:Notulen v1.md:# Nieuw',
            haystack: $this->written->getArrayCopy()
        );

    }//end testWriteMeetingFileOverwritesExistingFile()

    /**
     * writeMeetingFile sanitises unsafe characters in subfolder + file name.
     *
     * @spec openspec/specs/resolution-minutes/spec.md
     *
     * @return void
     */
    public function testWriteMeetingFileSanitisesNames(): void
    {
        $service = $this->makeWritableService();

        $path = $service->writeMeetingFile(
            meeting: ['id' => 'meet-12', 'title' => 'Sync'],
            subfolder: 'Min<utes>',
            fileName: 'Doc: v1?.md',
            content: 'x'
        );

        self::assertNotNull(actual: $path);
        self::assertStringNotContainsString(needle: '<', haystack: $path);
        self::assertStringNotContainsString(needle: '?', haystack: $path);
        self::assertStringNotContainsString(needle: ':', haystack: substr($path, strlen('Decidesk/')));

    }//end testWriteMeetingFileSanitisesNames()

    /**
     * writeMeetingFile fails soft (null) when the meeting folder tree
     * cannot be built — Files unavailability never fatals.
     *
     * @spec openspec/specs/resolution-minutes/spec.md
     *
     * @return void
     */
    public function testWriteMeetingFileFailsSoftWithoutMeetingFolder(): void
    {
        $service = $this->makeWritableService();

        $path = $service->writeMeetingFile(
            meeting: [],
            subfolder: 'Minutes',
            fileName: 'Notulen.md',
            content: 'x'
        );

        self::assertNull(actual: $path);
        self::assertSame(expected: [], actual: $this->written->getArrayCopy());

    }//end testWriteMeetingFileFailsSoftWithoutMeetingFolder()
}//end class
