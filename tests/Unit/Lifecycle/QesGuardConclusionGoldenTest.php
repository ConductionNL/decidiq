<?php

/**
 * Golden test for QesGuard::canConclude() over stored minutes rows.
 *
 * Pins the allowed and refused outcomes when the signed-by list comes from the
 * minutes rows ObjectService::findAll() returns, both as entities and as plain
 * arrays: every required signer present and valid is allowed; a missing
 * signer, an invalid certificate, unsigned minutes, minutes of another meeting
 * and an empty resolution id are refused.
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\Lifecycle
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Lifecycle;

use OCA\Decidiq\Lifecycle\QesGuard;
use OCA\Decidiq\Service\IEIDASSignatureService;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Allowed and refused outcomes of canConclude() over minutes rows.
 */
class QesGuardConclusionGoldenTest extends TestCase {

	/**
	 * The signed minutes of meeting m-1, signed by alice and bob.
	 *
	 * @var array<string, mixed>
	 */
	private const SIGNED = [
		'meetingIntegration' => 'm-1',
		'version' => 'signed',
		'signedBy' => [
			['signer' => 'alice', 'thumbprint' => 't-alice'],
			['signerUuid' => 'bob', 'certificateThumbprint' => 't-bob'],
		],
	];

	/**
	 * Every required signer present and valid, from an entity row: allowed.
	 *
	 * @return void
	 */
	public function testEntityMinutesAllow(): void {
		$guard  = $this->guard(rows: [$this->entity(row: self::SIGNED)], invalid: []);
		$result = $guard->canConclude(resolutionId: 'r-1', requiredSigners: ['alice', 'bob']);

		self::assertSame(
			expected: ['allowed' => true, 'reason' => 'QES present and verified.', 'missing' => [], 'invalid' => []],
			actual: $result
		);

	}//end testEntityMinutesAllow()

	/**
	 * Every required signer present and valid, from an array row: allowed.
	 *
	 * @return void
	 */
	public function testArrayMinutesAllow(): void {
		$result = $this->guard(rows: [self::SIGNED], invalid: [])->canConclude(resolutionId: 'r-1', requiredSigners: ['alice']);

		self::assertTrue(condition: $result['allowed']);

	}//end testArrayMinutesAllow()

	/**
	 * A missing signer, an invalid certificate, unsigned or foreign minutes: refused.
	 *
	 * @return void
	 */
	public function testRefusals(): void {
		$signed = [$this->entity(row: self::SIGNED)];

		$missing = $this->guard(rows: $signed, invalid: [])->canConclude(resolutionId: 'r-1', requiredSigners: ['alice', 'carol']);
		self::assertFalse(condition: $missing['allowed']);
		self::assertSame(expected: ['carol'], actual: $missing['missing']);

		$invalid = $this->guard(rows: $signed, invalid: ['t-bob'])->canConclude(resolutionId: 'r-1', requiredSigners: ['alice', 'bob']);
		self::assertFalse(condition: $invalid['allowed']);
		self::assertSame(expected: ['bob'], actual: $invalid['invalid']);

		$draft = [$this->entity(row: array_merge(self::SIGNED, ['version' => 'draft']))];
		self::assertFalse(condition: $this->guard(rows: $draft, invalid: [])->canConclude(resolutionId: 'r-1', requiredSigners: ['alice'])['allowed']);

		$foreign = [$this->entity(row: array_merge(self::SIGNED, ['meetingIntegration' => 'm-2'])), 'not a row'];
		self::assertFalse(condition: $this->guard(rows: $foreign, invalid: [])->canConclude(resolutionId: 'r-1', requiredSigners: ['alice'])['allowed']);

		self::assertSame(
			expected: ['allowed' => false, 'reason' => 'resolutionId is required.', 'missing' => [], 'invalid' => []],
			actual: $this->guard(rows: [], invalid: [])->canConclude(resolutionId: '', requiredSigners: ['alice'])
		);

	}//end testRefusals()

	/**
	 * A guard whose resolution links meeting m-1 and whose minutes are the given rows.
	 *
	 * @param list<mixed>  $rows    The minutes rows findAll() returns.
	 * @param list<string> $invalid The certificate thumbprints that fail validation.
	 *
	 * @return QesGuard The guard.
	 */
	private function guard(array $rows, array $invalid): QesGuard {
		$resolution = new ObjectEntity();
		$resolution->setUuid('r-1');
		$resolution->setObject(['meetingIntegration' => 'm-1']);

		$objects = $this->createMock(originalClassName: ObjectServiceInterface::class);
		$objects->method('find')->willReturn($resolution);
		$objects->method('findAll')->willReturn($rows);

		$signatures = $this->createMock(originalClassName: IEIDASSignatureService::class);
		$signatures->method('validateCertificateChain')->willReturnCallback(
			static fn (string $thumbprint): array => ['valid' => in_array($thumbprint, $invalid, true) === false]
		);

		return new QesGuard(
			logger: $this->createMock(originalClassName: LoggerInterface::class),
			signatureService: $signatures,
			objectService: $objects
		);

	}//end guard()

	/**
	 * An entity holding a row.
	 *
	 * @param array<string, mixed> $row The row.
	 *
	 * @return ObjectEntity The entity.
	 */
	private function entity(array $row): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid('minutes-1');
		$entity->setObject($row);

		return $entity;

	}//end entity()
}//end class
