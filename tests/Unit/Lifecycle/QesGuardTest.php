<?php
/**
 * Unit tests for QesGuard.
 *
 * @category Test
 * @package  OCA\Decidesk\Tests\Unit\Lifecycle
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

namespace OCA\Decidesk\Tests\Unit\Lifecycle;

use OCA\Decidesk\Lifecycle\QesGuard;
use OCA\Decidesk\Service\IEIDASSignatureService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for QesGuard.
 *
 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-3.1
 */
class QesGuardTest extends TestCase
{


    /**
     * Build a QesGuard wired to a stubbed ObjectService chain.
     *
     * @param array<string, mixed>             $resolution  Resolution row returned by find()
     * @param array<int, array<string, mixed>> $minutesRows Minutes rows returned by findAll()
     * @param array<string, array{valid:bool}> $certResults Map of thumbprint => verdict
     *
     * @return QesGuard
     */
    private function makeGuard(array $resolution, array $minutesRows, array $certResults): QesGuard
    {
        $objectService = $this->createMock(ObjectService::class);

        $resolutionEntity = $this->createMock(ObjectEntity::class);
        $resolutionEntity->method('jsonSerialize')->willReturn($resolution);
        $resolutionEntity->method('getObject')->willReturn($resolution);

        $objectService->method('find')->willReturnCallback(
            function (int|string $id, ?array $_extend=[], bool $files=false, string|int|null $register=null, string|int|null $schema=null) use ($resolutionEntity) {
                if ($schema === 'resolution') {
                    return $resolutionEntity;
                }

                return null;
            }
        );

        $objectService->method('findAll')->willReturn($minutesRows);

        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')->willReturn($objectService);

        $signatureService = $this->createMock(IEIDASSignatureService::class);
        $signatureService->method('validateCertificateChain')->willReturnCallback(
            function (string $thumbprint) use ($certResults): array {
                $verdict = ($certResults[$thumbprint] ?? ['valid' => false]);
                return [
                    'valid'          => (bool) ($verdict['valid'] ?? false),
                    'issuer'         => null,
                    'trustListLevel' => null,
                    'message'        => '',
                ];
            }
        );

        return new QesGuard(
            container: $container,
            logger: $this->createMock(LoggerInterface::class),
            signatureService: $signatureService
        );

    }//end makeGuard()


    /**
     * canConclude returns allowed:true when every required signer has a valid
     * cert chain attached to the signed Minutes row.
     *
     * @return void
     */
    public function testAllowsWhenAllSignersHaveValidQes(): void
    {
        $guard = $this->makeGuard(
            resolution: ['meetingKoppeling' => 'meet-1'],
            minutesRows: [
                [
                    'meetingKoppeling' => 'meet-1',
                    'version'          => 'signed',
                    'signedBy'         => [
                        ['signerUuid' => 'm-1', 'certificateThumbprint' => 'thumb-a'],
                        ['signerUuid' => 'm-2', 'certificateThumbprint' => 'thumb-b'],
                    ],
                ],
            ],
            certResults: [
                'thumb-a' => ['valid' => true],
                'thumb-b' => ['valid' => true],
            ]
        );

        $result = $guard->canConclude('res-1', ['m-1', 'm-2']);

        $this->assertTrue($result['allowed']);
        $this->assertSame([], $result['missing']);
        $this->assertSame([], $result['invalid']);

    }//end testAllowsWhenAllSignersHaveValidQes()


    /**
     * canConclude refuses when a required signer is missing from signedBy.
     *
     * @return void
     */
    public function testBlocksWhenSignerMissing(): void
    {
        $guard = $this->makeGuard(
            resolution: ['meetingKoppeling' => 'meet-1'],
            minutesRows: [
                [
                    'meetingKoppeling' => 'meet-1',
                    'version'          => 'signed',
                    'signedBy'         => [
                        ['signerUuid' => 'm-1', 'certificateThumbprint' => 'thumb-a'],
                    ],
                ],
            ],
            certResults: ['thumb-a' => ['valid' => true]]
        );

        $result = $guard->canConclude('res-1', ['m-1', 'm-2']);

        $this->assertFalse($result['allowed']);
        $this->assertContains('m-2', $result['missing']);
        $this->assertStringContainsString('missing QES signatures', $result['reason']);

    }//end testBlocksWhenSignerMissing()


    /**
     * canConclude marks a signer with an invalid cert chain as invalid.
     *
     * @return void
     */
    public function testBlocksWhenCertChainInvalid(): void
    {
        $guard = $this->makeGuard(
            resolution: ['meetingKoppeling' => 'meet-1'],
            minutesRows: [
                [
                    'meetingKoppeling' => 'meet-1',
                    'version'          => 'signed',
                    'signedBy'         => [
                        ['signerUuid' => 'm-1', 'certificateThumbprint' => 'thumb-a'],
                        ['signerUuid' => 'm-2', 'certificateThumbprint' => 'thumb-b'],
                    ],
                ],
            ],
            certResults: [
                'thumb-a' => ['valid' => true],
                'thumb-b' => ['valid' => false],
            ]
        );

        $result = $guard->canConclude('res-1', ['m-1', 'm-2']);

        $this->assertFalse($result['allowed']);
        $this->assertContains('m-2', $result['invalid']);
        $this->assertStringContainsString('invalid QES certificate chain', $result['reason']);

    }//end testBlocksWhenCertChainInvalid()


    /**
     * canConclude rejects an empty resolutionId immediately.
     *
     * @return void
     */
    public function testRejectsEmptyResolutionId(): void
    {
        $guard  = $this->makeGuard(resolution: [], minutesRows: [], certResults: []);
        $result = $guard->canConclude('', ['m-1']);

        $this->assertFalse($result['allowed']);
        $this->assertSame('resolutionId is required.', $result['reason']);

    }//end testRejectsEmptyResolutionId()


    /**
     * canConclude treats a missing signed Minutes row as "all signers missing".
     *
     * @return void
     */
    public function testTreatsMissingSignedMinutesAsAllMissing(): void
    {
        $guard = $this->makeGuard(
            resolution: ['meetingKoppeling' => 'meet-1'],
            minutesRows: [
                [
                    'meetingKoppeling' => 'meet-1',
                    'version'          => 'draft',
                    'signedBy'         => [],
                ],
            ],
            certResults: []
        );

        $result = $guard->canConclude('res-1', ['m-1', 'm-2']);

        $this->assertFalse($result['allowed']);
        $this->assertSame(['m-1', 'm-2'], $result['missing']);

    }//end testTreatsMissingSignedMinutesAsAllMissing()


}//end class
