<?php

/**
 * 
 * 
 */
class SpecialGoogleDocCreator extends SpecialPage {
	public function __construct() {
		parent::__construct( 'GoogleDocCreator', 'googledoccreator' );
	}

	/**
	 */
	public function execute( $par ) {
		global $wgGoogleApiClientCredentialsPath;

		$this->setHeaders();
		$request = $this->getRequest();
		$out = $this->getOutput();

		if ( !class_exists( "Google_Client" ) ) {
			$out->addHTML( '<div class="errorbox">You must install Google_Client. Run "composer update" from your main directory.</div>' );
			return;
		}

		if( !in_array( 'sysop', $this->getUser()->getEffectiveGroups()) ) {
			$out->addHTML( '<div class="errorbox">This page is only accessible by users with sysop right.</div>' );
			return;
		}

		// Get the API client and construct the service object.
		$client = self::getGoogleClient();
		$accessToken = self::readKeyValue( "access_token" );

		if ( empty( $accessToken ) ) {
			if ( empty( $this->getRequest()->getVal( 'code' ) ) ) {
				// Request authorization from the user.
				$client->setRedirectUri( $this->getPageTitle()->getFullUrl() );
				$authUrl = $client->createAuthUrl();
				$out->addHTML(
					Html::element( 'a', array( "href" => $authUrl ), " Click to Authorize this extension to access Google Drive" ) . "<br><br>"
				);
				return;
			} else {
				$authCode = $this->getRequest()->getVal( 'code' );
			}

			// Exchange authorization code for an access token.
			$accessToken = $client->fetchAccessTokenWithAuthCode($authCode);

			// Check to see if there was an error.
			if (array_key_exists('error', $accessToken)) {
				throw new Exception(join(', ', $accessToken));
			}
			self::writeKeyValue( "access_token", $accessToken );
			$out->addHTML(
				Html::element( 'span', array(), "Successfully authenticated!" )
			);

			$client->setAccessToken($accessToken);
		}

		if ( empty( $this->getRequest()->getVal( 'wikipage_name' ) ) ) {
			$formOpts = [
				'id' => 'get_wikipage_name',
				'method' => 'post',
				'action' => $this->getPageTitle()->getFullUrl()
			];
			$out->addHTML(
				Html::openElement( 'form', $formOpts ) . "<br>" .
				Html::label( 'Enter Wiki Page Title',"", array( "for" => "wikipage_name" ) ) . "<br><br>" . 
				Html::element( 'input', array( "id" => "wikipage_name", "name" => "wikipage_name", "type" => "text" ) ) . "<br><br>"
			);
			$out->addHTML(
				Html::submitButton( "Submit", array() ) .
				Html::closeElement( 'form' )
			);
			return;
		} else {
			$wikipage_name = $this->getRequest()->getVal( 'wikipage_name' );
		}

		$service = new Google_Service_Drive( $client );

		//Create the file
		$file = new Google_Service_Drive_DriveFile();
		$file->setName( $wikipage_name );
		$file->setMimeType( 'application/vnd.google-apps.document' );
		$file = $service->files->create( $file );

		$fileId = $file->getId();

		//Give everyone permission to read and write the file
		$permission = new Google_Service_Drive_Permission ();
		$permission->setRole( 'writer' );
		$permission->setType( 'anyone' );
		$service->permissions->create( $fileId, $permission );

		$title = Title::newFromText( $wikipage_name );
		$article = new Article( $title );
		$content = new WikitextContent( '<gdoc id="' . $fileId . '" />' );
		$article->doEditContent( $content , "auto created by GoogleDocCreator");
		$out->addHTML( "Page Created Successfully: " . Linker::linkKnown( Title::newFromText( $wikipage_name ), $wikipage_name, array('target' => '_blank') ) );
	}

	public static function getGoogleClient() {
		global $wgGoogleApiClientCredentialsPath;

		$client = new Google_Client();
		$client->setApplicationName('Google Doc Creator MediaWiki Extension');
		$client->setScopes( array ( 'https://www.googleapis.com/auth/drive' ) );
		$client->setAuthConfig( $wgGoogleApiClientCredentialsPath );
		$client->setAccessType('offline');

		$cache_object = ObjectCache::getInstance( CACHE_DB );
		$accessToken = self::readKeyValue( "access_token" );

		if ( !$accessToken ) {
			return $client;
		}

		$client->setAccessToken($accessToken);

		// Refresh the token if it's expired.
		if ($client->isAccessTokenExpired()) {
			$client->fetchAccessTokenWithRefreshToken($client->getRefreshToken());
			self::writeKeyValue( "access_token", $client->getAccessToken() );
		}
		return $client;
	}

	// <google_drive_linked_folder copy_file_id="" parent_file_id=""/>
	public static function onParserFirstCallInit( Parser $parser ) {
		$parser->setHook( 'google_drive_linked_folder', [ self::class, 'renderGoogleDriveLinkedFolder' ] );
	}

	public static function renderGoogleDriveLinkedFolder( $input, array $args, Parser $parser, PPFrame $frame ) {
		global $wgTitle;

		$google_drive_linked_folder = self::readKeyValue( "google_drive_linked_folder_" . $wgTitle->getArticleID() );
		if ( !$google_drive_linked_folder ) {
			$client = self::getGoogleClient();
			$google_drive_linked_folder = self::copyDriveFolder( $client, $wgTitle->getText(), $args['parent_file_id'], $args['copy_file_id'] );
			self::writeKeyValue( "google_drive_linked_folder_" . $wgTitle->getArticleID(), $google_drive_linked_folder );
		}

		return '<a target="_blank" href="https://drive.google.com/drive/u/0/folders/'. $google_drive_linked_folder .'">Google Drive Linked Folder</a>';
	}

	public static function copyDriveFolder( $client, $wikipage_name, $parent_folder_id, $copy_file_id ) {
		$driveService = new Google_Service_Drive( $client );

		//Create the folder
		$fileMetadata = new Google_Service_Drive_DriveFile(
			array(
				'name' => $wikipage_name,
				'parents' => array( $parent_folder_id ),
				'mimeType' => 'application/vnd.google-apps.folder'
			)
		);
		$file = $driveService->files->create( $fileMetadata );
		self::recursiveCopy( $driveService, $copy_file_id, $file->getId() );

		return $file->getId();
	}

	public static function recursiveCopy( $driveService, $folder_to_recurse, $parent_folder_id ) {
		$pageToken = NULL;

		do {
			try {
				$parameters = array();
				if ($pageToken) {
					$parameters['pageToken'] = $pageToken;
				}
				$children = $driveService->files->listFiles(array('q' => "'$folder_to_recurse' in parents", "fields" => "files(id,name,parents,mimeType,size,createdTime,modifiedTime)"));

				foreach ($children->getFiles() as $child) {
					if ( $child->mimeType == "application/vnd.google-apps.folder" ) {
						$fileMetadata = new Google_Service_Drive_DriveFile(
							array(
								'name' => $child->name,
								'parents' => array( $parent_folder_id ),
								'mimeType' => $child->mimeType
							)
						);
						$file = $driveService->files->create( $fileMetadata );
						self::recursiveCopy( $driveService, $child->getId(), $file->getId() );
					} else {
						$copiedFile = new Google_Service_Drive_DriveFile(
							array(
								'name' => $child->name,
								'parents' => array( $parent_folder_id ),
								'mimeType' => $child->mimeType
							)
						);
						$file = $driveService->files->copy( $child->getId(), $copiedFile);
					}
  				}
				$pageToken = $children->getNextPageToken();
			} catch (Exception $e) {
			 	print "An error occurred: " . $e->getMessage();
 				var_dump( $e->getMessage() );
 				die();
			  $pageToken = NULL;
			}
		} while ($pageToken);
	}

	public static function readKeyValue( $key ) {
		$dbr = wfGetDB( DB_REPLICA );
		$accessToken = $dbr->selectField( 'google_doc_creator_ukv1', // table to use
			'value', // Field to select
			array( 'u_key' => $key ), // where conditions
			__METHOD__
		);
		return json_decode( $accessToken, true );
	}

	public static function writeKeyValue( $key, $accessToken ) {
		$dbr = wfGetDB( DB_REPLICA );
		$dbw = wfGetDB( DB_MASTER );

		$row = $dbr->select( 'google_doc_creator_ukv1', // table to use
			'value', // Field to select
			array( 'u_key' => $key ), // where conditions
			__METHOD__
		);
		$accessToken = json_encode( $accessToken );
		if ( $row->numRows() == 0 ) {
			$dbw->insert(
				"google_doc_creator_ukv1",
				array(
					'value' => $accessToken,
					'u_key' => $key
				),
				__METHOD__,
				array( 'IGNORE' )
			);
		} else {
			$dbw->update(
				"google_doc_creator_ukv1",
				array(
					'value' => $accessToken
				),
				array( "u_key" => $key ),
				__METHOD__,
				array( 'IGNORE' )
			);
		}
	}

	public static function onLoadExtensionSchemaUpdates( DatabaseUpdater $updater ) {
		$updater->addExtensionUpdate( array( 'addTable', 'google_doc_creator_ukv1', dirname( __FILE__ ) . '/google_doc_creator.sql', true ) );
	}
}
