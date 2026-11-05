<?php
namespace WPFlatForms;

use WPFlatCore\Registry;

defined( 'ABSPATH' ) || exit;

/** WPFlat Forms's connection to WPFlat Core: it describes itself, then hooks into the shared places. */
class Module {

	public static function init() {
		Install::maybe_upgrade();
		add_action( 'wpflatcore_register', array( __CLASS__, 'register' ) );
		add_action( 'wpflatcore_register_routes', array( Routes::class, 'register' ) );
		if ( Registry::enabled( 'form' ) || ! did_action( 'wpflatcore_ready' ) ) {
			Public_Page::init();
			Uploads::init();
			Pdf::init();
		}
	}

	public static function register() {
		wpflatcore_register_module(
			array(
				'id'            => 'form',
				'label'         => 'Forms',
				'version'       => WPFLATFORMS_VERSION,
				'requires_core' => '0.6.0',
				'desc'          => 'Online forms your clients fill in, with sections that repeat for each company, saved drafts and an inbox of answers.',
				'icon'          => 'form',
				'route'         => '/forms',
				'routes'        => array( 'forms' ),
				'cap'           => 'wpflatforms_inbox',
				'caps'          => array( 'wpflatforms_inbox' ),
				'resources'     => array(),
				'tables'        => array_map( function ( $t ) {
					return 'wpflatforms_' . $t;
				}, Install::TABLES ),
				'options'       => array(),
				'file_kinds'    => array( 'forms' ),
				'refs'          => array(
					'wpflatforms_forms'       => array( 'client_id' => 'wpflat_clients' ),
					'wpflatforms_submissions' => array( 'form_id' => 'wpflatforms_forms' ),
					'wpflatforms_files'       => array( 'submission_id' => 'wpflatforms_submissions' ),
				),
				'public_paths'  => array( Public_Page::base() ),
				'public_params' => array( 'bd_resume', 'bd_formfile', 'bd_formpdf' ),
				'front'         => array( 'url' => array( Public_Page::class, 'home_url_for_core' ) ),
				'roles'         => array(
					'wpflat_forms_team' => array( 'label' => 'Forms Team', 'desc' => 'Reads and exports the answers people send through your forms.', 'caps' => array( 'wpflatforms_inbox' ) ),
				),
				'client_manager_caps' => array(),
				'manager_grants'      => array( 'form' => array( 'label' => 'Forms', 'caps' => array( 'wpflatforms_inbox' ) ) ),
				'menu'          => array(
					array( 'label' => 'Forms', 'route' => '/forms' ),
				),
				'assets'        => array( 'script' => 'wpflatforms.js' ),
				'assets_dir'    => WPFLATFORMS_DIR . 'build',
				'wp'            => array( 'dashicons-feedback', 31 ),
			)
		);
	}
}
