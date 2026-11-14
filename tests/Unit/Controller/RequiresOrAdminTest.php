<?php

/**
 * Unit tests for the RequiresOrAdmin controller trait.
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
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Controller;

use OCA\Decidiq\Controller\RequiresOrAdmin;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Decidiq\Controller\RequiresOrAdmin
 */
class RequiresOrAdminTest extends TestCase {

	/**
	 * A minimal host exposing the trait's private guard.
	 *
	 * @param IUser|null $user    The session user
	 * @param bool       $isAdmin What the group manager answers
	 *
	 * @return object
	 */
	private function host(?IUser $user, bool $isAdmin, ?string &$askedUid = null): object {
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('isAdmin')->willReturnCallback(
			static function (string $uid) use ($isAdmin, &$askedUid): bool {
				$askedUid = $uid;
				return $isAdmin;
			}
		);

		return new class($session, $groupManager) {
			use RequiresOrAdmin;

			public function __construct(
				private IUserSession $userSession,
				private IGroupManager $groupManager,
			) {
			}

			public function guard(): ?JSONResponse {
				return $this->requireAdmin();
			}
		};

	}//end host()

	/**
	 * A user double with a uid.
	 *
	 * @param string $uid The uid
	 *
	 * @return IUser
	 */
	private function user(string $uid): IUser {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		return $user;

	}//end user()

	public function testAnonymousIsRefusedWith401(): void {
		$response = $this->host(null, true)->guard();

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertSame(['message' => 'Authentication required.'], $response->getData());

	}//end testAnonymousIsRefusedWith401()

	public function testNonAdminIsRefusedWith403(): void {
		$asked = null;
		$response = $this->host($this->user('alice'), false, $asked)->guard();

		$this->assertSame('alice', $asked);
		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertSame(['message' => 'Administrator role required.'], $response->getData());

	}//end testNonAdminIsRefusedWith403()

	public function testAdminPasses(): void {
		$asked = null;
		$this->assertNull($this->host($this->user('admin'), true, $asked)->guard());
		$this->assertSame('admin', $asked);

	}//end testAdminPasses()
}//end class
