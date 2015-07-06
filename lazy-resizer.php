<?php

/**
 * Plugin Name: Lazy Resizer
 * Description: Resize WordPress images only when needed (on-demand).
 * Version: 1.0
 * Author: Miroslav Mitev
 * License: GPL2+
 * Text Domain: lazy-resizer
 */

// Exit if accessed directly
defined( 'ABSPATH' ) or exit;

if ( ! class_exists( 'Lazy_Resizer' ) && ! function_exists( 'lazy_resizer' ) ) :

class Lazy_Resizer {

	/**
	 * Singleton implementation.
	 *
	 * @return Lazy_Resizer
	 */
	public static function instance() {
		// Store the instance locally to avoid private static replication.
		static $instance;

		if ( ! is_a( $instance, 'Lazy_Resizer' ) ) {
			$instance = new Lazy_Resizer;
			$instance->setup();
		}

		return $instance;
	}

	/**
	 * Silence is golden.
	 */
	private function __construct() {}

	/**
	 * Register actions, filters and check the environment.
	 */
	private function setup() {
		// Check if the site uses "Pretty" permalinks. The plugin heavily depends on that.
		if ( ! get_option( 'permalink_structure' ) ) {
			add_action( 'admin_notices', array( $this, 'pretty_permalinks_notice' ) );
			return; // Bail, we have a problem...
		}

		// Disable WordPress image resizing.
		add_filter( 'intermediate_image_sizes_advanced', '__return_empty_array', 999 );

		// Generate fake intermediate size metadata for the attachments.
		add_filter( 'wp_generate_attachment_metadata', array( $this, 'generate_fake_intermediate_sizes' ), 5, 2 );

		// Handle the image resizing when the image file is not found (on 404).
		add_filter( 'template_redirect', array( $this, 'resizer' ), 9 );
	}

	/**
	 * Creates a resized image file for not found images, that have a particular size.
	 * Stalking on every 404 response.
	 *
	 * On success, the image will be streamed, otherwise an error is displayed.
	 */
	public function resizer() {
		// Bail if this is not 404.
		if ( ! is_404() ) {
			return;
		}

		// Has the file name the proper format for this handler?
		if ( ! preg_match( '~(?P<path>.*)-(?P<width>[0-9]+)x(?P<height>[0-9]+)?\.(?P<extension>[0-9a-z]+)~i', $_SERVER['REQUEST_URI'], $matches ) ) {
			return;
		}

		$uploads_dir      = wp_upload_dir();
		$upload_path      = urldecode( parse_url( $uploads_dir['baseurl'], PHP_URL_PATH ) );
		$file_upload_path = urldecode( $matches['path'] . '.' . $matches['extension'] );
		$file_upload_path = str_replace( $upload_path, '', $file_upload_path );
		$main_image_path  = $uploads_dir['basedir'] . $file_upload_path;
		$main_image_url   = $uploads_dir['baseurl'] . $file_upload_path;

		// Check if the main (full-sized) image exists.
		if ( ! file_exists( $main_image_path ) ) {
			die( __( 'Main image file not found.', 'lazy-resizer' ) );
		}

		// Get the attachment ID based on the main image URL
		$attachment_id = self::get_attachment_id_from_url( $main_image_url );

		// Check if we have an attachment.
		if ( ! $attachment_id ) {
			die( __( 'Unknown attachment.', 'lazy-resizer' ) );
		}

		$width  = $matches['width'];
		$height = $matches['height'];
		$sizes  = $this->get_image_sizes();
		$size   = $this->find_attachment_image_size($attachment_id, $width, $height);

		// Check if we have an intermediate image size.
		if ( empty( $size ) || empty( $sizes[$size] ) ) {
			die( __( 'Unknown image size.', 'lazy-resizer' ) );
		}

		$crop  = $sizes[$size]['crop'];

		// Resize and store the image.
		$image = $this->resize( $main_image_path, $width, $height, $crop );

		// Check if the resizing was successful.
		if ( is_wp_error($image) ) {
			status_header( 500 );
			die( $image->get_error_message() );
		}

		// Check if the resized image is the proper size.
		$image_size = $image->get_size();
		if ($image_size['width'] != $width || $image_size['height'] != $height) {
			unlink( $image->generate_filename() );
			die( __( 'The resized image isn\'t the right size.', 'lazy-resizer' ) );
		}

		// Everything looks OK, stream the image!
		status_header( 200 );
		$stream = $image->stream(); // Abracadabra, poof...

		// One final stream check, damn you Imagick.
		if ( is_wp_error( $stream ) ) {
			status_header( 500 );
			die( $stream->get_error_message() );
		}
	}

	/**
	 * Resize and store the image.
	 *
	 * @param string $file Path or URL to the file
	 * @param int $width
	 * @param int $height
	 * @param bool|array $crop
	 *
	 * @return WP_Image_Editor|WP_Error The WP_Image_Editor object if successful,
	 *                                  an WP_Error object otherwise.
	 */
	public function resize( $file, $width, $height, $crop ) {
		// Load the image editor
		$image = wp_get_image_editor( $file );

		// Check that the image editor has loaded the image.
		if ( is_wp_error( $image ) ) {
			return $image->get_error_message();
		}

		// Resize the image.
		$resized = $image->resize( $width, $height, $crop );

		// Save the image. The image suffix is added automatically.
		$saved   = $image->save( $image->generate_filename() ); 

		// Check if the resizing was successful.
		if ( is_wp_error( $resized ) ) {
			return $resized->get_error_message();
		}

		// Check if the resized image was saved.
		if ( is_wp_error( $saved ) ) {
			return $saved->get_error_message();
		}

		return $image;
	}

	/**
	 * Generates a fake intermediate sizes metadata for the attachment.
	 * This will trick WordPress into thinking that images were resized.
	 *
	 * @param  array $attachment_meta
	 * @param  int   $attachment_id
	 *
	 * @return mixed Metadata for the attachment.
	 */
	public function generate_fake_intermediate_sizes( $attachment_meta, $attachment_id ) {
		$file = get_attached_file( $attachment_id );

		// Check if this attachment is an image and can be displayed.
		// Using the same check as in wp_generate_attachment_metadata().
		if ( !preg_match( '!^image/!', get_post_mime_type( $attachment_id ) ) || ! file_is_displayable_image( $file ) ) {
			return $attachment_meta;
		}

		// Loop all known sizes and try to generate the metadata.
		foreach ( $this->get_image_sizes() as $size_name => $size_meta ) {
			// Bail if the size meta is already generated.
			if ( ! empty( $attachment_meta['sizes'][$size_name] ) ) {
				continue; // Hmm is it a good idea to bail here?
			}

			// Load the image editor.
			$image = wp_get_image_editor( $file );

			// Check that the image editor has loaded the image.
			if ( is_wp_error( $image ) ) {
				continue; // The editor can't load this file for some reason...
			}

			// Figure out what size WordPress would make the image.
			$resized    = $image->resize( $size_meta['width'], $size_meta['height'], $size_meta['crop'] ); // this updates the size
			$image_size = $image->get_size(); // get the size after resizing

			if ( ! is_wp_error( $resized ) && ! empty( $image_size ) ) {
				$filename  = wp_basename( $image->generate_filename() ); // suffix is added automatically
				$extension = pathinfo( $filename, PATHINFO_EXTENSION );
				$mime_type = self::get_mime_type( $extension );

				$attachment_meta['sizes'][$size_name] = array(
					'file'      => $filename,
					'width'     => $image_size['width'],
					'height'    => $image_size['height'],
					'mime-type' => $mime_type,
				);
			}
		}

		return $attachment_meta;
	}

	/**
	 * Looks for the appropriate image size for an attachment, based on the width and height.
	 *
	 * @param  int $attachment_id
	 * @param  int $width
	 * @param  int $height
	 *
	 * @return string|false The size name on success, false on failure.
	 */
	public function find_attachment_image_size($attachment_id, $width, $height) {
		$attachment_meta = wp_get_attachment_metadata( $attachment_id );

		// The sizes metadata should already be generated by self::generate_fake_intermediate_sizes().
		foreach ( $attachment_meta['sizes'] as $size_name => $size_meta ) {
			if ( $size_meta['width'] == $width && $size_meta['height'] == $height ) {
				return $size_name;
			}
		}

		return false;
	}

	/**
	 * Gets all available intermediate image sizes.
	 * Copied from wp_generate_attachment_metadata().
	 * 
	 * @see    wp_generate_attachment_metadata()
	 * @global $_wp_additional_image_sizes
	 *
	 * @return array Associative array with all sizes and metadata.
	 */
	public function get_image_sizes() {
		static $sizes = array();

		if ($sizes) {
			return $sizes;
		}

		global $_wp_additional_image_sizes;

		foreach ( get_intermediate_image_sizes() as $s ) {
			$sizes[$s] = array( 'width' => '', 'height' => '', 'crop' => false );
			if ( isset( $_wp_additional_image_sizes[$s]['width'] ) )
				$sizes[$s]['width'] = intval( $_wp_additional_image_sizes[$s]['width'] ); // For theme-added sizes
			else
				$sizes[$s]['width'] = get_option( "{$s}_size_w" ); // For default sizes set in options
			if ( isset( $_wp_additional_image_sizes[$s]['height'] ) )
				$sizes[$s]['height'] = intval( $_wp_additional_image_sizes[$s]['height'] ); // For theme-added sizes
			else
				$sizes[$s]['height'] = get_option( "{$s}_size_h" ); // For default sizes set in options
			if ( isset( $_wp_additional_image_sizes[$s]['crop'] ) )
				$sizes[$s]['crop'] = $_wp_additional_image_sizes[$s]['crop']; // For theme-added sizes
			else
				$sizes[$s]['crop'] = get_option( "{$s}_crop" ); // For default sizes set in options
		}

		return $sizes;
	}

	/**
	 * Output the "Pretty" permalinks notice template.
	 */
	public function pretty_permalinks_notice() {
		// Check if the user can do something about it.
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		include( __DIR__ . '/templates/notice-pretty-permalinks.php' );
	}

	/**
	 * Gets the attachment ID based on an absolute URL.
	 * Copied from attachment_url_to_postid() which was added in 4.0.0.
	 * 
	 * @uses   attachment_url_to_postid()
	 * @global $wpdb
	 *
	 * @param  string $url The full image URL.
	 *
	 * @return int The found post ID, or 0 on failure.  
	 */
	public static function get_attachment_id_from_url( $url ) {
		// Try to fallback to a core function. WP >= 4.0.0.
		if ( function_exists( 'attachment_url_to_postid' ) ) {
			return attachment_url_to_postid( $url );
		}

		global $wpdb;

		$dir = wp_upload_dir();
		$path = $url;

		if ( 0 === strpos( $path, $dir['baseurl'] . '/' ) ) {
			$path = substr( $path, strlen( $dir['baseurl'] . '/' ) );
		}

		$sql = $wpdb->prepare(
			"SELECT post_id FROM $wpdb->postmeta WHERE meta_key = '_wp_attached_file' AND meta_value = %s",
			$path
		);

		$post_id = $wpdb->get_var( $sql );
		$post_id = apply_filters( 'attachment_url_to_postid', $post_id, $url );

		return (int) $post_id;
	}

	/**
	 * Returns first matched mime-type from extension,
	 * as mapped from wp_get_mime_types()
	 *
	 * @param  string $extension
	 *
	 * @return string|boolean
	 */
	public static function get_mime_type( $extension ) {
		$mime_types = wp_get_mime_types();
		$extensions = array_keys( $mime_types );

		foreach( $extensions as $_extension ) {
			if ( preg_match( "/{$extension}/i", $_extension ) ) {
				return $mime_types[$_extension];
			}
		}

		return false;
	}

} // Lazy_Resizer class end

/**
 * The main function responsible for returning the Lazy_Resizer instance.
 *
 * Use this function like you would a global variable, except without needing
 * to declare the global.
 *
 * @example $lazy_resizer = lazy_resizer();
 *
 * @return Lazy_Resizer instance
 */
function lazy_resizer() {
	return Lazy_Resizer::instance();
}

/**
 * Hook Lazy_Resizer early onto the 'plugins_loaded' action.
 *
 * This gives all other plugins the chance to load before Lazy_Resizer, to get their
 * actions, filters, and overrides setup without Lazy_Resizer being in the way.
 */
if ( defined( 'LAZY_RESIZER_LOAD_PRIORITY' ) ) {
	add_action( 'plugins_loaded', 'lazy_resizer', (int) LAZY_RESIZER_LOAD_PRIORITY );
} else {
	lazy_resizer();
}

endif; // class|function_exists check
