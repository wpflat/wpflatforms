<?php
namespace WPFlatForms;

use WPFlatCore\Services\Mailer as Core_Mailer;

defined( 'ABSPATH' ) || exit;

/** The few emails WPFlat Forms sends: a link to come back, a copy for the person, and a note to your team. All use WPFlatCore's branded email look. */
class Mailer {

	public static function resume_url( array $form, array $sub ) {
		return add_query_arg( 'bd_resume', $sub['token'], $form['url'] );
	}

	/** The sender chosen for this form: a team member's own address (kept with who chose it so it is re-checked every time). */
	private static function from_args( array $form ) {
		return ! empty( $form['sender_email'] ) ? array( 'from_email' => $form['sender_email'], 'from_user' => (int) $form['sender_user'] ) : array();
	}

	private static function send( array $m ) {
		return Core_Mailer::send( $m );
	}

	public static function resume_link( array $form, array $sub ) {
		if ( '' === $sub['email'] ) {
			return;
		}
		$url  = self::resume_url( $form, $sub );
		$name = '' !== $sub['name'] ? $sub['name'] : 'there';
		$html = '<p>Hi ' . esc_html( $name ) . ',</p><p>Your answers to <strong>' . esc_html( $form['title'] ) . '</strong> are being saved as you go. Use your private link whenever you want to carry on where you stopped.</p><p><a href="' . esc_url( $url ) . '">Continue my form</a></p><p>Anyone with this link can open your answers, so please keep it to yourself.</p>';
		self::send( self::from_args( $form ) + array( 'to' => array( $sub['email'] ), 'subject' => 'Your link to finish: ' . $form['title'], 'heading' => 'Pick up where you left off', 'preheader' => 'Your private link to ' . $form['title'], 'html' => $html, 'text' => "Hi {$name},\n\nYour answers to \"{$form['title']}\" are being saved as you go. Carry on any time with your private link:\n{$url}\n\nAnyone with the link can open your answers, so please keep it to yourself.\n" ) );
	}

	public static function submitted( array $form, array $sub ) {
		$md     = Forms::markdown( $form, $sub );
		$app    = \WPFlatCore\Urls::app( '/forms' );
		$pdf    = Pdf::to_temp_file( $form, $sub );
		$attach = $pdf ? array( $pdf ) : array();
		$who    = '' !== $sub['name'] ? $sub['name'] : 'Someone';
		foreach ( Forms::notify_emails( $form ) as $to ) {
			$html = '<p>' . esc_html( $who ) . ( '' !== $sub['email'] ? ' (' . esc_html( $sub['email'] ) . ')' : '' ) . ' has submitted <strong>' . esc_html( $form['title'] ) . '</strong>. Their answers are attached as a PDF' . ( $pdf ? '' : ' (the PDF could not be made; open them in the app)' ) . ' and in the app.</p><p><a href="' . esc_url( $app ) . '">Read the answers in WPFlat</a></p>';
			self::send( self::from_args( $form ) + array( 'to' => array( $to ), 'subject' => 'New answers: ' . $form['title'] . ( '' !== $sub['name'] ? ' from ' . $sub['name'] : '' ), 'heading' => 'New answers received', 'preheader' => $who . ' submitted ' . $form['title'], 'html' => $html, 'text' => "Submitted answers are below. Read them in the app: {$app}\n\n" . $md, 'attachments' => $attach ) );
		}
		if ( '' !== $sub['email'] ) {
			$url  = self::resume_url( $form, $sub );
			$html = '<p>Hi ' . esc_html( $who ) . ',</p><p>Thank you. We have your answers to <strong>' . esc_html( $form['title'] ) . '</strong>' . ( $pdf ? ', and a copy is attached as a PDF' : '' ) . '. You can still change your answers with your private link until we start work.</p><p><a href="' . esc_url( $url ) . '">View or change my answers</a></p>';
			self::send( self::from_args( $form ) + array( 'to' => array( $sub['email'] ), 'subject' => 'We received: ' . $form['title'], 'heading' => 'We received your answers', 'preheader' => 'A copy of your answers to ' . $form['title'], 'html' => $html, 'text' => "Hi {$who},\n\nThank you. This is a copy of what you sent us. You can still change your answers with your private link until we start work:\n{$url}\n\n" . $md, 'attachments' => $attach ) );
		}
		if ( $pdf ) {
			@unlink( $pdf ); // phpcs:ignore WordPress.WP.AlternativeFunctions,WordPress.PHP.NoSilencedErrors
		}
	}
}
