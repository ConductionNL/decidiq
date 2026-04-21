<?php

/**
 * Unit tests for DecisionDigestJob.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\Decidesk\Tests\Unit\BackgroundJob
 *
 * @spec openspec/changes/p2-minutes-and-decisions-other-t1/tasks.md#task-6
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Decidesk\Tests\Unit\BackgroundJob;

use OCA\Decidesk\BackgroundJob\DecisionDigestJob;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for DecisionDigestJob — covers HTML injection fix in digest emails.
 *
 * @spec openspec/changes/p2-minutes-and-decisions-other-t1/tasks.md#task-6
 */
class DecisionDigestJobTest extends TestCase
{

    /**
     * Mock DI container.
     *
     * @var ContainerInterface&MockObject
     */
    private ContainerInterface&MockObject $container;

    /**
     * Mock logger.
     *
     * @var LoggerInterface&MockObject
     */
    private LoggerInterface&MockObject $logger;

    /**
     * Mock time factory.
     *
     * @var ITimeFactory&MockObject
     */
    private ITimeFactory&MockObject $time;

    /**
     * Set up test fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->container = $this->createMock(ContainerInterface::class);
        $this->logger    = $this->createMock(LoggerInterface::class);
        $this->time      = $this->createMock(ITimeFactory::class);

    }//end setUp()

    /**
     * The job can be instantiated with the DI container and logger.
     *
     * @spec openspec/changes/p2-minutes-and-decisions-other-t1/tasks.md#task-6
     *
     * @return void
     */
    public function testJobCanBeInstantiated(): void
    {
        $job = new DecisionDigestJob(
            time: $this->time,
            container: $this->container,
            logger: $this->logger,
        );

        self::assertInstanceOf(DecisionDigestJob::class, $job);

    }//end testJobCanBeInstantiated()

    /**
     * run() logs an error and does not throw when ObjectService is unavailable.
     *
     * @spec openspec/changes/p2-minutes-and-decisions-other-t1/tasks.md#task-6
     *
     * @return void
     */
    public function testRunLogsErrorWhenObjectServiceUnavailable(): void
    {
        $this->container->method('get')
            ->willThrowException(new \Exception('Service unavailable'));

        $this->logger->expects($this->atLeastOnce())
            ->method('error');

        $job = new DecisionDigestJob(
            time: $this->time,
            container: $this->container,
            logger: $this->logger,
        );

        // run() is protected; invoke via reflection to test without NC bootstrap.
        $method = new \ReflectionMethod(DecisionDigestJob::class, 'run');
        $method->setAccessible(true);

        // Must not throw — the job must catch all errors gracefully.
        $method->invoke($job, null);

        // Assertion implicit: no exception thrown and error was logged.
        self::assertTrue(true);

    }//end testRunLogsErrorWhenObjectServiceUnavailable()

    /**
     * run() logs info on start when service resolves but returns no governance bodies.
     *
     * @spec openspec/changes/p2-minutes-and-decisions-other-t1/tasks.md#task-6
     *
     * @return void
     */
    public function testRunLogsInfoWhenNoBodiesFound(): void
    {
        $objectService = $this->getMockBuilder(\stdClass::class)
            ->addMethods(['setRegister', 'setSchema', 'findAll'])
            ->getMock();

        $objectService->method('setRegister')->willReturnSelf();
        $objectService->method('setSchema')->willReturnSelf();
        $objectService->method('findAll')->willReturn([]);

        $appConfig = $this->getMockBuilder(\stdClass::class)
            ->addMethods(['getValueBool'])
            ->getMock();
        $appConfig->method('getValueBool')->willReturn(true);

        $this->container->method('get')
            ->willReturnCallback(
                function (string $id) use ($objectService, $appConfig): object {
                    if ($id === 'OCA\OpenRegister\Service\ObjectService') {
                        return $objectService;
                    }

                    if (str_contains($id, 'IAppConfig') === true || $id === \OCP\IAppConfig::class) {
                        return $appConfig;
                    }

                    throw new \Exception("Unknown service: $id");
                }
            );

        $this->logger->expects($this->atLeastOnce())->method('info');

        $job = new DecisionDigestJob(
            time: $this->time,
            container: $this->container,
            logger: $this->logger,
        );

        $method = new \ReflectionMethod(DecisionDigestJob::class, 'run');
        $method->setAccessible(true);
        $method->invoke($job, null);

        self::assertTrue(true);

    }//end testRunLogsInfoWhenNoBodiesFound()

    /**
     * HTML digest output escapes user-controlled field values (HTML injection fix).
     *
     * Verifies that title/dueDate/lifecycle containing HTML special characters
     * are escaped in the HTML email body.
     *
     * @spec openspec/changes/p2-minutes-and-decisions-other-t1/tasks.md#task-6
     *
     * @return void
     */
    public function testHtmlBodyEscapesUserControlledValues(): void
    {
        $job = new DecisionDigestJob(
            time: $this->time,
            container: $this->container,
            logger: $this->logger,
        );

        $maliciousTitle   = '<script>alert("xss")</script>';
        $maliciousDueDate = '2026-01-01"><img src=x onerror=alert(1)>';
        $maliciousState   = 'legal-review<br>';

        $upcomingItems    = [['title' => $maliciousTitle, 'dueDate' => $maliciousDueDate]];
        $overdueItems     = [['title' => $maliciousTitle, 'dueDate' => $maliciousDueDate]];
        $pendingDecisions = [['title' => $maliciousTitle, 'lifecycle' => $maliciousState]];

        // Access buildHtmlBody via reflection.
        $method = new \ReflectionMethod(DecisionDigestJob::class, 'buildHtmlBody');
        $method->setAccessible(true);

        $html = $method->invoke(
            $job,
            $upcomingItems,
            $overdueItems,
            $pendingDecisions,
        );

        // Raw HTML tags must not appear in the output.
        self::assertStringNotContainsString('<script>', $html);
        self::assertStringNotContainsString('onerror=alert', $html);
        self::assertStringNotContainsString($maliciousState, $html);

        // The escaped form must be present.
        self::assertStringContainsString('&lt;script&gt;', $html);

    }//end testHtmlBodyEscapesUserControlledValues()

}//end class
