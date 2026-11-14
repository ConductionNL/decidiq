<?php

/**
 * Unit tests for PublicationConfigService.
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

use OCA\Decidiq\Service\PublicationConfigService;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Per-body publication settings read from and written to app config.
 *
 * @covers \OCA\Decidiq\Service\PublicationConfigService
 */
class PublicationConfigServiceTest extends TestCase {

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
	 * Build the service with a stored raw value.
	 *
	 * @param string $raw The stored publication_config value
	 *
	 * @return PublicationConfigService
	 */
	private function service(string $raw): PublicationConfigService {
		$this->appConfig->method('getValueString')
			->with('decidiq', 'publication_config', '')
			->willReturn($raw);

		return new PublicationConfigService(appConfig: $this->appConfig);

	}//end service()

	/**
	 * Empty, invalid or non-object JSON reads as no configuration.
	 *
	 * @return void
	 */
	public function testGetAllTreatsUnusableValuesAsEmpty(): void {
		foreach (['', '{broken', '"text"'] as $raw) {
			$appConfig = $this->createMock(IAppConfig::class);
			$appConfig->method('getValueString')->willReturn($raw);

			$this->assertSame([], (new PublicationConfigService(appConfig: $appConfig))->getAll(), $raw);
		}

	}//end testGetAllTreatsUnusableValuesAsEmpty()

	/**
	 * A stored body configuration is returned normalised.
	 *
	 * @return void
	 */
	public function testGetForBodyReturnsStoredValues(): void {
		$service = $this->service(
			json_encode(
				[
					'body-1' => [
						'catalog' => 'cat-1',
						'policy' => ['decision' => 'prompt-on-transition'],
						'attendance' => 'role-holders',
					],
				]
			)
		);

		$this->assertSame(
			['catalog' => 'cat-1', 'policy' => ['decision' => 'prompt-on-transition'], 'attendance' => 'role-holders'],
			$service->getForBody('body-1')
		);

	}//end testGetForBodyReturnsStoredValues()

	/**
	 * Unknown bodies, a non-array policy and an unknown attendance value get the defaults.
	 *
	 * @return void
	 */
	public function testGetForBodyDefaults(): void {
		$service = $this->service(json_encode(['body-1' => ['policy' => 'manual-only', 'attendance' => 'everyone']]));

		$defaults = ['catalog' => '', 'policy' => [], 'attendance' => 'counts'];
		$this->assertSame($defaults, $service->getForBody('body-1'));
		$this->assertSame($defaults, $service->getForBody('body-unknown'));

	}//end testGetForBodyDefaults()

	/**
	 * Only known policy values are returned; anything else is manual-only.
	 *
	 * @return void
	 */
	public function testGetPolicy(): void {
		$service = $this->service(
			json_encode(['body-1' => ['policy' => ['decision' => 'prompt-on-transition', 'agenda' => 'auto']]])
		);

		$this->assertSame('prompt-on-transition', $service->getPolicy('body-1', 'decision'));
		$this->assertSame('manual-only', $service->getPolicy('body-1', 'agenda'));
		$this->assertSame('manual-only', $service->getPolicy('body-1', 'minutes'));
		$this->assertSame('manual-only', $service->getPolicy('body-2', 'decision'));

	}//end testGetPolicy()

	/**
	 * Saving keeps only known source types and values, drops non-array bodies and persists JSON.
	 *
	 * @return void
	 */
	public function testSaveSanitisesAndPersists(): void {
		$expected = [
			'body-1' => [
				'catalog' => 'cat/één',
				'policy' => ['decision' => 'prompt-on-transition', 'minutes' => 'manual-only'],
				'attendance' => 'role-holders',
			],
			'7' => [
				'catalog' => '',
				'policy' => [],
				'attendance' => 'counts',
			],
		];
		$this->appConfig->expects($this->once())
			->method('setValueString')
			->with('decidiq', 'publication_config', '{"body-1":{"catalog":"cat/één","policy":{"decision":"prompt-on-transition","minutes":"manual-only"},"attendance":"role-holders"},"7":{"catalog":"","policy":[],"attendance":"counts"}}')
			->willReturn(true);
		$service = new PublicationConfigService(appConfig: $this->appConfig);

		$clean = $service->save(
			[
				'body-1' => [
					'catalog' => 'cat/één',
					'policy' => [
						'decision' => 'prompt-on-transition',
						'agenda' => 'publish-everything',
						'minutes' => 'manual-only',
						'other' => 'manual-only',
					],
					'attendance' => 'role-holders',
				],
				7 => ['policy' => 'not-an-array', 'attendance' => 'nobody'],
				'body-3' => 'not-an-array',
			]
		);

		$this->assertSame($expected, $clean);

	}//end testSaveSanitisesAndPersists()

	/**
	 * A value that cannot be encoded is stored as an empty object.
	 *
	 * @return void
	 */
	public function testSaveUnencodableStoresEmptyObject(): void {
		$this->appConfig->expects($this->once())
			->method('setValueString')
			->with('decidiq', 'publication_config', '{}')
			->willReturn(true);
		$service = new PublicationConfigService(appConfig: $this->appConfig);

		$clean = $service->save(['body-1' => ['catalog' => "\xB1\x31"]]);

		$this->assertSame("\xB1\x31", $clean['body-1']['catalog']);

	}//end testSaveUnencodableStoresEmptyObject()
}//end class
