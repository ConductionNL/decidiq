<?php

/**
 * Unit tests for MigrateWorkspacesToCollectives repair step.
 *
 * @category Test
 * @package  OCA\Decidesk\Tests\Unit\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/migrate-workspaces-to-collectives-leaf/tasks.md#task-3.1
 */

declare(strict_types=1);

namespace OCA\Decidesk\Tests\Unit\Repair;

use OCA\Decidesk\Repair\MigrateWorkspacesToCollectives;
use OCP\App\IAppManager;
use OCP\Http\Client\IClientService;
use OCP\Migration\IOutput;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for MigrateWorkspacesToCollectives repair step.
 *
 * @spec openspec/changes/migrate-workspaces-to-collectives-leaf/tasks.md#task-3.1
 * @spec openspec/changes/migrate-workspaces-to-collectives-leaf/tasks.md#task-3.2
 * @spec openspec/changes/migrate-workspaces-to-collectives-leaf/tasks.md#task-3.3
 */
class MigrateWorkspacesToCollectivesTest extends TestCase
{

    /**
     * Repair step under test.
     *
     * @var MigrateWorkspacesToCollectives
     */
    private MigrateWorkspacesToCollectives $repairStep;

    /**
     * Mock DI container.
     *
     * @var ContainerInterface&MockObject
     */
    private ContainerInterface&MockObject $container;

    /**
     * Mock IAppManager.
     *
     * @var IAppManager&MockObject
     */
    private IAppManager&MockObject $appManager;

    /**
     * Mock IClientService.
     *
     * @var IClientService&MockObject
     */
    private IClientService&MockObject $clientService;

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
     * Set up test fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->container     = $this->createMock(originalClassName: ContainerInterface::class);
        $this->appManager    = $this->createMock(originalClassName: IAppManager::class);
        $this->clientService = $this->createMock(originalClassName: IClientService::class);
        $this->logger        = $this->createMock(originalClassName: LoggerInterface::class);
        $this->output        = $this->createMock(originalClassName: IOutput::class);

        $this->repairStep = new MigrateWorkspacesToCollectives(
            container: $this->container,
            appManager: $this->appManager,
            clientService: $this->clientService,
            logger: $this->logger,
        );

    }//end setUp()

    /**
     * Build a minimal ObjectService mock with the methods used by the migration.
     *
     * @return MockObject
     */
    private function buildObjectServiceMock(): MockObject
    {
        // phpcs:disable CustomSniffs.Functions.NamedParameters
        return $this->getMockBuilder(\stdClass::class)
            ->addMethods(['setRegister', 'setSchema', 'findAll', 'find', 'saveObject'])
            ->getMock();
        // phpcs:enable CustomSniffs.Functions.NamedParameters

    }//end buildObjectServiceMock()

    /**
     * Wire a container mock to return the ObjectService mock for the known service key.
     *
     * @param MockObject $objectServiceMock ObjectService mock
     *
     * @return void
     */
    private function wireContainerMock(MockObject $objectServiceMock): void
    {
        $this->container->expects($this->any())
            ->method(constraint: 'get')
            ->willReturnCallback(
                function (string $id) use ($objectServiceMock): object {
                    if ($id === 'OCA\OpenRegister\Service\ObjectService') {
                        return $objectServiceMock;
                    }

                    throw new \RuntimeException("Unknown service: $id");
                }
            );

    }//end wireContainerMock()

    /**
     * Test getName returns a meaningful description.
     *
     * @return void
     *
     * @spec openspec/changes/migrate-workspaces-to-collectives-leaf/tasks.md#task-3.1
     */
    public function testGetNameReturnsDescription(): void
    {
        self::assertStringContainsString(
            needle: 'CollaborationWorkspace',
            haystack: $this->repairStep->getName()
        );

    }//end testGetNameReturnsDescription()

    /**
     * Test run skips when OpenRegister is not available.
     *
     * @return void
     *
     * @spec openspec/changes/migrate-workspaces-to-collectives-leaf/tasks.md#task-3.1
     */
    public function testRunSkipsWhenOpenRegisterUnavailable(): void
    {
        $this->container->expects($this->once())
            ->method(constraint: 'get')
            ->with('OCA\OpenRegister\Service\ObjectService')
            ->willThrowException(new \RuntimeException('Not found'));

        $this->output->expects($this->atLeastOnce())
            ->method(constraint: 'warning');

        $this->repairStep->run(output: $this->output);

    }//end testRunSkipsWhenOpenRegisterUnavailable()

    /**
     * Test run logs notice when Collectives app is not installed.
     *
     * Workspaces should still be archived even without Collectives.
     *
     * @return void
     *
     * @spec openspec/changes/migrate-workspaces-to-collectives-leaf/tasks.md#task-1.4
     * @spec openspec/changes/migrate-workspaces-to-collectives-leaf/tasks.md#task-3.2
     */
    public function testRunLogsNoticeWhenCollectivesNotInstalled(): void
    {
        $objectServiceMock = $this->buildObjectServiceMock();

        $objectServiceMock->expects($this->any())
            ->method(constraint: 'setRegister');

        $objectServiceMock->expects($this->any())
            ->method(constraint: 'setSchema');

        $objectServiceMock->expects($this->once())
            ->method(constraint: 'findAll')
            ->willReturn([]);

        $this->wireContainerMock(objectServiceMock: $objectServiceMock);

        $this->appManager->expects($this->once())
            ->method(constraint: 'isInstalled')
            ->with('collectives')
            ->willReturn(false);

        $this->output->expects($this->atLeastOnce())
            ->method(constraint: 'info');

        $this->repairStep->run(output: $this->output);

    }//end testRunLogsNoticeWhenCollectivesNotInstalled()

    /**
     * Test run skips already-archived workspaces (idempotency).
     *
     * @return void
     *
     * @spec openspec/changes/migrate-workspaces-to-collectives-leaf/tasks.md#task-3.3
     */
    public function testRunSkipsAlreadyArchivedWorkspaces(): void
    {
        $archivedWorkspace = [
            'id'       => 'ws-uuid-1',
            'name'     => 'Already archived workspace',
            'members'  => [],
            'archived' => true,
        ];

        $objectServiceMock = $this->buildObjectServiceMock();

        $objectServiceMock->expects($this->any())
            ->method(constraint: 'setRegister');

        $objectServiceMock->expects($this->any())
            ->method(constraint: 'setSchema');

        $objectServiceMock->expects($this->once())
            ->method(constraint: 'findAll')
            ->willReturn([$archivedWorkspace]);

        // SaveObject must NOT be called because the workspace is already archived.
        $objectServiceMock->expects($this->never())
            ->method(constraint: 'saveObject');

        $this->wireContainerMock(objectServiceMock: $objectServiceMock);

        $this->appManager->expects($this->once())
            ->method(constraint: 'isInstalled')
            ->with('collectives')
            ->willReturn(false);

        $this->repairStep->run(output: $this->output);

    }//end testRunSkipsAlreadyArchivedWorkspaces()

    /**
     * Test run archives a pending workspace when Collectives is not installed.
     *
     * @return void
     *
     * @spec openspec/changes/migrate-workspaces-to-collectives-leaf/tasks.md#task-3.2
     */
    public function testRunArchivesPendingWorkspaceWhenCollectivesAbsent(): void
    {
        $pendingWorkspace = [
            'id'       => 'ws-uuid-2',
            'name'     => 'Faction A workspace',
            'members'  => ['participant-uuid-1'],
            'archived' => false,
        ];

        $objectServiceMock = $this->buildObjectServiceMock();

        $objectServiceMock->expects($this->any())
            ->method(constraint: 'setRegister');

        $objectServiceMock->expects($this->any())
            ->method(constraint: 'setSchema');

        $objectServiceMock->expects($this->once())
            ->method(constraint: 'findAll')
            ->willReturn([$pendingWorkspace]);

        $objectServiceMock->expects($this->once())
            ->method(constraint: 'find')
            ->willReturn((object) $pendingWorkspace);

        $objectServiceMock->expects($this->once())
            ->method(constraint: 'saveObject');

        $this->wireContainerMock(objectServiceMock: $objectServiceMock);

        $this->appManager->expects($this->once())
            ->method(constraint: 'isInstalled')
            ->with('collectives')
            ->willReturn(false);

        $this->repairStep->run(output: $this->output);

    }//end testRunArchivesPendingWorkspaceWhenCollectivesAbsent()
}//end class
