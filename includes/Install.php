<?php
namespace WPFlatForms;

defined( 'ABSPATH' ) || exit;

/** WPFlat Forms's tables. */
class Install {

	const DB_VERSION = '4';
	const OPTION     = 'wpflatforms_db_version';
	const TABLES     = array( 'forms', 'submissions', 'files' );

	public static function table( $name ) {
		global $wpdb;
		return $wpdb->prefix . 'wpflatforms_' . $name;
	}

	/** Cheap check on every request so an updated plugin upgrades itself. */
	public static function maybe_upgrade() {
		if ( get_option( self::OPTION ) !== self::DB_VERSION ) {
			self::install_tables();
		}
		if ( ! get_option( 'wpflatforms_seeded' ) ) {
			self::seed();
		}
	}

	public static function install_tables() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$c = $wpdb->get_charset_collate();

		dbDelta( 'CREATE TABLE ' . self::table( 'forms' ) . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  slug varchar(120) NOT NULL DEFAULT '',
  title varchar(190) NOT NULL DEFAULT '',
  definition longtext,
  status varchar(10) NOT NULL DEFAULT 'open',
  notify_email varchar(500) NOT NULL DEFAULT '',
  sender_email varchar(190) NOT NULL DEFAULT '',
  sender_user bigint(20) unsigned NOT NULL DEFAULT 0,
  client_id bigint(20) unsigned NOT NULL DEFAULT 0,
  created_at datetime DEFAULT NULL,
  updated_at datetime DEFAULT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY slug (slug)
) $c;" );

		dbDelta( 'CREATE TABLE ' . self::table( 'submissions' ) . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  form_id bigint(20) unsigned NOT NULL DEFAULT 0,
  token char(32) NOT NULL DEFAULT '',
  status varchar(10) NOT NULL DEFAULT 'draft',
  name varchar(190) NOT NULL DEFAULT '',
  email varchar(190) NOT NULL DEFAULT '',
  org varchar(190) NOT NULL DEFAULT '',
  entities int(11) NOT NULL DEFAULT 1,
  consent_at datetime DEFAULT NULL,
  consent_ver varchar(16) NOT NULL DEFAULT '',
  data longtext,
  created_at datetime DEFAULT NULL,
  updated_at datetime DEFAULT NULL,
  submitted_at datetime DEFAULT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY token (token),
  KEY form_id (form_id),
  KEY status (status)
) $c;" );

		dbDelta( 'CREATE TABLE ' . self::table( 'files' ) . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  submission_id bigint(20) unsigned NOT NULL DEFAULT 0,
  entity int(11) NOT NULL DEFAULT 0,
  field varchar(60) NOT NULL DEFAULT '',
  name varchar(190) NOT NULL DEFAULT '',
  stored_name varchar(80) NOT NULL DEFAULT '',
  mime varchar(120) NOT NULL DEFAULT '',
  size bigint(20) unsigned NOT NULL DEFAULT 0,
  created_at datetime DEFAULT NULL,
  PRIMARY KEY  (id),
  KEY submission_id (submission_id)
) $c;" );

		update_option( self::OPTION, self::DB_VERSION, false );
	}

	/** The ready-made forms (see Templates), added once (deleting one later does not bring it back). */
	public static function seed() {
		global $wpdb;
		$now = current_time( 'mysql' );
		foreach ( Templates::all() as $slug => $def ) {
			$wpdb->query( $wpdb->prepare( 'INSERT IGNORE INTO ' . self::table( 'forms' ) . ' (slug,title,definition,status,notify_email,created_at,updated_at) VALUES (%s,%s,%s,%s,%s,%s,%s)', $slug, $def['title'], wp_json_encode( $def ), 'open', '', $now, $now ) ); // phpcs:ignore WordPress.DB
		}
		update_option( 'wpflatforms_seeded', 1, false );
	}
}
