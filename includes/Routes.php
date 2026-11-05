<?php
namespace WPFlatForms;

use WPFlatCore\Rest\Resource_Controller;
use WPFlatCore\Rest\Routes as Core_Routes;

defined( 'ABSPATH' ) || exit;

/** WPFlat Forms's REST routes (in WPFlat Core's wpflatcore/v1 namespace, gone when the module is off). */
class Routes {

	private static function reg( $route, $args ) {
		Core_Routes::add( 'form', $route, $args );
	}

	private static function nf() {
		return new \WP_Error( 'wpflatforms_not_found', 'That form or answer does not exist.', array( 'status' => 404 ) );
	}

	public static function register() {
		$perm = array( Resource_Controller::class, 'can' );

		// People filling in a form are not signed in: the form's address and their private link are what let them in, and every call is rate limited.
		self::reg( '/form/public/(?P<slug>[a-z0-9-]+)/save', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'public_save' ), 'permission_callback' => '__return_true' ) );

		self::reg( '/form/public/(?P<slug>[a-z0-9-]+)/upload', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'public_upload' ), 'permission_callback' => '__return_true' ) );
		self::reg( '/form/public/(?P<slug>[a-z0-9-]+)/file/(?P<id>\d+)', array( 'methods' => 'DELETE', 'callback' => array( __CLASS__, 'public_file_delete' ), 'permission_callback' => '__return_true' ) );

		self::reg( '/forms', array( 'methods' => 'GET', 'callback' => function ( $r ) { return rest_ensure_response( Forms::all( (int) $r->get_param( 'client_id' ) ) ); }, 'permission_callback' => $perm ) );
		self::reg( '/forms/(?P<id>\d+)', array( 'methods' => 'POST', 'callback' => function ( $r ) {
			$f = Forms::get( (int) $r['id'] );
			if ( ! $f ) {
				return self::nf();
			}
			$u = Forms::update_form( $f['id'], (array) $r->get_json_params() );
			unset( $u['definition'] );
			return rest_ensure_response( $u );
		}, 'permission_callback' => $perm ) );
		self::reg( '/forms/(?P<id>\d+)/submissions', array( 'methods' => 'GET', 'callback' => function ( $r ) {
			return Forms::get( (int) $r['id'] ) ? rest_ensure_response( Forms::submissions( (int) $r['id'] ) ) : self::nf();
		}, 'permission_callback' => $perm ) );
		self::reg( '/forms/(?P<id>\d+)/demo', array( 'methods' => 'POST', 'callback' => function ( $r ) {
			$f = Forms::get( (int) $r['id'] );
			if ( ! $f ) {
				return self::nf();
			}
			$id = Demo::load( $f );
			return is_wp_error( $id ) ? $id : rest_ensure_response( array( 'id' => $id ) );
		}, 'permission_callback' => $perm ) );
		self::reg( '/forms/(?P<id>\d+)/export', array( 'methods' => 'GET', 'callback' => function ( $r ) {
			$f = Forms::get( (int) $r['id'] );
			return $f ? rest_ensure_response( array( 'filename' => $f['slug'] . '-answers.csv', 'csv' => Forms::csv( $f ) ) ) : self::nf();
		}, 'permission_callback' => $perm ) );
		self::reg( '/form-submissions/(?P<id>\d+)', array(
			array( 'methods' => 'GET', 'callback' => function ( $r ) {
				$s = Forms::submission( (int) $r['id'] );
				$f = $s ? Forms::get( $s['form_id'] ) : null;
				if ( ! $s || ! $f ) {
					return self::nf();
				}
				$resume = Mailer::resume_url( $f, $s );
				unset( $s['token'] );
				return rest_ensure_response( array( 'submission' => $s, 'resume_url' => $resume, 'pdf_url' => Pdf::staff_url( $s['id'] ), 'files' => Uploads::for_submission( $s['id'] ), 'client_name' => $f['client_name'], 'definition' => $f['definition'], 'title' => $f['title'], 'markdown' => Forms::markdown( $f, Forms::submission( $s['id'] ) ) ) );
			}, 'permission_callback' => $perm ),
			array( 'methods' => 'DELETE', 'callback' => function ( $r ) {
				return Forms::delete_submission( (int) $r['id'] ) ? rest_ensure_response( array( 'deleted' => true ) ) : self::nf();
			}, 'permission_callback' => $perm ),
		) );
	}

	/** Allow N calls per window from one address. */
	private static function limited( $bucket, $max, $window ) {
		$ip  = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$key = 'wpflatforms_rl_' . $bucket . '_' . md5( $ip );
		$n   = (int) get_transient( $key );
		if ( $n >= $max ) {
			return true;
		}
		set_transient( $key, $n + 1, $window );
		return false;
	}

	public static function public_save( \WP_REST_Request $r ) {
		$form = Forms::by_slug( (string) $r['slug'] );
		if ( ! $form || 'open' !== $form['status'] ) {
			return new \WP_Error( 'wpflatforms_closed', 'This form is not taking answers.', array( 'status' => 404 ) );
		}
		$p = (array) $r->get_json_params();
		if ( ! empty( $p['hp'] ) ) { // a hidden field only a bot fills in: look successful, save nothing
			return rest_ensure_response( array( 'token' => str_repeat( '0', 32 ), 'status' => 'draft' ) );
		}
		$submit = ! empty( $p['submit'] );
		if ( self::limited( 'save', 240, 15 * MINUTE_IN_SECONDS ) || ( $submit && self::limited( 'submit', 12, HOUR_IN_SECONDS ) ) ) {
			return new \WP_Error( 'wpflatforms_rate', 'Too many requests from this connection. Please wait a few minutes and try again.', array( 'status' => 429 ) );
		}
		$token = isset( $p['token'] ) ? (string) $p['token'] : '';
		$sub   = Forms::save( $form, $token, isset( $p['data'] ) ? $p['data'] : array(), $submit, ! empty( $p['consent'] ) );
		if ( is_wp_error( $sub ) ) {
			return $sub;
		}
		return rest_ensure_response( array(
			'token'      => $sub['token'],
			'status'     => $sub['status'],
			'resume_url' => Mailer::resume_url( $form, $sub ),
			'saved_at'   => $sub['updated_at'],
			'reference'  => 'F-' . $sub['id'],
			'pdf_url'    => Pdf::token_url( $form, $sub ),
		) );
	}

	/** The form and the person's draft, from the address and the private token in the request. */
	private static function context( \WP_REST_Request $r, $token ) {
		$form = Forms::by_slug( (string) $r['slug'] );
		if ( ! $form || 'open' !== $form['status'] ) {
			return new \WP_Error( 'wpflatforms_closed', 'This form is not taking answers.', array( 'status' => 404 ) );
		}
		$sub = Forms::submission_by_token( (string) $token );
		if ( ! $sub || $sub['form_id'] !== $form['id'] ) {
			return new \WP_Error( 'wpflatforms_token', 'Save your answers first, then add files.', array( 'status' => 400 ) );
		}
		return array( $form, $sub );
	}

	public static function public_upload( \WP_REST_Request $r ) {
		if ( self::limited( 'upload', 80, 15 * MINUTE_IN_SECONDS ) ) {
			return new \WP_Error( 'wpflatforms_rate', 'Too many uploads from this connection. Please wait a few minutes.', array( 'status' => 429 ) );
		}
		$ctx = self::context( $r, (string) $r->get_param( 'token' ) );
		if ( is_wp_error( $ctx ) ) {
			return $ctx;
		}
		$files = $r->get_file_params();
		if ( empty( $files['file'] ) ) {
			return new \WP_Error( 'wpflatforms_upload', 'No file was sent.', array( 'status' => 400 ) );
		}
		$row = Uploads::add( $ctx[0], $ctx[1], (int) $r->get_param( 'entity' ), sanitize_key( (string) $r->get_param( 'field' ) ), $files['file'] );
		return is_wp_error( $row ) ? $row : rest_ensure_response( $row );
	}

	public static function public_file_delete( \WP_REST_Request $r ) {
		$ctx = self::context( $r, (string) $r->get_param( 'token' ) );
		if ( is_wp_error( $ctx ) ) {
			return $ctx;
		}
		return Uploads::remove( $ctx[1], (int) $r['id'] ) ? rest_ensure_response( array( 'deleted' => true ) ) : self::nf();
	}
}
