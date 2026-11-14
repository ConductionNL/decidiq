<?php

/**
 * Unit tests for MailVoteSigner.
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service;

use OCA\Decidiq\Service\MailVoteSigner;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * HMAC signing and verification of `_mail` vote entries.
 *
 * @covers \OCA\Decidiq\Service\MailVoteSigner
 */
class MailVoteSignerTest extends TestCase {

	/**
	 * Mock app config.
	 *
	 * @var IAppConfig&MockObject
	 */
	private IAppConfig&MockObject $appConfig;

	/**
	 * Set up shared mocks.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->appConfig = $this->createMock(IAppConfig::class);

	}//end setUp()

	/**
	 * Configure a fixed base secret.
	 *
	 * @param string $base The stored voter_token_secret
	 *
	 * @return MailVoteSigner
	 */
	private function signer(string $base = 'base-secret'): MailVoteSigner {
		$this->appConfig->method('getValueString')
			->with('decidiq', 'voter_token_secret', '')
			->willReturn($base);

		return new MailVoteSigner(appConfig: $this->appConfig);

	}//end signer()

	/**
	 * The HMAC is over participant:round:timestamp with a round-scoped derived key.
	 *
	 * @return void
	 */
	public function testComputeUsesRoundScopedKey(): void {
		$signer = $this->signer();
		$this->appConfig->expects($this->never())->method('setValueString');

		$key = hash_hmac('sha256', 'mail-reply:round-1', 'base-secret');
		$expected = hash_hmac('sha256', 'p-1:round-1:2026-01-01T10:00:00Z', $key);

		$this->assertSame($expected, $signer->compute('p-1', 'round-1', '2026-01-01T10:00:00Z'));
		$this->assertNotSame($expected, $signer->compute('p-1', 'round-2', '2026-01-01T10:00:00Z'));

	}//end testComputeUsesRoundScopedKey()

	/**
	 * sign() keeps the entry and appends the matching hmac.
	 *
	 * @return void
	 */
	public function testSignAppendsHmac(): void {
		$signer = $this->signer();
		$entry = ['participantId' => 'p-1', 'timestamp' => 't-1', 'replyBody' => 'VOOR'];

		$signed = $signer->sign($entry, 'round-1');

		$this->assertSame('VOOR', $signed['replyBody']);
		$this->assertSame($signer->compute('p-1', 'round-1', 't-1'), $signed['hmac']);

	}//end testSignAppendsHmac()

	/**
	 * A signed entry verifies, also with a different reply body.
	 *
	 * @return void
	 */
	public function testSignedEntryVerifies(): void {
		$signer = $this->signer();
		$signed = $signer->sign(['participantId' => 'p-1', 'timestamp' => 't-1'], 'round-1');
		$signed['replyBody'] = 'TEGEN';

		$this->assertTrue($signer->verify($signed, 'round-1'));

	}//end testSignedEntryVerifies()

	/**
	 * Tampering with a signed field, or replaying into another round, fails verification.
	 *
	 * @return void
	 */
	public function testTamperedEntryFails(): void {
		$signer = $this->signer();
		$signed = $signer->sign(['participantId' => 'p-1', 'timestamp' => 't-1'], 'round-1');

		$this->assertFalse($signer->verify(array_merge($signed, ['participantId' => 'p-2']), 'round-1'));
		$this->assertFalse($signer->verify(array_merge($signed, ['timestamp' => 't-2']), 'round-1'));
		$this->assertFalse($signer->verify($signed, 'round-2'));

	}//end testTamperedEntryFails()

	/**
	 * Entries missing the hmac, participant or timestamp are refused.
	 *
	 * @return void
	 */
	public function testIncompleteEntriesFail(): void {
		$signer = $this->signer();
		$signed = $signer->sign(['participantId' => 'p-1', 'timestamp' => 't-1'], 'round-1');

		$this->assertFalse($signer->verify(['participantId' => 'p-1', 'timestamp' => 't-1'], 'round-1'));
		$this->assertFalse($signer->verify(array_merge($signed, ['participantId' => '']), 'round-1'));
		$this->assertFalse($signer->verify(array_merge($signed, ['timestamp' => '']), 'round-1'));
		$this->assertFalse($signer->verify(array_merge($signed, ['hmac' => '']), 'round-1'));

	}//end testIncompleteEntriesFail()

	/**
	 * Without a stored base secret one is generated and persisted as sensitive.
	 *
	 * @return void
	 */
	public function testMissingBaseSecretIsGeneratedAndPersisted(): void {
		$stored = null;
		$this->appConfig->expects($this->once())
			->method('setValueString')
			->willReturnCallback(
				function (string $app, string $key, string $value, bool $lazy = false, bool $sensitive = false) use (&$stored): bool {
					$this->assertSame('decidiq', $app);
					$this->assertSame('voter_token_secret', $key);
					$this->assertTrue($sensitive);
					$stored = $value;
					return true;
				}
			);
		$signer = $this->signer('');

		$hmac = $signer->compute('p-1', 'round-1', 't-1');

		$this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', (string)$stored);
		$key = hash_hmac('sha256', 'mail-reply:round-1', (string)$stored);
		$this->assertSame(hash_hmac('sha256', 'p-1:round-1:t-1', $key), $hmac);

	}//end testMissingBaseSecretIsGeneratedAndPersisted()
}//end class
