<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin dashboard ("שדרוג מהיר"): upload an image, watch the pipeline run live, and get the
 * created files on the same page. The page itself drives the job step by step (see
 * OFP_Processor::drive), so it does not depend on WP-Cron or loopback requests.
 *
 * Three ways in:
 *   1. wp-admin → שדרוג גרפיקה → שדרוג מהיר                    (logged-in administrator)
 *   2. [ofnoa_upscaler_dashboard] on any page                  (administrator, or ?ofp_key=<staff key>)
 *   3. admin-ajax.php?action=ofp_staff&k=<staff key>           (standalone page, no WordPress login)
 * The staff key is a long random secret; anyone holding the link can upload and see every file,
 * so it is for the team only and can be rotated from the settings page.
 */
class OFP_Quick {

	const SLUG = 'ofp-quick';
	const CAP  = 'manage_options';

	const KEY_OPTION = 'ofp_staff_key';

	/** True when the current request was authorised by the staff key (not by a logged-in admin). */
	private static $via_key = false;

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 11 );
		foreach ( array( 'upload', 'status', 'list', 'view', 'delete' ) as $a ) {
			add_action( 'wp_ajax_ofp_quick_' . $a, array( __CLASS__, 'ajax_' . $a ) );
			add_action( 'wp_ajax_nopriv_ofp_quick_' . $a, array( __CLASS__, 'ajax_' . $a ) );
		}
		add_action( 'wp_ajax_ofp_staff', array( __CLASS__, 'staff_page' ) );
		add_action( 'wp_ajax_nopriv_ofp_staff', array( __CLASS__, 'staff_page' ) );
		add_action( 'admin_post_ofp_staff_rotate', array( __CLASS__, 'rotate_key' ) );
		add_shortcode( 'ofnoa_upscaler_dashboard', array( __CLASS__, 'shortcode' ) );
	}

	/* ---------------- access ---------------- */

	public static function staff_key() {
		$k = get_option( self::KEY_OPTION );
		if ( ! is_string( $k ) || strlen( $k ) < 32 ) {
			$k = wp_generate_password( 40, false, false );
			update_option( self::KEY_OPTION, $k, false );
		}
		return $k;
	}

	private static function request_key() {
		foreach ( array( 'k', 'ofp_key' ) as $p ) {
			if ( isset( $_REQUEST[ $p ] ) && is_string( $_REQUEST[ $p ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
				return sanitize_text_field( wp_unslash( $_REQUEST[ $p ] ) ); // phpcs:ignore WordPress.Security.NonceVerification
			}
		}
		return '';
	}

	private static function key_ok() {
		$k = self::request_key();
		return strlen( $k ) >= 32 && hash_equals( self::staff_key(), $k );
	}

	/** Staff link (standalone page, works without a WordPress login). */
	public static function staff_url() {
		return add_query_arg(
			array(
				'action' => 'ofp_staff',
				'k'      => self::staff_key(),
			),
			admin_url( 'admin-ajax.php' )
		);
	}

	public static function rotate_key() {
		check_admin_referer( 'ofp_staff_rotate' );
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( 'forbidden' );
		}
		update_option( self::KEY_OPTION, wp_generate_password( 40, false, false ), false );
		wp_safe_redirect( admin_url( 'admin.php?page=ofp-settings&ofp_staff_rotated=1' ) );
		exit;
	}

	public static function url() {
		return admin_url( 'admin.php?page=' . self::SLUG );
	}

	public static function menu() {
		add_submenu_page( 'ofp-jobs', 'שדרוג מהיר', 'שדרוג מהיר', self::CAP, self::SLUG, array( __CLASS__, 'page' ), 0 );
	}

	/**
	 * Is this request allowed to use the dashboard?
	 * A valid staff key is enough on its own (it travels in every request, so there is no cookie and
	 * nothing for a third-party site to ride on). Otherwise: a logged-in administrator plus the nonce.
	 */
	private static function allowed( $need_nonce ) {
		if ( self::key_ok() ) {
			self::$via_key = true;
			return true;
		}
		if ( ! current_user_can( self::CAP ) ) {
			return false;
		}
		return ! $need_nonce || (bool) check_ajax_referer( 'ofp_quick', 'nonce', false );
	}

	private static function guard() {
		if ( ! self::allowed( true ) ) {
			wp_send_json_error( array( 'message' => 'אין הרשאה או שפג תוקף הדף – רעננו אותו (F5) ונסו שוב' ), 403 );
		}
	}

	/* ---------------- upload ---------------- */

	public static function ajax_upload() {
		self::guard();
		if ( empty( $_FILES['file'] ) ) {
			wp_send_json_error( array( 'message' => 'לא התקבל קובץ. ייתכן שהוא גדול ממגבלת ההעלאה של השרת (' . size_format( wp_max_upload_size() ) . ').' ), 400 );
		}
		$f = $_FILES['file']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$v = OFP_Frontend::validate_upload( $f );
		if ( is_wp_error( $v ) ) {
			wp_send_json_error( array( 'message' => $v->get_error_message() ), 400 );
		}
		list( $ext, $orig ) = $v;
		$user = wp_get_current_user();
		$who  = self::$via_key ? 'קישור צוות' : ( $user->display_name ? $user->display_name : $user->user_login );
		$opts = array(
			'enhance' => ! empty( $_POST['enhance'] ),
			'proof'   => ! empty( $_POST['proof'] ),
			'mail'    => ! empty( $_POST['mail'] ),
			'origin'  => 'dashboard',
		);
		$id   = OFP_Jobs::create(
			array(
				'customer_name'  => 'דאשבורד – ' . $who,
				'customer_email' => self::$via_key ? '' : $user->user_email,
				'original_name'  => $orig,
				'ip'             => self::$via_key ? 'staff-link' : 'admin',
				'status'         => 'receiving',
				'report'         => wp_json_encode( array( 'opts' => $opts ), JSON_UNESCAPED_UNICODE ),
			)
		);
		$job = OFP_Jobs::get( $id );
		if ( ! $job ) {
			wp_send_json_error( array( 'message' => 'יצירת העבודה נכשלה (מסד הנתונים)' ), 500 );
		}
		$dst = OFP_Jobs::job_dir( $job ) . '/original.' . $ext;
		if ( ! move_uploaded_file( $f['tmp_name'], $dst ) ) {
			OFP_Jobs::update( $id, array( 'status' => 'failed' ) );
			wp_send_json_error( array( 'message' => 'שמירת הקובץ בשרת נכשלה (הרשאות כתיבה בתיקיית uploads)' ), 500 );
		}
		OFP_Jobs::update(
			$id,
			array(
				'original_path' => $dst,
				'status'        => 'queued',
			)
		);
		OFP_Jobs::log( $id, 'התקבל מהדאשבורד: ' . $orig . ' (' . size_format( filesize( $dst ) ) . ')' );
		wp_send_json_success( array( 'job' => $id ) );
	}

	/* ---------------- status + drive ---------------- */

	private static function report( $job ) {
		$r = json_decode( (string) $job->report, true );
		return is_array( $r ) ? $r : array();
	}

	private static function progress( $job, $r ) {
		$agents = isset( $r['agents'] ) ? count( $r['agents'] ) : 0;
		$names  = array( 'מתמלל את המקור', 'מתמלל את התוצאה', 'משווה מקור מול תוצאה', 'בודק איות ופרטי קשר', 'ההגה הראשי מסכם' );
		$map    = array(
			'receiving' => array( 3, 'מקבל את הקובץ…' ),
			'queued'    => array( 8, 'מכין את הקובץ…' ),
			'enhancing' => array( 25, 'משפר את התמונה לפי הפרומפט (תאורה, עומק, חדות)…' ),
			'upscaling' => array( 50, 'מגדיל ל-4K…' ),
			'verifying' => array( 64, 'בודק נאמנות למקור…' ),
			'proofing'  => array( 68 + 4 * min( 5, $agents ), 'הגהה: ' . ( isset( $names[ $agents ] ) ? $names[ $agents ] : 'מסיים' ) . ' (' . min( 5, $agents + 1 ) . '/5)…' ),
			'building'  => array( 92, 'בונה PDF ב-300DPI…' ),
			'mailing'   => array( 96, 'שולח במייל…' ),
			'done'      => array( 100, 'הושלם' ),
			'failed'    => array( 100, 'נכשל' ),
		);
		return isset( $map[ $job->status ] ) ? $map[ $job->status ] : array( 5, $job->status );
	}

	/** Everything the page needs to render one job. */
	public static function payload( $job ) {
		$r      = self::report( $job );
		$p      = self::progress( $job, $r );
		$lines  = array_values( array_filter( array_map( 'trim', explode( "\n", (string) $job->log ) ) ) );
		$error  = '';
		foreach ( array_reverse( $lines ) as $l ) {
			if ( false !== strpos( $l, 'שגיאה:' ) ) {
				$error = trim( substr( $l, strpos( $l, 'שגיאה:' ) + strlen( 'שגיאה:' ) ) );
				break;
			}
		}
		$done   = 'done' === $job->status;
		$failed = 'failed' === $job->status;
		$engine = isset( $r['engine'] ) ? $r['engine'] : '';
		$prov   = array_unique( array_map( array( 'OFP_Engines', 'label' ), (array) ( isset( $r['ai_providers'] ) ? $r['ai_providers'] : array() ) ) );
		$etxt   = 'real-esrgan' === $engine ? 'Real-ESRGAN' . ( $prov ? ' דרך ' . implode( ' + ', $prov ) : '' ) : ( 'lanczos' === $engine ? 'Lanczos (בלי AI)' : ( 'none' === $engine ? 'ללא הגדלה' : '' ) );
		$enh    = isset( $r['enhance']['status'] ) ? $r['enhance'] : array();
		$ntxt   = '';
		if ( $enh ) {
			$st   = array(
				'done'    => 'בוצע (' . ( isset( $enh['model'] ) ? $enh['model'] : '' ) . ')',
				'running' => 'בתהליך',
				'skipped' => 'דולג – ' . ( isset( $enh['error'] ) ? $enh['error'] : '' ),
				'failed'  => 'נכשל – ' . ( isset( $enh['error'] ) ? $enh['error'] : '' ),
			);
			$ntxt = isset( $st[ $enh['status'] ] ) ? $st[ $enh['status'] ] : $enh['status'];
		} elseif ( isset( $r['opts']['enhance'] ) && ! $r['opts']['enhance'] ) {
			$ntxt = 'כבוי לעבודה הזו';
		}
		$verdict = OFP_Mailer::status_label( $job->verdict );
		$out     = array(
			'job'      => (int) $job->id,
			'name'     => $job->original_name,
			'status'   => $job->status,
			'pct'      => $p[0],
			'text'     => $failed ? 'העבודה נכשלה' . ( $error ? ': ' . $error : '' ) : $p[1],
			'done'     => $done,
			'failed'   => $failed,
			'error'    => $error,
			'retry'    => (int) $job->step_attempts,
			'size_in'  => (int) $job->width ? (int) $job->width . '×' . (int) $job->height : '',
			'size_out' => (int) $job->out_width ? (int) $job->out_width . '×' . (int) $job->out_height : '',
			'engine'   => $etxt,
			'note'     => isset( $r['engine_note'] ) ? $r['engine_note'] : '',
			'enhance'  => $ntxt,
			'verdict'  => $job->verdict ? $verdict[0] : '',
			'vcolor'   => $verdict[1],
			'summary'  => isset( $r['agents']['chief']['summary'] ) ? $r['agents']['chief']['summary'] : '',
			'log'      => array_slice( $lines, -8 ),
			'report'   => self::$via_key ? '' : admin_url( 'admin.php?page=ofp-jobs&job=' . (int) $job->id ),
			'files'    => array(),
			'views'    => array(),
		);
		if ( $job->source_path && file_exists( $job->source_path ) ) {
			$out['views']['source'] = self::view_url( $job, 'source', 'view' );
		}
		if ( $job->original_path && file_exists( $job->original_path ) ) {
			$out['files']['original'] = OFP_Mailer::download_url( $job, 'original' );
		}
		if ( $job->upscaled_path && file_exists( $job->upscaled_path ) && in_array( $job->status, array( 'verifying', 'proofing', 'building', 'mailing', 'done', 'failed' ), true ) ) {
			$out['views']['result'] = self::view_url( $job, 'result', 'view' );
			$out['files']['png']    = OFP_Mailer::download_url( $job, 'png' );
			$out['bytes_png']       = size_format( filesize( $job->upscaled_path ) );
		}
		if ( $job->pdf_path && file_exists( $job->pdf_path ) ) {
			$out['files']['pdf'] = OFP_Mailer::download_url( $job, 'pdf' );
			$out['bytes_pdf']    = size_format( filesize( $job->pdf_path ) );
		}
		return $out;
	}

	public static function ajax_status() {
		self::guard();
		$id  = isset( $_POST['job'] ) ? absint( $_POST['job'] ) : 0;
		$job = $id ? OFP_Jobs::get( $id ) : null;
		if ( ! $job ) {
			wp_send_json_error( array( 'message' => 'העבודה לא נמצאה' ), 404 );
		}
		$busy = false;
		if ( ! empty( $_POST['drive'] ) && in_array( $job->status, OFP_Processor::ACTIVE, true ) ) {
			$busy = ! OFP_Processor::drive( $id );
			$job  = OFP_Jobs::get( $id );
		}
		$out         = self::payload( $job );
		$out['busy'] = $busy;
		wp_send_json_success( $out );
	}

	/* ---------------- previews ---------------- */

	private static function view_url( $job, $what, $size ) {
		$args = array(
			'action' => 'ofp_quick_view',
			'j'      => (int) $job->id,
			'w'      => $what,
			'z'      => $size,
			'v'      => rawurlencode( substr( md5( $job->updated_at . $job->status ), 0, 8 ) ),
		);
		if ( self::$via_key ) {
			$args['k'] = self::staff_key();
		} else {
			$args['nonce'] = wp_create_nonce( 'ofp_quick' );
		}
		return add_query_arg( $args, admin_url( 'admin-ajax.php' ) );
	}

	/** Inline JPEG preview (cached next to the job files). Admin only. */
	public static function ajax_view() {
		if ( ! self::allowed( true ) ) {
			status_header( 403 );
			exit;
		}
		$job  = OFP_Jobs::get( isset( $_GET['j'] ) ? absint( $_GET['j'] ) : 0 );
		$what = isset( $_GET['w'] ) && 'source' === $_GET['w'] ? 'source' : 'result';
		$size = isset( $_GET['z'] ) && 'thumb' === $_GET['z'] ? 'thumb' : 'view';
		$path = $job ? ( 'source' === $what ? $job->source_path : $job->upscaled_path ) : '';
		if ( ! $path || ! file_exists( $path ) ) {
			status_header( 404 );
			exit;
		}
		$cache = OFP_Jobs::job_dir( $job ) . '/preview-' . $what . '-' . $size . '.jpg';
		if ( ! file_exists( $cache ) || filemtime( $cache ) < filemtime( $path ) ) {
			wp_raise_memory_limit( 'image' );
			$b64 = OFP_Image::b64_view( $path, 'thumb' === $size ? 520 : 1800 );
			if ( '' === $b64 ) {
				status_header( 500 );
				exit;
			}
			file_put_contents( $cache, base64_decode( $b64 ) ); // phpcs:ignore
		}
		header( 'Content-Type: image/jpeg' );
		header( 'Cache-Control: private, max-age=86400' );
		header( 'Content-Length: ' . filesize( $cache ) );
		readfile( $cache ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
		exit;
	}

	/* ---------------- created files list ---------------- */

	public static function list_html( $limit = 12 ) {
		$rows = OFP_Jobs::list_recent( $limit );
		if ( ! $rows ) {
			return '<p class="ofq-empty">עדיין לא נוצרו קבצים. העלו תמונה למעלה.</p>';
		}
		$h = '';
		foreach ( $rows as $row ) {
			$job = OFP_Jobs::get( $row->id );
			if ( ! $job ) {
				continue;
			}
			$has_png = $job->upscaled_path && file_exists( $job->upscaled_path ) && ! in_array( $job->status, array( 'queued', 'enhancing', 'upscaling' ), true );
			$has_pdf = $job->pdf_path && file_exists( $job->pdf_path );
			$active  = in_array( $job->status, OFP_Processor::ACTIVE, true );
			$verdict = OFP_Mailer::status_label( $job->verdict );
			$state   = 'done' === $job->status ? ( $job->verdict ? $verdict[0] : 'הושלם' ) : ( 'failed' === $job->status ? 'נכשל' : ( $active ? 'בעיבוד' : $job->status ) );
			$color   = 'done' === $job->status ? ( $job->verdict ? $verdict[1] : '#0F7B3F' ) : ( 'failed' === $job->status ? '#B00020' : '#555' );
			$h      .= '<article class="ofq-item" data-job="' . (int) $job->id . '">';
			$h      .= '<label class="ofq-pick"><input type="checkbox" class="ofq-check" value="' . (int) $job->id . '"><span class="screen-reader-text">בחירת עבודה ' . (int) $job->id . ' למחיקה</span></label>';
			$h      .= '<div class="ofq-thumb">' . ( $has_png ? '<a href="' . esc_url( self::view_url( $job, 'result', 'view' ) ) . '" target="_blank" rel="noopener"><img loading="lazy" alt="" src="' . esc_url( self::view_url( $job, 'result', 'thumb' ) ) . '"></a>' : '<span>' . ( $active ? 'בעיבוד…' : 'אין קובץ' ) . '</span>' ) . '</div>';
			$h      .= '<div class="ofq-meta"><b title="' . esc_attr( $job->original_name ) . '">#' . (int) $job->id . ' · ' . esc_html( $job->original_name ) . '</b>';
			$h      .= '<span class="ofq-dims" dir="ltr">' . ( (int) $job->width ? (int) $job->width . '×' . (int) $job->height : '—' ) . ( (int) $job->out_width ? ' → ' . (int) $job->out_width . '×' . (int) $job->out_height : '' ) . '</span>';
			$h      .= '<span class="ofq-state" style="color:' . esc_attr( $color ) . '">' . esc_html( $state ) . '</span>';
			$h      .= '<span>' . esc_html( get_date_from_gmt( $job->created_at, 'd/m/Y H:i' ) ) . '</span></div>';
			$h      .= '<div class="ofq-acts">';
			if ( $has_png ) {
				$h .= '<a class="button button-small" href="' . esc_url( OFP_Mailer::download_url( $job, 'png' ) ) . '">PNG</a>';
			}
			if ( $has_pdf ) {
				$h .= '<a class="button button-small" href="' . esc_url( OFP_Mailer::download_url( $job, 'pdf' ) ) . '">PDF</a>';
			}
			if ( $active ) {
				$h .= '<button type="button" class="button button-small ofq-resume" data-job="' . (int) $job->id . '">המשך מעקב</button>';
			}
			if ( ! self::$via_key ) {
				$h .= '<a class="button button-small button-link" href="' . esc_url( admin_url( 'admin.php?page=ofp-jobs&job=' . (int) $job->id ) ) . '">דוח</a>';
			}
			$h .= '</div></article>';
		}
		return $h;
	}

	public static function ajax_list() {
		self::guard();
		wp_send_json_success( array( 'html' => self::list_html() ) );
	}

	/**
	 * Permanently delete the selected jobs with all their files (original, result, PDF, previews).
	 * A job that is still being processed is skipped unless it has been stuck for over 30 minutes.
	 */
	public static function ajax_delete() {
		self::guard();
		$ids     = isset( $_POST['jobs'] ) ? array_filter( array_map( 'absint', explode( ',', sanitize_text_field( wp_unslash( $_POST['jobs'] ) ) ) ) ) : array(); // phpcs:ignore WordPress.Security.NonceVerification -- verified in guard().
		$ids     = array_slice( array_unique( $ids ), 0, 200 );
		$deleted = 0;
		$skipped = 0;
		foreach ( $ids as $id ) {
			$job = OFP_Jobs::get( $id );
			if ( ! $job ) {
				continue;
			}
			$busy = in_array( $job->status, OFP_Processor::ACTIVE, true ) && strtotime( $job->updated_at . ' UTC' ) > time() - 30 * MINUTE_IN_SECONDS;
			if ( $busy ) {
				$skipped++;
				continue;
			}
			if ( OFP_Jobs::delete( $job ) ) {
				$deleted++;
			}
		}
		$msg = 0 === $deleted ? 'לא נמחק דבר' : ( 1 === $deleted ? 'נמחקה עבודה אחת עם כל הקבצים שלה' : 'נמחקו ' . $deleted . ' עבודות עם כל הקבצים שלהן' );
		if ( $skipped ) {
			$msg .= ' · ' . $skipped . ' עדיין בעיבוד ולא נמחקו';
		}
		wp_send_json_success(
			array(
				'deleted' => $deleted,
				'skipped' => $skipped,
				'message' => $msg,
				'ids'     => array_values( $ids ),
				'html'    => self::list_html(),
			)
		);
	}

	/* ---------------- page ---------------- */

	/** wp-admin screen. */
	public static function page() {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}
		self::render( false );
	}

	/** [ofnoa_upscaler_dashboard] – for an administrator, or for anyone opening the page with ?ofp_key=<staff key>. */
	public static function shortcode() {
		if ( ! self::allowed( false ) ) {
			return '<p class="ofq-denied" dir="rtl">אין הרשאה לצפות בדף הזה.</p>';
		}
		// Never let a page cache keep a copy of this screen.
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}
		do_action( 'litespeed_control_set_nocache', 'ofnoa upscaler dashboard' );
		ob_start();
		self::render( true );
		return ob_get_clean();
	}

	/** Standalone page behind the staff key – no theme, no WordPress login. */
	public static function staff_page() {
		$ok = self::key_ok();
		nocache_headers();
		if ( ! $ok ) {
			status_header( 403 );
		}
		header( 'Content-Type: text/html; charset=utf-8' );
		header( 'X-Robots-Tag: noindex, nofollow' );
		header( 'Referrer-Policy: no-referrer' );
		echo '<!doctype html><html lang="he" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><meta name="referrer" content="no-referrer"><title>שדרוג מהיר – ' . esc_html( get_bloginfo( 'name' ) ) . '</title><style>body{margin:0;padding:24px 16px;background:#f0f0f1;color:#1d2327;font:14px/1.6 -apple-system,BlinkMacSystemFont,"Segoe UI",Heebo,Arial,sans-serif}.ofq{margin:0 auto}</style></head><body>';
		if ( ! $ok ) {
			echo '<p class="ofq-denied">הקישור לא תקין או שהמפתח הוחלף. בקשו קישור עדכני ממנהל האתר.</p></body></html>';
			exit;
		}
		self::$via_key = true;
		self::render( true );
		echo '</body></html>';
		exit;
	}

	private static function render( $front ) {
		$max     = min( (int) OFP_Settings::get( 'max_upload_mb' ) * MB_IN_BYTES, wp_max_upload_size() );
		$mode    = OFP_Settings::get( 'enhance_mode' );
		$enh_on  = 'off' !== $mode;
		$admin   = ! self::$via_key && current_user_can( self::CAP );
		$enh_ok  = OFP_Engines::enhance_ready();
		$engines = OFP_Engines::order();
		$claude  = (bool) OFP_Settings::secret( 'anthropic_key' );
		$cfg     = array(
			'ajax'     => admin_url( 'admin-ajax.php' ),
			'key'      => self::$via_key ? self::staff_key() : '',
			'nonce'    => self::$via_key ? '' : wp_create_nonce( 'ofp_quick' ),
			'maxBytes' => $max,
			'maxText'  => size_format( $max ),
			'ext'      => OFP_Frontend::EXT,
		);
		?>
		<div class="<?php echo $front ? 'ofq ofq-front' : 'wrap ofq'; ?>" dir="rtl">
			<h1>שדרוג מהיר</h1>
			<p class="ofq-lead">מעלים תמונה, רואים את העיבוד בזמן אמת, ומקבלים את הקבצים כאן בדף.<?php if ( $admin && ! $front ) : ?> קישור לצוות, בלי התחברות: <a href="<?php echo esc_url( admin_url( 'admin.php?page=ofp-settings#ofp-staff' ) ); ?>">בהגדרות</a>.<?php endif; ?></p>
			<?php if ( ! $engines ) : ?>
				<div class="notice notice-warning inline ofq-warn"><p>לא מוגדר אף מנוע הגדלה פעיל – התמונה תוגדל ב-Lanczos בלבד (בלי AI).<?php if ( $admin ) : ?> <a href="<?php echo esc_url( admin_url( 'admin.php?page=ofp-settings' ) ); ?>">להגדרות</a><?php endif; ?></p></div>
			<?php endif; ?>

			<div class="ofq-card">
				<form id="ofq-form" novalidate>
					<label class="ofq-drop" id="ofq-drop" tabindex="0">
						<input type="file" id="ofq-file" accept=".png,.jpg,.jpeg,.webp,.pdf,image/png,image/jpeg,image/webp,application/pdf" hidden>
						<img id="ofq-prev" alt="" hidden>
						<span class="ofq-drop-t" id="ofq-drop-t">גררו לכאן תמונה או לחצו לבחירה</span>
						<span class="ofq-drop-s">PNG · JPG · WEBP · PDF · עד <?php echo esc_html( size_format( $max ) ); ?></span>
					</label>
					<div class="ofq-opts">
						<label><input type="checkbox" id="ofq-enhance" <?php checked( $enh_on && $enh_ok ); ?> <?php disabled( ! $enh_ok ); ?>> שיפור AI לפי הפרומפט<?php echo 'on' === $mode ? ' (לכל קובץ)' : ' – רק כשהתמונה היא צילום עם אדם'; ?><?php echo $enh_ok ? '' : ' – <em>דורש מפתח deAPI</em>'; ?></label>
						<label><input type="checkbox" id="ofq-proof" <?php checked( $claude ); ?> <?php disabled( ! $claude ); ?>> הגהת סוכנים ובדיקת איות<?php echo $claude ? '' : ' – <em>דורש מפתח Anthropic</em>'; ?></label>
						<label><input type="checkbox" id="ofq-mail"> לשלוח גם את ה-PDF במייל</label>
					</div>
					<button type="submit" class="button button-primary button-hero ofq-go" id="ofq-go" disabled>התחלת שדרוג</button>
					<p class="ofq-msg" id="ofq-msg" role="status" aria-live="polite"></p>
				</form>
			</div>

			<div class="ofq-card" id="ofq-job" hidden>
				<div class="ofq-job-head"><h2 id="ofq-title"></h2><span class="ofq-badge" id="ofq-badge"></span></div>
				<div class="ofq-bar"><span id="ofq-bar"></span></div>
				<p class="ofq-step" id="ofq-step"></p>
				<div class="ofq-result" id="ofq-result" hidden>
					<div class="ofq-compare">
						<figure><figcaption>לפני <small id="ofq-size-in"></small></figcaption><a id="ofq-a-src" target="_blank" rel="noopener"><img id="ofq-img-src" alt="המקור"></a></figure>
						<figure><figcaption>אחרי <small id="ofq-size-out"></small></figcaption><a id="ofq-a-res" target="_blank" rel="noopener"><img id="ofq-img-res" alt="התוצאה"></a></figure>
					</div>
					<p class="ofq-facts" id="ofq-facts"></p>
					<p class="ofq-summary" id="ofq-summary"></p>
					<p class="ofq-dl" id="ofq-dl"></p>
				</div>
				<details class="ofq-log"><summary>יומן העיבוד</summary><pre id="ofq-log"></pre></details>
			</div>

			<div class="ofq-listhead">
				<h2 class="ofq-h2">הקבצים שנוצרו</h2>
				<div class="ofq-tools">
					<button type="button" class="button button-small" id="ofq-all">סימון הכל</button>
					<button type="button" class="button button-small ofq-danger" id="ofq-del" disabled>מחיקת המסומנים</button>
					<span class="ofq-confirm" id="ofq-confirm" hidden>
						<span id="ofq-confirm-t"></span>
						<button type="button" class="button button-small ofq-danger-solid" id="ofq-del-yes">כן, למחוק לצמיתות</button>
						<button type="button" class="button button-small" id="ofq-del-no">ביטול</button>
					</span>
				</div>
			</div>
			<p class="ofq-msg" id="ofq-listmsg" role="status" aria-live="polite"></p>
			<div class="ofq-list" id="ofq-list"><?php echo self::list_html(); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in list_html. ?></div>
		</div>
		<style>
			.ofq{max-width:1040px}.ofq-lead{color:#50575e;max-width:820px}.ofq-lead code{direction:ltr;unicode-bidi:embed;font-size:12px}
			.ofq-card{background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:20px;margin:16px 0}
			.ofq-drop{display:flex;flex-direction:column;align-items:center;justify-content:center;gap:6px;min-height:170px;border:2px dashed #b4b9be;border-radius:8px;cursor:pointer;text-align:center;padding:16px;transition:border-color .15s,background .15s}
			.ofq-drop:hover,.ofq-drop:focus,.ofq-drop.over{border-color:#2271b1;background:#f6f9fc;outline:none}
			.ofq-drop img{max-height:150px;max-width:100%;border-radius:4px}.ofq-drop-t{font-size:15px;font-weight:600}.ofq-drop-s{color:#646970;font-size:12px}
			.ofq-opts{display:flex;flex-direction:column;gap:8px;margin:16px 0}.ofq-opts em{color:#996800;font-style:normal}
			.ofq-msg{min-height:20px;margin:10px 0 0}.ofq-msg.bad{color:#b32d2e;font-weight:600}
			.ofq-job-head{display:flex;align-items:center;gap:10px;flex-wrap:wrap}.ofq-job-head h2{margin:0;font-size:16px;word-break:break-all}
			.ofq-badge{display:inline-block;padding:3px 10px;border-radius:4px;color:#fff;font-size:12px;font-weight:600}.ofq-badge:empty{display:none}
			.ofq-bar{height:10px;background:#f0f0f1;border-radius:5px;overflow:hidden;margin:14px 0 8px}.ofq-bar span{display:block;height:100%;width:0;background:#2271b1;transition:width .5s}
			.ofq-bar.ok span{background:#0f7b3f}.ofq-bar.bad span{background:#b32d2e}
			.ofq-step{margin:0;font-size:14px}.ofq-step.bad{color:#b32d2e;font-weight:600}
			.ofq-compare{display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-top:18px}.ofq-compare figure{margin:0}
			.ofq-compare figcaption{font-weight:600;margin-bottom:6px}.ofq-compare small{font-weight:400;color:#646970;direction:ltr;unicode-bidi:embed}
			.ofq-compare img{width:100%;height:auto;display:block;border:1px solid #dcdcde;border-radius:6px;background:repeating-conic-gradient(#f0f0f1 0 25%,#fff 0 50%) 0 0/16px 16px}
			.ofq-facts{color:#50575e;margin:14px 0 4px}.ofq-summary{margin:4px 0 0;max-width:820px}
			.ofq-dl{display:flex;gap:8px;flex-wrap:wrap;margin-top:14px}
			.ofq-log{margin-top:14px}.ofq-log summary{cursor:pointer;color:#50575e}.ofq-log pre{background:#1d2327;color:#dcdcde;padding:12px;border-radius:6px;white-space:pre-wrap;max-height:220px;overflow:auto;font-size:12px}
			.ofq [hidden]{display:none!important}
			.ofq-listhead{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-top:28px}.ofq-listhead .ofq-h2{margin:0}
			.ofq-tools{display:flex;align-items:center;gap:6px;flex-wrap:wrap}.ofq-confirm{display:inline-flex;align-items:center;gap:6px;flex-wrap:wrap;font-weight:600;color:#b32d2e}
			.ofq .ofq-danger{color:#b32d2e;border-color:#b32d2e}.ofq .ofq-danger-solid{background:#b32d2e;border-color:#b32d2e;color:#fff}
			.ofq-item{position:relative}.ofq-pick{position:absolute;top:8px;inset-inline-start:8px;z-index:2;background:#fff;border:1px solid #c3c4c7;border-radius:4px;padding:3px 5px;line-height:0;cursor:pointer}
			.ofq-pick input{margin:0;width:16px;height:16px}.ofq-item.sel{border-color:#b32d2e;box-shadow:0 0 0 1px #b32d2e}
			.ofq .screen-reader-text{position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0);white-space:nowrap}
			.ofq-h2{margin-top:28px}.ofq-list{display:grid;grid-template-columns:repeat(auto-fill,minmax(230px,1fr));gap:14px}
			.ofq-item{background:#fff;border:1px solid #dcdcde;border-radius:8px;overflow:hidden;display:flex;flex-direction:column}
			.ofq-thumb{aspect-ratio:4/3;background:#f6f7f7;display:flex;align-items:center;justify-content:center;color:#8c8f94;font-size:12px}
			.ofq-thumb img{width:100%;height:100%;object-fit:contain;display:block}.ofq-thumb a{display:block;width:100%;height:100%}
			.ofq-meta{padding:10px 12px 4px;display:flex;flex-direction:column;gap:2px;font-size:12px;color:#50575e}
			.ofq-meta b{color:#1d2327;font-size:13px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.ofq-state{font-weight:600}.ofq-dims{text-align:right}
			.ofq-acts{padding:8px 12px 12px;display:flex;gap:6px;flex-wrap:wrap;margin-top:auto}.ofq-empty{color:#646970}
			.ofq-front{max-width:1040px;margin-left:auto;margin-right:auto;font-size:14px;line-height:1.6;color:#1d2327}
			.ofq-front h1{font-size:26px;font-weight:600;margin:0 0 6px}.ofq-front h2{font-size:18px}
			.ofq-front .button{display:inline-block;padding:6px 14px;border:1px solid #2271b1;border-radius:4px;background:#f6f7f7;color:#2271b1;font:inherit;font-size:13px;text-decoration:none;cursor:pointer;line-height:1.5}
			.ofq-front .button:hover{background:#eef4fa}.ofq-front .button-primary{background:#2271b1;color:#fff}.ofq-front .button-primary:hover{background:#135e96}
			.ofq-front .button-hero{padding:10px 30px;font-size:15px}.ofq-front .button-small{padding:2px 10px;font-size:12px}
			.ofq-front .button:disabled{opacity:.5;cursor:default}.ofq-front .button-link{border:0;background:none;text-decoration:underline}
			.ofq-front .ofq-warn{background:#fcf9e8;border:1px solid #dba617;border-radius:6px;padding:2px 14px}
			.ofq-denied{font-size:15px}
			@media (max-width:700px){.ofq-compare{grid-template-columns:1fr}}
		</style>
		<script>
		(function () {
			var C = <?php echo wp_json_encode( $cfg ); ?>;
			var $ = function (id) { return document.getElementById(id); };
			var drop = $('ofq-drop'), input = $('ofq-file'), go = $('ofq-go'), msg = $('ofq-msg'), file = null, running = false, current = 0;

			function say(t, bad) { msg.textContent = t || ''; msg.className = 'ofq-msg' + (bad ? ' bad' : ''); }
			function pick(f) {
				if (!f) return;
				var ext = (f.name.split('.').pop() || '').toLowerCase();
				if (C.ext.indexOf(ext) < 0) { say('אפשר להעלות PNG, JPG, WEBP או PDF', 1); return; }
				if (f.size > C.maxBytes) { say('הקובץ גדול מ-' + C.maxText, 1); return; }
				file = f; say('');
				$('ofq-drop-t').textContent = f.name + ' · ' + (f.size / 1048576).toFixed(1) + 'MB';
				var prev = $('ofq-prev');
				if (/^image\//.test(f.type)) { prev.src = URL.createObjectURL(f); prev.hidden = false; } else { prev.hidden = true; }
				go.disabled = running;
			}
			input.addEventListener('change', function () { pick(input.files[0]); });
			drop.addEventListener('keydown', function (e) { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); input.click(); } });
			['dragenter', 'dragover'].forEach(function (ev) { drop.addEventListener(ev, function (e) { e.preventDefault(); drop.classList.add('over'); }); });
			['dragleave', 'drop'].forEach(function (ev) { drop.addEventListener(ev, function (e) { e.preventDefault(); drop.classList.remove('over'); }); });
			drop.addEventListener('drop', function (e) { if (e.dataTransfer && e.dataTransfer.files[0]) pick(e.dataTransfer.files[0]); });

			function post(action, data) {
				var fd = new FormData();
				fd.append('action', action);
				if (C.key) { fd.append('k', C.key); } else { fd.append('nonce', C.nonce); }
				Object.keys(data || {}).forEach(function (k) { fd.append(k, data[k]); });
				return fetch(C.ajax, { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) {
					return r.text().then(function (t) {
						var j = null; try { j = JSON.parse(t); } catch (e) {}
						if (!j) throw new Error('השרת החזיר תשובה לא תקינה (קוד ' + r.status + ')');
						if (!j.success) throw new Error((j.data && j.data.message) || 'שגיאה (קוד ' + r.status + ')');
						return j.data;
					});
				});
			}
			function wait(ms) { return new Promise(function (res) { setTimeout(res, ms); }); }

			function render(d) {
				$('ofq-job').hidden = false;
				$('ofq-title').textContent = '#' + d.job + ' · ' + d.name;
				var badge = $('ofq-badge'); badge.textContent = d.verdict || ''; badge.style.background = d.vcolor || '#555';
				var bar = $('ofq-bar'); bar.style.width = d.pct + '%';
				bar.parentNode.className = 'ofq-bar' + (d.done ? ' ok' : (d.failed ? ' bad' : ''));
				var step = $('ofq-step');
				step.textContent = d.text + (d.retry && !d.done && !d.failed ? ' (ניסיון ' + (d.retry + 1) + ' מתוך 3)' : '');
				step.className = 'ofq-step' + (d.failed ? ' bad' : '');
				$('ofq-log').textContent = (d.log || []).join('\n');
				var res = $('ofq-result');
				if (d.views && d.views.result) {
					res.hidden = false;
					if (d.views.source && $('ofq-img-src').getAttribute('src') !== d.views.source) { $('ofq-img-src').src = d.views.source; $('ofq-a-src').href = d.views.source; }
					if ($('ofq-img-res').getAttribute('src') !== d.views.result) { $('ofq-img-res').src = d.views.result; $('ofq-a-res').href = d.views.result; }
					$('ofq-size-in').textContent = d.size_in; $('ofq-size-out').textContent = d.size_out;
					var facts = [];
					if (d.enhance) facts.push('שיפור בפרומפט: ' + d.enhance);
					if (d.engine) facts.push('הגדלה: ' + d.engine);
					$('ofq-facts').textContent = facts.join(' · ') + (d.note ? ' — ' + d.note : '');
					$('ofq-summary').textContent = d.summary || '';
					var dl = $('ofq-dl'); dl.innerHTML = '';
					[['png', 'הורדת PNG ברזולוציה מלאה', d.bytes_png, 1], ['pdf', 'הורדת PDF להדפסה', d.bytes_pdf, 0], ['original', 'הקובץ המקורי', '', 0]].forEach(function (x) {
						if (!d.files || !d.files[x[0]]) return;
						var a = document.createElement('a'); a.href = d.files[x[0]]; a.className = 'button' + (x[3] ? ' button-primary' : '');
						a.textContent = x[1] + (x[2] ? ' (' + x[2] + ')' : ''); dl.appendChild(a);
					});
					if (d.report) { var r = document.createElement('a'); r.href = d.report; r.className = 'button button-link'; r.textContent = 'הדוח המלא'; dl.appendChild(r); }
				} else { res.hidden = true; }
			}

			function refreshList() {
				var keep = picked();
				return post('ofp_quick_list').then(function (d) {
					$('ofq-list').innerHTML = d.html;
					keep.forEach(function (id) { var c = document.querySelector('#ofq-list .ofq-check[value="' + id + '"]'); if (c) c.checked = true; });
					syncPick();
				}).catch(function () {});
			}

			function follow(job) {
				running = true; current = job; go.disabled = true;
				var errors = 0;
				function loop() {
					if (current !== job) return;
					return post('ofp_quick_status', { job: job, drive: 1 }).then(function (d) {
						errors = 0; render(d);
						if (d.done || d.failed) { running = false; go.disabled = !file; return refreshList(); }
						return wait(d.busy ? 4000 : 1500).then(loop);
					}).catch(function (e) {
						errors++;
						if (errors >= 6) {
							running = false; go.disabled = !file;
							$('ofq-step').textContent = 'אבד הקשר עם השרת (' + e.message + '). העבודה ממשיכה ברקע – אפשר לחזור אליה מרשימת הקבצים למטה.';
							$('ofq-step').className = 'ofq-step bad';
							return refreshList();
						}
						return wait(5000).then(loop);
					});
				}
				return loop();
			}

			$('ofq-form').addEventListener('submit', function (e) {
				e.preventDefault();
				if (!file || running) return;
				running = true; go.disabled = true; say('מעלה את הקובץ…');
				$('ofq-result').hidden = true; $('ofq-img-src').removeAttribute('src'); $('ofq-img-res').removeAttribute('src');
				var data = { file: file };
				if ($('ofq-enhance').checked) data.enhance = 1;
				if ($('ofq-proof').checked) data.proof = 1;
				if ($('ofq-mail').checked) data.mail = 1;
				post('ofp_quick_upload', data).then(function (d) {
					say('');
					$('ofq-job').scrollIntoView({ behavior: 'smooth', block: 'start' });
					return follow(d.job);
				}).catch(function (err) { running = false; go.disabled = !file; say(err.message, 1); });
			});

			/* ---- deleting files ---- */
			var delBtn = $('ofq-del'), conf = $('ofq-confirm'), listMsg = $('ofq-listmsg');
			function picked() { return Array.prototype.map.call(document.querySelectorAll('#ofq-list .ofq-check:checked'), function (c) { return c.value; }); }
			function syncPick() {
				Array.prototype.forEach.call(document.querySelectorAll('#ofq-list .ofq-item'), function (it) { var c = it.querySelector('.ofq-check'); it.classList.toggle('sel', !!(c && c.checked)); });
				var n = picked().length;
				delBtn.disabled = !n; delBtn.textContent = n ? 'מחיקת המסומנים (' + n + ')' : 'מחיקת המסומנים';
				conf.hidden = true; delBtn.hidden = false;
				var all = document.querySelectorAll('#ofq-list .ofq-check').length;
				$('ofq-all').textContent = all && n === all ? 'ביטול הסימון' : 'סימון הכל';
				$('ofq-all').disabled = !all;
			}
			$('ofq-list').addEventListener('change', function (e) { if (e.target.classList && e.target.classList.contains('ofq-check')) { listMsg.textContent = ''; syncPick(); } });
			$('ofq-all').addEventListener('click', function () {
				var boxes = document.querySelectorAll('#ofq-list .ofq-check'), on = picked().length !== boxes.length;
				Array.prototype.forEach.call(boxes, function (c) { c.checked = on; }); listMsg.textContent = ''; syncPick();
			});
			delBtn.addEventListener('click', function () {
				var n = picked().length; if (!n) return;
				$('ofq-confirm-t').textContent = (n === 1 ? 'למחוק עבודה אחת' : 'למחוק ' + n + ' עבודות') + ' עם כל הקבצים (מקור, תוצאה, PDF)? אי אפשר לשחזר.';
				delBtn.hidden = true; conf.hidden = false;
			});
			$('ofq-del-no').addEventListener('click', syncPick);
			$('ofq-del-yes').addEventListener('click', function () {
				var ids = picked(); if (!ids.length) return;
				$('ofq-del-yes').disabled = true;
				post('ofp_quick_delete', { jobs: ids.join(',') }).then(function (d) {
					$('ofq-list').innerHTML = d.html; listMsg.textContent = d.message; listMsg.className = 'ofq-msg';
					if (current && ids.indexOf(String(current)) > -1 && d.deleted) { current = 0; running = false; go.disabled = !file; $('ofq-job').hidden = true; }
				}).catch(function (err) { listMsg.textContent = err.message; listMsg.className = 'ofq-msg bad'; })
					.then(function () { $('ofq-del-yes').disabled = false; syncPick(); });
			});
			syncPick();

			$('ofq-list').addEventListener('click', function (e) {
				var b = e.target.closest ? e.target.closest('.ofq-resume') : null;
				if (!b || running) return;
				$('ofq-result').hidden = true;
				follow(parseInt(b.getAttribute('data-job'), 10));
				$('ofq-job').scrollIntoView({ behavior: 'smooth', block: 'start' });
			});
		})();
		</script>
		<?php
	}
}
