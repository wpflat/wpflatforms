<?php
namespace WPFlatForms;

defined( 'ABSPATH' ) || exit;

/** Forms and their submissions: reading, cleaning what people typed against the form's own fields, and turning answers into text. */
class Forms {

	const MAX_BYTES = 400000;

	/** @var array uploaded files of the submission being turned into text */
	private static $files = array();

	public static function slug_url( $slug ) {
		return home_url( '/' . Public_Page::base() . '/' . $slug . '/' );
	}

	public static function by_slug( $slug ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Install::table( 'forms' ) . ' WHERE slug=%s', sanitize_title( $slug ) ), ARRAY_A ); // phpcs:ignore WordPress.DB
		return $row ? self::hydrate( $row ) : null;
	}

	public static function get( $id ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Install::table( 'forms' ) . ' WHERE id=%d', (int) $id ), ARRAY_A ); // phpcs:ignore WordPress.DB
		return $row ? self::hydrate( $row ) : null;
	}

	private static function hydrate( array $row ) {
		$def               = json_decode( (string) $row['definition'], true );
		$row['id']         = (int) $row['id'];
		$row['definition'] = is_array( $def ) ? $def : array( 'sections' => array() );
		$row['client_id']  = (int) $row['client_id'];
		$row['client_name'] = $row['client_id'] ? self::client_name( $row['client_id'] ) : '';
		$row['url']        = self::slug_url( $row['slug'] );
		return $row;
	}

	public static function client_name( $id ) {
		global $wpdb;
		return (string) $wpdb->get_var( $wpdb->prepare( 'SELECT name FROM ' . \WPFlatCore\Database\Schema::table( 'wpflat_clients' ) . ' WHERE id=%d', (int) $id ) ); // phpcs:ignore WordPress.DB
	}

	public static function all( $client_id = 0 ) {
		global $wpdb;
		$out = array();
		$where = $client_id ? $wpdb->prepare( ' WHERE client_id=%d', (int) $client_id ) : '';
		foreach ( (array) $wpdb->get_results( 'SELECT * FROM ' . Install::table( 'forms' ) . $where . ' ORDER BY id', ARRAY_A ) as $row ) { // phpcs:ignore WordPress.DB
			$f           = self::hydrate( $row );
			$f['counts'] = array( 'submitted' => 0, 'draft' => 0 );
			foreach ( (array) $wpdb->get_results( $wpdb->prepare( 'SELECT status, COUNT(*) n FROM ' . Install::table( 'submissions' ) . ' WHERE form_id=%d GROUP BY status', $f['id'] ), ARRAY_A ) as $c ) { // phpcs:ignore WordPress.DB
				$f['counts'][ $c['status'] ] = (int) $c['n'];
			}
			unset( $f['definition'] );
			$out[] = $f;
		}
		return $out;
	}

	public static function notify_emails( array $form ) {
		$list = array();
		foreach ( preg_split( '/[,;\s]+/', (string) $form['notify_email'] ) as $e ) {
			if ( is_email( $e ) ) {
				$list[] = $e;
			}
		}
		return $list ? $list : array( get_option( 'admin_email' ) );
	}

	public static function update_form( $id, array $in ) {
		global $wpdb;
		$set = array( 'updated_at' => current_time( 'mysql' ) );
		if ( isset( $in['status'] ) && in_array( $in['status'], array( 'open', 'closed' ), true ) ) {
			$set['status'] = $in['status'];
		}
		if ( isset( $in['notify_email'] ) ) {
			$set['notify_email'] = mb_substr( sanitize_text_field( (string) $in['notify_email'] ), 0, 500 );
		}
		if ( array_key_exists( 'sender_email', $in ) ) {
			// Emails about this form come from the person's own address only when they may use it (same domain as the email settings).
			$ok = '' !== (string) $in['sender_email'] && class_exists( '\\WPFlatCore\\Mail_Settings' ) && \WPFlatCore\Mail_Settings::may_send_as( (string) $in['sender_email'] );
			$set['sender_email'] = $ok ? strtolower( trim( (string) $in['sender_email'] ) ) : '';
			$set['sender_user']  = $ok ? get_current_user_id() : 0;
		}
		if ( array_key_exists( 'client_id', $in ) ) {
			$cid = (int) $in['client_id'];
			$set['client_id'] = ( $cid && '' !== self::client_name( $cid ) ) ? $cid : 0;
		}
		if ( isset( $in['title'] ) && '' !== trim( (string) $in['title'] ) ) {
			$set['title'] = mb_substr( sanitize_text_field( (string) $in['title'] ), 0, 190 );
		}
		$wpdb->update( Install::table( 'forms' ), $set, array( 'id' => (int) $id ) );
		return self::get( $id );
	}

	/* ───────────── Submissions ───────────── */

	public static function submission( $id ) {
		global $wpdb;
		return self::sub_row( $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Install::table( 'submissions' ) . ' WHERE id=%d', (int) $id ), ARRAY_A ) ); // phpcs:ignore WordPress.DB
	}

	public static function submission_by_token( $token ) {
		global $wpdb;
		if ( ! preg_match( '/^[a-f0-9]{32}$/', (string) $token ) ) {
			return null;
		}
		return self::sub_row( $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Install::table( 'submissions' ) . ' WHERE token=%s', $token ), ARRAY_A ) ); // phpcs:ignore WordPress.DB
	}

	private static function sub_row( $row ) {
		if ( ! $row ) {
			return null;
		}
		$data        = json_decode( (string) $row['data'], true );
		$row['id']   = (int) $row['id'];
		$row['form_id'] = (int) $row['form_id'];
		$row['entities'] = (int) $row['entities'];
		$row['consent_at'] = isset( $row['consent_at'] ) ? $row['consent_at'] : null;
		$row['data'] = is_array( $data ) ? $data : array( 'count' => 0, 'general' => array(), 'entities' => array() );
		return $row;
	}

	public static function submissions( $form_id ) {
		global $wpdb;
		$out  = array();
		$form = self::get( $form_id );
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( 'SELECT id,form_id,token,status,name,email,org,entities,created_at,updated_at,submitted_at FROM ' . Install::table( 'submissions' ) . ' WHERE form_id=%d ORDER BY (status="submitted") DESC, COALESCE(submitted_at,updated_at) DESC', (int) $form_id ), ARRAY_A ) as $r ) { // phpcs:ignore WordPress.DB
			$r['id']         = (int) $r['id'];
			$r['entities']   = (int) $r['entities'];
			$r['resume_url'] = $form ? Mailer::resume_url( $form, $r ) : '';
			unset( $r['token'] );
			$out[]           = $r;
		}
		return $out;
	}

	public static function delete_submission( $id ) {
		global $wpdb;
		Uploads::purge_submission( $id );
		return (bool) $wpdb->delete( Install::table( 'submissions' ), array( 'id' => (int) $id ) );
	}

	/**
	 * Save what a person typed. Only the form's own fields are kept, each cleaned for its type.
	 *
	 * @return array|\WP_Error The stored submission.
	 */
	public static function save( array $form, $token, $raw, $submit, $consent = false ) {
		global $wpdb;
		$def   = $form['definition'];
		$cver  = self::consent_version( $def );
		$clean = self::clean( $def, is_array( $raw ) ? $raw : array() );
		if ( strlen( wp_json_encode( $clean ) ) > self::MAX_BYTES ) {
			return new \WP_Error( 'wpflatforms_big', 'That is more text than we can save. Please shorten some answers.', array( 'status' => 413 ) );
		}
		if ( $submit ) {
			$missing = self::missing( $def, $clean );
			if ( $missing ) {
				return new \WP_Error( 'wpflatforms_required', 'Please complete: ' . implode( ', ', array_slice( $missing, 0, 6 ) ) . ( count( $missing ) > 6 ? '…' : '' ), array( 'status' => 422, 'missing' => $missing ) );
			}
		}
		$g     = $clean['general'];
		$name  = isset( $g['name'] ) ? (string) $g['name'] : '';
		$email = isset( $g['email'] ) && is_email( $g['email'] ) ? (string) $g['email'] : '';
		$orgs  = isset( $g['companies'][0]['name'] ) ? (string) $g['companies'][0]['name'] : '';
		$now   = current_time( 'mysql' );
		$cur   = $token ? self::submission_by_token( $token ) : null;
		if ( $cur && $cur['form_id'] !== $form['id'] ) {
			$cur = null;
		}
		if ( '' !== $cver && ! $consent && ! ( $cur && $cur['consent_at'] ) ) {
			return new \WP_Error( 'wpflatforms_consent', 'Please agree to the information and consent page first.', array( 'status' => 422 ) );
		}
		$row = array(
			'name'       => mb_substr( $name, 0, 190 ),
			'email'      => mb_substr( $email, 0, 190 ),
			'org'        => mb_substr( $orgs, 0, 190 ),
			'entities'   => count( $clean['entities'] ),
			'data'       => wp_json_encode( $clean ),
			'updated_at' => $now,
		);
		if ( '' !== $cver && $consent && ! ( $cur && $cur['consent_at'] ) ) {
			$row['consent_at']  = $now;
			$row['consent_ver'] = $cver;
		}
		$first_email = false;
		if ( $cur ) {
			if ( $submit ) {
				$row['status'] = 'submitted';
				$row['submitted_at'] = $now;
			}
			$first_email = '' === $cur['email'] && '' !== $email;
			$wpdb->update( Install::table( 'submissions' ), $row, array( 'id' => $cur['id'] ) );
			$id = $cur['id'];
		} else {
			$row += array( 'form_id' => $form['id'], 'token' => bin2hex( random_bytes( 16 ) ), 'status' => $submit ? 'submitted' : 'draft', 'created_at' => $now, 'submitted_at' => $submit ? $now : null );
			$wpdb->insert( Install::table( 'submissions' ), $row );
			$id          = (int) $wpdb->insert_id;
			$first_email = '' !== $email;
		}
		$sub = self::submission( $id );
		if ( $submit ) {
			Mailer::submitted( $form, $sub );
		} elseif ( $first_email ) {
			Mailer::resume_link( $form, $sub );
		}
		return $sub;
	}

	/** A short fingerprint of the consent wording, kept with each agreement so we know which wording was agreed to. '' when the form has no consent step. */
	public static function consent_version( array $def ) {
		return ( isset( $def['cover']['consent'] ) && '' !== $def['cover']['consent'] ) ? substr( md5( $def['cover']['consent'] ), 0, 8 ) : '';
	}

	/** Keep only known fields, cleaned for their type. Repeating sections are kept once per company. */
	public static function clean( array $def, array $raw ) {
		$max   = isset( $def['group']['max'] ) ? (int) $def['group']['max'] : 0;
		$count = isset( $raw['count'] ) ? max( 0, min( $max, (int) $raw['count'] ) ) : 0;
		$out   = array( 'count' => $count, 'general' => array(), 'entities' => array() );
		$gen_in = isset( $raw['general'] ) && is_array( $raw['general'] ) ? $raw['general'] : array();
		$ent_in = isset( $raw['entities'] ) && is_array( $raw['entities'] ) ? array_values( $raw['entities'] ) : array();
		$tbl    = isset( $def['group']['table'] ) ? $def['group']['table'] : '';
		for ( $i = 0; $i <= $count; $i++ ) {
			$out['entities'][ $i ] = array();
		}
		foreach ( (array) $def['sections'] as $s ) {
			foreach ( (array) $s['fields'] as $f ) {
				if ( empty( $f['id'] ) ) {
					continue;
				}
				if ( ! empty( $s['repeat'] ) ) {
					for ( $i = 0; $i <= $count; $i++ ) {
						$src = isset( $ent_in[ $i ] ) && is_array( $ent_in[ $i ] ) ? $ent_in[ $i ] : array();
						if ( isset( $src[ $f['id'] ] ) ) {
							$v = self::clean_value( $f, $src[ $f['id'] ] );
							if ( null !== $v ) {
								$out['entities'][ $i ][ $f['id'] ] = $v;
							}
						}
					}
				} elseif ( 'count' === $f['type'] ) {
					$out['general'][ $f['id'] ] = $count;
				} elseif ( 'companies' === $f['type'] ) {
					$rows = isset( $gen_in[ $f['id'] ] ) && is_array( $gen_in[ $f['id'] ] ) ? array_values( $gen_in[ $f['id'] ] ) : array();
					$list = array();
					for ( $i = 0; $i <= $count; $i++ ) {
						$r   = isset( $rows[ $i ] ) && is_array( $rows[ $i ] ) ? $rows[ $i ] : array();
						$row = array();
						foreach ( (array) $f['columns'] as $col ) {
							$row[ $col['id'] ] = isset( $r[ $col['id'] ] ) ? mb_substr( sanitize_textarea_field( (string) $r[ $col['id'] ] ), 0, 500 ) : '';
						}
						$list[] = $row;
					}
					$out['general'][ $f['id'] ] = $list;
				} elseif ( isset( $gen_in[ $f['id'] ] ) ) {
					$v = self::clean_value( $f, $gen_in[ $f['id'] ] );
					if ( null !== $v ) {
						$out['general'][ $f['id'] ] = $v;
					}
				}
			}
		}
		unset( $tbl );
		return $out;
	}

	private static function clean_value( array $f, $v ) {
		switch ( $f['type'] ) {
			case 'heading':
			case 'note':
			case 'files':
				return null;
			case 'email':
				$e = sanitize_email( (string) $v );
				return is_email( $e ) ? $e : '';
			case 'url':
				return mb_substr( esc_url_raw( (string) $v ), 0, 500 );
			case 'number':
				return is_numeric( $v ) ? (string) ( 0 + $v ) : '';
			case 'tel':
			case 'text':
				return mb_substr( sanitize_text_field( (string) $v ), 0, 500 );
			case 'select':
			case 'radio':
				return in_array( (string) $v, (array) $f['options'], true ) ? (string) $v : '';
			case 'checkboxes':
				return array_values( array_intersect( (array) $f['options'], is_array( $v ) ? array_map( 'strval', $v ) : array() ) );
			default: // textarea
				return mb_substr( sanitize_textarea_field( (string) $v ), 0, 20000 );
		}
	}

	/** Labels of the required fields that are empty. */
	public static function missing( array $def, array $data ) {
		$out = array();
		foreach ( (array) $def['sections'] as $s ) {
			foreach ( (array) $s['fields'] as $f ) {
				if ( 'companies' === $f['type'] ) {
					foreach ( (array) $data['general'][ $f['id'] ] as $i => $row ) {
						foreach ( (array) $f['columns'] as $col ) {
							if ( ! empty( $col['required'] ) && '' === trim( (string) $row[ $col['id'] ] ) ) {
								$out[] = ( 0 === $i ? 'Parent company' : 'Subsidiary ' . $i ) . ': ' . $col['label'];
							}
						}
					}
				} elseif ( ! empty( $f['required'] ) && empty( $s['repeat'] ) ) {
					$v = isset( $data['general'][ $f['id'] ] ) ? $data['general'][ $f['id'] ] : '';
					if ( '' === $v || array() === $v || null === $v ) {
						$out[] = $f['label'];
					}
				}
			}
		}
		return $out;
	}

	/* ───────────── Entities ───────────── */

	/** Name shown for company i: what was typed in the companies table, or the template's main-company label / "Branch or company 2". */
	public static function entity_label( array $def, array $data, $i ) {
		$g   = isset( $def['group'] ) ? $def['group'] : array();
		$tbl = isset( $g['table'] ) ? $g['table'] : '';
		$n   = isset( $data['general'][ $tbl ][ $i ]['name'] ) ? trim( (string) $data['general'][ $tbl ][ $i ]['name'] ) : '';
		if ( '' !== $n ) {
			return $n;
		}
		return 0 === $i ? ( isset( $g['parent'] ) ? $g['parent'] : 'Main company' ) : ( ( isset( $g['sub'] ) ? $g['sub'] : 'Subsidiary' ) . ' ' . $i );
	}

	/* ───────────── Text and CSV ───────────── */

	private static function value_text( array $f, $v ) {
		if ( is_array( $v ) ) {
			return implode( ', ', $v );
		}
		return trim( (string) $v );
	}

	/** The whole submission as Markdown, in the order of the form. */
	public static function markdown( array $form, array $sub ) {
		$def  = $form['definition'];
		$data = $sub['data'];
		self::$files = Uploads::for_submission( $sub['id'] );
		$md   = '# ' . $form['title'] . "\n\n";
		$md  .= 'From: ' . ( '' !== $sub['name'] ? $sub['name'] : 'unknown' ) . ( '' !== $sub['email'] ? ' <' . $sub['email'] . '>' : '' ) . "\n";
		$md  .= 'Status: ' . $sub['status'] . ( $sub['submitted_at'] ? ' on ' . $sub['submitted_at'] : '' ) . "\n\n";
		foreach ( (array) $def['sections'] as $s ) {
			$md .= '## ' . $s['title'] . "\n\n";
			if ( ! empty( $s['repeat'] ) ) {
				for ( $i = 0; $i <= (int) $data['count']; $i++ ) {
					$md .= '### ' . self::entity_label( $def, $data, $i ) . "\n\n";
					$md .= self::fields_md( $s['fields'], isset( $data['entities'][ $i ] ) ? $data['entities'][ $i ] : array(), 4, $i );
				}
			} else {
				$md .= self::fields_md( $s['fields'], $data['general'], 3, 0 );
			}
		}
		return $md;
	}

	private static function fields_md( array $fields, array $vals, $level, $entity = 0 ) {
		$md = '';
		foreach ( $fields as $f ) {
			if ( 'files' === $f['type'] ) {
				$names = array();
				foreach ( self::$files as $fl ) {
					if ( $fl['field'] === $f['id'] && (int) $fl['entity'] === (int) $entity ) {
						$names[] = $fl['name'];
					}
				}
				$md .= '**' . $f['label'] . "**\n\n" . ( $names ? '- ' . implode( "\n- ", $names ) : '_No files_' ) . "\n\n";
				continue;
			}
			if ( 'heading' === $f['type'] ) {
				$md .= str_repeat( '#', $level ) . ' ' . $f['label'] . "\n\n";
				continue;
			}
			if ( 'note' === $f['type'] ) {
				continue;
			}
			if ( 'companies' === $f['type'] ) {
				$md .= '**' . $f['label'] . "**\n\n";
				$cols = (array) $f['columns'];
				$md  .= '| ' . implode( ' | ', wp_list_pluck( $cols, 'label' ) ) . " |\n| " . implode( ' | ', array_fill( 0, count( $cols ), '---' ) ) . " |\n";
				foreach ( (array) ( isset( $vals[ $f['id'] ] ) ? $vals[ $f['id'] ] : array() ) as $row ) {
					$cells = array();
					foreach ( $cols as $c ) {
						$cells[] = str_replace( array( '|', "\n" ), array( '/', ' ' ), isset( $row[ $c['id'] ] ) ? (string) $row[ $c['id'] ] : '' );
					}
					$md .= '| ' . implode( ' | ', $cells ) . " |\n";
				}
				$md .= "\n";
				continue;
			}
			$v   = isset( $vals[ $f['id'] ] ) ? self::value_text( $f, $vals[ $f['id'] ] ) : '';
			$md .= '**' . ( ! empty( $f['n'] ) ? $f['n'] . '. ' : '' ) . $f['label'] . "**\n\n" . ( '' !== $v ? $v : '_No answer_' ) . "\n\n";
		}
		return $md;
	}

	/** One row per company: the person and group answers repeated on each row, then that company's own answers. */
	public static function csv( array $form ) {
		$def     = $form['definition'];
		$general = array();
		$repeat  = array();
		foreach ( (array) $def['sections'] as $s ) {
			foreach ( (array) $s['fields'] as $f ) {
				if ( empty( $f['id'] ) || in_array( $f['type'], array( 'heading', 'note', 'companies', 'count', 'files' ), true ) ) {
					continue;
				}
				if ( ! empty( $s['repeat'] ) ) {
					$repeat[] = $f;
				} else {
					$general[] = $f;
				}
			}
		}
		$head = array( 'Submission', 'Status', 'Submitted', 'Company' );
		foreach ( $general as $f ) {
			$head[] = ( ! empty( $f['n'] ) ? 'G' . $f['n'] . ' ' : '' ) . $f['label'];
		}
		foreach ( $repeat as $f ) {
			$head[] = ( ! empty( $f['n'] ) && 'f_send' !== $f['id'] ? 'Q' . $f['n'] . ' ' : '' ) . $f['label'];
		}
		$rows = array( $head );
		foreach ( self::submissions( $form['id'] ) as $s ) {
			$sub  = self::submission( $s['id'] );
			$data = $sub['data'];
			for ( $i = 0; $i <= (int) $data['count']; $i++ ) {
				$r = array( $sub['id'], $sub['status'], (string) $sub['submitted_at'], self::entity_label( $def, $data, $i ) );
				foreach ( $general as $f ) {
					$r[] = isset( $data['general'][ $f['id'] ] ) ? self::value_text( $f, $data['general'][ $f['id'] ] ) : '';
				}
				foreach ( $repeat as $f ) {
					$r[] = isset( $data['entities'][ $i ][ $f['id'] ] ) ? self::value_text( $f, $data['entities'][ $i ][ $f['id'] ] ) : '';
				}
				$rows[] = $r;
			}
		}
		$fh = fopen( 'php://temp', 'r+' );
		fwrite( $fh, "\xEF\xBB\xBF" );
		foreach ( $rows as $r ) {
			fputcsv( $fh, array_map( function ( $c ) {
				$c = (string) $c;
				return preg_match( '/^[=+\-@\t\r]/', $c ) ? "'" . $c : $c; // spreadsheets run text that starts with these as formulas
			}, $r ) );
		}
		rewind( $fh );
		$csv = stream_get_contents( $fh );
		fclose( $fh );
		return $csv;
	}
}
