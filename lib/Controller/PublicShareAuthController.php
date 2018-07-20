<?php
declare(strict_types=1);

/**
 *
 * @copyright Copyright (c) 2018, Daniel Calviño Sánchez (danxuliu@gmail.com)
 *
 * @license GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 */

namespace OCA\Spreed\Controller;

use OCA\Spreed\Manager;
use OCA\Spreed\Participant;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Share\IManager as ShareManager;
use OCP\Share\Exceptions\ShareNotFound;

class PublicShareAuthController extends OCSController {

	/** @var IUserManager */
	private $userManager;
	/** @var ShareManager */
	private $shareManager;
	/** @var Manager */
	private $manager;
	/** @var IL10N */
	private $l10n;

	/**
	 * @param string $appName
	 * @param IRequest $request
	 * @param IUserManager $userManager
	 * @param ShareManager $shareManager
	 * @param Manager $manager
	 * @param IL10N $l10n
	 */
	public function __construct(
			$appName,
			IRequest $request,
			IUserManager $userManager,
			ShareManager $shareManager,
			Manager $manager,
			IL10N $l10n
	) {
		parent::__construct($appName, $request);
		$this->userManager = $userManager;
		$this->shareManager = $shareManager;
		$this->manager = $manager;
		$this->l10n = $l10n;
	}

	/**
	 * @PublicPage
	 *
	 * Creates a new room for requesting the password of a share.
	 *
	 * The new room is a public room associated with a "share:password" object
	 * with the ID of the share token. Unlike normal rooms in which the owner is
	 * the user that created the room these are special rooms always created by
	 * a guest or user on behalf of a registered user, the sharer, who will be
	 * the owner of the room.
	 *
	 * If there is already a room for requesting the password of the given share
	 * no new room is created; the existing room is returned instead.
	 *
	 * The share must have "send password by Talk" enabled; an error is returned
	 * otherwise.
	 *
	 * @param string $shareToken
	 * @return DataResponse the status code is "201 Created" if a new room is
	 *         created, "200 OK" if an existing room is returned, or "404 Not
	 *         found" if the given share was invalid.
	 */
	public function createRoom(string $shareToken) {
		try {
			$share = $this->shareManager->getShareByToken($shareToken);
		} catch (ShareNotFound $e) {
			return new DataResponse([], Http::STATUS_NOT_FOUND);
		}

		if (!$share->getSendPasswordByTalk()) {
			return new DataResponse([], Http::STATUS_NOT_FOUND);
		}

		$sharerUser = $this->userManager->get($share->getSharedBy());

		if (!$sharerUser instanceof IUser) {
			return new DataResponse([], Http::STATUS_NOT_FOUND);
		}

		// If there is already a room for the share just return it.
		$roomsForSharer = $this->manager->getRoomsForParticipant($sharerUser->getUID());
		foreach ($roomsForSharer as $room) {
			if ($room->getObjectType() === 'share:password' && $room->getObjectId() === $shareToken) {
				return new DataResponse([
					'token' => $room->getToken(),
					'name' => $room->getName(),
					'displayName' => $room->getName(),
				]);
			}
		}

		$roomName = $this->l10n->t("Password request by %s", [$share->getSharedWith()]);

		// Create the room
		$room = $this->manager->createPublicRoom($roomName, 'share:password', $shareToken);
		$room->addUsers([
			'userId' => $sharerUser->getUID(),
			'participantType' => Participant::OWNER,
		]);

		return new DataResponse([
			'token' => $room->getToken(),
			'name' => $room->getName(),
			'displayName' => $room->getName(),
		], Http::STATUS_CREATED);
	}
}
