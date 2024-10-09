=== GP Auto Extract ===
Contributors: GregRoss, brazabr
Donate link: http://toolstack.com/donate
Plugin URI: http://glot-o-matic.com/gp-auto-extract
Author URI: http://toolstack.com
Tags: translation, glotpress
Requires at least: 4.4
Tested up to: 6.6
Stable tag: 0.9
License: GPLv2
License URI: http://www.gnu.org/licenses/gpl-2.0.html

A plugin for GlotPress that adds an option to extract original strings from a remote source repo directly in to a GlotPress project.

== Description ==

A plugin for [GlotPress](https://wordpress.org/plugins/glotpress) that adds an option to extract original strings from a remote source repo directly in to a GlotPress project.

Features:

1. WordPress, GitHub and custom sources.
2. Private GitHub repos with HTTP basic authentication.
3. GitHub tags and branches.
4. Importing for an existing extract file.

To set it up, go to Settings->GP Auto Extract in WordPress. You'll see a list of your projects in GP, you can edit the settings for each one:

1. Source Type
2. Setting
3. Branch/Tag (for GitHub repos only)
4. Use HTTP Basic Authentication (for GitHub repos only)
4. Import from existing file

Each source type has the following settings associated with it:

1. None - Don't auto extract this project.
2. WordPress.org - the slug for the WordPress.org SVN repo to extract from (for example "gp-auto-extract" for this plugin).
3. GitHub - The user name and repo name on GitHub to extract from (for example "toolstack/gp-auto-extract").
4. Custom - a complete url to a ZIP file containing the source code to extract from.

Once the setting has be entered, you can save them with the button to the right and then run an extract which will update the originals in the given project from the source selected.

== Installation ==

Install from the WordPress plugin directory.

== Frequently Asked Questions ==

= Does the plugin support webhooks for remote repos? =

Not yet, the 1.0 release will likely have support for webhooks so the extract can be executed whenever a remote file changes.

== Changelog ==
= 0.9 =
* Release Date: October 9, 2024
* Added: Support for WordPress Themes, thanks @pedro-mendonca.
* Fixed: Warnings/errors due to create_function() being removed in PHP8.
* Updated: pomo and extract functions from WP and WP I18N libraries respectivly.

= 0.8 =
* Release Date: January 16, 2017
* Info: Welcome new contributor brazabr!
* Added: Support for HTTP Basic Authentication for GitHub (thanks brazabr).
* Added: Option to skip POT generation and import an existing file from repository/archive (thanks brazabr).
* Added: Option to override GitHub branch or tag (thanks brazabr).
* Updated: UI for editing each project setting (thanks brazabr).

= 0.7 =
* Release Date: January 10, 2017
* Added: Auto Extract option to the projects menu on the front end.
* Fixed: Various WP_DEBUG warnings.
* Fixed: Better handling of corrupt zip files.
* Fixed: Make sure to remove temporary files.
* Fixed: Source file references would incorrectly include plugin top level directory, thanks @brazabr.

= 0.6 =
* Release Date: March 18, 2016
* Documentation updates.

= 0.5
* Release Date: January 28, 2016
* Initial release.
