<?php
/*
Plugin Name: Gravity Forms Klaviyo Add-On
Plugin URI: https://wordpress.org/plugins/gf-klaviyo-add-on/
Description: Integrates Gravity Forms with Klaviyo allowing form submissions to be automatically sent to your Klaviyo account.
Version: 2.0
Author: GravityExtra
Author URI: https://gravityextra.com/
*/

define('GF_KLAVIYO_API_VERSION', '2.0');

add_action('gform_loaded', array('GF_KLAVIYO_API', 'load'), 5);

class GF_KLAVIYO_API {
	public static function load() {
		if (! method_exists('GFForms', 'include_feed_addon_framework')) {
			return;
		}

		require_once('class-gfklaviyofeedaddon.php');
		GFAddOn::register('GFKlaviyoAPI');
	}
	
}

function gf_klaviyo_api_feed() {
	return GFKlaviyoAPI::get_instance();
}
