<?php

// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Retracting an OpenCatalogi publication deletes it through an ObjectDeletion.
 *
 * The delete sat inside a catch(\Throwable): on openregister #9099's
 * signature the old named arguments would throw, the retraction would answer
 * false and the publication would stay online. This test fails on the old call.
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

use OCA\Decidiq\Service\OpenCatalogiPublisher;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Service\Object\ObjectDeletion;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/**
 * Covers OpenCatalogiPublisher::retract().
 */
class OpenCatalogiRetractTest extends TestCase {

	public function testRetractDeletesThePublicationInTheOpenCatalogiRegister(): void {
		$deleted = [];
		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->expects(self::once())->method('deleteObject')->willReturnCallback(
			static function (ObjectDeletion $deletion) use (&$deleted): bool {
				$deleted[] = [$deletion->uuid, $deletion->target->register, $deletion->target->schema];
				return true;
			}
		);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->with(ObjectServiceInterface::class)->willReturn($objects);

		$apps = $this->createMock(IAppManager::class);
		$apps->method('isEnabledForAnyone')->with('opencatalogi')->willReturn(true);

		$publisher = new OpenCatalogiPublisher(container: $container, appManager: $apps, logger: new NullLogger());

		self::assertTrue($publisher->retract(catalogPublication: 'pub-1'));
		self::assertSame([['pub-1', 'opencatalogi', 'publication']], $deleted);
	}//end testRetractDeletesThePublicationInTheOpenCatalogiRegister()
}//end class
