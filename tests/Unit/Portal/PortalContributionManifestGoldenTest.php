<?php

/**
 * Golden test of the whole citizen portal manifest.
 *
 * Pins the manifest Decidiq contributes to the portal byte for byte (every
 * collection, scope field, trust level, field whitelist, action and default,
 * in order), so moving its parts into named helpers cannot change a single
 * key, value or position. Also pins that no other audience gets a manifest.
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\Portal
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec exclude golden test of the portal manifest, no behaviour of its own
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2
declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Portal;

use OCA\Decidiq\Portal\PortalContributionProvider;
use PHPUnit\Framework\TestCase;

final class PortalContributionManifestGoldenTest extends TestCase {

	private const GOLDEN = __DIR__ . '/../../fixtures/portal-citizen-manifest-golden.json';

	private const TIMESTAMP = '/"\\d{4}-\\d{2}-\\d{2}T\\d{2}:\\d{2}:\\d{2}[+-]\\d{2}:\\d{2}"/';

	/**
	 * The citizen manifest, exactly as before.
	 *
	 * @return void
	 */
	public function testCitizenManifestIsUnchanged(): void {
		$manifest = (new PortalContributionProvider())->getContribution(
			subject: [
				'subjectRef' => '00000000-0000-0000-0000-000000000001',
				'audience' => 'citizen',
				'trust' => 'low',
			]
		);
		$json = json_encode(value: $manifest, flags: (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) . "\n";
		// The create defaults stamp the current time; the golden holds a marker.
		$json = preg_replace(pattern: self::TIMESTAMP, replacement: '"<now>"', subject: $json);
		if (getenv('DECIDIQ_WRITE_GOLDEN') === '1') {
			file_put_contents(filename: self::GOLDEN, data: $json);
		}

		self::assertSame(expected: file_get_contents(filename: self::GOLDEN), actual: $json);

	}//end testCitizenManifestIsUnchanged()

	/**
	 * Every other audience, and no audience, gets nothing.
	 *
	 * @return void
	 */
	public function testOtherAudiencesGetNoManifest(): void {
		$provider = new PortalContributionProvider();
		foreach (['staff', 'business', '', 'Citizen'] as $audience) {
			self::assertNull(actual: $provider->getContribution(subject: ['audience' => $audience]));
		}

		self::assertNull(actual: $provider->getContribution(subject: []));

	}//end testOtherAudiencesGetNoManifest()
}//end class
