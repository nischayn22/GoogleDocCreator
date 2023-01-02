# GoogleDocCreator

Creates a Google Doc in your Drive and embeds it to a wiki page using (https://www.mediawiki.org/wiki/Extension:GoogleDocTag)

## Installation

Download API credentials from https://github.com/googleapis/google-api-php-client/blob/HEAD/docs/oauth-web.md#create-authorization-credentials
Set Redirect URL as: http://localhost/mw_35/index.php/Special:GoogleDocCreator

Set the path to your credentials.json file:

    $wgGoogleApiClientCredentialsPath = "$IP/extensions/GoogleDocCreator/credentials.json";



Download this repo on your extensions folder

Add the following on your LocalSettings.php: 

    wfLoadExtension( 'GoogleDocCreator' );

Use composer to install dependencies. Run the following command from your main MediaWiki folder:

    composer update

## Usage

Visit the special page Special:GoogleDocCreator on your wiki. You must be logged in as a sysop user.
You must have GoogleDocTag installed for the embedding to work.

For creating a linked Google Doc use:

    <google_drive_linked_folder copy_file_id="1enbgrvcs7skgffMesfd1DJURNLOSoTA0" parent_file_id="1gMcc3KtyeZD4Z6I1Ns0rHChO1JESwz2Y"/>

copy_file_id is the id of the file/folder used as a template.
parent_file_id is the id of the folder where the newly created files will be stored.
