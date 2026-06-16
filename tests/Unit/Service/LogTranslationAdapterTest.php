<?php
/**
 * Unit tests for LogTranslationAdapter.
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
 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-6.3
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidesk\Tests\Unit\Service;

use OCA\Decidesk\Service\LogTranslationAdapter;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for LogTranslationAdapter.
 *
 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-6.3
 */
class LogTranslationAdapterTest extends TestCase
{


    /**
     * Identical locales short-circuit with provider=noop.
     *
     * @return void
     */
    public function testIdenticalLocalesShortCircuit(): void
    {
        $adapter = new LogTranslationAdapter(
            container: $this->createMock(ContainerInterface::class),
            logger: $this->createMock(LoggerInterface::class),
        );

        $result = $adapter->translate('hello', 'nl', 'nl');
        $this->assertTrue($result['success']);
        $this->assertSame('hello', $result['text']);
        $this->assertSame('noop', $result['provider']);

    }//end testIdenticalLocalesShortCircuit()


    /**
     * Without an openconnector translation service available, the adapter
     * returns the original text with provider=log.
     *
     * @return void
     */
    public function testFallsBackToLogProvider(): void
    {
        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')->willThrowException(new \RuntimeException('no provider'));

        $adapter = new LogTranslationAdapter(
            container: $container,
            logger: $this->createMock(LoggerInterface::class),
        );

        $result = $adapter->translate('Hallo wereld', 'nl', 'en');
        $this->assertTrue($result['success']);
        $this->assertSame('Hallo wereld', $result['text']);
        $this->assertSame('log', $result['provider']);

    }//end testFallsBackToLogProvider()


}//end class
