<?php

/**
 * An Office paper added to a meeting or agenda item folder reaches the
 * conversion listener: asserted from the caller, CrossAppEventRegistrar.
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\AppInfo
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/agenda-management/spec.md#requirement-req-opdf-001-an-office-paper-added-to-a-meeting-or-agenda-item-is-converted-to-pdf
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\AppInfo;

use OCA\Decidiq\AppInfo\Registrar\CrossAppEventRegistrar;
use OCA\Decidiq\Listener\OfficePaperAddedListener;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\Files\Events\Node\NodeCreatedEvent;
use OCP\Files\Events\Node\NodeWrittenEvent;
use PHPUnit\Framework\TestCase;

/**
 * The Files listeners are registered by the registrar the Application calls.
 */
class FilesEventRegistrarTest extends TestCase {

	/**
	 * Both a new file and an overwritten one reach the listener.
	 *
	 * @return void
	 */
	public function testTheCrossAppRegistrarRegistersTheOfficePaperListener(): void {
		$registered = [];
		$context    = $this->createMock(IRegistrationContext::class);
		$context->method('registerEventListener')->willReturnCallback(
			static function (string $event, string $listener) use (&$registered): void {
				$registered[] = [$event, $listener];
			}
		);

		(new CrossAppEventRegistrar())->register(context: $context);

		self::assertContains([NodeCreatedEvent::class, OfficePaperAddedListener::class], $registered);
		self::assertContains([NodeWrittenEvent::class, OfficePaperAddedListener::class], $registered);
	}//end testTheCrossAppRegistrarRegistersTheOfficePaperListener()
}//end class
