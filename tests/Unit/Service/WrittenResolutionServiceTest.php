<?php
/**
 * Unit tests for WrittenResolutionService.
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
 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-5.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidesk\Tests\Unit\Service;

use OCA\Decidesk\Service\AuditLogService;
use OCA\Decidesk\Service\IEIDASSignatureService;
use OCA\Decidesk\Service\WrittenResolutionService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for WrittenResolutionService.
 *
 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-5.2
 */
class WrittenResolutionServiceTest extends TestCase
{


    /**
     * Build a service wired against an in-memory store + stubbed signature
     * adapter.
     *
     * @param array<int, array<string, mixed>>            &$rows               Existing rows (resolutions, votes)
     * @param array<int, array<string, mixed>>            &$saved              Captured saves
     * @param array{valid:bool, message?:string}           $verifyOutcome      Signature verification verdict
     * @param array{success:bool, requestId?:string|null}  $signingOutcome     Signing-init verdict
     *
     * @return WrittenResolutionService
     */
    private function makeService(array &$rows, array &$saved, array $verifyOutcome, array $signingOutcome=['success' => true, 'requestId' => 'req-1']): WrittenResolutionService
    {
        $rowsRef       = &$rows;
        $savedRef      = &$saved;
        $objectService = $this->createMock(ObjectService::class);
        $objectService->method('findAll')->willReturnCallback(
            static function (array $config) use (&$rowsRef): array {
                $filters = ($config['filters'] ?? []);
                $schema  = ($config['schema'] ?? '');
                $out     = [];
                foreach ($rowsRef as $row) {
                    if (($row['_schema'] ?? '') !== $schema && $schema !== '') {
                        continue;
                    }

                    $matches = true;
                    foreach ($filters as $k => $v) {
                        if (($row[$k] ?? null) !== $v) {
                            $matches = false;
                            break;
                        }
                    }

                    if ($matches === true) {
                        $out[] = $row;
                    }
                }

                return $out;
            }
        );
        $objectService->method('find')->willReturnCallback(
            function (int|string $id, ?array $_extend=[], bool $files=false, string|int|null $register=null, string|int|null $schema=null) use (&$rowsRef) {
                foreach ($rowsRef as $row) {
                    if (($row['id'] ?? null) === $id && ($schema === null || ($row['_schema'] ?? '') === $schema)) {
                        $entity = $this->createMock(ObjectEntity::class);
                        $entity->method('jsonSerialize')->willReturn($row);
                        $entity->method('getObject')->willReturn($row);
                        return $entity;
                    }
                }

                return null;
            }
        );
        $objectService->method('saveObject')->willReturnCallback(
            function (array $object, ?array $extend=[], string|int|null $register=null, string|int|null $schema=null, ?string $uuid=null) use (&$savedRef, &$rowsRef): ObjectEntity {
                $savedRef[] = $object + ['_schema' => $schema];
                $existingId = ($uuid ?? ($object['id'] ?? null));
                if ($existingId !== null) {
                    foreach ($rowsRef as $i => $row) {
                        if (($row['id'] ?? null) === $existingId) {
                            $rowsRef[$i] = array_merge($row, $object, ['id' => $existingId, '_schema' => (string) $schema]);
                            $row         = $rowsRef[$i];
                            $entity      = $this->createMock(ObjectEntity::class);
                            $entity->method('jsonSerialize')->willReturn($row);
                            $entity->method('getObject')->willReturn($row);
                            return $entity;
                        }
                    }
                }

                $row       = array_merge(['id' => $schema.'-'.count($rowsRef)], $object, ['_schema' => (string) $schema]);
                $rowsRef[] = $row;
                $entity = $this->createMock(ObjectEntity::class);
                $entity->method('jsonSerialize')->willReturn($row);
                $entity->method('getObject')->willReturn($row);
                return $entity;
            }
        );

        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')->willReturn($objectService);

        $signature = $this->createMock(IEIDASSignatureService::class);
        $signature->method('initializeSigningRequest')->willReturn(
            [
                'success'    => (bool) ($signingOutcome['success'] ?? true),
                'requestId'  => ($signingOutcome['requestId'] ?? 'req-1'),
                'signingUrl' => 'https://qsp/sign/1',
                'message'    => '',
            ]
        );
        $signature->method('verifySignature')->willReturn(
            [
                'valid'                 => (bool) ($verifyOutcome['valid'] ?? false),
                'certificateThumbprint' => 'thumb-aabb',
                'timestamp'             => '2026-06-10T12:00:00Z',
                'message'               => (string) ($verifyOutcome['message'] ?? ''),
            ]
        );

        $audit = $this->createMock(AuditLogService::class);
        $audit->method('append')->willReturn(['success' => true, 'entry' => [], 'message' => 'ok']);

        return new WrittenResolutionService(
            container: $container,
            logger: $this->createMock(LoggerInterface::class),
            signatureService: $signature,
            auditLogService: $audit
        );

    }//end makeService()


    /**
     * initiate persists a written-resolution row with status under-signature.
     *
     * @return void
     */
    public function testInitiateCreatesUnderSignatureResolution(): void
    {
        $rows  = [];
        $saved = [];
        $svc   = $this->makeService($rows, $saved, ['valid' => true]);

        $result = $svc->initiate(
            ['title' => 'Annual Budget 2026'],
            ['m-1', 'm-2'],
            '2026-07-01T00:00:00Z'
        );

        $this->assertTrue($result['success']);
        $this->assertSame('req-1', $result['signingRequestId']);

        // Resolutions saved twice: once at proposed, once at under-signature.
        $resolutionSaves = array_values(
            array_filter($saved, static fn(array $s): bool => ($s['_schema'] ?? '') === 'resolution')
        );
        $this->assertGreaterThanOrEqual(2, count($resolutionSaves));
        $this->assertSame('under-signature', end($resolutionSaves)['status']);

    }//end testInitiateCreatesUnderSignatureResolution()


    /**
     * initiate rejects missing title.
     *
     * @return void
     */
    public function testInitiateRejectsMissingTitle(): void
    {
        $rows  = [];
        $saved = [];
        $svc   = $this->makeService($rows, $saved, ['valid' => true]);

        $result = $svc->initiate([], ['m-1'], '2026-07-01T00:00:00Z');
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('title is required', $result['message']);

    }//end testInitiateRejectsMissingTitle()


    /**
     * initiate rejects empty signer list.
     *
     * @return void
     */
    public function testInitiateRejectsEmptySigners(): void
    {
        $rows  = [];
        $saved = [];
        $svc   = $this->makeService($rows, $saved, ['valid' => true]);

        $result = $svc->initiate(['title' => 'x'], [], '2026-07-01T00:00:00Z');
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('signer', $result['message']);

    }//end testInitiateRejectsEmptySigners()


    /**
     * collectSignature rejects an invalid signature.
     *
     * @return void
     */
    public function testCollectSignatureRejectsInvalidSignature(): void
    {
        $rows  = [];
        $saved = [];
        $svc   = $this->makeService($rows, $saved, ['valid' => false, 'message' => 'eIDAS QES integration is not configured.']);

        $result = $svc->collectSignature('res-1', 'm-1', 'sig', 'req-1');
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('not configured', $result['message']);

    }//end testCollectSignatureRejectsInvalidSignature()


    /**
     * collectSignature persists a board-vote row when verification succeeds.
     *
     * @return void
     */
    public function testCollectSignatureRecordsVote(): void
    {
        $rows  = [];
        $saved = [];
        $svc   = $this->makeService($rows, $saved, ['valid' => true]);

        $result = $svc->collectSignature('res-1', 'm-1', 'sig', 'req-1');

        $this->assertTrue($result['success']);
        $voteSaves = array_values(
            array_filter($saved, static fn(array $s): bool => ($s['_schema'] ?? '') === 'board-vote')
        );
        $this->assertCount(1, $voteSaves);
        $this->assertSame('in-favor', $voteSaves[0]['vote']);
        $this->assertSame('written-ballot', $voteSaves[0]['voteMethod']);

    }//end testCollectSignatureRecordsVote()


    /**
     * finalize adopts the resolution when every required signer has signed.
     *
     * @return void
     */
    public function testFinalizeAdoptsWhenUnanimous(): void
    {
        $rows = [
            [
                'id'              => 'res-1',
                '_schema'         => 'resolution',
                'requiredSigners' => ['m-1', 'm-2'],
                'status'          => 'under-signature',
            ],
            [
                'id'                   => 'v-1',
                '_schema'              => 'board-vote',
                'resolutionKoppeling'  => 'res-1',
                'boardMemberKoppeling' => 'm-1',
                'vote'                 => 'in-favor',
            ],
            [
                'id'                   => 'v-2',
                '_schema'              => 'board-vote',
                'resolutionKoppeling'  => 'res-1',
                'boardMemberKoppeling' => 'm-2',
                'vote'                 => 'in-favor',
            ],
        ];
        $saved = [];
        $svc   = $this->makeService($rows, $saved, ['valid' => true]);

        $result = $svc->finalize('res-1');

        $this->assertTrue($result['success']);
        $this->assertSame(2, $result['signaturesCollected']);
        $this->assertSame('adopted', $result['resolution']['status']);

    }//end testFinalizeAdoptsWhenUnanimous()


    /**
     * finalize reports missing signers when unanimity is not reached.
     *
     * @return void
     */
    public function testFinalizeReportsMissingSigners(): void
    {
        $rows = [
            [
                'id'              => 'res-1',
                '_schema'         => 'resolution',
                'requiredSigners' => ['m-1', 'm-2'],
                'status'          => 'under-signature',
            ],
            [
                'id'                   => 'v-1',
                '_schema'              => 'board-vote',
                'resolutionKoppeling'  => 'res-1',
                'boardMemberKoppeling' => 'm-1',
                'vote'                 => 'in-favor',
            ],
        ];
        $saved = [];
        $svc   = $this->makeService($rows, $saved, ['valid' => true]);

        $result = $svc->finalize('res-1');

        $this->assertFalse($result['success']);
        $this->assertSame(1, $result['signaturesCollected']);
        $this->assertStringContainsString('m-2', $result['message']);

    }//end testFinalizeReportsMissingSigners()


    /**
     * finalize reports 'not found' for an unknown resolution.
     *
     * @return void
     */
    public function testFinalizeReportsNotFound(): void
    {
        $rows  = [];
        $saved = [];
        $svc   = $this->makeService($rows, $saved, ['valid' => true]);

        $result = $svc->finalize('does-not-exist');
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('not found', $result['message']);

    }//end testFinalizeReportsNotFound()


}//end class
