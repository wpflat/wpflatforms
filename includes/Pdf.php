<?php
namespace WPFlatForms;

defined( 'ABSPATH' ) || exit;

/** The PDF of a form's answers. Made on the fly from the saved answers with a bundled dompdf, never stored. */
class Pdf {

	public static function init() {
		add_action( 'template_redirect', array( __CLASS__, 'maybe_serve' ), 1 );
	}

	public static function staff_url( $submission_id ) {
		return home_url( '/?bd_formpdf=' . (int) $submission_id );
	}

	public static function token_url( array $form, array $sub ) {
		return home_url( '/?bd_formpdf=' . $sub['token'] );
	}

	/** ?bd_formpdf=ID for the team (signed in, Forms access) or ?bd_formpdf=<private token> for the person who filled it in. */
	public static function maybe_serve() {
		if ( empty( $_GET['bd_formpdf'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		$key = sanitize_text_field( wp_unslash( $_GET['bd_formpdf'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
		if ( preg_match( '/^[a-f0-9]{32}$/', $key ) ) {
			$sub = Forms::submission_by_token( $key );
		} else {
			if ( ! is_user_logged_in() || ! ( current_user_can( 'manage_options' ) || ( current_user_can( 'manage_wpflatcore' ) && current_user_can( 'wpflatforms_inbox' ) ) ) ) {
				wp_die( 'You do not have access to that.', 'Not allowed', array( 'response' => 403 ) );
			}
			$sub = Forms::submission( absint( $key ) );
		}
		$form = $sub ? Forms::get( $sub['form_id'] ) : null;
		if ( ! $form ) {
			wp_die( 'That form could not be found.', 'Not found', array( 'response' => 404 ) );
		}
		try {
			$bytes = self::render( $form, $sub );
		} catch ( \Throwable $e ) {
			wp_die( 'The PDF could not be made: ' . esc_html( $e->getMessage() ), 'PDF error', array( 'response' => 500 ) );
		}
		nocache_headers();
		header( 'X-Robots-Tag: noindex, nofollow' );
		header( 'Content-Type: application/pdf' );
		header( 'Content-Disposition: inline; filename="' . self::filename( $form, $sub ) . '"' );
		header( 'Content-Length: ' . strlen( $bytes ) );
		echo $bytes; // phpcs:ignore WordPress.Security.EscapeOutput
		exit;
	}

	public static function filename( array $form, array $sub ) {
		return sanitize_file_name( $form['title'] . ' - answers F-' . $sub['id'] ) . '.pdf';
	}

	/** For an email attachment: a temporary file the caller deletes. '' if the PDF could not be made. */
	public static function to_temp_file( array $form, array $sub ) {
		try {
			$bytes = self::render( $form, $sub );
			$path  = wp_tempnam( 'wpflatforms-' . $sub['id'] . '.pdf' );
			$new   = preg_replace( '/\.tmp$/', '', $path ) . '-' . sanitize_file_name( $form['slug'] ) . '.pdf';
			return ( $path && file_put_contents( $path, $bytes ) && rename( $path, $new ) ) ? $new : '';
		} catch ( \Throwable $e ) {
			return '';
		}
	}

	/** The business logo as a data URI the PDF engine can use ('' when there is none). The email logo is preferred: it is always a raster image. */
	private static function logo() {
		$s = \WPFlatCore\Settings::get();
		foreach ( array( 'business_logo_email_url', 'business_logo_url' ) as $k ) {
			if ( empty( $s[ $k ] ) ) {
				continue;
			}
			$url = (string) $s[ $k ];
			$u   = wp_upload_dir();
			if ( 0 === strpos( $url, \WPFlatCore\Media::base_url() ) ) {
				$file = \WPFlatCore\Media::path_of( rawurldecode( substr( $url, strlen( \WPFlatCore\Media::base_url() ) ) ) );
			} elseif ( 0 === strpos( $url, $u['baseurl'] ) ) {
				$file = \WPFlatCore\Media::resolve_upload( rawurldecode( ltrim( substr( $url, strlen( $u['baseurl'] ) ), '/' ) ) );
			} else {
				continue;
			}
			$file = wp_normalize_path( (string) $file );
			if ( '' === $file || false !== strpos( $file, '..' ) || ! is_readable( $file ) || filesize( $file ) > 6291456 ) {
				continue;
			}
			$mime = array( 'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif', 'svg' => 'image/svg+xml' );
			$ext  = strtolower( pathinfo( $file, PATHINFO_EXTENSION ) );
			if ( isset( $mime[ $ext ] ) ) {
				return 'data:' . $mime[ $ext ] . ';base64,' . base64_encode( file_get_contents( $file ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			}
		}
		return '';
	}

	private static function e( $s ) {
		return esc_html( (string) $s );
	}

	private static function para( $s ) {
		return nl2br( esc_html( (string) $s ) );
	}

	public static function render( array $form, array $sub ) {
		if ( ! class_exists( '\Dompdf\Dompdf', false ) ) {
			require_once WPFLATFORMS_DIR . 'lib/dompdf/autoload.inc.php';
		}
		if ( function_exists( 'wp_raise_memory_limit' ) ) {
			wp_raise_memory_limit( 'admin' );
		}
		@set_time_limit( 90 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors

		$work = \WPFlatCore\Paths::dir( 'forms' ) . '/.pdf';
		wp_mkdir_p( $work );
		$opt = new \Dompdf\Options();
		$opt->set( 'fontDir', $work );
		$opt->set( 'fontCache', $work );
		$opt->set( 'tempDir', $work );
		$opt->set( 'chroot', array( WPFLATFORMS_DIR, $work ) );
		$opt->set( 'isRemoteEnabled', false );
		$opt->set( 'isPhpEnabled', false );
		$opt->set( 'isJavascriptEnabled', false );
		$opt->set( 'defaultFont', 'Inter' );
		$opt->set( 'dpi', 96 );
		$opt->set( 'isFontSubsettingEnabled', true );
		$pdf = new \Dompdf\Dompdf( $opt );
		self::register_fonts( $pdf );
		$pdf->loadHtml( self::html( $form, $sub ), 'UTF-8' );
		$pdf->setPaper( 'A4', 'portrait' );
		$pdf->render();

		$canvas = $pdf->getCanvas();
		$fm     = $pdf->getFontMetrics();
		$font   = $fm->getFont( 'Inter', 'normal' );
		$w      = $canvas->get_width();
		$h      = $canvas->get_height();
		$parties = isset( $form['definition']['cover']['parties'] ) ? (array) $form['definition']['cover']['parties'] : array();
		$left    = implode( '   ·   ', array_map( function ( $p ) {
			return $p['name'] . ', ' . $p['detail'];
		}, $parties ) );
		$grey = array( 0.53, 0.56, 0.62 );
		$canvas->page_text( 51, $h - 38, '' !== $left ? $left : $form['title'], $font, 7.5, $grey );
		$canvas->page_text( $w - 51 - $fm->getTextWidth( 'Page 00 of 00', $font, 7.5 ), $h - 38, 'Page {PAGE_NUM} of {PAGE_COUNT}', $font, 7.5, $grey );
		$pdf->addInfo( 'Title', $form['title'] . ' - answers F-' . $sub['id'] );
		$pdf->addInfo( 'Author', (string) \WPFlatCore\Settings::get( 'business_name' ) );
		$pdf->addInfo( 'Creator', 'WPFlat Forms' );
		return $pdf->output();
	}

	private static function register_fonts( \Dompdf\Dompdf $pdf ) {
		$fm  = $pdf->getFontMetrics();
		$dir = WPFLATCORE_DIR . 'assets/pdf-fonts/';
		$fam = $fm->getFamily( 'inter' );
		if ( is_array( $fam ) && count( $fam ) >= 4 ) {
			return;
		}
		foreach ( array( array( 'normal', 'normal', 'Inter-Regular.ttf' ), array( 'normal', 'bold', 'Inter-Bold.ttf' ), array( 'italic', 'normal', 'Inter-Italic.ttf' ), array( 'italic', 'bold', 'Inter-BoldItalic.ttf' ) ) as $m ) {
			$fm->registerFont( array( 'family' => 'Inter', 'style' => $m[0], 'weight' => $m[1] ), $dir . $m[2] );
		}
	}

	/* ───────────── HTML ───────────── */

	private static function fields_html( array $fields, array $vals, array $files, $entity ) {
		$h = '';
		foreach ( $fields as $f ) {
			$type = $f['type'];
			if ( 'heading' === $type ) {
				$h .= '<h3>' . self::e( $f['label'] ) . '</h3>';
			} elseif ( 'note' === $type ) {
				$h .= '<p class="muted">' . self::e( $f['label'] ) . '</p>';
			} elseif ( 'companies' === $type ) {
				$cols = (array) $f['columns'];
				$h   .= '<div class="q"><div class="l">' . self::e( $f['label'] ) . '</div><table class="grid"><tr>';
				foreach ( $cols as $c ) {
					$h .= '<th>' . self::e( $c['label'] ) . '</th>';
				}
				$h .= '</tr>';
				foreach ( (array) ( isset( $vals[ $f['id'] ] ) ? $vals[ $f['id'] ] : array() ) as $row ) {
					$h .= '<tr>';
					foreach ( $cols as $c ) {
						$v  = isset( $row[ $c['id'] ] ) ? trim( (string) $row[ $c['id'] ] ) : '';
						$h .= '<td>' . ( '' !== $v ? self::e( $v ) : '<span class="muted">—</span>' ) . '</td>';
					}
					$h .= '</tr>';
				}
				$h .= '</table></div>';
			} elseif ( 'files' === $type ) {
				$list = array();
				foreach ( $files as $fl ) {
					if ( $fl['field'] === $f['id'] && (int) $fl['entity'] === (int) $entity ) {
						$list[] = self::e( $fl['name'] ) . ' <span class="muted">(' . size_format( $fl['size'] ) . ')</span>';
					}
				}
				$h .= '<div class="q"><div class="l">' . self::e( $f['label'] ) . '</div>' . ( $list ? '<ul><li>' . implode( '</li><li>', $list ) . '</li></ul>' : '<div class="muted">No files uploaded</div>' ) . '</div>';
			} elseif ( ! empty( $f['id'] ) ) {
				$v = isset( $vals[ $f['id'] ] ) ? $vals[ $f['id'] ] : '';
				$v = is_array( $v ) ? implode( ', ', $v ) : trim( (string) $v );
				$h .= '<div class="q"><div class="l">' . ( ! empty( $f['n'] ) ? self::e( $f['n'] ) . '. ' : '' ) . self::e( $f['label'] ) . '</div>' . ( '' !== $v ? '<div class="a">' . self::para( $v ) . '</div>' : '<div class="muted">No answer</div>' ) . '</div>';
			}
		}
		return $h;
	}

	private static function html( array $form, array $sub ) {
		$def   = $form['definition'];
		$data  = $sub['data'];
		$files = Uploads::for_submission( $sub['id'] );
		$biz   = (string) \WPFlatCore\Settings::get( 'business_name' );
		$cover = isset( $def['cover'] ) ? $def['cover'] : array();
		$for   = '' !== (string) $form['client_name'] ? $form['client_name'] : ( isset( $cover['for'] ) ? $cover['for'] : '' );
		$state = 'submitted' === $sub['status'] ? 'Submitted' . ( $sub['submitted_at'] ? ' on ' . gmdate( 'j F Y, H:i', strtotime( $sub['submitted_at'] ) ) : '' ) : 'In progress (not yet submitted)';

		$css = 'body{font-family:Inter,DejaVu Sans,sans-serif;font-size:9pt;line-height:1.4;color:#1f2430;margin:0}
h1{font-size:21pt;margin:0 0 3pt;color:#0e121b;letter-spacing:-.02em}h2{font-size:12.5pt;margin:15pt 0 5pt;color:#0e121b;padding-bottom:4pt;border-bottom:1.5px solid #0e121b;page-break-after:avoid}h3{font-size:9pt;margin:10pt 0 2pt;color:#2b6cff;text-transform:uppercase;letter-spacing:.06em;page-break-after:avoid}
.k{font-size:7.5pt;font-weight:bold;letter-spacing:.08em;text-transform:uppercase;color:#868e9d}.muted{color:#868e9d}.q{margin:0 0 6pt;page-break-inside:avoid}.l{font-weight:bold;font-size:9pt;margin-bottom:1.5pt}.a{margin-left:0}
table.meta{width:100%;border-collapse:collapse;margin:10pt 0 4pt;background:#f6f8fa}table.meta td{padding:4pt 10pt;vertical-align:top;font-size:8.5pt}table.meta td.k2{width:105pt;color:#868e9d}
table.grid{width:100%;border-collapse:collapse;margin-top:3pt}table.grid th{text-align:left;font-size:7.5pt;color:#868e9d;padding:0 6pt 3pt 0;border-bottom:1px solid #e3e7ec}table.grid td{padding:3pt 6pt 3pt 0;font-size:8.5pt;vertical-align:top;border-bottom:1px solid #eef1f5}
.consent{border:1px solid #cfe0ff;background:#f6f9ff;padding:8pt 11pt;margin:12pt 0 0;font-size:8.5pt}ul{margin:2pt 0 0;padding-left:14pt}.brand{font-size:13pt;font-weight:bold}.company{page-break-before:always}';

		$h  = '<html><head><meta charset="utf-8"><style>' . $css . '</style></head><body>';
		$h .= '<table style="width:100%"><tr><td>' . ( '' !== ( $logo = self::logo() ) ? '<img src="' . $logo . '" style="height:40pt">' : '<div class="brand">' . self::e( $biz ) . '</div>' ) . '</td><td style="text-align:right"><div class="k">Reference</div><div style="font-weight:bold">F-' . (int) $sub['id'] . '</div></td></tr></table>';
		$h .= '<div style="margin-top:22pt"><div class="k">Answers</div><h1>' . self::e( $form['title'] ) . '</h1>' . ( '' !== $for ? '<div style="font-size:11pt;color:#525866">Prepared for ' . self::e( $for ) . '</div>' : '' ) . '</div>';
		$h .= '<table class="meta">';
		$rows = array( 'From' => trim( $sub['name'] . ( $sub['email'] ? '  <' . $sub['email'] . '>' : '' ) ), 'Phone' => isset( $data['general']['phone'] ) ? (string) $data['general']['phone'] : '', 'Status' => $state, 'Companies' => (string) ( (int) $data['count'] + 1 ) . ' (the parent company and ' . (int) $data['count'] . ' subsidiar' . ( 1 === (int) $data['count'] ? 'y' : 'ies' ) . ')' );
		$parties = isset( $cover['parties'] ) ? (array) $cover['parties'] : array();
		if ( $parties ) {
			$rows['Collected by'] = implode( '; ', array_map( function ( $p ) {
				return $p['name'] . ' (' . $p['detail'] . '), ' . $p['address'];
			}, $parties ) );
		}
		foreach ( $rows as $k => $v ) {
			if ( '' !== $v ) {
				$h .= '<tr><td class="k2">' . self::e( $k ) . '</td><td>' . self::e( $v ) . '</td></tr>';
			}
		}
		$h .= '</table>';
		if ( ! empty( $cover['consent'] ) ) {
			$h .= '<div class="consent"><div class="k">Consent</div>' . ( $sub['consent_at'] ? self::e( ( $sub['name'] ? $sub['name'] : 'The person who filled in this form' ) . ' agreed on ' . gmdate( 'j F Y, H:i', strtotime( $sub['consent_at'] ) ) . ' UTC:' ) : 'Consent not recorded.' ) . '<div style="margin-top:3pt;color:#525866">' . self::e( $cover['consent'] ) . '</div></div>';
		}
		foreach ( (array) $def['sections'] as $s ) {
			if ( ! empty( $s['repeat'] ) ) {
				continue;
			}
			$h .= '<h2>' . self::e( $s['title'] ) . '</h2>' . self::fields_html( (array) $s['fields'], $data['general'], $files, 0 );
		}
		$repeat = array_values( array_filter( (array) $def['sections'], function ( $s ) {
			return ! empty( $s['repeat'] );
		} ) );
		if ( $repeat ) {
			for ( $i = 0; $i <= (int) $data['count']; $i++ ) {
				$ent = isset( $data['entities'][ $i ] ) ? $data['entities'][ $i ] : array();
				$h  .= '<div class="company"><div class="k">' . ( 0 === $i ? 'Parent company' : 'Subsidiary ' . $i ) . '</div><h1 style="font-size:17pt;margin-bottom:0">' . self::e( Forms::entity_label( $def, $data, $i ) ) . '</h1>';
				foreach ( $repeat as $s ) {
					$h .= '<h2>' . self::e( $s['title'] ) . '</h2>' . self::fields_html( (array) $s['fields'], $ent, $files, $i );
				}
				$h .= '</div>';
			}
		}
		return $h . '</body></html>';
	}
}
