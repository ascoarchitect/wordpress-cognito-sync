=== WordPress Cognito Sync ===
Contributors: Adam Scott
Tags: cognito, aws, users, sync, groups
Requires at least: 5.0
Tested up to: 6.6.2
Requires PHP: 7.2
Stable tag: 1.4.0
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Synchronizes WordPress user and group operations with Amazon Cognito User Pools.

== Description ==
This plugin automatically synchronizes user operations (create, update, delete) and group operations (create, update, delete) from WordPress to Amazon Cognito User Pools using AWS API Gateway and Lambda.

== Features ==
* Synchronize WordPress users with Amazon Cognito User Pools
* Synchronize WordPress roles with Amazon Cognito groups
* Automatically create Cognito accounts on user login
* Full and test synchronization options for users and groups
* Detailed logs and sync results

== Installation ==
1. Upload the plugin files to the `/wp-content/plugins/wp-cognito-sync` directory, or install the plugin through the WordPress plugins screen directly.
2. Activate the plugin through the 'Plugins' screen in WordPress.
3. Use the Settings->Cognito Sync screen to configure the plugin with your API Gateway URL and API key.

== Changelog ==
= 1.4.0 =
* Added group synchronization functionality
* Improved logging and error handling
* Updated to support the latest AWS SDK

= 1.0.0 =
* Initial release

This plugin is provided "as is" without any guarantees or warranty.
In association with the product, the author makes no warranties of any kind,
either express or implied, including but not limited to warranties of
merchantability, fitness for a particular purpose, of title,
or of non-infringement of third-party rights.
Use of the product by a user is at the user’s risk.