<?php

namespace Flow\Import\Postprocessor;

use Wikimedia\Rdbms\IDatabase;
use BatchRowIterator;
use EchoCallbackIterator;
use EchoEvent;
use Flow\Import\IImportHeader;
use Flow\Import\IImportPost;
use Flow\Import\IImportTopic;
use Flow\Import\ImportException;
use Flow\Import\LiquidThreadsApi\ImportTopic as LqtImportTopic;
use Flow\Import\PageImportState;
use Flow\Import\TopicImportState;
use Flow\Model\PostRevision;
use Flow\NotificationController;
use RecursiveIteratorIterator;
use User;

/**
 * Converts LQT unread notifications into Echo notifications after a topic is imported
 */
class LqtNotifications implements Postprocessor {

	/**
	 * @var NotificationController
	 */
	protected $controller;

	/**
	 * @var IDatabase
	 */
	protected $dbw;

	/**
	 * @var PostRevision[] Array of imported replies
	 */
	protected $postsImported = [];

	public function __construct( NotificationController $controller, IDatabase $dbw ) {
		$this->controller = $controller;
		$this->dbw = $dbw;
		$this->overrideUsersToNotify();
	}

	/**
	 * evil, but what should we do instead? This basically removes the default methods
	 * of determining users to notify so they can be replaced with this class during imports.
	 */
	protected function overrideUsersToNotify() {
		global $wgEchoNotifications;

		// Remove the user-locators that choose on a per-notification basis who
		// should be notified.
		$notifications = require __DIR__ . '/../../Notifications/Notifications.php';
		foreach ( array_keys( $notifications ) as $type ) {
			unset( $wgEchoNotifications[$type]['user-locators'] );

			// The job queue causes our overrides to be lost since it
			// has a separate execution context.
			$wgEchoNotifications[$type]['immediate'] = true;
		}

		// Insert our own user locator to decide who should be notified.
		// Note this has to be a closure rather than direct callback due to how
		// echo considers an array to be extra parameters.
		// Overrides existing user-locators, because we don't want unintended
		// notifications to go out here.
		$self = $this;
		$wgEchoNotifications['flow-post-reply']['user-locators'] = [
			function ( EchoEvent $event ) use ( $self ) {
				return $self->locateUsersWithPendingLqtNotifications( $event );
			}
		];
	}

	/**
	 * @param EchoEvent $event
	 * @param int $batchSize
	 * @throws ImportException
	 * @return \Iterator[User]
	 */
	public function locateUsersWithPendingLqtNotifications( EchoEvent $event, $batchSize = 500 ) {
		$activeThreadId = $event->getExtraParam( 'lqtThreadId' );
		if ( $activeThreadId === null ) {
			throw new ImportException( 'No active thread!' );
		}

		$it = new BatchRowIterator(
			$this->dbw,
			/* table = */ 'user_message_state',
			/* primary keys */ [ 'ums_user' ],
			$batchSize
		);
		$it->addConditions( [
			'ums_conversation' => $activeThreadId,
			'ums_read_timestamp' => null,
		] );

		// flatten result into a stream of rows
		$it = new RecursiveIteratorIterator( $it );

		// add callback to convert user id to user objects
		$it = new EchoCallbackIterator( $it, function ( $row ) {
			return User::newFromId( $row->ums_user );
		} );

		return $it;
	}

	public function afterTopicImported( TopicImportState $state, IImportTopic $topic ) {
		if ( !$topic instanceof LqtImportTopic ) {
			return;
		}
		if ( empty( $this->postsImported ) ) {
			// nothing was imported in this topic
			return;
		}

		$this->controller->notifyPostChange( 'flow-post-reply', [
			'revision' => $this->postsImported[0],
			'topic-title' => $state->topicTitle,
			'topic-workflow' => $state->topicWorkflow,
			'title' => $state->topicWorkflow->getOwnerTitle(),
			'reply-to' => $state->topicTitle,
			'extra-data' => [
				'lqtThreadId' => $topic->getLqtThreadId(),
				'notifyAgent' => true,
			],
			'timestamp' => $topic->getTimestamp(),
		] );

		$this->postsImported = [];
	}

	public function importAborted() {
		$this->postsImported = [];
	}

	public function afterHeaderImported( PageImportState $state, IImportHeader $header ) {
		// not a thing to do, yet
	}

	public function afterPostImported( TopicImportState $state, IImportPost $post, PostRevision $newPost ) {
		$this->postsImported[] = $newPost;
	}
}
