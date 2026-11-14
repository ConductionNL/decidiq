<?php

/**
 * Unit tests for ThemeController.
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/publication-theme-pages/specs/public-publication/spec.md#requirement-req-thp-002-a-theme-shows-a-timeline-of-what-belongs-to-it
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Controller;

use OCA\Decidiq\Controller\ThemeController;
use OCA\Decidiq\Service\ThemeTimelineBuilder;
use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * The staff timeline: the caller's own read of the theme decides, every
 * entry keeps whether it is published.
 *
 * @covers \OCA\Decidiq\Controller\ThemeController
 */
class ThemeControllerTest extends TestCase {

	/** @var ObjectServiceInterface&MockObject */
	private ObjectServiceInterface&MockObject $objects;

	/** @var ThemeTimelineBuilder&MockObject */
	private ThemeTimelineBuilder&MockObject $builder;

	protected function setUp(): void {
		parent::setUp();
		$this->objects = $this->createMock(ObjectServiceInterface::class);
		$this->builder = $this->createMock(ThemeTimelineBuilder::class);

	}//end setUp()

	/**
	 * The controller for a signed-in user, or for nobody.
	 *
	 * @param bool $signedIn Whether a user is in session.
	 *
	 * @return ThemeController
	 */
	private function controller(bool $signedIn = true): ThemeController {
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($signedIn === true ? $this->createMock(IUser::class) : null);

		return new ThemeController(
			request: $this->createMock(IRequest::class),
			userSession: $session,
			objectService: $this->objects,
			timeline: $this->builder,
			logger: new NullLogger()
		);

	}//end controller()

	/**
	 * A theme entity carrying $data.
	 *
	 * @param array<string, mixed> $data The theme.
	 *
	 * @return ObjectEntityInterface
	 */
	private function entity(array $data): ObjectEntityInterface {
		$entity = $this->createMock(ObjectEntityInterface::class);
		$entity->method('jsonSerialize')->willReturn($data);
		return $entity;

	}//end entity()

	/**
	 * The theme is read with the caller's rights, and every entry comes back in the builder's order, unpublished ones marked.
	 *
	 * @return void
	 */
	public function testTheTimelineListsEveryEntryWithItsPublishedState(): void {
		$theme = ['id' => 'th-1', 'title' => 'Nieuw zwembad De Hoge Dijk', 'meetings' => ['m-1']];
		$this->objects->expects($this->once())->method('find')
			->with('th-1', [], false, 'decidiq', 'governance-theme')
			->willReturn($this->entity(data: $theme));
		$this->builder->expects($this->once())->method('entries')->with($theme)->willReturn(
			[
				['date' => '2026-10-14', 'kind' => 'meeting', 'title' => 'Raad', 'objectId' => 'm-1', 'published' => true, 'publication' => 'pay-1'],
				['date' => '2026-12-01', 'kind' => 'commitment', 'title' => 'Toezegging', 'objectId' => 'c-1', 'published' => false, 'publication' => null],
			]
		);

		$response = $this->controller()->timeline(id: 'th-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(
			[
				'entries' => [
					['date' => '2026-10-14', 'kind' => 'meeting', 'title' => 'Raad', 'objectId' => 'm-1', 'published' => true, 'publication' => 'pay-1'],
					['date' => '2026-12-01', 'kind' => 'commitment', 'title' => 'Toezegging', 'objectId' => 'c-1', 'published' => false, 'publication' => null],
				],
			],
			$response->getData()
		);

	}//end testTheTimelineListsEveryEntryWithItsPublishedState()

	/**
	 * A theme the caller may not read (null) answers 404 and builds nothing.
	 *
	 * @return void
	 */
	public function testAThemeTheCallerCannotReadAnswers404(): void {
		$this->objects->method('find')->willReturn(null);
		$this->builder->expects($this->never())->method('entries');

		$response = $this->controller()->timeline(id: 'th-x');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame(['message' => 'Theme not found'], $response->getData());

	}//end testAThemeTheCallerCannotReadAnswers404()

	/**
	 * A refused read (exception) answers the same 404, not a 500 that tells it exists.
	 *
	 * @return void
	 */
	public function testARefusedReadAnswersTheSame404(): void {
		$this->objects->method('find')->willThrowException(new RuntimeException('forbidden'));
		$this->builder->expects($this->never())->method('entries');

		$this->assertSame(Http::STATUS_NOT_FOUND, $this->controller()->timeline(id: 'th-x')->getStatus());

	}//end testARefusedReadAnswersTheSame404()

	/**
	 * Signed out: 401, nothing read.
	 *
	 * @return void
	 */
	public function testSignedOutAnswers401(): void {
		$this->objects->expects($this->never())->method('find');

		$response = $this->controller(signedIn: false)->timeline(id: 'th-1');

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());

	}//end testSignedOutAnswers401()
}//end class
