<?php

/**
 * Edge cases of QesGuard that the main test does not reach.
 *
 * The guard must fail closed: a store that throws refuses with every required
 * signer listed as missing, a resolution without a meeting has no signatures,
 * and a signed-by entry without a signer or thumbprint is not counted.
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\Lifecycle
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Lifecycle;

use OCA\Decidiq\Lifecycle\QesGuard;
use OCA\Decidiq\Service\IEIDASSignatureService;
use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for QesGuard's fail-closed and skip paths.
 *
 * @covers \OCA\Decidiq\Lifecycle\QesGuard
 * @uses \OCA\Decidiq\Service\RowArrayNormaliser
 */
class QesGuardEdgeCaseTest extends TestCase {

	/**
	 * The mocked object service.
	 *
	 * @var ObjectServiceInterface&MockObject
	 */
	private ObjectServiceInterface $objectService;

	/**
	 * The mocked signature service.
	 *
	 * @var IEIDASSignatureService&MockObject
	 */
	private IEIDASSignatureService $signatures;

	/**
	 * The mocked logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * The guard under test.
	 *
	 * @var QesGuard
	 */
	private QesGuard $guard;

	/**
	 * Set up the guard with mocked boundaries.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->objectService = $this->createMock(ObjectServiceInterface::class);
		$this->signatures = $this->createMock(IEIDASSignatureService::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->guard = new QesGuard(
			logger: $this->logger,
			signatureService: $this->signatures,
			objectService: $this->objectService,
		);
	}

	/**
	 * Make find() answer a resolution row.
	 *
	 * @param array<string, mixed> $row The resolution row.
	 *
	 * @return void
	 */
	private function resolution(array $row): void {
		$entity = $this->createMock(ObjectEntityInterface::class);
		$entity->method('getObject')->willReturn($row);
		$this->objectService->method('find')->willReturn($entity);
	}

	/**
	 * A store that throws is logged and refused, with every required signer missing.
	 *
	 * @return void
	 */
	public function testAFailingStoreRefusesWithEverySignerMissing(): void {
		$this->objectService->method('find')->willThrowException(new RuntimeException('db down'));
		$this->logger->expects($this->once())
			->method('error')
			->with(
				'Decidiq: QesGuard could not load signed-by list',
				['resolutionId' => 'res-1', 'exception' => 'db down']
			);
		$this->signatures->expects($this->never())->method('validateCertificateChain');

		$this->assertSame(
			[
				'allowed' => false,
				'reason' => 'Failed to inspect minutes for QES signatures.',
				'missing' => ['m-1', 'm-2'],
				'invalid' => [],
			],
			$this->guard->canConclude('res-1', ['m-1', 'm-2'])
		);
	}

	/**
	 * A resolution without a meeting has no minutes, so every signer is missing.
	 *
	 * @return void
	 */
	public function testAResolutionWithoutAMeetingHasNoSignatures(): void {
		$this->resolution(['meetingIntegration' => '']);
		$this->objectService->expects($this->never())->method('findAll');

		$result = $this->guard->canConclude('res-1', ['m-1']);

		$this->assertFalse($result['allowed']);
		$this->assertSame(['m-1'], $result['missing']);
		$this->assertSame('Resolution cannot be adopted — missing QES signatures: m-1.', $result['reason']);
	}

	/**
	 * Minutes of another meeting are ignored even when the store returns them,
	 * and an entry without a signer or a thumbprint is not counted.
	 *
	 * @return void
	 */
	public function testForeignMinutesAndIncompleteEntriesAreSkipped(): void {
		$this->resolution(['meetingIntegration' => 'meet-1']);
		$this->objectService->expects($this->once())
			->method('findAll')
			->with([
				'register' => 'decidiq',
				'schema' => 'minutes',
				'filters' => ['meetingIntegration' => 'meet-1'],
				'limit' => 50,
			])
			->willReturn([
				[
					'meetingIntegration' => 'meet-2',
					'version' => 'signed',
					'signedBy' => [['signerUuid' => 'm-1', 'certificateThumbprint' => 'thumb-foreign']],
				],
				[
					'meetingIntegration' => 'meet-1',
					'version' => 'signed',
					'signedBy' => [
						['signerUuid' => 'm-1', 'certificateThumbprint' => ''],
						['signer' => '', 'thumbprint' => 'thumb-x'],
						['signer' => 'm-2', 'thumbprint' => 'thumb-b'],
					],
				],
			]);
		$this->signatures->expects($this->once())
			->method('validateCertificateChain')
			->with('thumb-b')
			->willReturn(['valid' => true]);

		$result = $this->guard->canConclude('res-1', ['m-1', 'm-2']);

		$this->assertFalse($result['allowed']);
		$this->assertSame(['m-1'], $result['missing']);
		$this->assertSame([], $result['invalid']);
	}

	/**
	 * A resolution the store does not know has no minutes, so every signer is
	 * missing and the minutes are never searched.
	 *
	 * @return void
	 */
	public function testAnUnknownResolutionHasNoSignatures(): void {
		$this->objectService->expects($this->once())
			->method('find')
			->with('res-404', $this->anything(), $this->anything(), 'decidiq', 'decision')
			->willReturn(null);
		$this->objectService->expects($this->never())->method('findAll');
		$this->signatures->expects($this->never())->method('validateCertificateChain');

		$result = $this->guard->canConclude('res-404', ['m-1', 'm-2']);

		$this->assertSame(
			[
				'allowed' => false,
				'reason' => 'Resolution cannot be adopted — missing QES signatures: m-1, m-2.',
				'missing' => ['m-1', 'm-2'],
				'invalid' => [],
			],
			$result
		);
	}
}
