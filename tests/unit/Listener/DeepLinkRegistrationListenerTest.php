<?php

/**
 * Unit tests for DeepLinkRegistrationListener.
 *
 * @category Test
 * @package  OCA\Decidesk\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/p1-schemas-and-data-model/tasks.md#task-8
 */

declare(strict_types=1);

namespace OCA\Decidesk\Tests\Unit\Listener;

use OCA\Decidesk\Listener\DeepLinkRegistrationListener;
use OCA\OpenRegister\Event\DeepLinkRegistrationEvent;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Tests for DeepLinkRegistrationListener.
 *
 * @spec openspec/changes/p1-schemas-and-data-model/tasks.md#task-8
 */
class DeepLinkRegistrationListenerTest extends TestCase
{

    /**
     * Listener under test.
     *
     * @var DeepLinkRegistrationListener
     */
    private DeepLinkRegistrationListener $listener;

    /**
     * Set up test fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->listener = new DeepLinkRegistrationListener();

    }//end setUp()

    /**
     * Test that handle registers all 17 entity deep links.
     *
     * @return void
     *
     * @spec openspec/changes/p1-schemas-and-data-model/tasks.md#task-8
     */
    public function testHandleRegistersAll17EntityDeepLinks(): void
    {
        $event = $this->createMock(DeepLinkRegistrationEvent::class);
        $event->expects($this->exactly(17))
            ->method('register')
            ->with(
                $this->identicalTo('decidesk'),
                $this->identicalTo('decidesk'),
                $this->isType('string'),
                $this->isType('string'),
            );

        $this->listener->handle($event);

    }//end testHandleRegistersAll17EntityDeepLinks()

    /**
     * Test that handle ignores non-DeepLinkRegistrationEvent events.
     *
     * @return void
     *
     * @spec openspec/changes/p1-schemas-and-data-model/tasks.md#task-8
     */
    public function testHandleIgnoresNonDeepLinkEvents(): void
    {
        $event = $this->createMock(Event::class);

        // Should not throw or call any methods — just return silently.
        $this->listener->handle($event);

        $this->addToAssertionCount(1);

    }//end testHandleIgnoresNonDeepLinkEvents()

    /**
     * Test that all registered URL templates contain the {uuid} placeholder.
     *
     * @return void
     *
     * @spec openspec/changes/p1-schemas-and-data-model/tasks.md#task-8
     */
    public function testAllUrlTemplatesContainUuidPlaceholder(): void
    {
        $registeredUrls = [];

        $event = $this->createMock(DeepLinkRegistrationEvent::class);
        $event->method('register')
            ->willReturnCallback(
                function (string $appId, string $registerSlug, string $schemaSlug, string $urlTemplate) use (&$registeredUrls): void {
                    $registeredUrls[$schemaSlug] = $urlTemplate;
                }
            );

        $this->listener->handle($event);

        self::assertCount(17, $registeredUrls, 'Expected 17 schemas to be registered');

        foreach ($registeredUrls as $schema => $url) {
            self::assertStringContainsString(
                '{uuid}',
                $url,
                "URL template for schema '{$schema}' must contain {uuid} placeholder"
            );
        }

    }//end testAllUrlTemplatesContainUuidPlaceholder()

    /**
     * Test that governance-body schema deep link uses correct URL.
     *
     * @return void
     *
     * @spec openspec/changes/p1-schemas-and-data-model/tasks.md#task-8
     */
    public function testGovernanceBodyDeepLinkUrl(): void
    {
        $capturedUrl = '';

        $event = $this->createMock(DeepLinkRegistrationEvent::class);
        $event->method('register')
            ->willReturnCallback(
                function (string $appId, string $registerSlug, string $schemaSlug, string $urlTemplate) use (&$capturedUrl): void {
                    if ($schemaSlug === 'governance-body') {
                        $capturedUrl = $urlTemplate;
                    }
                }
            );

        $this->listener->handle($event);

        self::assertSame('/apps/decidesk/#/governance-bodies/{uuid}', $capturedUrl);

    }//end testGovernanceBodyDeepLinkUrl()
}//end class
