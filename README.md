# GoogleDocCreator
=============

Creates a Google Doc in your Drive and embeds it to a wiki page using (https://www.mediawiki.org/wiki/Extension:GoogleDocTag)

## Installation

Download this repo on your extensions folder
Add the following on your LocalSettings.php: 
    wfLoadExtension( 'GoogleDocCreator' );

Use composer to install dependencies. Run the following command from your main MediaWiki folder:

    composer update

## Usage

Visit the special page Special:GoogleDocCreator on your wiki. You must be logged in as a sysop user.
You must have GoogleDocTag installed for the embedding to work.
