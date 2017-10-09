<?php
namespace Waiter;

/**
 * The plugin bootstrap file
 *
 * This file is read by WordPress to generate the plugin information in the
 * plugin admin area. This file also includes all of the dependencies used by
 * the plugin, registers the activation and deactivation functions, and defines
 * a function that starts the plugin.p
 *
 * @link              https://github.com/francisw/soup-waiter
 * @since             4.8.1
 * @package           soup_waiter
 *
 * @wordpress-plugin
 * Plugin Name:       Vacation Soup for VR Owners
 * Plugin URI:        https://github.com/francisw/soup-waiter
 * Description:       Syndicate and Automate Vacation Rental Posting with Vacation Soup
 * Version:           1009.84
 * Author:            Francis Wallinger
 * Author URI:        http://github.com/francisw
 * License:           GPL-3.0+
 * License URI:       http://www.gnu.org/licenses/gpl-3.0.txt
 *
 * Depends:           timber-library - enforced
 * Conflicts:         pixabay-images (disable that plugin, pixabay images is incorporated (modified) within this plugin
 */
// If this file is accessed directory, then abort.
if ( ! defined( 'WPINC' ) ) {
	throw new \Exception("Cannot be accessed directly");
}
if ( ! function_exists( 'version_compare' ) || version_compare( PHP_VERSION, '5.3.0', '<' ) ) {
	throw new \Exception("PHP Version not supported");
}

// Now check he required plugins

require_once dirname( __FILE__ ) . '/class-tgm-plugin-activation.php';
add_action( 'tgmpa_register', 'Waiter\soup_waiter_register_required_plugins' );

function soup_waiter_register_required_plugins() {
	$plugins = [
		[
			'name'      => 'Timber',
			'slug'      => 'timber-library',
			'required'  => true,
			'version'            => '1.4.1', // E.g. 1.0.0. If set, the active plugin must be this version or higher. If the plugin version is higher than the plugin version installed, the user will be notified to update the plugin.
			'force_activation'   => true, // If true, plugin is activated upon theme activation and cannot be deactivated until theme switch.
		]
	];
	$config = [
		'id'           => 'soup-waiter',                 // Unique ID for hashing notices for multiple instances of TGMPA.
		'default_path' => __FILE__.'/..',                      // Default absolute path to bundled plugins.
		'menu'         => 'tgmpa-install-plugins',  // Menu slug.
		'parent_slug'  => 'plugins.php',            // Parent menu slug.
		'capability'   => 'manage_options',    // Capability needed to view plugin install page, should be a capability associated with the parent menu used.
		'has_notices'  => true,                    // Show admin notices or not.
		'dismissable'  => false,                    // If false, a user cannot dismiss the nag message.
		'dismiss_msg'  => 'Vacation Soup for VR Owners plugin cannot start without some additional plugins.',                      // If 'dismissable' is false, this message will be output at top of nag.
		'is_automatic' => true,                   // Automatically activate plugins after installation or not.
		'message'      => ''                      // Message to output right before the plugins table.
	];
	// This should ensure the plugin gets installed
	tgmpa( $plugins, $config );
}


require_once("inc/autoloader.php");
add_action ('init',array(SoupWaiter::single(),'init'));
