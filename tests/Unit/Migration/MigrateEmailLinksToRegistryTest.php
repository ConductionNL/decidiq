<?php

/**
 * Unit tests for MigrateEmailLinksToRegistry repair step.
 *
 * @category Test
 * @package  OCA\Decidesk\Tests\Unit\Migration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/migrate-email-links-to-email-leaf/tasks.md#task-4.1
 */

declare(strict_types=1);

namespace OCA\Decidesk\Tests\Unit\Migration;

use OCA\Decidesk\Migration\MigrateEmailLinksToRegistry;
use OCA\Decidesk\Service\SettingsService;
use OCP\Migration\IOutput;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests the legacy-EmailLink migration repair step.
 *
 * @spec openspec/changes/migrate-email-links-to-email-leaf/tasks.md#task-4.1
 */
class MigrateEmailLinksToRegistryTest extends TestCase
{

    /**
     * Mock SettingsService.
     *
     * @var SettingsService&MockObject
     */
    private SettingsService&MockObject $settingsService;

    /**
     * Mock ContainerInterface.
     *
     * @var ContainerInterface&MockObject
     */
    private ContainerInterface&MockObject $container;

    /**
     * Mock LoggerInterface.
     *
     * @var LoggerInterface&MockObject
     */
    private LoggerInterface&MockObject $logger;

    /**
     * Mock IOutput.
     *
     * @var IOutput&MockObject
     */
    private IOutput&MockObject $output;

    /**
     * The repair step under test.
     *
     * @var MigrateEmailLinksToRegistry
     */
    private MigrateEmailLinksToRegistry $migration;

    /**
     * Set up test fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->settingsService = $this->createMock(originalClassName: SettingsService::class);
        $this->container       = $this->createMock(originalClassName: ContainerInterface::class);
        $this->logger          = $this->createMock(originalClassName: LoggerInterface::class);
        $this->output          = $this->createMock(originalClassName: IOutput::class);

        $this->migration = new MigrateEmailLinksToRegistry(
            settingsService: $this->settingsService,
            container: $this->container,
            logger: $this->logger,
        );

    }//end setUp()

    /**
     * The step name is descriptive.
     *
     * @return void
     */
    public function testGetNameReturnsDescription(): void
    {
        self::assertStringContainsString(
            needle: 'EmailLink',
            haystack: $this->migration->getName()
        );

    }//end testGetNameReturnsDescription()

    /**
     * When OpenRegister is unavailable the migration skips entirely.
     *
     * @return void
     */
    public function testRunSkipsWhenOpenRegisterUnavailable(): void
    {
        $this->settingsService->expects($this->once())
            ->method(constraint: 'isOpenRegisterAvailable')
            ->willReturn(false);

        $this->container->expects($this->never())->method(constraint: 'get');
        $this->output->expects($this->atLeastOnce())->method(constraint: 'warning');

        $this->migration->run(output: $this->output);

    }//end testRunSkipsWhenOpenRegisterUnavailable()

    /**
     * When the legacy schema was never instantiated, findAll() throwing is a
     * graceful no-op (the expected path on leaf-from-start installs).
     *
     * @return void
     */
    public function testRunNoOpWhenLegacySchemaAbsent(): void
    {
        $this->settingsService->expects($this->any())
            ->method(constraint: 'isOpenRegisterAvailable')
            ->willReturn(true);

        $objectService = $this->makeThrowingObjectService();

        $this->container->expects($this->any())
            ->method(constraint: 'get')
            ->willReturn($objectService);
        $this->output->expects($this->atLeastOnce())->method(constraint: 'info');

        $this->migration->run(output: $this->output);

    }//end testRunNoOpWhenLegacySchemaAbsent()

    /**
     * An already-migrated object is skipped (resume-safe / idempotent).
     *
     * @return void
     */
    public function testRunSkipsAlreadyMigratedObject(): void
    {
        $this->settingsService->expects($this->any())
            ->method(constraint: 'isOpenRegisterAvailable')
            ->willReturn(true);

        $objectService = $this->makeRecordingObjectService(
            legacyLinks: [
                [
                    'id'                  => 'link-1',
                    'linkedTo'            => 'decidesk:decision:dec-1',
                    '_migratedToRegistry' => true,
                ],
            ]
        );

        $this->container->expects($this->any())
            ->method(constraint: 'get')
            ->willReturn($objectService);

        $this->migration->run(output: $this->output);

        self::assertSame(expected: 0, actual: $objectService->saveCalls);
        self::assertSame(expected: 0, actual: $objectService->deleteCalls);

    }//end testRunSkipsAlreadyMigratedObject()

    /**
     * A fresh legacy link is relinked onto the target's relations and archived.
     *
     * @return void
     */
    public function testRunRelinksAndArchivesLegacyLink(): void
    {
        $this->settingsService->expects($this->any())
            ->method(constraint: 'isOpenRegisterAvailable')
            ->willReturn(true);

        $objectService = $this->makeRecordingObjectService(
            legacyLinks: [
                [
                    'id'       => 'link-1',
                    'emailUid' => 'mail-42',
                    'linkedTo' => 'decidesk:decision:dec-1',
                ],
            ]
        );

        $this->container->expects($this->any())
            ->method(constraint: 'get')
            ->willReturn($objectService);

        $this->migration->run(output: $this->output);

        // The target object's relations now carry the email reference.
        self::assertContains(needle: 'mail-42', haystack: $objectService->saved[0]['relations']['Email']);

        // The legacy object was archived.
        self::assertContains(needle: 'link-1', haystack: $objectService->deleted);

    }//end testRunRelinksAndArchivesLegacyLink()

    /**
     * Build a stub ObjectService whose findAll() throws — emulating a
     * never-instantiated legacy schema.
     *
     * @return object
     */
    private function makeThrowingObjectService(): object
    {
        return new class {
            /**
             * Stub setRegister.
             *
             * @param string $register Register slug.
             *
             * @return self
             */
            public function setRegister(string $register): self
            {
                return $this;

            }//end setRegister()

            /**
             * Stub setSchema.
             *
             * @param string $schema Schema slug.
             *
             * @return self
             */
            public function setSchema(string $schema): self
            {
                return $this;

            }//end setSchema()

            /**
             * Findall throws — emulates a never-instantiated schema.
             *
             * @param array<string,mixed> $config Query config.
             *
             * @return array<int,mixed>
             */
            public function findAll(array $config=[]): array
            {
                throw new \RuntimeException('schema not found');

            }//end findAll()
        };

    }//end makeThrowingObjectService()

    /**
     * Build a stub ObjectService that records saves/deletes and returns the
     * supplied legacy links from findAll().
     *
     * @param array<int,array<string,mixed>> $legacyLinks Legacy link rows.
     *
     * @return object
     */
    private function makeRecordingObjectService(array $legacyLinks): object
    {
        return new class($legacyLinks) {

            /**
             * Saved objects in call order.
             *
             * @var array<int,array<string,mixed>>
             */
            public array $saved = [];

            /**
             * Deleted UUIDs.
             *
             * @var array<int,string>
             */
            public array $deleted = [];

            /**
             * Number of saveObject calls.
             *
             * @var integer
             */
            public int $saveCalls = 0;

            /**
             * Number of deleteObject calls.
             *
             * @var integer
             */
            public int $deleteCalls = 0;

            /**
             * Legacy link rows returned by findAll.
             *
             * @var array<int,array<string,mixed>>
             */
            private array $legacyLinks;

            /**
             * Constructor.
             *
             * @param array<int,array<string,mixed>> $legacyLinks Legacy link rows.
             */
            public function __construct(array $legacyLinks)
            {
                $this->legacyLinks = $legacyLinks;

            }//end __construct()

            /**
             * Stub setRegister.
             *
             * @param string $register Register slug.
             *
             * @return self
             */
            public function setRegister(string $register): self
            {
                return $this;

            }//end setRegister()

            /**
             * Stub setSchema.
             *
             * @param string $schema Schema slug.
             *
             * @return self
             */
            public function setSchema(string $schema): self
            {
                return $this;

            }//end setSchema()

            /**
             * Return the configured legacy links.
             *
             * @param array<string,mixed> $config Query config.
             *
             * @return array<int,array<string,mixed>>
             */
            public function findAll(array $config=[]): array
            {
                return $this->legacyLinks;

            }//end findAll()

            /**
             * Return a target object with empty relations.
             *
             * @param string      $id       Target UUID.
             * @param string|null $register Register slug.
             * @param string|null $schema   Schema slug.
             *
             * @return object
             */
            public function find(string $id, ?string $register=null, ?string $schema=null): object
            {
                return new class {
                    /**
                     * Serialise the target object.
                     *
                     * @return array<string,mixed>
                     */
                    public function jsonSerialize(): array
                    {
                        return ['id' => 'dec-1', 'relations' => []];

                    }//end jsonSerialize()
                };

            }//end find()

            /**
             * Capture a save.
             *
             * @param array<string,mixed> $object   Object to save.
             * @param string|null         $register Register slug.
             * @param string|null         $schema   Schema slug.
             *
             * @return array<string,mixed>
             */
            public function saveObject(array $object, ?string $register=null, ?string $schema=null): array
            {
                $this->saveCalls++;
                $this->saved[] = $object;
                return $object;

            }//end saveObject()

            /**
             * Capture a delete.
             *
             * @param string      $uuid     Object UUID.
             * @param string|null $register Register slug.
             * @param string|null $schema   Schema slug.
             *
             * @return bool
             */
            public function deleteObject(string $uuid, ?string $register=null, ?string $schema=null): bool
            {
                $this->deleteCalls++;
                $this->deleted[] = $uuid;
                return true;

            }//end deleteObject()
        };

    }//end makeRecordingObjectService()
}//end class
