<?php
namespace WPFlatForms;

defined( 'ABSPATH' ) || exit;

/** The public form page: yoursite.com/form/<form-address>/ . A light page with no theme around it. */
class Public_Page {

	public static function init() {
		add_action( 'template_redirect', array( __CLASS__, 'maybe_serve' ), 1 );
	}

	public static function base() {
		$s = sanitize_title( (string) apply_filters( 'wpflatforms_base', 'form' ) );
		return '' !== $s ? $s : 'form';
	}

	/** For WPFlat Core's "Site home page" setting: the address of the first open form. */
	public static function home_url_for_core() {
		global $wpdb;
		$slug = $wpdb->get_var( 'SELECT slug FROM ' . Install::table( 'forms' ) . " WHERE status='open' ORDER BY id LIMIT 1" ); // phpcs:ignore WordPress.DB
		return $slug ? Forms::slug_url( $slug ) : '';
	}

	private static function requested() {
		$path = wp_parse_url( isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '', PHP_URL_PATH ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$home = (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH );
		return trim( substr( (string) $path, strlen( $home ) ), '/' );
	}

	public static function maybe_serve() {
		if ( ! \WPFlatCore\Registry::enabled( 'form' ) ) {
			return;
		}
		$path = self::requested();
		if ( ! preg_match( '#^' . preg_quote( self::base(), '#' ) . '/([a-z0-9-]+)$#', $path, $m ) ) {
			return;
		}
		$form = Forms::by_slug( $m[1] );
		if ( ! $form ) {
			return;
		}
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}
		do_action( 'litespeed_control_set_nocache', 'wpflatforms' );
		nocache_headers();
		header( 'X-Robots-Tag: noindex, nofollow' );
		header( 'X-Frame-Options: SAMEORIGIN' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Referrer-Policy: same-origin' );
		status_header( 200 );

		$biz   = (string) \WPFlatCore\Settings::get( 'business_name' );
		$logo  = (string) \WPFlatCore\Settings::get( 'business_logo_url' );
		$token = '';
		$data  = null;
		$state = 'new';
		$sub_files = array();
		$consented = '';
		$pdf_url   = '';
		if ( isset( $_GET['bd_resume'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$sub = Forms::submission_by_token( sanitize_text_field( wp_unslash( $_GET['bd_resume'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification
			if ( $sub && $sub['form_id'] === $form['id'] ) {
				$token = $sub['token'];
				$data  = $sub['data'];
				$state = $sub['status'];
				$sub_files = Uploads::for_submission( $sub['id'] );
				$consented = (string) $sub['consent_at'];
				$pdf_url   = Pdf::token_url( $form, $sub );
			}
		}
		$def = $form['definition'];
		$cfg = array(
			'title'  => $form['title'],
			'def'    => $def,
			'slug'   => $form['slug'],
			'open'   => 'open' === $form['status'],
			'save'   => rest_url( 'wpflatcore/v1/form/public/' . $form['slug'] . '/save' ),
			'url'    => $form['url'],
			'token'  => $token,
			'data'   => $data,
			'state'  => $state,
			'biz'    => $biz,
			'logo'   => $logo,
			'files'  => $sub_files,
			'maxMb'  => (int) floor( Uploads::max_bytes() / MB_IN_BYTES ),
			'allowed' => Uploads::ALLOWED,
			'client' => $form['client_name'],
			'consented' => $consented,
			'pdf'    => $pdf_url,
		);
		$css = (string) file_get_contents( WPFLATFORMS_DIR . 'assets/form.css' );
		$js  = (string) file_get_contents( WPFLATFORMS_DIR . 'assets/form.js' );
		?>
<!doctype html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?php echo esc_html( $form['title'] . ' — ' . $biz ); ?></title>
<style><?php echo $css; // phpcs:ignore WordPress.Security.EscapeOutput ?></style>
</head>
<body>
<header class="bf-top"><?php if ( $logo ) : ?><img src="<?php echo esc_url( $logo ); ?>" alt="<?php echo esc_attr( $biz ); ?>" class="bf-logo"><?php else : ?><b><?php echo esc_html( $biz ); ?></b><?php endif; ?></header>
<main id="bf-app" class="bf-wrap"><noscript><div class="bf-card">This form needs JavaScript. Please turn it on in your browser and reload.</div></noscript></main>
<script>window.WPFLATFORMS=<?php echo wp_json_encode( $cfg, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES ); // phpcs:ignore WordPress.Security.EscapeOutput ?>;</script>
<script><?php echo $js; // phpcs:ignore WordPress.Security.EscapeOutput ?></script>
</body>
</html>
		<?php
		exit;
	}
}
