<?php

/**
 * Unit tests for NotificationPreferenceController (user-settings-v1).
 *
 * Covers session-user scoping (the person is always the session user — no
 * IDOR surface), the defaults-merged show() response with accountEmail, the
 * field whitelisting and every 422 validation rejection for the new
 * preference categories.
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/user-settings/spec.md
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Controller;

use OCA\Decidiq\Controller\NotificationPreferenceController;
use OCA\Decidiq\Service\NotificationPreferenceRequestValidator;
use OCA\Decidiq\Service\NotificationPreferenceService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Tests for NotificationPreferenceController.
 *
 * @spec openspec/specs/user-settings/spec.md
 */
class NotificationPreferenceControllerTest extends TestCase {

	/**
	 * Mock preference service.
	 *
	 * @var NotificationPreferenceService&MockObject
	 */
	private NotificationPreferenceService&MockObject $service;

	/**
	 * Build the controller with a request param map and a session user.
	 *
	 * @param array<string, mixed> $params Request params returned by getParam().
	 * @param bool $loggedIn Whether a session user exists.
	 *
	 * @return NotificationPreferenceController
	 */
	private function buildController(array $params = [], bool $loggedIn = true): NotificationPreferenceController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			function (string $key, mixed $default = null) use ($params) {
				return ($params[$key] ?? $default);
			}
		);

		$this->service = $this->createMock(NotificationPreferenceService::class);

		$userSession = $this->createMock(IUserSession::class);
		if ($loggedIn === true) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn('session-user');
			$user->method('getEMailAddress')->willReturn('session@example.com');
			$userSession->method('getUser')->willReturn($user);
		} else {
			$userSession->method('getUser')->willReturn(null);
		}

		return new NotificationPreferenceController(
			request: $request,
			preferenceService: $this->service,
			userSession: $userSession,
			validator: new NotificationPreferenceRequestValidator(request: $request),
		);

	}//end buildController()

	/**
	 * show() returns the defaults-merged preference for the SESSION user plus accountEmail.
	 *
	 * @spec openspec/specs/user-settings/spec.md
	 *
	 * @return void
	 */
	public function testShowScopesToSessionUserAndIncludesAccountEmail(): void {
		$controller = $this->buildController();
		$this->service->expects($this->once())
			->method('getPreferenceWithDefaults')
			->with('session-user')
			->willReturn(['person' => 'session-user', 'deliveryMethod' => 'in-app']);

		$response = $controller->show();
		$data = $response->getData();

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame('session-user', $data['person']);
		self::assertSame('session@example.com', $data['accountEmail'], 'UI needs the NC default email');

	}//end testShowScopesToSessionUserAndIncludesAccountEmail()

	/**
	 * show() and update() reject unauthenticated requests.
	 *
	 * @spec openspec/specs/user-settings/spec.md
	 *
	 * @return void
	 */
	public function testUnauthenticatedRequestsAreRejected(): void {
		$controller = $this->buildController(loggedIn: false);

		self::assertSame(Http::STATUS_UNAUTHORIZED, $controller->show()->getStatus());
		self::assertSame(Http::STATUS_UNAUTHORIZED, $controller->update()->getStatus());

	}//end testUnauthenticatedRequestsAreRejected()

	/**
	 * update() persists whitelisted fields for the SESSION user only.
	 *
	 * @spec openspec/specs/user-settings/spec.md
	 *
	 * @return void
	 */
	public function testUpdatePersistsValidatedFieldsForSessionUser(): void {
		$controller = $this->buildController(
			params: [
				'votingOpened' => true,
				'meetingReminder' => false,
				'reminderTimes' => ['48h', '1h'],
				'deliveryMethod' => 'both',
				'delegate' => 'memberB',
				'delegationFrom' => '2026-07-01',
				'delegationUntil' => '2026-07-14',
				'governanceEmail' => 'work@example.com',
				'urgentPhone' => '+31 6 12345678',
				'communicationLanguage' => 'nl',
				'person' => 'attacker-controlled',
			]
		);

		$captured = null;
		$this->service->expects($this->once())
			->method('updatePreference')
			->willReturnCallback(
				function (string $personId, array $changes) use (&$captured) {
					$captured = ['personId' => $personId, 'changes' => $changes];
					return array_merge(['person' => $personId], $changes);
				}
			);

		$response = $controller->update();

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame('session-user', $captured['personId'], 'Person derives from the session, never the payload');
		self::assertArrayNotHasKey('person', $captured['changes'], 'person is not a writable field');
		self::assertSame(['48h', '1h'], $captured['changes']['reminderTimes']);
		self::assertSame('both', $captured['changes']['deliveryMethod']);
		self::assertSame('memberB', $captured['changes']['delegate']);
		self::assertSame('2026-07-14', $captured['changes']['delegationUntil']);
		self::assertSame('work@example.com', $captured['changes']['governanceEmail']);
		self::assertSame('nl', $captured['changes']['communicationLanguage']);
		self::assertFalse($captured['changes']['meetingReminder']);

	}//end testUpdatePersistsValidatedFieldsForSessionUser()

	/**
	 * An empty delegate clears the delegation and its period.
	 *
	 * @spec openspec/specs/user-settings/spec.md
	 *
	 * @return void
	 */
	public function testUpdateEmptyDelegateClearsDelegation(): void {
		$controller = $this->buildController(params: ['delegate' => '']);

		$this->service->expects($this->once())
			->method('updatePreference')
			->with(
				'session-user',
				[
					'delegate' => null,
					'delegationFrom' => null,
					'delegationUntil' => null,
				]
			)
			->willReturn(['person' => 'session-user']);

		self::assertSame(Http::STATUS_OK, $controller->update()->getStatus());

	}//end testUpdateEmptyDelegateClearsDelegation()

	/**
	 * Every invalid payload is rejected with 422 and never persisted.
	 *
	 * @param array<string, mixed> $params The invalid request params.
	 *
	 * @spec openspec/specs/user-settings/spec.md
	 *
	 * @return void
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider('invalidPayloadProvider')]
	public function testUpdateRejectsInvalidPayloadWith422(array $params): void {
		$controller = $this->buildController(params: $params);
		$this->service->expects($this->never())->method('updatePreference');

		$response = $controller->update();

		self::assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
		self::assertArrayHasKey('message', $response->getData());

	}//end testUpdateRejectsInvalidPayloadWith422()

	/**
	 * Invalid payloads for the 422 matrix.
	 *
	 * @return array<string, array<int, array<string, mixed>>>
	 */
	public static function invalidPayloadProvider(): array {
		return [
			'bad deliveryMethod' => [['deliveryMethod' => 'pigeon']],
			'empty reminderTimes' => [['reminderTimes' => []]],
			'unknown reminder token' => [['reminderTimes' => ['24h', '3d']]],
			'reminderTimes not array' => [['reminderTimes' => '24h']],
			'bad governanceEmail' => [['governanceEmail' => 'not-an-email']],
			'bad urgentPhone' => [['urgentPhone' => 'call me <script>']],
			'bad communicationLanguage' => [['communicationLanguage' => 'tlh']],
			'delegate without expiry' => [['delegate' => 'memberB']],
			'inverted delegation' => [
				[
					'delegate' => 'memberB',
					'delegationFrom' => '2026-07-14',
					'delegationUntil' => '2026-07-01',
				],
			],
			'non-ISO delegation date' => [
				[
					'delegate' => 'memberB',
					'delegationUntil' => '14-07-2026',
				],
			],
			'malformed delegate id' => [
				[
					'delegate' => "bob\nmallory",
					'delegationUntil' => '2026-07-14',
				],
			],
		];

	}//end invalidPayloadProvider()
}//end class
