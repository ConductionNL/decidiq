<?php

/**
 * Unit tests for InitializeSettings repair step.
 *
 * @category Test
 * @package  OCA\Decidesk\Tests\Unit\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/p1-schemas-and-data-model/tasks.md#task-3
 */

declare(strict_types=1);

namespace OCA\Decidesk\Tests\Unit\Repair;

use OCA\Decidesk\Repair\InitializeSettings;
use OCA\Decidesk\Service\SettingsService;
use OCP\Migration\IOutput;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for the InitializeSettings repair step.
 *
 * @spec openspec/changes/p1-schemas-and-data-model/tasks.md#task-3
 */
class InitializeSettingsTest extends TestCase
{

    /**
     * The repair step under test.
     *
     * @var InitializeSettings
     */
    private InitializeSettings $repairStep;

    /**
     * Mock SettingsService.
     *
     * @var SettingsService&MockObject
     */
    private SettingsService&MockObject $settingsService;

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

        $this->settingsService = $this->createMock(originalClassName: SettingsService::class);
        $this->logger          = $this->createMock(originalClassName: LoggerInterface::class);
        $this->output          = $this->createMock(originalClassName: IOutput::class);

        $this->repairStep = new InitializeSettings(
            settingsService: $this->settingsService,
            logger: $this->logger,
        );

    }//end setUp()

    /**
     * Test getName returns the expected description.
     *
     * @return void
     *
     * @spec openspec/changes/p1-schemas-and-data-model/tasks.md#task-3
     */
    public function testGetNameReturnsDescription(): void
    {
        self::assertStringContainsString(
            needle: 'Initialize',
            haystack: $this->repairStep->getName()
        );

    }//end testGetNameReturnsDescription()

    /**
     * Test that run skips initialization when OpenRegister is not available.
     *
     * @return void
     *
     * @spec openspec/changes/p1-schemas-and-data-model/tasks.md#task-3
     */
    public function testRunSkipsWhenOpenRegisterUnavailable(): void
    {
        $this->settingsService->expects($this->once())
            ->method(constraint: 'isOpenRegisterAvailable')
            ->willReturn(false);

        $this->output->expects($this->atLeastOnce())
            ->method(constraint: 'warning');

        $this->settingsService->expects($this->never())
            ->method(constraint: 'loadConfiguration');

        $this->repairStep->run(output: $this->output);

    }//end testRunSkipsWhenOpenRegisterUnavailable()

    /**
     * Test that run imports configuration when OpenRegister is available.
     *
     * @return void
     *
     * @spec openspec/changes/p1-schemas-and-data-model/tasks.md#task-3
     */
    public function testRunImportsWhenOpenRegisterAvailable(): void
    {
        $this->settingsService->expects($this->once())
            ->method(constraint: 'isOpenRegisterAvailable')
            ->willReturn(true);

        $this->settingsService->expects($this->once())
            ->method(constraint: 'loadConfiguration')
            ->with(true)
            ->willReturn(
                    [
                        'success' => true,
                        'version' => '0.1.0',
                    ]
                    );

        $this->output->expects($this->atLeastOnce())
            ->method(constraint: 'info');

        $this->repairStep->run(output: $this->output);

    }//end testRunImportsWhenOpenRegisterAvailable()

    /**
     * Test that run handles exceptions gracefully.
     *
     * @return void
     *
     * @spec openspec/changes/p1-schemas-and-data-model/tasks.md#task-3
     */
    public function testRunHandlesExceptionGracefully(): void
    {
        $this->settingsService->expects($this->once())
            ->method(constraint: 'isOpenRegisterAvailable')
            ->willReturn(true);

        $this->settingsService->expects($this->once())
            ->method(constraint: 'loadConfiguration')
            ->willThrowException(new \RuntimeException('Connection failed'));

        $this->output->expects($this->atLeastOnce())
            ->method(constraint: 'warning');

        $this->logger->expects($this->once())
            ->method(constraint: 'error');

        $this->repairStep->run(output: $this->output);

    }//end testRunHandlesExceptionGracefully()

}//end class
