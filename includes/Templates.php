<?php
namespace WPFlatForms;

defined( 'ABSPATH' ) || exit;

/**
 * Ready-made forms. A form is data, not code: one JSON file per form in templates/ (sections of fields; a section marked 'repeat' is answered once for the main company and once for each other company).
 * Add your own by dropping a .json file in a folder returned by the 'wpflatforms_template_dirs' filter. The file name (without .json) becomes the form's address.
 * In any text, {site} is replaced with this site's name when the form is added.
 */
class Templates {

	/** @return string[] Folders that hold template files. */
	public static function dirs() {
		return array_values( array_filter( (array) apply_filters( 'wpflatforms_template_dirs', array( dirname( __DIR__ ) . '/templates' ) ) ) );
	}

	/** @return array<string,array> Form definitions keyed by address (file name). */
	public static function all() {
		$out = array();
		foreach ( self::dirs() as $dir ) {
			foreach ( (array) glob( trailingslashit( $dir ) . '*.json' ) as $file ) {
				$def = json_decode( (string) file_get_contents( $file ), true );
				if ( ! is_array( $def ) || empty( $def['title'] ) ) {
					continue;
				}
				$out[ sanitize_title( basename( $file, '.json' ) ) ] = self::fill( $def );
			}
		}
		return $out;
	}

	/** Swaps {site} for the site name in every text of a definition. */
	private static function fill( $v ) {
		if ( is_array( $v ) ) {
			return array_map( array( __CLASS__, 'fill' ), $v );
		}
		return is_string( $v ) ? str_replace( '{site}', wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ), $v ) : $v;
	}
}
