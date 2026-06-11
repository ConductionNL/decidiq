<?php

/**
 * Unit tests for ConflictOfInterestController.
 *
 * @category Test
 * @package  OCA\Decidesk\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-4.3
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidesk\Tests\Unit\Controller;

use OCA\Decidesk\Controller\ConflictOfInterestController;
use OCA\Decidesk\Service\ConflictOfInterestService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * Tests for ConflictOfInterestController.
 *
 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-4.3
 */
class ConflictOfInterestControllerTest extends TestCase
{


    /**
     * Build a controller wired with the given service double.
     *
     * @param ConflictOfInterestService $service       Service double
     * @param array<string, mixed>      $requestParams Params returned by IRequest
     * @param bool                      $authenticated Session has user
     *
     * @return ConflictOfInterestController
     */
    private function makeController(ConflictOfInterestService $service, array $requestParams=[], bool $authenticated=true): ConflictOfInterestController
    {
        $request = $this->createMock(IRequest::class);
        $request->method('getParams')->willReturn($requestParams);
        $request->method('getParam')->willReturnCallback(
            static function (string $key, mixed $default=null) use ($requestParams): mixed {
                return ($requestParams[$key] ?? $default);
            }
        );

        $session = $this->createMock(IUserSession::class);
        if ($authenticated === true) {
            $user = $this->createMock(IUser::class);
            $user->method('getUID')->willReturn('alice');
            $session->method('getUser')->willReturn($user);
        } else {
            $session->method('getUser')->willReturn(null);
        }

        return new ConflictOfInterestController($request, $service, $session);

    }//end makeController()


    /**
     * declare requires boardMemberId + agendaItemId.
     *
     * @return void
     */
    public function testDeclareRequiresMemberAndAgenda(): void
    {
        $service    = $this->createMock(ConflictOfInterestService::class);
        $controller = $this->makeController($service, requestParams: ['boardMemberId' => 'm1']);

        $this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $controller->declare()->getStatus());

    }//end testDeclareRequiresMemberAndAgenda()


    /**
     * declare returns 201 and forwards severity to service.
     *
     * @return void
     */
    public function testDeclareSucceedsReturns201(): void
    {
        $service = $this->createMock(ConflictOfInterestService::class);
        $service->expects($this->once())
            ->method('declare')
            ->with('m1', 'a1', 'financial-interest', 'shares', 'material')
            ->willReturn(
                [
                    'success'     => true,
                    'declaration' => ['id' => 'd1'],
                    'message'     => 'ok',
                ]
            );

        $controller = $this->makeController(
            $service,
            requestParams: [
                'boardMemberId'   => 'm1',
                'agendaItemId'    => 'a1',
                'declarationType' => 'financial-interest',
                'description'     => 'shares',
                'severity'        => 'material',
            ]
        );

        $this->assertSame(Http::STATUS_CREATED, $controller->declare()->getStatus());

    }//end testDeclareSucceedsReturns201()


    /**
     * forMember requires agendaItemId; returns the conflict wrapper.
     *
     * @return void
     */
    public function testForMemberReturnsConflict(): void
    {
        $service = $this->createMock(ConflictOfInterestService::class);
        $service->expects($this->once())
            ->method('getActiveConflicts')
            ->with('m1', 'a1')
            ->willReturn(['id' => 'd1', 'actionTaken' => 'recused-from-vote']);

        $controller = $this->makeController($service, requestParams: ['agendaItemId' => 'a1']);
        $response   = $controller->forMember('m1');

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertSame('recused-from-vote', $response->getData()['conflict']['actionTaken']);

    }//end testForMemberReturnsConflict()


    /**
     * recordAction requires actionTaken body param.
     *
     * @return void
     */
    public function testRecordActionRequiresActionParam(): void
    {
        $service    = $this->createMock(ConflictOfInterestService::class);
        $controller = $this->makeController($service);

        $this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $controller->recordAction('d1')->getStatus());

    }//end testRecordActionRequiresActionParam()


    /**
     * Anonymous access rejected.
     *
     * @return void
     */
    public function testAnonymousAccessRejected(): void
    {
        $service    = $this->createMock(ConflictOfInterestService::class);
        $controller = $this->makeController($service, authenticated: false);

        $this->assertSame(Http::STATUS_UNAUTHORIZED, $controller->declare()->getStatus());
        $this->assertSame(Http::STATUS_UNAUTHORIZED, $controller->forMember('m1')->getStatus());
        $this->assertSame(Http::STATUS_UNAUTHORIZED, $controller->recordAction('d1')->getStatus());

    }//end testAnonymousAccessRejected()


}//end class
