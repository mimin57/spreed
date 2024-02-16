<?php

declare(strict_types=1);
/**
 * @copyright Copyright (c) 2021 Gary Kim <gary@garykim.dev>
 *
 * @author Gary Kim <gary@garykim.dev>
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

namespace OCA\Talk\Federation;

use Exception;
use OCA\FederatedFileSharing\AddressHandler;
use OCA\Talk\AppInfo\Application;
use OCA\Talk\Config;
use OCA\Talk\Events\AAttendeeRemovedEvent;
use OCA\Talk\Events\ARoomModifiedEvent;
use OCA\Talk\Events\AttendeesAddedEvent;
use OCA\Talk\Exceptions\RoomNotFoundException;
use OCA\Talk\Manager;
use OCA\Talk\Model\Attendee;
use OCA\Talk\Model\AttendeeMapper;
use OCA\Talk\Model\Invitation;
use OCA\Talk\Model\InvitationMapper;
use OCA\Talk\Participant;
use OCA\Talk\Room;
use OCA\Talk\Service\ParticipantService;
use OCA\Talk\Service\RoomService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Services\IAppConfig;
use OCP\DB\Exception as DBException;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\Federation\Exceptions\ActionNotSupportedException;
use OCP\Federation\Exceptions\AuthenticationFailedException;
use OCP\Federation\Exceptions\BadRequestException;
use OCP\Federation\Exceptions\ProviderCouldNotAddShareException;
use OCP\Federation\ICloudFederationProvider;
use OCP\Federation\ICloudFederationShare;
use OCP\Federation\ICloudIdManager;
use OCP\HintException;
use OCP\ISession;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Notification\IManager as INotificationManager;
use OCP\Share\Exceptions\ShareNotFound;
use Psr\Log\LoggerInterface;
use SensitiveParameter;

class CloudFederationProviderTalk implements ICloudFederationProvider {

	public function __construct(
		private ICloudIdManager $cloudIdManager,
		private IUserManager $userManager,
		private AddressHandler $addressHandler,
		private FederationManager $federationManager,
		private Config $config,
		private IAppConfig $appConfig,
		private INotificationManager $notificationManager,
		private ParticipantService $participantService,
		private RoomService $roomService,
		private AttendeeMapper $attendeeMapper,
		private InvitationMapper $invitationMapper,
		private Manager $manager,
		private ISession $session,
		private IEventDispatcher $dispatcher,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * @inheritDoc
	 */
	public function getShareType(): string {
		return 'talk-room';
	}

	/**
	 * @inheritDoc
	 * @throws HintException
	 * @throws DBException
	 */
	public function shareReceived(ICloudFederationShare $share): string {
		if (!$this->config->isFederationEnabled()) {
			$this->logger->debug('Received a federation invite but federation is disabled');
			throw new ProviderCouldNotAddShareException('Server does not support talk federation', '', Http::STATUS_SERVICE_UNAVAILABLE);
		}
		if (!$this->appConfig->getAppValueBool('federation_incoming_enabled', true)) {
			$this->logger->warning('Received a federation invite but incoming federation is disabled');
			throw new ProviderCouldNotAddShareException('Server does not support talk federation', '', Http::STATUS_SERVICE_UNAVAILABLE);
		}
		if (!in_array($share->getShareType(), $this->getSupportedShareTypes(), true)) {
			$this->logger->debug('Received a federation invite for invalid share type');
			throw new ProviderCouldNotAddShareException('Support for sharing with non-users not implemented yet', '', Http::STATUS_NOT_IMPLEMENTED);
			// TODO: Implement group shares
		}

		$roomType = $share->getProtocol()['roomType'];
		if (!is_numeric($roomType) || !in_array((int) $roomType, $this->validSharedRoomTypes(), true)) {
			$this->logger->debug('Received a federation invite for invalid room type');
			throw new ProviderCouldNotAddShareException('roomType is not a valid number', '', Http::STATUS_BAD_REQUEST);
		}

		$shareSecret = $share->getShareSecret();
		$shareWith = $share->getShareWith();
		$remoteId = $share->getProviderId();
		$roomToken = $share->getResourceName();
		$roomName = $share->getProtocol()['roomName'];
		if (isset($share->getProtocol()['invitedCloudId'])) {
			$invitedCloudId = $share->getProtocol()['invitedCloudId'];
		} else {
			$this->logger->debug('Received a federation invite without invitedCloudId, falling back to shareWith');
			$invitedCloudId = $this->cloudIdManager->getCloudId($shareWith, null);
		}
		$roomType = (int) $roomType;
		$sharedByDisplayName = $share->getSharedByDisplayName();
		$sharedByFederatedId = $share->getSharedBy();
		$ownerDisplayName = $share->getOwnerDisplayName();
		$ownerFederatedId = $share->getOwner();
		[, $remote] = $this->addressHandler->splitUserRemote($ownerFederatedId);

		// if no explicit information about the person who created the share was sent
		// we assume that the share comes from the owner
		if ($sharedByFederatedId === null) {
			$sharedByDisplayName = $ownerDisplayName;
			$sharedByFederatedId = $ownerFederatedId;
		}

		if ($remote && $shareSecret && $shareWith && $roomToken && $remoteId && is_string($roomName) && $roomName && $ownerDisplayName) {
			$shareWith = $this->userManager->get($shareWith);
			if ($shareWith === null) {
				$this->logger->debug('Received a federation invite for user that could not be found');
				throw new ProviderCouldNotAddShareException('User does not exist', '', Http::STATUS_BAD_REQUEST);
			}

			if ($this->config->isDisabledForUser($shareWith)) {
				$this->logger->debug('Received a federation invite for user that is not allowed to use Talk');
				throw new ProviderCouldNotAddShareException('User does not exist', '', Http::STATUS_BAD_REQUEST);
			}

			if (!$this->config->isFederationEnabledForUserId($shareWith)) {
				$this->logger->debug('Received a federation invite for user that is not allowed to use Talk Federation');
				throw new ProviderCouldNotAddShareException('User does not exist', '', Http::STATUS_BAD_REQUEST);
			}

			$invite = $this->federationManager->addRemoteRoom($shareWith, (int) $remoteId, $roomType, $roomName, $roomToken, $remote, $shareSecret, $sharedByFederatedId, $sharedByDisplayName, $invitedCloudId);

			$this->notifyAboutNewShare($shareWith, (string) $invite->getId(), $sharedByFederatedId, $sharedByDisplayName, $roomName, $roomToken, $remote);
			return (string) $invite->getId();
		}

		$this->logger->debug('Received a federation invite with missing request data');
		throw new ProviderCouldNotAddShareException('required request data not found', '', Http::STATUS_BAD_REQUEST);
	}

	/**
	 * @inheritDoc
	 */
	public function notificationReceived($notificationType, $providerId, array $notification): array {
		if (!is_numeric($providerId)) {
			throw new BadRequestException(['providerId']);
		}
		switch ($notificationType) {
			case FederationManager::NOTIFICATION_SHARE_ACCEPTED:
				return $this->shareAccepted((int) $providerId, $notification);
			case FederationManager::NOTIFICATION_SHARE_DECLINED:
				return $this->shareDeclined((int) $providerId, $notification);
			case FederationManager::NOTIFICATION_SHARE_UNSHARED:
				return $this->shareUnshared((int) $providerId, $notification);
			case FederationManager::NOTIFICATION_ROOM_MODIFIED:
				return $this->roomModified((int) $providerId, $notification);
		}

		throw new BadRequestException([$notificationType]);
	}

	/**
	 * @throws ActionNotSupportedException
	 * @throws ShareNotFound
	 * @throws AuthenticationFailedException
	 */
	private function shareAccepted(int $id, array $notification): array {
		$attendee = $this->getLocalAttendeeAndValidate($id, $notification['sharedSecret']);

		$this->session->set('talk-overwrite-actor-type', $attendee->getActorType());
		$this->session->set('talk-overwrite-actor-id', $attendee->getActorId());
		$this->session->set('talk-overwrite-actor-displayname', $attendee->getDisplayName());

		$room = $this->manager->getRoomById($attendee->getRoomId());
		$event = new AttendeesAddedEvent($room, [$attendee]);
		$this->dispatcher->dispatchTyped($event);

		$this->session->remove('talk-overwrite-actor-type');
		$this->session->remove('talk-overwrite-actor-id');
		$this->session->remove('talk-overwrite-actor-displayname');

		return [];
	}

	/**
	 * @throws ActionNotSupportedException
	 * @throws ShareNotFound
	 * @throws AuthenticationFailedException
	 */
	private function shareDeclined(int $id, array $notification): array {
		$attendee = $this->getLocalAttendeeAndValidate($id, $notification['sharedSecret']);

		$this->session->set('talk-overwrite-actor-type', $attendee->getActorType());
		$this->session->set('talk-overwrite-actor-id', $attendee->getActorId());
		$this->session->set('talk-overwrite-actor-displayname', $attendee->getDisplayName());

		$room = $this->manager->getRoomById($attendee->getRoomId());
		$participant = new Participant($room, $attendee, null);
		$this->participantService->removeAttendee($room, $participant, AAttendeeRemovedEvent::REASON_LEFT);

		$this->session->remove('talk-overwrite-actor-type');
		$this->session->remove('talk-overwrite-actor-id');
		$this->session->remove('talk-overwrite-actor-displayname');
		return [];
	}

	/**
	 * @throws ActionNotSupportedException
	 * @throws ShareNotFound
	 * @throws AuthenticationFailedException
	 */
	private function shareUnshared(int $remoteAttendeeId, array $notification): array {
		$invite = $this->getByRemoteAttendeeAndValidate($notification['remoteServerUrl'], $remoteAttendeeId, $notification['sharedSecret']);
		try {
			$room = $this->manager->getRoomById($invite->getLocalRoomId());
		} catch (RoomNotFoundException) {
			throw new ShareNotFound();
		}

		// Sanity check to make sure the room is a remote room
		if (!$room->isFederatedRemoteRoom()) {
			throw new ShareNotFound();
		}

		$this->invitationMapper->delete($invite);
		$participant = $this->participantService->getParticipantByActor($room, Attendee::ACTOR_USERS, $invite->getUserId());
		$this->participantService->removeAttendee($room, $participant, AAttendeeRemovedEvent::REASON_REMOVED);
		return [];
	}

	/**
	 * @param int $remoteAttendeeId
	 * @param array{remoteServerUrl: string, sharedSecret: string, remoteToken: string, changedProperty: string, newValue: string|int|bool|null, oldValue: string|int|bool|null} $notification
	 * @return array
	 * @throws ActionNotSupportedException
	 * @throws AuthenticationFailedException
	 * @throws ShareNotFound
	 */
	private function roomModified(int $remoteAttendeeId, array $notification): array {
		$invite = $this->getByRemoteAttendeeAndValidate($notification['remoteServerUrl'], $remoteAttendeeId, $notification['sharedSecret']);
		try {
			$room = $this->manager->getRoomById($invite->getLocalRoomId());
		} catch (RoomNotFoundException) {
			throw new ShareNotFound();
		}

		// Sanity check to make sure the room is a remote room
		if (!$room->isFederatedRemoteRoom()) {
			throw new ShareNotFound();
		}

		if ($notification['changedProperty'] === ARoomModifiedEvent::PROPERTY_AVATAR) {
			$this->roomService->setAvatar($room, $notification['newValue']);
		} elseif ($notification['changedProperty'] === ARoomModifiedEvent::PROPERTY_DESCRIPTION) {
			$this->roomService->setDescription($room, $notification['newValue']);
		} elseif ($notification['changedProperty'] === ARoomModifiedEvent::PROPERTY_NAME) {
			$this->roomService->setName($room, $notification['newValue'], $notification['oldValue']);
		} elseif ($notification['changedProperty'] === ARoomModifiedEvent::PROPERTY_READ_ONLY) {
			$this->roomService->setReadOnly($room, $notification['newValue']);
		} elseif ($notification['changedProperty'] === ARoomModifiedEvent::PROPERTY_TYPE) {
			$this->roomService->setType($room, $notification['newValue']);
		} else {
			$this->logger->debug('Update of room property "' . $notification['changedProperty'] . '" is not handled and should not be send via federation');
		}

		return [];
	}

	/**
	 * @throws AuthenticationFailedException
	 * @throws ActionNotSupportedException
	 * @throws ShareNotFound
	 */
	private function getLocalAttendeeAndValidate(
		int $attendeeId,
		#[SensitiveParameter]
		string $sharedSecret,
	): Attendee {
		if (!$this->config->isFederationEnabled()) {
			throw new ActionNotSupportedException('Server does not support Talk federation');
		}

		try {
			$attendee = $this->attendeeMapper->getById($attendeeId);
		} catch (Exception) {
			throw new ShareNotFound();
		}
		if ($attendee->getActorType() !== Attendee::ACTOR_FEDERATED_USERS) {
			throw new ShareNotFound();
		}
		if ($attendee->getAccessToken() !== $sharedSecret) {
			throw new AuthenticationFailedException();
		}
		return $attendee;
	}

	/**
	 * @throws ActionNotSupportedException
	 * @throws ShareNotFound
	 * @throws AuthenticationFailedException
	 */
	private function getByRemoteAttendeeAndValidate(
		string $remoteServerUrl,
		int $remoteAttendeeId,
		#[SensitiveParameter]
		string $sharedSecret,
	): Invitation {
		if (!$this->config->isFederationEnabled()) {
			throw new ActionNotSupportedException('Server does not support Talk federation');
		}

		if (!$sharedSecret) {
			throw new AuthenticationFailedException();
		}

		try {
			return $this->invitationMapper->getByRemoteAndAccessToken($remoteServerUrl, $remoteAttendeeId, $sharedSecret);
		} catch (DoesNotExistException) {
			throw new ShareNotFound();
		}
	}

	private function notifyAboutNewShare(IUser $shareWith, string $inviteId, string $sharedByFederatedId, string $sharedByName, string $roomName, string $remoteRoomToken, string $remoteServerUrl): void {
		$notification = $this->notificationManager->createNotification();
		$notification->setApp(Application::APP_ID)
			->setUser($shareWith->getUID())
			->setDateTime(new \DateTime())
			->setObject('remote_talk_share', $inviteId)
			->setSubject('remote_talk_share', [
				'sharedByDisplayName' => $sharedByName,
				'sharedByFederatedId' => $sharedByFederatedId,
				'roomName' => $roomName,
				'serverUrl' => $remoteServerUrl,
				'roomToken' => $remoteRoomToken,
			]);

		$this->notificationManager->notify($notification);
	}

	private function validSharedRoomTypes(): array {
		return [
			Room::TYPE_ONE_TO_ONE,
			Room::TYPE_GROUP,
			Room::TYPE_PUBLIC,
		];
	}

	/**
	 * @inheritDoc
	 */
	public function getSupportedShareTypes(): array {
		return ['user'];
	}
}
