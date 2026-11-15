<?php

// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * AgendaService no longer depends on OpenRegister's CalendarEventService.
 *
 * publishAgenda() used to call CalendarEventService::updateMeetingEvent()
 * behind a method_exists() guard. No OpenRegister version has that method, so
 * the branch never ran; the guard and the dependency are gone. This test fails
 * on the old constructor.
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

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service;

use OCA\Decidiq\Service\AgendaService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Guards against a dynamic call to a calendar method OpenRegister never had.
 */
class AgendaServiceCalendarTest extends TestCase {

	public function testTheConstructorTakesNoCalendarEventService(): void {
		$names = array_map(
			static fn (\ReflectionParameter $parameter): string => $parameter->getName(),
			(new ReflectionClass(AgendaService::class))->getConstructor()->getParameters()
		);

		self::assertNotContains('calendarEventService', $names);
	}//end testTheConstructorTakesNoCalendarEventService()

	public function testTheSourceHasNoMethodExistsGuardOnAnOpenRegisterService(): void {
		$source = (string)file_get_contents(__DIR__ . '/../../../lib/Service/AgendaService.php');

		self::assertStringNotContainsString('updateMeetingEvent', $source);
	}//end testTheSourceHasNoMethodExistsGuardOnAnOpenRegisterService()
}//end class
