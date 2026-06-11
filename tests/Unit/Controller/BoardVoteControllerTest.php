<?php

/**
 * Unit tests for BoardVoteController.
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
 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-phase3-board-vote-controller
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidesk\Tests\Unit\Controller;

use OCA\Decidesk\Controller\BoardVoteController;
use OCA\Decidesk\Service\BoardVoteService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * Tests for BoardVoteController.
 *
 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-phase3-board-vote-controller
 */
class BoardVoteControllerTest extends TestCase
{


    /**
     * Build a controller backed by the given service double.
     *
     * @param BoardVoteService     $service       Service double
     * @param array<string, mixed> $requestParams Params returned by IRequest
     * @param bool                 $authenticated Whether the session has a user
     *
     * @return BoardVoteController
     */
    private function makeController(BoardVoteService $service, array $requestParams=[], bool $authenticated=true): BoardVoteController
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

        return new BoardVoteController($request, $service, $session);

    }//end makeController()


    /**
     * cast requires both boardMemberId and vote.
     *
     * @return void
     */
    public function testCastRequiresMemberAndVote(): void
    {
        $service    = $this->createMock(BoardVoteService::class);
        $controller = $this->makeController($service, requestParams: ['boardMemberId' => 'm1']);

        $response = $controller->cast('r1');

        $this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());

    }//end testCastRequiresMemberAndVote()


    /**
     * cast forwards optional fields and returns 201 on success.
     *
     * @return void
     */
    public function testCastSucceedsReturns201(): void
    {
        $service = $this->createMock(BoardVoteService::class);
        $service->expects($this->once())
            ->method('cast')
            ->with(
                $this->equalTo('r1'),
                $this->equalTo('m1'),
                $this->equalTo('in-favor'),
                $this->callback(static function (array $extra): bool {
                    return (($extra['voteMethod'] ?? null) === 'electronic'
                        && ($extra['agendaItemKoppeling'] ?? null) === 'a1');
                })
            )
            ->willReturn(
                [
                    'success' => true,
                    'vote'    => ['id' => 'v1'],
                    'message' => 'ok',
                ]
            );

        $controller = $this->makeController(
            $service,
            requestParams: [
                'boardMemberId'        => 'm1',
                'vote'                 => 'in-favor',
                'voteMethod'           => 'electronic',
                'agendaItemKoppeling'  => 'a1',
            ]
        );

        $this->assertSame(Http::STATUS_CREATED, $controller->cast('r1')->getStatus());

    }//end testCastSucceedsReturns201()


    /**
     * tally returns the running counts shape.
     *
     * @return void
     */
    public function testTallyReturnsCounts(): void
    {
        $service = $this->createMock(BoardVoteService::class);
        $service->method('tally')->willReturn(
            [
                'success' => true,
                'tally'   => ['in-favor' => 2, 'against' => 1, 'abstain' => 0, 'absent' => 0, 'recused-due-to-conflict' => 0],
                'cast'    => 3,
                'total'   => 3,
            ]
        );

        $controller = $this->makeController($service);
        $response   = $controller->tally('r1');

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertSame(3, $response->getData()['cast']);

    }//end testTallyReturnsCounts()


    /**
     * audit returns the raw rows.
     *
     * @return void
     */
    public function testAuditReturnsRawRows(): void
    {
        $service = $this->createMock(BoardVoteService::class);
        $service->method('audit')->willReturn(
            [
                'success' => true,
                'votes'   => [['id' => 'v1'], ['id' => 'v2']],
            ]
        );

        $controller = $this->makeController($service);
        $response   = $controller->audit('r1');

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertSame(2, $response->getData()['total']);

    }//end testAuditReturnsRawRows()


    /**
     * Anonymous calls are rejected.
     *
     * @return void
     */
    public function testAnonymousAccessRejected(): void
    {
        $service    = $this->createMock(BoardVoteService::class);
        $controller = $this->makeController($service, authenticated: false);

        $this->assertSame(Http::STATUS_UNAUTHORIZED, $controller->cast('r1')->getStatus());
        $this->assertSame(Http::STATUS_UNAUTHORIZED, $controller->tally('r1')->getStatus());
        $this->assertSame(Http::STATUS_UNAUTHORIZED, $controller->audit('r1')->getStatus());

    }//end testAnonymousAccessRejected()


}//end class
