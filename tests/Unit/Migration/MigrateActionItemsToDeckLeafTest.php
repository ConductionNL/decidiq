<?php

/**
 * Unit tests for MigrateActionItemsToDeckLeaf repair step.
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
 * @spec openspec/changes/migrate-action-items-to-deck-leaf/tasks.md#task-3.1
 */

declare(strict_types=1);

namespace OCA\Decidesk\Tests\Unit\Migration;

use OCA\Decidesk\Migration\MigrateActionItemsToDeckLeaf;
use OCA\Decidesk\Service\SettingsService;
use OCP\Migration\IOutput;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests the legacy Task/Delegation migration repair step.
 *
 * @spec openspec/changes/migrate-action-items-to-deck-leaf/tasks.md#task-3.1
 * @spec openspec/changes/migrate-action-items-to-deck-leaf/tasks.md#task-3.3
 */
class MigrateActionItemsToDeckLeafTest extends TestCase
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
     * @var MigrateActionItemsToDeckLeaf
     */
    private MigrateActionItemsToDeckLeaf $migration;

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

        $this->migration = new MigrateActionItemsToDeckLeaf(
            settingsService: $this->settingsService,
            container: $this->container,
            logger: $this->logger,
        );

    }//end setUp()

    /**
     * The step name mentions Task and Deck.
     *
     * @return void
     *
     * @spec openspec/changes/migrate-action-items-to-deck-leaf/tasks.md#task-3.1
     */
    public function testGetNameReturnsDescription(): void
    {
        $name = $this->migration->getName();
        self::assertStringContainsString(needle: 'Task', haystack: $name);
        self::assertStringContainsString(needle: 'Deck', haystack: $name);

    }//end testGetNameReturnsDescription()

    /**
     * When OpenRegister is unavailable the migration exits without touching OR.
     *
     * @return void
     *
     * @spec openspec/changes/migrate-action-items-to-deck-leaf/tasks.md#task-3.1
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
     * When legacy schemas were never instantiated, findAll() throwing is a
     * graceful no-op.
     *
     * @return void
     *
     * @spec openspec/changes/migrate-action-items-to-deck-leaf/tasks.md#task-3.4
     */
    public function testRunNoOpWhenLegacySchemasAbsent(): void
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

    }//end testRunNoOpWhenLegacySchemasAbsent()

    /**
     * An already-migrated task is skipped — idempotent / resume-safe.
     *
     * @return void
     *
     * @spec openspec/changes/migrate-action-items-to-deck-leaf/tasks.md#task-3.4
     */
    public function testRunSkipsAlreadyMigratedTask(): void
    {
        $this->settingsService->expects($this->any())
            ->method(constraint: 'isOpenRegisterAvailable')
            ->willReturn(true);

        $objectService = $this->makeRecordingObjectService(
            tasks: [
                [
                    'id'                  => 'task-1',
                    'title'               => 'Already migrated.',
                    '_migratedToDeckLeaf' => true,
                ],
            ],
            delegations: [],
        );

        $this->container->expects($this->any())
            ->method(constraint: 'get')
            ->willReturn($objectService);

        $this->migration->run(output: $this->output);

        // No ActionItem write and no archival for an already-migrated task.
        self::assertSame(expected: 0, actual: $objectService->deleteCalls);
        self::assertSame(expected: [], actual: $objectService->savedActionItems);

    }//end testRunSkipsAlreadyMigratedTask()

    /**
     * A fresh task is projected to a VTODO ActionItem, stamped, and archived.
     *
     * @return void
     *
     * @spec openspec/changes/migrate-action-items-to-deck-leaf/tasks.md#task-3.1
     * @spec openspec/changes/migrate-action-items-to-deck-leaf/tasks.md#task-3.3
     */
    public function testRunProjectsAndArchivesTask(): void
    {
        $this->settingsService->expects($this->any())
            ->method(constraint: 'isOpenRegisterAvailable')
            ->willReturn(true);

        $objectService = $this->makeRecordingObjectService(
            tasks: [
                [
                    'id'         => 'task-99',
                    'title'      => 'Draft the policy memo',
                    'assignee'   => 'user-alice',
                    'dueDate'    => '2026-02-01',
                    'taskStatus' => 'in-progress',
                    'meeting'    => 'meeting-abc',
                ],
            ],
            delegations: [],
        );

        $this->container->expects($this->any())
            ->method(constraint: 'get')
            ->willReturn($objectService);

        $this->migration->run(output: $this->output);

        // A VTODO ActionItem must be created carrying the task content.
        self::assertCount(expectedCount: 1, haystack: $objectService->savedActionItems);
        $item = $objectService->savedActionItems[0];
        self::assertSame(expected: 'Draft the policy memo', actual: $item['title']);
        self::assertSame(expected: 'user-alice', actual: $item['assignee']);
        self::assertSame(expected: 'in-progress', actual: $item['taskStatus']);
        self::assertSame(expected: 'task-99', actual: $item['_migratedFromTaskUuid']);

        // The legacy task must be stamped and archived (soft-deleted, not purged).
        self::assertTrue(condition: $objectService->stampedMarker);
        self::assertContains(needle: 'task-99', haystack: $objectService->deleted);

    }//end testRunProjectsAndArchivesTask()

    /**
     * A delegation replays its effective assignee onto the target VTODO
     * ActionItem and archives the legacy delegation. A reclaimed/revoked
     * delegation restores the delegator as assignee (REQ-AI-DECK-002).
     *
     * @return void
     *
     * @spec openspec/changes/migrate-action-items-to-deck-leaf/tasks.md#task-3.2
     */
    public function testRunReplaysReclaimedDelegationOntoActionItem(): void
    {
        $this->settingsService->expects($this->any())
            ->method(constraint: 'isOpenRegisterAvailable')
            ->willReturn(true);

        $objectService = $this->makeRecordingObjectService(
            tasks: [],
            delegations: [
                [
                    'id'         => 'deleg-1',
                    'task'       => 'action-item-xyz',
                    'delegator'  => 'user-delegator',
                    'substitute' => 'user-sub',
                    'status'     => 'reclaimed',
                ],
            ],
            // The target ActionItem the delegation points at.
            actionItem: ['id' => 'action-item-xyz', 'title' => 'Follow up', 'assignee' => 'user-sub'],
        );

        $this->container->expects($this->any())
            ->method(constraint: 'get')
            ->willReturn($objectService);

        $this->migration->run(output: $this->output);

        // Reclaimed → assignee reverts to the delegator on the VTODO ActionItem.
        self::assertCount(expectedCount: 1, haystack: $objectService->savedActionItems);
        self::assertSame(
            expected: 'user-delegator',
            actual: $objectService->savedActionItems[0]['assignee']
        );

        // The legacy delegation must be archived.
        self::assertContains(needle: 'deleg-1', haystack: $objectService->deleted);

    }//end testRunReplaysReclaimedDelegationOntoActionItem()

    /**
     * When OR ObjectService cannot be resolved, the migration exits cleanly.
     *
     * @return void
     *
     * @spec openspec/changes/migrate-action-items-to-deck-leaf/tasks.md#task-3.1
     */
    public function testRunExitsGracefullyWhenObjectServiceUnavailable(): void
    {
        $this->settingsService->expects($this->any())
            ->method(constraint: 'isOpenRegisterAvailable')
            ->willReturn(true);

        $this->container->expects($this->any())
            ->method(constraint: 'get')
            ->willThrowException(new \RuntimeException('Service not found.'));

        $this->output->expects($this->atLeastOnce())->method(constraint: 'warning');

        $this->migration->run(output: $this->output);

    }//end testRunExitsGracefullyWhenObjectServiceUnavailable()

    /**
     * Build a stub ObjectService whose findAll() throws — emulating
     * never-instantiated legacy schemas.
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
             * FindAll throws — emulates a never-instantiated schema.
             *
             * @param integer $limit Max results.
             *
             * @return array<int,mixed>
             */
            public function findAll(int $limit=100): array
            {
                throw new \RuntimeException('schema not found');

            }//end findAll()
        };

    }//end makeThrowingObjectService()

    /**
     * Build a recording stub ObjectService.
     *
     * `findAll()` returns task or delegation rows depending on the schema set by
     * the immediately preceding `setSchema()` call (or an empty list for the
     * ActionItem existence check). `find()` returns the configured ActionItem.
     *
     * @param array<int,array<string,mixed>> $tasks       Legacy task rows.
     * @param array<int,array<string,mixed>> $delegations Legacy delegation rows.
     * @param array<string,mixed>|null       $actionItem  ActionItem returned by find().
     *
     * @return object
     */
    private function makeRecordingObjectService(array $tasks, array $delegations, ?array $actionItem=null): object
    {
        return new class($tasks, $delegations, $actionItem) {

            /**
             * ActionItem objects written via saveObject (schema ActionItem).
             *
             * @var array<int,array<string,mixed>>
             */
            public array $savedActionItems = [];

            /**
             * Deleted (archived) UUIDs.
             *
             * @var array<int,string>
             */
            public array $deleted = [];

            /**
             * Number of deleteObject calls.
             *
             * @var integer
             */
            public int $deleteCalls = 0;

            /**
             * Whether a legacy object was stamped with the migration marker.
             *
             * @var boolean
             */
            public bool $stampedMarker = false;

            /**
             * Schema set by the last setSchema() call.
             *
             * @var string
             */
            private string $currentSchema = '';

            /**
             * Legacy task rows.
             *
             * @var array<int,array<string,mixed>>
             */
            private array $tasks;

            /**
             * Legacy delegation rows.
             *
             * @var array<int,array<string,mixed>>
             */
            private array $delegations;

            /**
             * Target ActionItem returned by find().
             *
             * @var array<string,mixed>|null
             */
            private ?array $actionItem;

            /**
             * Constructor.
             *
             * @param array<int,array<string,mixed>> $tasks       Legacy task rows.
             * @param array<int,array<string,mixed>> $delegations Legacy delegation rows.
             * @param array<string,mixed>|null       $actionItem  ActionItem for find().
             */
            public function __construct(array $tasks, array $delegations, ?array $actionItem)
            {
                $this->tasks       = $tasks;
                $this->delegations = $delegations;
                $this->actionItem  = $actionItem;

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
             * Record the active schema for findAll() dispatch.
             *
             * @param string $schema Schema slug.
             *
             * @return self
             */
            public function setSchema(string $schema): self
            {
                $this->currentSchema = $schema;
                return $this;

            }//end setSchema()

            /**
             * Return rows for the active schema.
             *
             * @param integer $limit Max results.
             *
             * @return array<int,array<string,mixed>>
             */
            public function findAll(int $limit=100): array
            {
                if ($this->currentSchema === 'task') {
                    return $this->tasks;
                }

                if ($this->currentSchema === 'delegation') {
                    return $this->delegations;
                }

                // ActionItem existence check — no pre-existing items in tests.
                return [];

            }//end findAll()

            /**
             * Return the configured target ActionItem for delegation replay.
             *
             * @param string      $id       Object UUID.
             * @param string|null $register Register slug.
             * @param string|null $schema   Schema slug.
             *
             * @return object|null
             */
            public function find(string $id, ?string $register=null, ?string $schema=null): ?object
            {
                if ($this->actionItem === null) {
                    return null;
                }

                $item = $this->actionItem;
                return new class($item) {

                    /**
                     * The wrapped ActionItem array.
                     *
                     * @var array<string,mixed>
                     */
                    private array $item;

                    /**
                     * Constructor.
                     *
                     * @param array<string,mixed> $item ActionItem array.
                     */
                    public function __construct(array $item)
                    {
                        $this->item = $item;

                    }//end __construct()

                    /**
                     * Serialise to the wrapped array.
                     *
                     * @return array<string,mixed>
                     */
                    public function jsonSerialize(): array
                    {
                        return $this->item;

                    }//end jsonSerialize()
                };

            }//end find()

            /**
             * Capture a save. ActionItem writes are recorded separately so tests
             * can assert the projected VTODO content; legacy stamps set a flag.
             *
             * @param array<string,mixed> $object   Object to save.
             * @param string|null         $register Register slug.
             * @param string|null         $schema   Schema slug.
             * @param string|null         $uuid     Object UUID (legacy stamp / replay).
             *
             * @return array<string,mixed>
             */
            public function saveObject(array $object, ?string $register=null, ?string $schema=null, ?string $uuid=null): array
            {
                if ($schema === 'ActionItem') {
                    $this->savedActionItems[] = $object;
                }

                if (($object['_migratedToDeckLeaf'] ?? false) === true) {
                    $this->stampedMarker = true;
                }

                return $object;

            }//end saveObject()

            /**
             * Capture an archival (soft delete).
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
