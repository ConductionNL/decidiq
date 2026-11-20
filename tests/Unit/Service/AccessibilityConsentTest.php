<?php

/**
 * Test Suite for AccessibilityConsent
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\Service
 *
 * @spec openspec/changes/archive/2026-11-20-document-accessibility-check/specs/document-accessibility-check/spec.md#requirement-req-003-publication-gate-on-failing-documents-with-recorded-override
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service;

use OCA\Decidiq\Service\AccessibilityConsent;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * The consent a publish request carries for the accessibility gate.
 *
 * @covers \OCA\Decidiq\Service\AccessibilityConsent
 *
 * @spec openspec/changes/archive/2026-11-20-document-accessibility-check/specs/document-accessibility-check/spec.md#requirement-req-003-publication-gate-on-failing-documents-with-recorded-override
 */
class AccessibilityConsentTest extends TestCase {

	/**
	 * A request answering these parameters.
	 *
	 * @param array<string, mixed> $params The request parameters.
	 *
	 * @return IRequest
	 */
	private function request(array $params): IRequest {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, mixed $default = null): mixed => (array_key_exists($key, $params) === true ? $params[$key] : $default)
		);

		return $request;
	}//end request()

	/**
	 * Nothing said: not acknowledged, no override.
	 *
	 * @return void
	 */
	public function testAnEmptyRequestConsentsToNothing(): void {
		self::assertSame(
			['acknowledged' => false, 'overrideReason' => null],
			(new AccessibilityConsent())->fromRequest(request: $this->request(params: []))
		);
	}//end testAnEmptyRequestConsentsToNothing()

	/**
	 * The acknowledgement is read as a boolean, from a form value too.
	 *
	 * @return void
	 */
	public function testTheAcknowledgementIsABoolean(): void {
		foreach (['true' => true, '1' => true, 'on' => true, 'false' => false, '0' => false, 'maybe' => false] as $value => $expected) {
			self::assertSame(
				$expected,
				(new AccessibilityConsent())->fromRequest(request: $this->request(params: ['accessibilityAcknowledged' => (string)$value]))['acknowledged'],
				(string)$value
			);
		}

		self::assertTrue((new AccessibilityConsent())->fromRequest(request: $this->request(params: ['accessibilityAcknowledged' => true]))['acknowledged']);
	}//end testTheAcknowledgementIsABoolean()

	/**
	 * The override reason comes bare or as `{reason}`; anything else is no override.
	 *
	 * @return void
	 */
	public function testTheOverrideReasonIsReadBareOrNested(): void {
		$bare   = (new AccessibilityConsent())->fromRequest(request: $this->request(params: ['accessibilityOverride' => 'Scanned copy of a signed letter']));
		$nested = (new AccessibilityConsent())->fromRequest(request: $this->request(params: ['accessibilityOverride' => ['reason' => 'Map without text alternative']]));

		self::assertSame('Scanned copy of a signed letter', $bare['overrideReason']);
		self::assertSame('Map without text alternative', $nested['overrideReason']);
		self::assertNull((new AccessibilityConsent())->fromRequest(request: $this->request(params: ['accessibilityOverride' => ['why' => 'x']]))['overrideReason']);
		self::assertNull((new AccessibilityConsent())->fromRequest(request: $this->request(params: ['accessibilityOverride' => 42]))['overrideReason']);
		self::assertNull((new AccessibilityConsent())->fromRequest(request: $this->request(params: ['accessibilityOverride' => ['reason' => 7]]))['overrideReason']);
	}//end testTheOverrideReasonIsReadBareOrNested()
}//end class
