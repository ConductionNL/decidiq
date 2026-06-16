<?php

/**
 * Unit tests for OriController — the ORI harvest feed over published payloads.
 *
 * @category Test
 * @package  OCA\Decidesk\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2.

declare(strict_types=1);

namespace OCA\Decidesk\Tests\Unit\Controller;

use OCA\Decidesk\Controller\OriController;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Http;
use OCP\IConfig;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for the OriController `publications` ORI harvest resource.
 *
 * publish-decisions-via-opencatalogi task 5.2 — the harvest feed must surface a
 * PublicationPayload only inside its RBAC published-predicate window: visible
 * once `publicatiedatum <= now`, never before that date, and gone once
 * `depublicatiedatum` is in the past. The feed must self-declare each item's ORI
 * `@type` from the payload `oriType` and carry only the allow-list, PII-free
 * fields the payload schema constructs.
 *
 * @spec openspec/changes/publish-decisions-via-opencatalogi/specs/public-publication/spec.md
 */
class OriControllerTest extends TestCase
{

    /**
     * Mock IRequest.
     *
     * @var IRequest&MockObject
     */
    private IRequest&MockObject $request;

    /**
     * Mock IConfig.
     *
     * @var IConfig&MockObject
     */
    private IConfig&MockObject $config;

    /**
     * Mock ContainerInterface.
     *
     * @var ContainerInterface&MockObject
     */
    private ContainerInterface&MockObject $container;

    /**
     * Mock ObjectService.
     *
     * @var ObjectService&MockObject
     */
    private ObjectService&MockObject $objectService;

    /**
     * Mock LoggerInterface.
     *
     * @var LoggerInterface&MockObject
     */
    private LoggerInterface&MockObject $logger;

    /**
     * The controller under test.
     *
     * @var OriController
     */
    private OriController $controller;


    /**
     * Set up mocks and the controller.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->request       = $this->createMock(IRequest::class);
        $this->config        = $this->createMock(IConfig::class);
        $this->container     = $this->createMock(ContainerInterface::class);
        $this->objectService = $this->createMock(ObjectService::class);
        $this->logger        = $this->createMock(LoggerInterface::class);

        $this->container->method('get')
            ->with('OCA\\OpenRegister\\Service\\ObjectService')
            ->willReturn($this->objectService);

        $this->config->method('getSystemValueString')->willReturn('https://gemeente.example');

        $this->controller = new OriController(
            $this->request,
            $this->config,
            $this->container,
            $this->logger,
        );

    }//end setUp()


    /**
     * Build a mock ObjectEntity returning $data from jsonSerialize().
     *
     * @param array<string,mixed> $data The serialized payload.
     *
     * @return object
     */
    private function makeEntity(array $data): object
    {
        $entity = $this->getMockBuilder(\OCA\OpenRegister\Db\ObjectEntity::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['jsonSerialize'])
            ->getMock();
        $entity->method('jsonSerialize')->willReturn($data);
        return $entity;

    }//end makeEntity()


    /**
     * A Besluit payload published in the past (and not depublished) appears on
     * the harvest feed, self-declares oriType=Besluit, and carries no UID/PII.
     *
     * @return void
     *
     * @spec openspec/changes/publish-decisions-via-opencatalogi/specs/public-publication/spec.md
     */
    public function testLivePublicationAppearsOnFeed(): void
    {
        $past = (new \DateTimeImmutable('-1 day'))->format(\DateTimeInterface::ATOM);

        $this->objectService->method('findAll')->willReturn(
            [
                $this->makeEntity(
                    [
                        'uuid'            => 'pub-1',
                        'oriType'         => 'Besluit',
                        'schemaOrgType'   => 'ChooseAction',
                        'title'           => 'Vaststelling Programmabegroting 2026',
                        'text'            => 'De raad besluit.',
                        'outcome'         => 'adopted',
                        'voteTotals'      => ['for' => 30, 'against' => 5, 'abstain' => 0],
                        'bodyName'        => 'Gemeenteraad Amsterdam',
                        'publicatiedatum' => $past,
                    ]
                ),
            ]
        );

        $response = $this->controller->index(resource: 'publications');
        self::assertSame(Http::STATUS_OK, $response->getStatus());

        $body = $response->getData();
        self::assertSame(1, $body['count']);
        $item = $body['items'][0];

        self::assertSame('Besluit', $item['@type']);
        self::assertSame('Besluit', $item['oriType']);
        self::assertSame('pub-1', $item['id']);
        self::assertSame('Vaststelling Programmabegroting 2026', $item['name']);
        self::assertSame('adopted', $item['outcome']);
        self::assertSame(['for' => 30, 'against' => 5, 'abstain' => 0], $item['vote_totals']);
        self::assertSame('Gemeenteraad Amsterdam', $item['body']);

        // PII guard: a payload never carries voter identities, UIDs, or contact
        // details — the feed must not surface any such field.
        self::assertArrayNotHasKey('email', $item);
        self::assertArrayNotHasKey('@self', $item);
        self::assertArrayNotHasKey('uid', $item);

    }//end testLivePublicationAppearsOnFeed()


    /**
     * A future-dated publication (publicatiedatum in the future) is NOT visible
     * on the anonymous harvest feed.
     *
     * @return void
     *
     * @spec openspec/changes/publish-decisions-via-opencatalogi/specs/public-publication/spec.md
     */
    public function testFutureDatedPublicationIsHidden(): void
    {
        $future = (new \DateTimeImmutable('+10 days'))->format(\DateTimeInterface::ATOM);

        $this->objectService->method('findAll')->willReturn(
            [
                $this->makeEntity(
                    [
                        'uuid'            => 'pub-future',
                        'oriType'         => 'Besluit',
                        'title'           => 'Embargoed decision',
                        'publicatiedatum' => $future,
                    ]
                ),
            ]
        );

        $response = $this->controller->index(resource: 'publications');
        $body     = $response->getData();

        self::assertSame(0, $body['count']);
        self::assertSame([], $body['items']);

    }//end testFutureDatedPublicationIsHidden()


    /**
     * A depublished publication (depublicatiedatum in the past) is gone from the
     * harvest feed even though publicatiedatum is in the past.
     *
     * @return void
     *
     * @spec openspec/changes/publish-decisions-via-opencatalogi/specs/public-publication/spec.md
     */
    public function testDepublishedPublicationIsHidden(): void
    {
        $past         = (new \DateTimeImmutable('-10 days'))->format(\DateTimeInterface::ATOM);
        $depublishPast = (new \DateTimeImmutable('-1 day'))->format(\DateTimeInterface::ATOM);

        $this->objectService->method('findAll')->willReturn(
            [
                $this->makeEntity(
                    [
                        'uuid'              => 'pub-withdrawn',
                        'oriType'           => 'Verslag',
                        'title'             => 'Withdrawn minutes',
                        'publicatiedatum'   => $past,
                        'depublicatiedatum' => $depublishPast,
                    ]
                ),
            ]
        );

        $response = $this->controller->index(resource: 'publications');
        $body     = $response->getData();

        self::assertSame(0, $body['count']);

    }//end testDepublishedPublicationIsHidden()


    /**
     * A scheduled depublication still in the future does not hide a live payload.
     *
     * @return void
     *
     * @spec openspec/changes/publish-decisions-via-opencatalogi/specs/public-publication/spec.md
     */
    public function testFutureDepublicationStaysVisible(): void
    {
        $past   = (new \DateTimeImmutable('-2 days'))->format(\DateTimeInterface::ATOM);
        $future = (new \DateTimeImmutable('+5 days'))->format(\DateTimeInterface::ATOM);

        $this->objectService->method('findAll')->willReturn(
            [
                $this->makeEntity(
                    [
                        'uuid'              => 'pub-scheduled',
                        'oriType'           => 'Vergadering',
                        'title'             => 'Agenda with scheduled depublication',
                        'meetingDate'       => $past,
                        'agendaItems'       => [['oriType' => 'AgendaPunt', 'title' => 'Item 1']],
                        'publicatiedatum'   => $past,
                        'depublicatiedatum' => $future,
                    ]
                ),
            ]
        );

        $response = $this->controller->index(resource: 'publications');
        $body     = $response->getData();

        self::assertSame(1, $body['count']);
        $item = $body['items'][0];
        self::assertSame('Vergadering', $item['@type']);
        self::assertSame($past, $item['meeting_date']);
        self::assertSame([['oriType' => 'AgendaPunt', 'title' => 'Item 1']], $item['agenda_items']);

    }//end testFutureDepublicationStaysVisible()


    /**
     * A payload with no publicatiedatum at all (never published) is not visible.
     *
     * @return void
     *
     * @spec openspec/changes/publish-decisions-via-opencatalogi/specs/public-publication/spec.md
     */
    public function testNeverPublishedPayloadIsHidden(): void
    {
        $this->objectService->method('findAll')->willReturn(
            [
                $this->makeEntity(
                    [
                        'uuid'    => 'pub-draft',
                        'oriType' => 'Besluit',
                        'title'   => 'Drafted but not published',
                    ]
                ),
            ]
        );

        $response = $this->controller->index(resource: 'publications');
        self::assertSame(0, $response->getData()['count']);

    }//end testNeverPublishedPayloadIsHidden()


    /**
     * show() returns 404 for a future-dated payload (not 403 — never confirm an
     * unpublished payload exists).
     *
     * @return void
     *
     * @spec openspec/changes/publish-decisions-via-opencatalogi/specs/public-publication/spec.md
     */
    public function testShowFutureDatedPayloadIs404(): void
    {
        $future = (new \DateTimeImmutable('+3 days'))->format(\DateTimeInterface::ATOM);

        $this->objectService->method('find')->willReturn(
            $this->makeEntity(
                [
                    'uuid'            => 'pub-future',
                    'oriType'         => 'Besluit',
                    'title'           => 'Embargoed',
                    'publicatiedatum' => $future,
                ]
            )
        );

        $response = $this->controller->show(resource: 'publications', id: 'pub-future');
        self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());

    }//end testShowFutureDatedPayloadIs404()


    /**
     * show() returns the ORI JSON-LD entity for a live payload.
     *
     * @return void
     *
     * @spec openspec/changes/publish-decisions-via-opencatalogi/specs/public-publication/spec.md
     */
    public function testShowLivePayloadReturnsEntity(): void
    {
        $past = (new \DateTimeImmutable('-1 day'))->format(\DateTimeInterface::ATOM);

        $this->objectService->method('find')->willReturn(
            $this->makeEntity(
                [
                    'uuid'            => 'pub-live',
                    'oriType'         => 'Besluit',
                    'title'           => 'Live decision',
                    'publicatiedatum' => $past,
                ]
            )
        );

        $response = $this->controller->show(resource: 'publications', id: 'pub-live');
        self::assertSame(Http::STATUS_OK, $response->getStatus());

        $body = $response->getData();
        self::assertSame('Besluit', $body['@type']);
        self::assertSame('pub-live', $body['id']);
        self::assertSame('Live decision', $body['name']);

    }//end testShowLivePayloadReturnsEntity()


    /**
     * An unknown ORI resource slug returns 404.
     *
     * @return void
     *
     * @spec openspec/changes/p4-integration/tasks.md#task-11
     */
    public function testUnknownResourceIs404(): void
    {
        $response = $this->controller->index(resource: 'nonexistent');
        self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());

    }//end testUnknownResourceIs404()


}//end class
