<?php
/**
 * The main plugin file
 *
 * @package WordPress_Plugins
 * @subpackage OS_Disable_WordPress_Updates
 */

/*
Plugin Name: Disable All WordPress Updates
Description: Disables the theme, plugin and core update checking, the related cronjobs and notification system.
Plugin URI:  https://wordpress.org/plugins/disable-wordpress-updates/
Version:     2.0.1
Author:      Oliver Schlöbe
Author URI:  https://www.schloebe.de/
Text Domain: disable-wordpress-updates
Domain Path: /languages
License:	 GPL2

Copyright 2013-2026 Oliver Schlöbe (email : wordpress@schloebe.de)

This program is free software; you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation; either version 2 of the License, or
(at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program; if not, write to the Free Software
Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA
*/

// Don't load directly.
if ( ! defined( 'ABSPATH' ) ) {
	die( '-1' );
}


/**
 * Define the plugin version
 */
const OSDWPUVERSION = "2.0.1";


/**
 * The OS_Disable_WordPress_Updates class
 *
 * @package 	WordPress_Plugins
 * @subpackage 	OS_Disable_WordPress_Updates
 * @since 		1.3
 * @author 		wordpress@schloebe.de
 */
class OS_Disable_WordPress_Updates {
	/**
	 * The OS_Disable_WordPress_Updates class constructor
	 * initializing required stuff for the plugin
	 *
	 * PHP 5 Constructor
	 *
	 * @since 		1.3
	 * @author 		wordpress@schloebe.de
	 */
	public function __construct() {
		add_action( 'admin_init', [&$this, 'admin_init'] );

		/*
		 * Disable Theme Updates
		 * 2.8 to 3.0
		 */
		add_filter( 'pre_transient_update_themes', [__CLASS__, 'last_checked_atm'] );
		/*
		 * 3.0
		 */
		add_filter( 'pre_site_transient_update_themes', [__CLASS__, 'last_checked_atm'] );


		/*
		 * Disable Plugin Updates
		 * 2.8 to 3.0
		 */
		add_action( 'pre_transient_update_plugins', [__CLASS__, 'last_checked_atm'] );
		/*
		 * 3.0
		 */
		add_filter( 'pre_site_transient_update_plugins', [__CLASS__, 'last_checked_atm'] );


		/*
		 * Security Mode detection: when enabled, WordPress core automatic
		 * updates are restricted to minor / security releases instead of being
		 * fully disabled (see includes/class-osdwp-security-mode.php).
		 */
		$security_mode = self::is_security_mode();

		/*
		 * Disable Core Updates
		 * 2.8 to 3.0
		 *
		 * Skipped entirely in Security Mode: the empty-updates transient below
		 * would otherwise prevent ANY core update offer (including minor and
		 * security releases) from ever being discovered or applied.
		 */
		if ( ! $security_mode ) {
			add_filter( 'pre_transient_update_core', [__CLASS__, 'last_checked_atm'] );
			/*
			 * 3.0
			 */
			add_filter( 'pre_site_transient_update_core', [__CLASS__, 'last_checked_atm'] );
		}
		
		
		/*
		 * Filter schedule checks
		 *
		 * @link https://wordpress.org/support/topic/possible-performance-improvement/#post-8970451
		 */
		add_action( 'schedule_event', [__CLASS__, 'filter_cron_events'] );
		
		add_action( 'pre_set_site_transient_update_plugins', [__CLASS__, 'last_checked_atm'], 21, 1 );
		add_action( 'pre_set_site_transient_update_themes', [__CLASS__, 'last_checked_atm'], 21, 1 );

		/*
		 * Disable All Automatic Updates
		 * 3.7+
		 *
		 * @author	sLa NGjI's @ slangji.wordpress.com
		 */
		add_filter( 'auto_update_translation', '__return_false' );

		/*
		 * The automatic updater is only disabled when Security Mode is OFF.
		 * Security Mode needs it to remain active so minor / security core
		 * releases can be installed automatically (the allow_* filters in
		 * class-osdwp-security-mode.php decide which releases qualify).
		 */
		if ( $security_mode ) {
			add_filter( 'automatic_updater_disabled', '__return_false' );
		} else {
			add_filter( 'automatic_updater_disabled', '__return_true' );
		}
		add_filter( 'allow_minor_auto_core_updates', '__return_false' );
		add_filter( 'allow_major_auto_core_updates', '__return_false' );
		add_filter( 'allow_dev_auto_core_updates', '__return_false' );
		add_filter( 'auto_update_core', '__return_false' );
		add_filter( 'wp_auto_update_core', '__return_false' );
		add_filter( 'auto_core_update_send_email', '__return_false' );
		add_filter( 'send_core_update_notification_email', '__return_false' );
		add_filter( 'auto_update_plugin', '__return_false' );
		add_filter( 'auto_update_theme', '__return_false' );
		add_filter( 'automatic_updates_send_debug_email', '__return_false' );
		add_filter( 'automatic_updates_is_vcs_checkout', '__return_true' );

		/*
		 * Core update checks (the version-check cron and the auto-update cron)
		 * are only removed when Security Mode is OFF; Security Mode needs them
		 * so minor / security core offers can be fetched and applied.
		 */
		if ( ! $security_mode ) {
			remove_action( 'init', 'wp_schedule_update_checks' );
		}
		remove_all_filters( 'plugins_api' );

		add_filter( 'automatic_updates_send_debug_email ', '__return_false', 1 );
		if ( $security_mode ) {
			/*
			 * Security Mode: keep the updater active and restrict core to
			 * 'minor' (the allow_* filters then narrow it further to minor /
			 * security releases only).
			 */
			if ( ! defined( 'AUTOMATIC_UPDATER_DISABLED' ) ) define( 'AUTOMATIC_UPDATER_DISABLED', false );
			if ( ! defined( 'WP_AUTO_UPDATE_CORE' ) ) define( 'WP_AUTO_UPDATE_CORE', 'minor' );
		} else {
			if ( ! defined( 'AUTOMATIC_UPDATER_DISABLED' ) ) define( 'AUTOMATIC_UPDATER_DISABLED', true );
			if ( ! defined( 'WP_AUTO_UPDATE_CORE' ) ) define( 'WP_AUTO_UPDATE_CORE', false );
		}

		/*
		 * Only block WordPress.org update-check requests when Security Mode is
		 * OFF; the version check must be able to reach api.wordpress.org so it
		 * can fetch minor / security core offers.
		 */
		if ( ! $security_mode ) {
			add_filter( 'pre_http_request', [__CLASS__, 'block_request'], 10, 3 );
		}
	}


	/**
	 * Initialize and load the plugin stuff
	 *
	 * @since 		1.3
	 * @author 		wordpress@schloebe.de
	 */
	public function admin_init() {
		if ( !function_exists("remove_action") ) return;

		if ( current_user_can( 'update_core' ) ) {
			add_action( 'admin_bar_menu', [__CLASS__, 'add_adminbar_items'], 100 );
			add_action( 'admin_enqueue_scripts', [__CLASS__, 'admin_css_overrides'] );
		}
		
		/*
		 * Remove 'update plugins' option from bulk operations select list
		 */
		global $current_user;
		$current_user->allcaps['update_plugins'] = 0;
		
		/*
		 * Hide maintenance and update nag
		 */
		if ( ! self::is_security_mode() ) {
			add_filter( 'site_status_tests', [__CLASS__, 'site_status_tests'] );
			remove_action( 'admin_notices', 'update_nag', 3 );
			remove_action( 'network_admin_notices', 'update_nag', 3 );
			remove_action( 'admin_notices', 'maintenance_nag' );
			remove_action( 'network_admin_notices', 'maintenance_nag' );
		}
		

		/*
		 * Disable Theme Updates
		 * 2.8 to 3.0
		 */
		remove_action( 'load-themes.php', 'wp_update_themes' );
		remove_action( 'load-update.php', 'wp_update_themes' );
		remove_action( 'admin_init', '_maybe_update_themes' );
		remove_action( 'wp_update_themes', 'wp_update_themes' );
		wp_clear_scheduled_hook( 'wp_update_themes' );


		/*
		 * 3.0
		 */
		remove_action( 'load-update-core.php', 'wp_update_themes' );
		wp_clear_scheduled_hook( 'wp_update_themes' );


		/*
		 * Disable Plugin Updates
		 * 2.8 to 3.0
		 */
		remove_action( 'load-plugins.php', 'wp_update_plugins' );
		remove_action( 'load-update.php', 'wp_update_plugins' );
		remove_action( 'admin_init', '_maybe_update_plugins' );
		remove_action( 'wp_update_plugins', 'wp_update_plugins' );
		wp_clear_scheduled_hook( 'wp_update_plugins' );

		/*
		 * 3.0
		 */
		remove_action( 'load-update-core.php', 'wp_update_plugins' );
		wp_clear_scheduled_hook( 'wp_update_plugins' );


		/*
		 * Disable Core Updates
		 * 2.8 to 3.0
		 *
		 * Skipped entirely in Security Mode so the version check and the
		 * auto-update cron stay active and minor / security core releases can
		 * be discovered and applied automatically.
		 */
		if ( ! self::is_security_mode() ) {
			add_filter( 'pre_option_update_core', '__return_null' );

			remove_action( 'wp_version_check', 'wp_version_check' );
			remove_action( 'admin_init', '_maybe_update_core' );
			wp_clear_scheduled_hook( 'wp_version_check' );


			/*
			 * 3.0
			 */
			wp_clear_scheduled_hook( 'wp_version_check' );


			/*
			 * 3.7+
			 */
			remove_action( 'wp_maybe_auto_update', 'wp_maybe_auto_update' );
			remove_action( 'admin_init', 'wp_maybe_auto_update' );
			remove_action( 'admin_init', 'wp_auto_update_core' );
			wp_clear_scheduled_hook( 'wp_maybe_auto_update' );
		}
		
		remove_all_filters( 'plugins_api' );
	}



	/**
	 * Hide update checks in the Site Health screen
	 *
	 * @since 		1.6.8
	 */
	public function site_status_tests($tests) {
		unset( $tests['async']['background_updates'] );
		unset( $tests['direct']['plugin_theme_auto_updates'] );
		return $tests;
	}



	/**
	 * Add notice to admin bar when plugin is active
	 *
	 * @since 		1.7.0
	 */
	public static function add_adminbar_items($admin_bar) {
		$plugin_data   = get_plugin_data( __FILE__ );
		$sm_available  = class_exists( 'OSDWP_Security_Mode' );
		$security_mode = $sm_available && (bool) get_option( OSDWP_Security_Mode::OPTION_NAME, false );

		// Link the admin-bar indicator straight to the plugin's settings page.
		$settings_slug = $sm_available ? OSDWP_Security_Mode::PAGE_SLUG : 'osdwp-security-mode';
		$settings_url  = admin_url( 'options-general.php?page=' . $settings_slug );

		// Build the tooltip; add a note when Security Mode is on so the orange
		// indicator (instead of red) is explained on hover.
		$tooltip = sprintf(
			/* translators: %s: Name of the plugin */
			__('"%s" plugin is enabled! Click to manage update settings.', 'disable-wordpress-updates'),
			$plugin_data['Name']
		);
		if ( $security_mode ) {
			$tooltip .= ' ' . __( 'Security Mode enabled.', 'disable-wordpress-updates' );
		}

		$admin_bar->add_menu([
			'id' => 'dwuos-notice',
			'title' => '<span class="dashicons dashicons-info" aria-hidden="true"></span>',
			'href' => $settings_url,
			'meta' => [
				'class' => 'wp-admin-bar-dwuos-notice',
				'title' => $tooltip,
			],
		]);
	}



	/**
	 * Apply CSS styles to admin bar notice
	 *
	 * @since 		1.7.0
	 */
	public static function admin_css_overrides() {
		wp_add_inline_style( 'admin-bar', '.wp-admin-bar-dwuos-notice { background-color: rgba(190, 0, 0, 0.4) !important; } .wp-admin-bar-dwuos-notice .dashicons { font-family: dashicons !important; }' );
	}


	/**
	 * Check the outgoing request
	 *
	 * @since 		1.4.4
	 */
	public static function block_request($pre, $args, $url) {
		/* Empty url */
		if( empty( $url ) ) {
			return $pre;
		}

		/* Invalid host */
		if( !$host = parse_url($url, PHP_URL_HOST) ) {
			return $pre;
		}

		$url_data = parse_url( $url );

		/* block request */
		if( false !== stripos( $host, 'api.wordpress.org' ) &&
		    isset( $url_data['path'] ) &&
		    (false !== stripos( $url_data['path'], 'update-check' ) ||
		     false !== stripos( $url_data['path'], 'version-check' ) ||
		     false !== stripos( $url_data['path'], 'browse-happy' ) ||
		     false !== stripos( $url_data['path'], 'serve-happy' )) ) {
			return true;
		}

		return $pre;
	}


	/**
	 * Whether the optional "Security Mode" (minor / security core auto-updates
	 * only) is currently enabled.
	 *
	 * @since 		2.0.1
	 *
	 * @return bool
	 */
	public static function is_security_mode() {
		return class_exists( 'OSDWP_Security_Mode' )
			&& (bool) get_option( OSDWP_Security_Mode::OPTION_NAME, false );
	}


	/**
	 * Filter cron events
	 *
	 * @since 		1.5.0
	 */
	public static function filter_cron_events($event) {
		if ( self::is_security_mode() ) {
			/*
			 * Security Mode: the core version check and the auto-update cron
			 * must be allowed so minor / security core releases can be
			 * discovered and installed. Plugin and theme update crons stay
			 * blocked (plugin/theme updates remain disabled regardless).
			 */
			switch( $event->hook ) {
				case 'wp_update_plugins':
				case 'wp_update_themes':
					$event = false;
					break;
			}
			return $event;
		}

		switch( $event->hook ) {
			case 'wp_version_check':
			case 'wp_update_plugins':
			case 'wp_update_themes':
			case 'wp_maybe_auto_update':
				$event = false;
				break;
		}
		return $event;
	}
	
	
	/**
	 * Override version check info
	 *
	 * @since 		1.6.0
	 */
	public static function last_checked_atm( $t ) {
		if ( function_exists( 'wp_get_wp_version' ) ) {
			$wp_version = wp_get_wp_version();
		} else {
			include ABSPATH . WPINC . '/version.php';
		}

		$current = new stdClass;
		$current->updates = [];
		$current->version_checked = $wp_version;
		$current->last_checked = time();

		return $current;
	}
}

/*
 * Load the optional "Security Mode" feature (core auto-update control).
 *
 * Instantiated BEFORE the main class on purpose: it needs to detect whether
 * the WP_AUTO_UPDATE_CORE constant was already defined elsewhere (wp-config.php,
 * a host panel, an mu-plugin, another plugin) before this plugin's own
 * constructor defines it.
 */
require_once __DIR__ . '/includes/class-osdwp-security-mode.php';

if ( class_exists( 'OSDWP_Security_Mode' ) ) {
	$GLOBALS['osdwp_security_mode'] = new OSDWP_Security_Mode();
}

if ( class_exists('OS_Disable_WordPress_Updates') ) {
	$OS_Disable_WordPress_Updates = new OS_Disable_WordPress_Updates();
}
