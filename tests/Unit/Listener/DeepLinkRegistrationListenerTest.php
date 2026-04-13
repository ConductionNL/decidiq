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
use OCA\OpenRegister\Event\DeepLinkRegistrationEvent;
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
        $this->addToAssertionCount(1);

    }//end testIgnoresNonDeepLinkEvents()

    /**
     * Test that an event that is not a DeepLinkRegistrationEvent results in zero registrations.
     *
     * The anonymous class below has a register() method but does NOT extend
     * DeepLinkRegistrationEvent, so the listener must return early without
     * calling register().
     *
     * @return void
     *
     * @spec openspec/changes/p1-schemas-and-data-model/tasks.md#task-1
     */
    public function testIgnoresEventThatIsNotDeepLinkRegistrationEvent(): void
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

        $this->listener->handle(event: $event);

        self::assertCount(expectedCount: 0, haystack: $registrations);

    }//end testIgnoresEventThatIsNotDeepLinkRegistrationEvent()

    /**
     * Test that handling a DeepLinkRegistrationEvent registers exactly 17 schemas.
     *
     * Each registration must use appId 'decidesk', registerSlug 'decidesk',
     * and a urlTemplate matching /apps/decidesk/#/{routeSegment}/{uuid}.
     *
     * @return void
     *
     * @spec openspec/changes/p1-schemas-and-data-model/tasks.md#task-1
     */
    public function testRegistersAllSeventeenSchemas(): void
    {
        $event = new DeepLinkRegistrationEvent();
        $this->listener->handle(event: $event);

        $registrations = $event->getRegistrations();

        self::assertCount(expectedCount: 17, haystack: $registrations);

        foreach ($registrations as $reg) {
            self::assertSame(expected: 'decidesk', actual: $reg['appId']);
            self::assertSame(expected: 'decidesk', actual: $reg['registerSlug']);
            self::assertMatchesRegularExpression(
                pattern: '~^/apps/decidesk/#/.+/\{uuid\}$~',
                string: $reg['urlTemplate'],
            );
        }

    }//end testRegistersAllSeventeenSchemas()

    /**
     * Test that all 17 expected schema slugs are registered with correct URL templates.
     *
     * @return void
     *
     * @spec openspec/changes/p1-schemas-and-data-model/tasks.md#task-1
     */
    public function testRegistersExpectedSlugsAndUrlTemplates(): void
    {
        $event = new DeepLinkRegistrationEvent();
        $this->listener->handle(event: $event);

        $registrations = $event->getRegistrations();
        $bySlug        = [];
        foreach ($registrations as $reg) {
            $bySlug[$reg['schemaSlug']] = $reg['urlTemplate'];
        }

        $expected = [
            'governance-body'  => '/apps/decidesk/#/governance-bodies/{uuid}',
            'meeting'          => '/apps/decidesk/#/meetings/{uuid}',
            'participant'      => '/apps/decidesk/#/participants/{uuid}',
            'agenda-item'      => '/apps/decidesk/#/agenda-items/{uuid}',
            'motion'           => '/apps/decidesk/#/motions/{uuid}',
            'amendment'        => '/apps/decidesk/#/amendments/{uuid}',
            'voting-round'     => '/apps/decidesk/#/voting-rounds/{uuid}',
            'vote'             => '/apps/decidesk/#/votes/{uuid}',
            'decision'         => '/apps/decidesk/#/decisions/{uuid}',
            'action-item'      => '/apps/decidesk/#/action-items/{uuid}',
            'minutes'          => '/apps/decidesk/#/minutes/{uuid}',
            'digital-document' => '/apps/decidesk/#/digital-documents/{uuid}',
            'monetary-amount'  => '/apps/decidesk/#/monetary-amounts/{uuid}',
            'offer'            => '/apps/decidesk/#/offers/{uuid}',
            'order'            => '/apps/decidesk/#/orders/{uuid}',
            'product'          => '/apps/decidesk/#/products/{uuid}',
            'report'           => '/apps/decidesk/#/reports/{uuid}',
        ];

        foreach ($expected as $slug => $urlTemplate) {
            self::assertArrayHasKey(key: $slug, array: $bySlug, message: "Schema slug '{$slug}' not registered");
            self::assertSame(expected: $urlTemplate, actual: $bySlug[$slug]);
        }

    }//end testRegistersExpectedSlugsAndUrlTemplates()

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
