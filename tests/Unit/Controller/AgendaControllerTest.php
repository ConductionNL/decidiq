<?php

/**
 * Unit tests for AgendaController.
 *
 * @category Test
 * @package  OCA\Decidesk\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/p2-agenda-management/tasks.md#task-9
 */

// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

declare(strict_types=1);

namespace OCA\Decidesk\Tests\Unit\Controller;

use OCA\Decidesk\Controller\AgendaController;
use OCA\Decidesk\Service\AgendaService;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Tests for AgendaController.
 *
 * @spec openspec/changes/p2-agenda-management/tasks.md#task-9
 */
class AgendaControllerTest extends TestCase
{

    /**
     * The controller under test.
     *
     * @var AgendaController
     */
    private AgendaController $controller;

    /**
     * Mock IRequest.
     *
     * @var IRequest&MockObject
     */
    private IRequest&MockObject $request;

    /**
     * Mock AgendaService.
     *
     * @var AgendaService&MockObject
     */
    private AgendaService&MockObject $agendaService;

    /**
     * Set up test fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->request       = $this->createMock(originalClassName: IRequest::class);
        $this->agendaService = $this->createMock(originalClassName: AgendaService::class);

        $this->controller = new AgendaController(
            request: $this->request,
            agendaService: $this->agendaService,
        );

    }//end setUp()

    /**
     * Test publish returns success response.
     *
     * @return void
     *
     * @spec openspec/changes/p2-agenda-management/tasks.md#task-9
     */
    public function testPublishReturnsSuccess(): void
    {
        $expected = [
            'success'       => true,
            'message'       => 'Agenda gepubliceerd',
            'notifications' => 3,
        ];

        $this->agendaService->expects($this->once())
            ->method('publishAgenda')
            ->with(meetingId: 'meeting-1')
            ->willReturn($expected);

        $result = $this->controller->publish(meetingId: 'meeting-1');

        self::assertInstanceOf(expected: JSONResponse::class, actual: $result);
        self::assertSame(expected: $expected, actual: $result->getData());

    }//end testPublishReturnsSuccess()

    /**
     * Test publish returns 400 on RuntimeException.
     *
     * @return void
     *
     * @spec openspec/changes/p2-agenda-management/tasks.md#task-9
     */
    public function testPublishReturns400OnError(): void
    {
        $this->agendaService->expects($this->once())
            ->method('publishAgenda')
            ->willThrowException(new \RuntimeException('Een agenda moet minimaal één agendapunt bevatten'));

        $result = $this->controller->publish(meetingId: 'meeting-1');

        self::assertInstanceOf(expected: JSONResponse::class, actual: $result);
        self::assertFalse(condition: $result->getData()['success']);
        self::assertSame(expected: 400, actual: $result->getStatus());

    }//end testPublishReturns400OnError()

    /**
     * Test advanceBobPhase delegates to service.
     *
     * @return void
     *
     * @spec openspec/changes/p2-agenda-management/tasks.md#task-9
     */
    public function testAdvanceBobPhaseDelegatesToService(): void
    {
        $expected = [
            'success'       => true,
            'previousPhase' => 'beeldvorming',
            'currentPhase'  => 'oordeelsvorming',
        ];

        $this->agendaService->expects($this->once())
            ->method('advanceBobPhase')
            ->with(agendaItemId: 'item-1')
            ->willReturn($expected);

        $result = $this->controller->advanceBobPhase(id: 'item-1');

        self::assertInstanceOf(expected: JSONResponse::class, actual: $result);
        self::assertTrue(condition: $result->getData()['success']);

    }//end testAdvanceBobPhaseDelegatesToService()

    /**
     * Test processHamerstukken delegates to service.
     *
     * @return void
     *
     * @spec openspec/changes/p2-agenda-management/tasks.md#task-9
     */
    public function testProcessHamerstukkenDelegatesToService(): void
    {
        $expected = [
            'success' => true,
            'count'   => 2,
        ];

        $this->agendaService->expects($this->once())
            ->method('processHamerstukken')
            ->with(meetingId: 'meeting-1')
            ->willReturn($expected);

        $result = $this->controller->processHamerstukken(meetingId: 'meeting-1');

        self::assertInstanceOf(expected: JSONResponse::class, actual: $result);
        self::assertSame(expected: 2, actual: $result->getData()['count']);

    }//end testProcessHamerstukkenDelegatesToService()

    /**
     * Test reorder passes IDs from request to service.
     *
     * @return void
     *
     * @spec openspec/changes/p2-agenda-management/tasks.md#task-9
     */
    public function testReorderPassesIdsToService(): void
    {
        $ids      = ['item-c', 'item-a', 'item-b'];
        $expected = [
            'success' => true,
            'count'   => 3,
        ];

        $this->request->expects($this->once())
            ->method('getParam')
            ->with('ids', [])
            ->willReturn($ids);

        $this->agendaService->expects($this->once())
            ->method('reorderItems')
            ->with(
                meetingId: 'meeting-1',
                orderedIds: $ids
            )
            ->willReturn($expected);

        $result = $this->controller->reorder(meetingId: 'meeting-1');

        self::assertInstanceOf(expected: JSONResponse::class, actual: $result);
        self::assertSame(expected: 3, actual: $result->getData()['count']);

    }//end testReorderPassesIdsToService()
}//end class
