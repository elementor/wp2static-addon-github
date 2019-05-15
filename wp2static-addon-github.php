<?php

/**
 * Plugin Name:       WP2Static Add-on: GitHub
 * Plugin URI:        https://wp2static.com
 * Description:       GitHub as a deployment option for WP2Static.
 * Version:           0.1
 * Author:            Leon Stafford
 * Author URI:        https://ljs.dev
 * License:           Unlicense
 * License URI:       http://unlicense.org
 * Text Domain:       wp2static-addon-github
 * Domain Path:       /languages
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

define( 'WP2STATIC_NETLIFY_PATH', plugin_dir_path( __FILE__ ) );

require WP2STATIC_NETLIFY_PATH . 'vendor/autoload.php';

// @codingStandardsIgnoreStart
$ajax_action = isset( $_POST['ajax_action'] ) ? $_POST['ajax_action'] : '';
// @codingStandardsIgnoreEnd

// NOTE: bypass instantiating plugin for specific AJAX requests
if ( $ajax_action == 'test_github' ) {
    $github = new WP2Static\GitHub;

    $github->test_github();

    wp_die();
    return null;
} elseif ( $ajax_action == 'github_prepare_export' ) {
    $github = new WP2Static\GitHub;

    $github->bootstrap();
    $github->prepareDeploy();

    wp_die();
    return null;
} elseif ( $ajax_action == 'github_transfer_files' ) {
    $github = new WP2Static\GitHub;

    $github->bootstrap();
    $github->github_transfer_files();

    wp_die();
    return null;
} elseif ( $ajax_action == 'cloudfront_invalidate_all_items' ) {
    $github = new WP2Static\GitHub;

    $github->cloudfront_invalidate_all_items();

    wp_die();
    return null;
}

define( 'PLUGIN_NAME_VERSION', '0.1' );

function run_wp2static_addon_github() {
	$plugin = new WP2Static\GitHubAddon();
	$plugin->run();

}

run_wp2static_addon_github();

