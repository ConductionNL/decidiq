<?php
/**
 * Unit tests for EIDASSignatureController.
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
 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-3.3
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidesk\Tests\Unit\Controller;

use OCA\Decidesk\Controller\EIDASSignatureController;
use OCA\Decidesk\Service\IEIDASSignatureService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * Tests for EIDASSignatureController.
 *
 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-3.3
 */
class EIDASSignatureControllerTest extends TestCase
{


    /**
     * Build a controller wired to the supplied adapter and request params.
     *
     * @param IEIDASSignatureService $service       The eIDAS adapter double
     * @param array<string, mixed>   $requestParams Params returned by IRequest
     * @param bool                   $authenticated Whether the session has a user
     *
     * @return EIDASSignatureController
     */
    private function makeController(IEIDASSignatureService $service, array $requestParams=[], bool $authenticated=true): EIDASSignatureController
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

        return new EIDASSignatureController($request, $service, $session);

    }//end makeController()


    /**
     * initiate returns 401 when unauthenticated.
     *
     * @return void
     */
    public function testInitiateRequiresAuthentication(): void
    {
        $service    = $this->createMock(IEIDASSignatureService::class);
        $controller = $this->makeController($service, authenticated: false);

        $response = $controller->initiate('min-1');

        $this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());

    }//end testInitiateRequiresAuthentication()


    /**
     * initiate returns 202 + payload on success.
     *
     * @return void
     */
    public function testInitiateReturnsAcceptedOnSuccess(): void
    {
        $service = $this->createMock(IEIDASSignatureService::class);
        $service->expects($this->once())->method('initializeSigningRequest')
            ->willReturn(
                [
                    'success'    => true,
                    'requestId'  => 'req-1',
                    'signingUrl' => 'https://qsp/sign/1',
                    'message'    => 'Signing request initiated.',
                ]
            );

        $controller = $this->makeController($service, requestParams: ['signatories' => ['m-1']]);

        $response = $controller->initiate('min-1');

        $this->assertSame(Http::STATUS_ACCEPTED, $response->getStatus());
        $data = $response->getData();
        $this->assertSame('req-1', $data['requestId']);
        $this->assertSame('https://qsp/sign/1', $data['signingUrl']);

    }//end testInitiateReturnsAcceptedOnSuccess()


    /**
     * initiate returns 422 on service failure.
     *
     * @return void
     */
    public function testInitiateReturns422OnServiceFailure(): void
    {
        $service = $this->createMock(IEIDASSignatureService::class);
        $service->method('initializeSigningRequest')->willReturn(
            [
                'success'    => false,
                'requestId'  => null,
                'signingUrl' => null,
                'message'    => 'eIDAS QES integration is not configured.',
            ]
        );

        $controller = $this->makeController($service, requestParams: ['signatories' => ['m-1']]);

        $response = $controller->initiate('min-1');

        $this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
        $this->assertSame('eIDAS QES integration is not configured.', $response->getData()['message']);

    }//end testInitiateReturns422OnServiceFailure()


    /**
     * verify rejects missing parameters.
     *
     * @return void
     */
    public function testVerifyRejectsMissingParameters(): void
    {
        $service    = $this->createMock(IEIDASSignatureService::class);
        $controller = $this->makeController($service, requestParams: ['requestId' => 'req-1']);

        $response = $controller->verify('min-1');
        $this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());

    }//end testVerifyRejectsMissingParameters()


    /**
     * verify surfaces the service verdict with the minutesId included.
     *
     * @return void
     */
    public function testVerifyReturnsServiceVerdict(): void
    {
        $service = $this->createMock(IEIDASSignatureService::class);
        $service->method('verifySignature')->willReturn(
            [
                'valid'                 => true,
                'certificateThumbprint' => 'thumb-aabb',
                'timestamp'             => '2026-06-10T12:00:00Z',
                'message'               => 'Signature verified.',
            ]
        );

        $controller = $this->makeController(
            $service,
            requestParams: ['requestId' => 'req-1', 'signature' => 'sig-blob']
        );

        $response = $controller->verify('min-1');

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $data = $response->getData();
        $this->assertTrue($data['valid']);
        $this->assertSame('min-1', $data['minutesId']);

    }//end testVerifyReturnsServiceVerdict()


    /**
     * finalize returns 422 on failure, 200 on success.
     *
     * @return void
     */
    public function testFinalizeReturnsServiceShape(): void
    {
        $service = $this->createMock(IEIDASSignatureService::class);
        $service->method('finalizeMinutes')->willReturn(
            [
                'success'             => true,
                'pdfArchiveReference' => 'docudesk/min/1.pdf',
                'hashSha256'          => 'aa',
                'message'             => 'Minutes finalized.',
            ]
        );

        $controller = $this->makeController(
            $service,
            requestParams: ['signatures' => [['signer' => 'm-1', 'signature' => 's', 'timestamp' => 't']]]
        );

        $response = $controller->finalize('min-1');

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $data = $response->getData();
        $this->assertSame('docudesk/min/1.pdf', $data['pdfArchiveReference']);
        $this->assertSame('aa', $data['hashSha256']);

    }//end testFinalizeReturnsServiceShape()


    /**
     * validateCert rejects an empty thumbprint.
     *
     * @return void
     */
    public function testValidateCertRejectsEmptyThumbprint(): void
    {
        $service    = $this->createMock(IEIDASSignatureService::class);
        $controller = $this->makeController($service, requestParams: []);

        $response = $controller->validateCert();

        $this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());

    }//end testValidateCertRejectsEmptyThumbprint()


    /**
     * validateCert returns the service verdict on success.
     *
     * @return void
     */
    public function testValidateCertReturnsServiceVerdict(): void
    {
        $service = $this->createMock(IEIDASSignatureService::class);
        $service->method('validateCertificateChain')->willReturn(
            [
                'valid'          => true,
                'issuer'         => 'CN=Example',
                'trustListLevel' => 'qualified',
                'message'        => 'Certificate chain valid.',
            ]
        );

        $controller = $this->makeController($service, requestParams: ['certificateThumbprint' => 'thumb']);

        $response = $controller->validateCert();

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $data = $response->getData();
        $this->assertTrue($data['valid']);
        $this->assertSame('CN=Example', $data['issuer']);

    }//end testValidateCertReturnsServiceVerdict()


}//end class
