<?php
namespace WPFlatForms;

defined( 'ABSPATH' ) || exit;

/** Files people drop into a form: stored in WPFlat's private folder, listed under their company, opened only by your team. */
class Uploads {

	const MAX_FILE_MB      = 50;
	const MAX_TOTAL_MB     = 1000;
	const MAX_PER_FIELD    = 60;
	const ALLOWED          = array( 'pdf', 'doc', 'docx', 'xls', 'xlsx', 'csv', 'ppt', 'pptx', 'txt', 'rtf', 'odt', 'png', 'jpg', 'jpeg', 'gif', 'webp', 'svg', 'ai', 'eps', 'psd', 'indd', 'zip', 'mp4', 'mov' );

	public static function init() {
		add_action( 'template_redirect', array( __CLASS__, 'maybe_stream' ), 1 );
	}

	public static function max_bytes() {
		$wp = (int) wp_max_upload_size();
		$cap = self::MAX_FILE_MB * MB_IN_BYTES;
		return $wp > 0 ? min( $wp, $cap ) : $cap;
	}

	private static function dir() {
		return \WPFlatCore\Paths::dir( 'forms' );
	}

	public static function row_out( array $r ) {
		return array( 'id' => (int) $r['id'], 'entity' => (int) $r['entity'], 'field' => $r['field'], 'name' => $r['name'], 'size' => (int) $r['size'], 'created_at' => $r['created_at'] );
	}

	public static function for_submission( $sub_id ) {
		global $wpdb;
		$out = array();
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . Install::table( 'files' ) . ' WHERE submission_id=%d ORDER BY id', (int) $sub_id ), ARRAY_A ) as $r ) { // phpcs:ignore WordPress.DB
			$out[] = self::row_out( $r );
		}
		return $out;
	}

	private static function files_field( array $def, $field ) {
		foreach ( (array) $def['sections'] as $s ) {
			foreach ( (array) $s['fields'] as $f ) {
				if ( isset( $f['id'] ) && $f['id'] === $field && 'files' === $f['type'] ) {
					return array( $f, ! empty( $s['repeat'] ) );
				}
			}
		}
		return null;
	}

	/**
	 * Save one uploaded file for a submission.
	 *
	 * @return array|\WP_Error The file row (as the page shows it).
	 */
	public static function add( array $form, array $sub, $entity, $field, array $file ) {
		global $wpdb;
		$ff = self::files_field( $form['definition'], (string) $field );
		if ( ! $ff ) {
			return new \WP_Error( 'wpflatforms_field', 'Files cannot be added there.', array( 'status' => 400 ) );
		}
		$entity = $ff[1] ? (int) $entity : 0;
		if ( $entity < 0 || $entity > (int) $sub['data']['count'] ) {
			return new \WP_Error( 'wpflatforms_field', 'That company is not in your form.', array( 'status' => 400 ) );
		}
		if ( empty( $file['tmp_name'] ) || ! is_uploaded_file( $file['tmp_name'] ) || ! empty( $file['error'] ) ) {
			return new \WP_Error( 'wpflatforms_upload', 'The file did not arrive. It may be larger than this site allows (' . size_format( self::max_bytes() ) . ').', array( 'status' => 400 ) );
		}
		$name = sanitize_file_name( wp_unslash( (string) $file['name'] ) );
		$ext  = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );
		if ( '' === $name || ! in_array( $ext, self::ALLOWED, true ) ) {
			return new \WP_Error( 'wpflatforms_type', 'That kind of file is not allowed. Allowed: ' . implode( ', ', self::ALLOWED ) . '.', array( 'status' => 400 ) );
		}
		$size = (int) $file['size'];
		if ( $size <= 0 || $size > self::max_bytes() ) {
			return new \WP_Error( 'wpflatforms_big', 'That file is too large. The limit is ' . size_format( self::max_bytes() ) . ' per file.', array( 'status' => 413 ) );
		}
		$t   = Install::table( 'files' );
		$tot = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(size),0) FROM $t WHERE submission_id=%d", $sub['id'] ) ); // phpcs:ignore WordPress.DB
		$n   = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $t WHERE submission_id=%d AND entity=%d AND field=%s", $sub['id'], $entity, $field ) ); // phpcs:ignore WordPress.DB
		if ( $tot + $size > self::MAX_TOTAL_MB * MB_IN_BYTES || $n >= self::MAX_PER_FIELD ) {
			return new \WP_Error( 'wpflatforms_big', 'You have reached the upload limit for this form. Please send the rest by email.', array( 'status' => 413 ) );
		}
		$stored = bin2hex( random_bytes( 16 ) ) . '.' . $ext;
		$dest   = self::dir() . '/' . $stored;
		if ( ! move_uploaded_file( $file['tmp_name'], $dest ) ) {
			return new \WP_Error( 'wpflatforms_upload', 'The file could not be saved.', array( 'status' => 500 ) );
		}
		@chmod( $dest, 0640 ); // phpcs:ignore WordPress.WP.AlternativeFunctions,WordPress.PHP.NoSilencedErrors
		$mime = function_exists( 'mime_content_type' ) ? (string) mime_content_type( $dest ) : 'application/octet-stream';
		$wpdb->insert( $t, array( 'submission_id' => $sub['id'], 'entity' => $entity, 'field' => $field, 'name' => mb_substr( $name, 0, 190 ), 'stored_name' => $stored, 'mime' => mb_substr( $mime, 0, 120 ), 'size' => $size, 'created_at' => current_time( 'mysql' ) ) ); // phpcs:ignore WordPress.DB
		$id = (int) $wpdb->insert_id;
		if ( ! $id ) {
			@unlink( $dest ); // phpcs:ignore WordPress.WP.AlternativeFunctions,WordPress.PHP.NoSilencedErrors
			return new \WP_Error( 'wpflatforms_db', 'The file could not be recorded.', array( 'status' => 500 ) );
		}
		$wpdb->update( Install::table( 'submissions' ), array( 'updated_at' => current_time( 'mysql' ) ), array( 'id' => $sub['id'] ) );
		return self::row_out( $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $t WHERE id=%d", $id ), ARRAY_A ) ); // phpcs:ignore WordPress.DB
	}

	private static function row( $id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Install::table( 'files' ) . ' WHERE id=%d', (int) $id ), ARRAY_A ); // phpcs:ignore WordPress.DB
	}

	private static function purge_row( array $r ) {
		global $wpdb;
		$p = self::dir() . '/' . basename( $r['stored_name'] );
		is_file( $p ) && unlink( $p ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$wpdb->delete( Install::table( 'files' ), array( 'id' => (int) $r['id'] ) );
	}

	/** The person removes one of their own files (they need their private link's token). */
	public static function remove( array $sub, $file_id ) {
		$r = self::row( $file_id );
		if ( ! $r || (int) $r['submission_id'] !== (int) $sub['id'] ) {
			return false;
		}
		self::purge_row( $r );
		return true;
	}

	/** When a submission is deleted its files go with it. */
	public static function purge_submission( $sub_id ) {
		global $wpdb;
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . Install::table( 'files' ) . ' WHERE submission_id=%d', (int) $sub_id ), ARRAY_A ) as $r ) { // phpcs:ignore WordPress.DB
			self::purge_row( $r );
		}
	}

	/** ?bd_formfile=ID — only people on the team with Forms access may open a file. */
	public static function maybe_stream() {
		if ( empty( $_GET['bd_formfile'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		if ( ! is_user_logged_in() || ! ( current_user_can( 'manage_options' ) || ( current_user_can( 'manage_wpflatcore' ) && current_user_can( 'wpflatforms_inbox' ) ) ) ) {
			wp_die( 'You do not have access to that file.', 'Not allowed', array( 'response' => 403 ) );
		}
		$r = self::row( absint( $_GET['bd_formfile'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
		$p = $r ? self::dir() . '/' . basename( $r['stored_name'] ) : '';
		if ( ! $r || ! is_readable( $p ) ) {
			wp_die( 'That file no longer exists.', 'Not found', array( 'response' => 404 ) );
		}
		nocache_headers();
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Content-Type: application/octet-stream' );
		header( 'Content-Disposition: attachment; filename="' . str_replace( '"', '', $r['name'] ) . '"' );
		header( 'Content-Length: ' . filesize( $p ) );
		readfile( $p ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		exit;
	}
}
