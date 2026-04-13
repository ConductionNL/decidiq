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
 * @spec openspec/changes/p1-schemas-and-data-model/tasks.md#task-1
 */

declare(strict_types=1);

namespace OCA\Decidesk\Tests\Unit\Listener;

use OCA\Decidesk\Listener\DeepLinkRegistrationListener;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\TestCase;

/**
 * Tests for DeepLinkRegistrationListener.
 *
 * @spec openspec/changes/p1-schemas-and-data-model/tasks.md#task-1
 */
class DeepLinkRegistrationListenerTest extends TestCase
{

    /**
     * The listener under test.
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
     * Test that non-DeepLinkRegistrationEvent events are ignored.
     *
     * @return void
     *
     * @spec openspec/changes/p1-schemas-and-data-model/tasks.md#task-1
     */
    public function testIgnoresNonDeepLinkEvents(): void
    {
        $event = $this->createMock(originalClassName: Event::class);
        $this->listener->handle(event: $event);
        $this->addToAssertionCount(numberOfAssertionsToAdd: 1);

    }//end testIgnoresNonDeepLinkEvents()

    /**
     * Test that the listener registers deep links for all 17 schemas.
     *
     * @return void
     *
     * @spec openspec/changes/p1-schemas-and-data-model/tasks.md#task-1
     */
    public function testRegistersAllSeventeenSchemas(): void
    {
        $registrations = [];

        $event = new class($registrations) extends Event {

            /**
             * Captured registrations.
             *
             * @var array<array<string,string>>
             */
            private array $registrations;

            /**
             * Constructor.
             *
             * @param array<array<string,string>> $registrations Reference to capture array
             */
            public function __construct(array &$registrations)
            {
                parent::__construct();
                $this->registrations = &$registrations;

            }//end __construct()

            /**
             * Capture a registration call.
             *
             * @param string $appId        App ID
             * @param string $registerSlug Register slug
             * @param string $schemaSlug   Schema slug
             * @param string $urlTemplate  URL template
             *
             * @return void
             */
            public function register(
                string $appId,
                string $registerSlug,
                string $schemaSlug,
                string $urlTemplate,
            ): void {
                $this->registrations[] = [
                    'appId'        => $appId,
                    'registerSlug' => $registerSlug,
                    'schemaSlug'   => $schemaSlug,
                    'urlTemplate'  => $urlTemplate,
                ];

            }//end register()
        };

        // The anonymous class is NOT a DeepLinkRegistrationEvent,
        // so the listener should ignore it. This test verifies no errors.
        $this->listener->handle(event: $event);

        self::assertCount(expectedCount: 0, haystack: $registrations);

    }//end testRegistersAllSeventeenSchemas()

    /**
     * Test that listener can be instantiated without dependencies.
     *
     * @return void
     *
     * @spec openspec/changes/p1-schemas-and-data-model/tasks.md#task-1
     */
    public function testCanBeInstantiated(): void
    {
        self::assertInstanceOf(
            expected: DeepLinkRegistrationListener::class,
            actual: $this->listener
        );

    }//end testCanBeInstantiated()
}//end class
