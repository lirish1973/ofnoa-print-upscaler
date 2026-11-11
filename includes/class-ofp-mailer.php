<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * E-mails to the shop owner: the print-ready PDF + the full proofing report.
 */
class OFP_Mailer {

	public static function init_download() {
		add_action( 'wp_ajax_ofp_dl', array( __CLASS__, 'download' ) );
		add_action( 'wp_ajax_nopriv_ofp_dl', array( __CLASS__, 'download' ) );
	}

	public static function sign( $job, $what ) {
		return hash_hmac( 'sha256', $job->token . '|' . $what, wp_salt( 'auth' ) );
	}

	public static function download_url( $job, $what = 'pdf' ) {
		return add_query_arg(
			array(
				'action' => 'ofp_dl',
				'j'      => (int) $job->id,
				'w'      => $what,
				's'      => self::sign( $job, $what ),
			),
			admin_url( 'admin-ajax.php' )
		);
	}

	/** Signed, expiring download for files too large to attach. */
	public static function download() {
		$id   = isset( $_GET['j'] ) ? absint( $_GET['j'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification
		$what = isset( $_GET['w'] ) ? sanitize_key( $_GET['w'] ) : 'pdf'; // phpcs:ignore WordPress.Security.NonceVerification
		$sig  = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$job  = OFP_Jobs::get( $id );
		if ( ! $job || ! hash_equals( self::sign( $job, $what ), $sig ) ) {
			wp_die( 'קישור לא תקין', '', array( 'response' => 403 ) );
		}
		if ( strtotime( $job->created_at . ' UTC' ) < time() - 30 * DAY_IN_SECONDS ) {
			wp_die( 'פג תוקף הקישור', '', array( 'response' => 410 ) );
		}
		$map  = array(
			'pdf'      => $job->pdf_path,
			'original' => $job->original_path,
			'png'      => $job->upscaled_path,
		);
		$path = isset( $map[ $what ] ) ? $map[ $what ] : '';
		if ( ! $path || ! file_exists( $path ) ) {
			wp_die( 'הקובץ כבר לא זמין', '', array( 'response' => 404 ) );
		}
		$name = 'pdf' === $what ? 'styleprint-' . $job->id . '.pdf' : ( 'png' === $what ? 'styleprint-' . $job->id . '-4k.png' : $job->original_name );
		nocache_headers();
		header( 'Content-Type: ' . ( 'pdf' === $what ? 'application/pdf' : 'application/octet-stream' ) );
		header( 'Content-Disposition: attachment; filename="' . rawurlencode( $name ) . '"' );
		header( 'Content-Length: ' . filesize( $path ) );
		readfile( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
		exit;
	}

	private static function e( $s ) {
		return esc_html( (string) $s );
	}

	public static function status_label( $v ) {
		$m = array(
			'approved'     => array( '✅ אושר להדפסה', '#0F7B3F' ),
			'needs_review' => array( '⚠️ דורש בדיקה', '#B36B00' ),
			'rejected'     => array( '⛔ נדחה – הגרפיקה השתנתה', '#B00020' ),
			'unchecked'    => array( 'ללא הגהה', '#555' ),
		);
		return isset( $m[ $v ] ) ? $m[ $v ] : array( $v, '#555' );
	}

	public static function report_html( $job ) {
		$r      = json_decode( (string) $job->report, true );
		$r      = is_array( $r ) ? $r : array();
		$a      = isset( $r['agents'] ) ? $r['agents'] : array();
		$chief  = isset( $a['chief'] ) ? $a['chief'] : array();
		list( $label, $color ) = self::status_label( $job->verdict );
		$fid    = isset( $r['fidelity'] ) ? $r['fidelity'] : array();
		$td     = isset( $r['text_diff'] ) ? $r['text_diff'] : array();
		$h      = '<div dir="rtl" style="font-family:Arial,Helvetica,sans-serif;color:#111;max-width:720px">';
		$h     .= '<h2 style="margin:0 0 6px">גרפיקה #' . (int) $job->id . ' – ' . self::e( $job->original_name ) . '</h2>';
		$h     .= '<div style="display:inline-block;padding:6px 12px;border-radius:6px;background:' . $color . ';color:#fff;font-weight:bold">' . self::e( $label ) . '</div>';
		if ( ! empty( $chief['summary'] ) ) {
			$h .= '<p style="font-size:15px;line-height:1.6">' . self::e( $chief['summary'] ) . '</p>';
		}
		$h .= '<h3>פרטי הלקוח</h3><table cellpadding="4" style="border-collapse:collapse;font-size:14px">';
		foreach ( array(
			'שם'      => $job->customer_name,
			'טלפון'   => $job->customer_phone,
			'אימייל'  => $job->customer_email,
			'הערות'   => $job->notes,
		) as $k => $v ) {
			if ( $v ) {
				$h .= '<tr><td style="color:#666">' . $k . '</td><td>' . nl2br( self::e( $v ) ) . '</td></tr>';
			}
		}
		$h .= '</table>';

		$h .= '<h3>רזולוציה</h3><p>מקור: ' . (int) $job->width . '×' . (int) $job->height . ' ← תוצאה: <b>' . (int) $job->out_width . '×' . (int) $job->out_height . '</b>';
		$engine = isset( $r['engine'] ) ? $r['engine'] : '';
		$prov   = array_map( array( 'OFP_Engines', 'label' ), (array) ( isset( $r['ai_providers'] ) ? $r['ai_providers'] : array() ) );
		$h     .= '<br>מנוע: ' . self::e( 'real-esrgan' === $engine ? 'Real-ESRGAN (AI, ×' . implode( ' ואז ×', (array) ( isset( $r['ai_scale'] ) ? $r['ai_scale'] : array() ) ) . ')' . ( $prov ? ' דרך ' . implode( ' + ', array_unique( $prov ) ) : '' ) : ( 'lanczos' === $engine ? 'Lanczos קלאסי' : 'ללא הגדלה' ) );
		if ( ! empty( $r['enhance']['status'] ) ) {
			$es = array(
				'done'    => 'בוצע' . ( ! empty( $r['enhance']['model'] ) ? ' (' . $r['enhance']['model'] . ')' : '' ) . ' – התאורה, הצבע והחדות שונו בכוונה לפי הפרומפט שבהגדרות',
				'skipped' => 'דולג – ' . ( isset( $r['enhance']['error'] ) ? $r['enhance']['error'] : '' ),
				'failed'  => 'נכשל – ' . ( isset( $r['enhance']['error'] ) ? $r['enhance']['error'] : '' ) . ' (בוצעה הגדלה רגילה של המקור)',
				'running' => 'בתהליך',
			);
			$h .= '<br>שיפור בפרומפט: ' . self::e( isset( $es[ $r['enhance']['status'] ] ) ? $es[ $r['enhance']['status'] ] : $r['enhance']['status'] );
		}
		if ( ! empty( $r['engine_note'] ) ) {
			$h .= '<br><small>' . self::e( $r['engine_note'] ) . '</small>';
		}
		if ( ! empty( $r['pdf'] ) ) {
			$h .= '<br>PDF: 300DPI, ' . self::e( $r['pdf']['mode'] ) . ', ' . self::e( size_format( $r['pdf']['bytes'] ) );
		}
		$h .= '</p>';

		$h .= '<h3>בדיקת נאמנות לפיקסלים</h3><p>הפרש ממוצע: ' . self::e( isset( $fid['mean_diff'] ) ? $fid['mean_diff'] : '-' ) . ' · האזור הכי שונה: ' . self::e( isset( $fid['worst_tile'] ) ? $fid['worst_tile'] : '-' ) . ' · ' . ( ! empty( $fid['pass'] ) ? '✅ עבר' : '⚠️ חרג מהסף' ) . '</p>';

		$insp = isset( $a['inspect'] ) ? $a['inspect'] : array();
		$h   .= '<h3>סוכן השוואה ויזואלית</h3><p>תוצאה: <b>' . self::e( isset( $insp['verdict'] ) ? $insp['verdict'] : '-' ) . '</b>';
		if ( ! empty( $insp['notes'] ) ) {
			$h .= ' – ' . self::e( $insp['notes'] );
		}
		$h .= '</p>';
		if ( ! empty( $insp['differences'] ) ) {
			$h .= '<ul>';
			foreach ( $insp['differences'] as $d ) {
				$h .= '<li>[' . self::e( isset( $d['severity'] ) ? $d['severity'] : '' ) . '] ' . self::e( isset( $d['where'] ) ? $d['where'] : '' ) . ': ' . self::e( isset( $d['description'] ) ? $d['description'] : '' ) . '</li>';
			}
			$h .= '</ul>';
		}

		$h .= '<h3>השוואת טקסט (שני סוכנים עצמאיים)</h3><p>התאמה: ' . self::e( isset( $td['match_ratio'] ) ? round( $td['match_ratio'] * 100 ) . '%' : '-' ) . '</p>';
		if ( ! empty( $td['only_in_original'] ) || ! empty( $td['only_in_result'] ) ) {
			$h .= '<p style="font-size:13px">רק במקור: ' . self::e( implode( ' · ', (array) $td['only_in_original'] ) ) . '<br>רק בתוצאה: ' . self::e( implode( ' · ', (array) $td['only_in_result'] ) ) . '</p>';
		}

		$issues = isset( $a['proofread']['issues'] ) ? $a['proofread']['issues'] : array();
		$h     .= '<h3>הגהה ובדיקת איות של הגרפיקה המקורית</h3>';
		if ( $issues ) {
			$h .= '<p style="color:#B36B00">נמצאו ' . count( $issues ) . ' הערות. הגרפיקה <b>לא</b> תוקנה – כדאי לבדוק מול הלקוח לפני הדפסה.</p><table cellpadding="6" style="border-collapse:collapse;font-size:14px;width:100%"><tr style="background:#f3f3f3"><th align="right">טקסט</th><th align="right">הצעה</th><th align="right">סוג</th><th align="right">ודאות</th><th align="right">הסבר</th></tr>';
			foreach ( $issues as $i ) {
				$h .= '<tr style="border-bottom:1px solid #eee"><td><b>' . self::e( isset( $i['text'] ) ? $i['text'] : '' ) . '</b></td><td>' . self::e( isset( $i['suggestion'] ) ? $i['suggestion'] : '' ) . '</td><td>' . self::e( isset( $i['type'] ) ? $i['type'] : '' ) . '</td><td>' . self::e( isset( $i['confidence'] ) ? $i['confidence'] : '' ) . '</td><td>' . self::e( isset( $i['explanation'] ) ? $i['explanation'] : '' ) . '</td></tr>';
			}
			$h .= '</table>';
		} else {
			$h .= '<p>✅ לא נמצאו שגיאות איות.</p>';
		}

		if ( ! empty( $chief['action_items'] ) ) {
			$h .= '<h3>מה לעשות</h3><ul>';
			foreach ( (array) $chief['action_items'] as $x ) {
				$h .= '<li>' . self::e( $x ) . '</li>';
			}
			$h .= '</ul>';
		}

		$texts = isset( $a['transcribe_original']['texts'] ) ? $a['transcribe_original']['texts'] : array();
		if ( $texts ) {
			$h .= '<h3>הטקסט שזוהה בגרפיקה</h3><ul style="font-size:13px;color:#333">';
			foreach ( $texts as $t ) {
				$h .= '<li>' . self::e( isset( $t['text'] ) ? $t['text'] : '' ) . ( ! empty( $t['where'] ) ? ' <span style="color:#999">(' . self::e( $t['where'] ) . ')</span>' : '' ) . '</li>';
			}
			$h .= '</ul>';
		}
		$h .= '<p style="font-size:13px"><a href="' . esc_url( self::download_url( $job, 'pdf' ) ) . '">הורדת ה-PDF</a> · <a href="' . esc_url( self::download_url( $job, 'png' ) ) . '">הורדת PNG ברזולוציה מלאה</a> · <a href="' . esc_url( self::download_url( $job, 'original' ) ) . '">הקובץ המקורי</a> · <a href="' . esc_url( admin_url( 'admin.php?page=ofp-jobs&job=' . (int) $job->id ) ) . '">פתיחה בלוח הבקרה</a></p>';
		$h .= '<p style="font-size:12px;color:#999">Ofnoa Print Upscaler ' . OFP_VERSION . '</p></div>';
		return $h;
	}

	public static function send_result( $job ) {
		$job    = OFP_Jobs::get( $job->id );
		list( $label ) = self::status_label( $job->verdict );
		$r      = json_decode( (string) $job->report, true );
		$issues = isset( $r['agents']['proofread']['issues'] ) ? $r['agents']['proofread']['issues'] : array();
		$hold   = 'hold' === OFP_Settings::get( 'spelling_policy' ) && array_filter(
			$issues,
			function ( $i ) {
				return isset( $i['confidence'] ) && 'high' === $i['confidence'];
			}
		);
		$attach = array();
		$limit  = (int) OFP_Settings::get( 'attach_limit_mb' ) * MB_IN_BYTES;
		if ( ! $hold && $job->pdf_path && file_exists( $job->pdf_path ) && filesize( $job->pdf_path ) <= $limit ) {
			$attach[] = $job->pdf_path;
		}
		$subject = '[' . wp_strip_all_tags( $label ) . '] גרפיקה #' . $job->id . ' – ' . $job->original_name;
		if ( $hold ) {
			$subject = '[⚠️ הוחזק – שגיאות כתיב] גרפיקה #' . $job->id;
		}
		$body = self::report_html( $job );
		if ( ! $attach && ! $hold ) {
			$body = '<p dir="rtl">ה-PDF גדול מדי לצירוף – <a href="' . esc_url( self::download_url( $job, 'pdf' ) ) . '">להורדה</a> (תקף 30 יום).</p>' . $body;
		}
		$headers = array( 'Content-Type: text/html; charset=UTF-8' );
		if ( is_email( $job->customer_email ) ) {
			$headers[] = 'Reply-To: ' . $job->customer_name . ' <' . $job->customer_email . '>';
		}
		$ok = wp_mail( OFP_Settings::recipients(), $subject, $body, $headers, $attach );
		return $ok ? true : new WP_Error( 'ofp_mail', 'שליחת המייל נכשלה (בדקו SMTP)' );
	}

	public static function send_failure( $job, $msg ) {
		if ( ! $job ) {
			return;
		}
		$body = '<div dir="rtl" style="font-family:Arial">העיבוד של גרפיקה #' . (int) $job->id . ' (' . esc_html( $job->original_name ) . ') נכשל: <b>' . esc_html( $msg ) . '</b><br>לקוח: ' . esc_html( $job->customer_name . ' ' . $job->customer_phone ) . '<br><a href="' . esc_url( admin_url( 'admin.php?page=ofp-jobs&job=' . (int) $job->id ) ) . '">פתיחה בלוח הבקרה</a> · <a href="' . esc_url( self::download_url( $job, 'original' ) ) . '">הקובץ המקורי</a></div>';
		wp_mail( OFP_Settings::recipients(), '[❌ נכשל] גרפיקה #' . $job->id, $body, array( 'Content-Type: text/html; charset=UTF-8' ) );
	}
}
OFP_Mailer::init_download();
