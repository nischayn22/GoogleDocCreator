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
		$client = new Google_Client();
		$client->setApplicationName('Google Doc Creator MediaWiki Extension');
		$client->setScopes( array ( 'https://www.googleapis.com/auth/drive' ) );
		$client->setAuthConfig( $wgGoogleApiClientCredentialsPath );
		$client->setAccessType('offline');

		$cache_object = ObjectCache::getInstance( CACHE_DB );
		$accessToken = $cache_object->get( "google-doc-creator-access-token" );
		if ( empty( $accessToken ) ) {
			if ( empty( $this->getRequest()->getVal( 'auth_code' ) ) ) {
				// Request authorization from the user.
				$authUrl = $client->createAuthUrl();
				$formOpts = [
					'id' => 'get_auth_code',
					'method' => 'post',
					'action' => $this->getTitle()->getFullUrl()
				];
				$out->addHTML(
					Html::openElement( 'form', $formOpts ) . "<br>" .
					Html::label( 'Enter Auth Code',"", array( "for" => "auth_code" ) ) . "<br><br>" . 
					Html::element( 'input', array( "id" => "auth_code", "name" => "auth_code", "type" => "text" ) ) . 
					Html::element( 'a', array( "href" => $authUrl, "target" => "_blank" ), " Click to get Auth Code" ) . "<br><br>"
				);
				$out->addHTML(
					Html::submitButton( "Submit", array() ) .
					Html::closeElement( 'form' )
				);
				return;
			} else {
				$authCode = $this->getRequest()->getVal( 'auth_code' );
			}

			// Exchange authorization code for an access token.
			$accessToken = $client->fetchAccessTokenWithAuthCode($authCode);

			// Check to see if there was an error.
			if (array_key_exists('error', $accessToken)) {
				throw new Exception(join(', ', $accessToken));
			}

			$cache_object->set( "google-doc-creator-access-token", $accessToken, 600 );
		}
		$client->setAccessToken($accessToken);

		// Refresh the token if it's expired.
		if ($client->isAccessTokenExpired()) {
			$client->fetchAccessTokenWithRefreshToken($client->getRefreshToken());
			$cache_object->set( "google-doc-creator-access-token", $client->getAccessToken(), 600 );
		}

		if ( empty( $this->getRequest()->getVal( 'wikipage_name' ) ) ) {
			$formOpts = [
				'id' => 'get_wikipage_name',
				'method' => 'post',
				'action' => $this->getTitle()->getFullUrl()
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
}
