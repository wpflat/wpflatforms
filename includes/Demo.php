<?php
namespace WPFlatForms;

defined( 'ABSPATH' ) || exit;

/** A sample set of answers, so you can see how a filled-in form and its PDF look. Delete it like any other answer. */
class Demo {

	private static function sample( array $f, $company ) {
		switch ( $f['type'] ) {
			case 'email':
				return 'hello@example.com';
			case 'tel':
				return '+234 800 000 0000';
			case 'url':
				return 'https://example.com';
			case 'number':
				return '12';
			case 'select':
			case 'radio':
				return isset( $f['options'][0] ) ? $f['options'][0] : '';
			case 'checkboxes':
				return array_slice( (array) $f['options'], 0, 3 );
			case 'text':
				return 'Sample answer';
			default:
				return "Sample answer for " . $company . ". This text only shows how a longer answer looks in the form, in the app and in the PDF.\n- A point to show a list\n- Another point";
		}
	}

	/** @return int|\WP_Error the new submission id */
	public static function load( array $form ) {
		global $wpdb;
		$def    = $form['definition'];
		$count  = 2;
		$names  = array( 'Sample Parent Company', 'Sample Subsidiary One', 'Sample Subsidiary Two' );
		$data   = array( 'count' => $count, 'general' => array(), 'entities' => array( array(), array(), array() ) );
		foreach ( (array) $def['sections'] as $s ) {
			foreach ( (array) $s['fields'] as $f ) {
				if ( empty( $f['id'] ) || in_array( $f['type'], array( 'files', 'heading', 'note' ), true ) ) {
					continue;
				}
				if ( ! empty( $s['repeat'] ) ) {
					for ( $i = 0; $i <= $count; $i++ ) {
						$data['entities'][ $i ][ $f['id'] ] = self::sample( $f, $names[ $i ] );
					}
				} elseif ( 'count' === $f['type'] ) {
					$data['general'][ $f['id'] ] = $count;
				} elseif ( 'companies' === $f['type'] ) {
					$rows = array();
					foreach ( $names as $i => $n ) {
						$rows[] = array( 'name' => $n, 'does' => 'One line about what it does', 'website' => 'example.com', 'contact' => 'Sample Person' );
					}
					$data['general'][ $f['id'] ] = $rows;
				} else {
					$data['general'][ $f['id'] ] = 'name' === $f['id'] ? 'Sample Person (demo)' : self::sample( $f, 'the group' );
				}
			}
		}
		$now  = current_time( 'mysql' );
		$ok   = $wpdb->insert( Install::table( 'submissions' ), array(
			'form_id'      => $form['id'],
			'token'        => bin2hex( random_bytes( 16 ) ),
			'status'       => 'submitted',
			'name'         => 'Sample Person (demo)',
			'email'        => 'hello@example.com',
			'org'          => $names[0],
			'entities'     => $count + 1,
			'consent_at'   => $now,
			'consent_ver'  => Forms::consent_version( $def ),
			'data'         => wp_json_encode( $data ),
			'created_at'   => $now,
			'updated_at'   => $now,
			'submitted_at' => $now,
		) );
		return $ok ? (int) $wpdb->insert_id : new \WP_Error( 'wpflatforms_db', 'The sample could not be saved.', array( 'status' => 500 ) );
	}
}
