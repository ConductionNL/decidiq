<?php
/**
 * Unit tests for EIDASSignatureService (openconnector-delegating QES adapter).
 *
 * The tests stub openconnector's CallService + SourceMapper via the DI container
 * to verify each delegated method's success / failure surface without requiring
 * the real openconnector app at test time.
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
 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-3.1
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidesk\Tests\Unit\Service;

use OCA\Decidesk\Service\AuditLogService;
use OCA\Decidesk\Service\EIDASSignatureService;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for EIDASSignatureService.
 *
 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-3.1
 */
class EIDASSignatureServiceTest extends TestCase
{


    /**
     * Build a service wired to a programmable openconnector stub. The
     * openconnector::CallService::call() response is built from $responseBody
     * to validate the JSON-decoding path.
     *
     * @param array<string, mixed> $responseBody  Body the stubbed source returns
     * @param object|null          $sourceObject  What SourceMapper::findBySlug returns
     *
     * @return EIDASSignatureService
     */
    private function makeService(array $responseBody, object|null $sourceObject=null): EIDASSignatureService
    {
        $sourceMapper = new class($sourceObject) {

            /**
             * Backing source object the test wants returned.
             *
             * @var object|null
             */
            private object|null $source;


            /**
             * Constructor.
             *
             * @param object|null $source The source double or null
             */
            public function __construct(object|null $source)
            {
                $this->source = $source;
            }


            /**
             * Mirror openconnector's SourceMapper::findBySlug shape.
             *
             * @param string $slug Source slug
             *
             * @return object|null
             */
            public function findBySlug(string $slug): ?object
            {
                if ($slug === EIDASSignatureService::ESIGN_SOURCE_SLUG) {
                    return $this->source;
                }

                return null;
            }


        };

        $callResult = new class($responseBody) {

            /**
             * JSON body returned via getResponse().
             *
             * @var array<string, mixed>
             */
            private array $body;


            /**
             * Constructor.
             *
             * @param array<string, mixed> $body Response body
             */
            public function __construct(array $body)
            {
                $this->body = $body;
            }


            /**
             * Mirror openconnector CallLog::getResponse shape.
             *
             * @return array<string, mixed>
             */
            public function getResponse(): array
            {
                return ['body' => json_encode($this->body)];
            }


        };

        $callService = new class($callResult) {

            /**
             * The fixed CallLog double returned from call().
             *
             * @var object
             */
            private object $result;


            /**
             * Constructor.
             *
             * @param object $result Pre-baked CallLog double
             */
            public function __construct(object $result)
            {
                $this->result = $result;
            }


            /**
             * Mirror openconnector CallService::call shape.
             *
             * @param object               $source   Source row
             * @param string               $endpoint Endpoint path
             * @param string               $method   HTTP method
             * @param array<string, mixed> $config   Call config
             *
             * @return object
             */
            public function call(object $source, string $endpoint, string $method, array $config): object
            {
                return $this->result;
            }


        };

        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')->willReturnCallback(
            function (string $id) use ($callService, $sourceMapper) {
                if ($id === 'OCA\\OpenConnector\\Service\\CallService') {
                    return $callService;
                }

                if ($id === 'OCA\\OpenConnector\\Db\\SourceMapper') {
                    return $sourceMapper;
                }

                return null;
            }
        );

        $logger = $this->createMock(LoggerInterface::class);
        $audit  = $this->createMock(AuditLogService::class);
        $audit->method('append')->willReturn(['success' => true, 'entry' => [], 'message' => 'ok']);

        return new EIDASSignatureService(container: $container, logger: $logger, auditLogService: $audit);

    }//end makeService()


    /**
     * Initialize returns success with the openconnector-supplied requestId
     * and signingUrl.
     *
     * @return void
     */
    public function testInitializeReturnsRequestIdFromOpenconnector(): void
    {
        $service = $this->makeService(
            responseBody: [
                'requestId'  => 'qes-req-42',
                'signingUrl' => 'https://qsp.example/sign/42',
            ],
            sourceObject: new \stdClass()
        );

        $result = $service->initializeSigningRequest('min-1', ['m-1', 'm-2']);

        $this->assertTrue($result['success']);
        $this->assertSame('qes-req-42', $result['requestId']);
        $this->assertSame('https://qsp.example/sign/42', $result['signingUrl']);

    }//end testInitializeReturnsRequestIdFromOpenconnector()


    /**
     * Initialize rejects empty signatories.
     *
     * @return void
     */
    public function testInitializeRejectsEmptySignatories(): void
    {
        $service = $this->makeService(responseBody: [], sourceObject: new \stdClass());

        $result = $service->initializeSigningRequest('min-1', []);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('at least one signatory', $result['message']);

    }//end testInitializeRejectsEmptySignatories()


    /**
     * Initialize returns success:false when the openconnector source is
     * unconfigured (SourceMapper::findBySlug returns null).
     *
     * @return void
     */
    public function testInitializeFailsWhenSourceNotConfigured(): void
    {
        $service = $this->makeService(responseBody: [], sourceObject: null);

        $result = $service->initializeSigningRequest('min-1', ['m-1']);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('not configured', $result['message']);

    }//end testInitializeFailsWhenSourceNotConfigured()


    /**
     * VerifySignature surfaces openconnector validation as valid:true.
     *
     * @return void
     */
    public function testVerifyReportsValidWhenOpenconnectorAcceptsSignature(): void
    {
        $service = $this->makeService(
            responseBody: [
                'valid'                 => true,
                'certificateThumbprint' => 'thumb-aabb',
                'timestamp'             => '2026-06-10T12:00:00Z',
            ],
            sourceObject: new \stdClass()
        );

        $result = $service->verifySignature('req-1', 'sig-blob');

        $this->assertTrue($result['valid']);
        $this->assertSame('thumb-aabb', $result['certificateThumbprint']);
        $this->assertSame('2026-06-10T12:00:00Z', $result['timestamp']);

    }//end testVerifyReportsValidWhenOpenconnectorAcceptsSignature()


    /**
     * VerifySignature rejects missing required parameters before opening a
     * call to openconnector.
     *
     * @return void
     */
    public function testVerifyRejectsMissingParameters(): void
    {
        $service = $this->makeService(responseBody: [], sourceObject: new \stdClass());

        $result = $service->verifySignature('', 'sig');
        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('required', $result['message']);

        $result2 = $service->verifySignature('req-1', '');
        $this->assertFalse($result2['valid']);
        $this->assertStringContainsString('required', $result2['message']);

    }//end testVerifyRejectsMissingParameters()


    /**
     * ValidateCertificateChain rejects an empty thumbprint.
     *
     * @return void
     */
    public function testValidateCertRejectsEmptyThumbprint(): void
    {
        $service = $this->makeService(responseBody: [], sourceObject: new \stdClass());

        $result = $service->validateCertificateChain('');
        $this->assertFalse($result['valid']);

    }//end testValidateCertRejectsEmptyThumbprint()


    /**
     * ValidateCertificateChain surfaces openconnector's verdict.
     *
     * @return void
     */
    public function testValidateCertSurfacesOpenconnectorVerdict(): void
    {
        $service = $this->makeService(
            responseBody: [
                'valid'          => true,
                'issuer'         => 'CN=Example QSP',
                'trustListLevel' => 'qualified',
            ],
            sourceObject: new \stdClass()
        );

        $result = $service->validateCertificateChain('thumb-aabb');

        $this->assertTrue($result['valid']);
        $this->assertSame('CN=Example QSP', $result['issuer']);
        $this->assertSame('qualified', $result['trustListLevel']);

    }//end testValidateCertSurfacesOpenconnectorVerdict()


}//end class
