<?php
/**
 * Comment Images Custom
 *
 * Allow your readers easily to attach an image to their comments on posts and pages.
 *
 * @package   Comment_Image_Custom
 * @author    pulltab.info
 * @license   GPL-2.0+
 * @link      http://pulltab.info
 * @copyright pulltab.info
 *
 * @wordpress-plugin
 * Plugin Name: Comment Images Custom
 * Plugin URI:  -
 * Description: Allow your readers easily to attach an image to their comments on posts and pages.
 * Version:     2.0.0
 * Author:      pulltab.info
 * Author URI:  http://pulltab.info
 * License:     GPL-2.0+
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 * Fork :       Comment Image Plugin(https://wordpress.org/plugins/comment-image/)
 * 
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
} // end if

require_once( plugin_dir_path( __FILE__ ) . 'class-comment-image-custom.php' );
Comment_Image_Custom::get_instance();