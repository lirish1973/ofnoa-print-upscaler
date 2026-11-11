<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Customer-facing upload form: [ofnoa_upscaler]
 */
class OFP_Frontend {

	const EXT = array( 'png', 'jpg', 'jpeg', 'webp', 'pdf' );

	public static function init() {
		add_shortcode( 'ofnoa_upscaler', array( __CLASS__, 'shortcode' ) );
		add_action( 'wp_ajax_ofp_upload', array( __CLASS__, 'upload' ) );
		add_action( 'wp_ajax_nopriv_ofp_upload', array( __CLASS__, 'upload' ) );
		add_action( 'wp_ajax_ofp_status', array( __CLASS__, 'status' ) );
		add_action( 'wp_ajax_nopriv_ofp_status', array( __CLASS__, 'status' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'register_assets' ) );
	}

	public static function register_assets() {
		wp_register_style( 'ofp', OFP_URL . 'assets/ofp.css', array(), OFP_VERSION );
		wp_register_script( 'ofp', OFP_URL . 'assets/ofp.js', array(), OFP_VERSION, true );
	}

	public static function shortcode( $atts ) {
		$atts = shortcode_atts(
			array(
				'title'    => 'שדרוג גרפיקה ל-4K',
				'subtitle' => 'העלו לוגו או גרפיקה – מנוע ה-AI שלנו יחדד אותה לרזולוציית 4K בלי לשנות אף פרט, והצוות יעבור עליה לפני ההדפסה.',
			),
			$atts,
			'ofnoa_upscaler'
		);
		self::register_assets();
		wp_enqueue_style( 'ofp' );
		wp_enqueue_script( 'ofp' );
		$max = (int) OFP_Settings::get( 'max_upload_mb' );
		wp_localize_script(
			'ofp',
			'OFP',
			array(
				'ajax'  => admin_url( 'admin-ajax.php' ),
				'nonce' => wp_create_nonce( 'ofp_upload' ),
				'maxMb' => $max,
				'ext'   => self::EXT,
				'msg'   => array(
					'success' => OFP_Settings::get( 'success_text' ),
					'tooBig'  => 'הקובץ גדול מ-' . $max . 'MB',
					'badType' => 'אפשר להעלות PNG, JPG, WEBP או PDF',
					'error'   => 'משהו השתבש. נסו שוב או שלחו לנו בוואטסאפ.',
				),
			)
		);
		ob_start();
		?>
		<div class="ofp" dir="rtl">
			<div class="ofp-head">
				<h3 class="ofp-title"><?php echo esc_html( $atts['title'] ); ?></h3>
				<p class="ofp-sub"><?php echo esc_html( $atts['subtitle'] ); ?></p>
			</div>
			<form class="ofp-form" novalidate>
				<label class="ofp-drop" tabindex="0">
					<input type="file" name="file" accept=".png,.jpg,.jpeg,.webp,.pdf,image/png,image/jpeg,image/webp,application/pdf" required hidden>
					<span class="ofp-drop-ic" aria-hidden="true">⇪</span>
					<span class="ofp-drop-t">גררו לכאן קובץ או לחצו לבחירה</span>
					<span class="ofp-drop-s">PNG · JPG · WEBP · PDF · עד <?php echo (int) $max; ?>MB</span>
					<img class="ofp-prev" alt="" hidden>
					<span class="ofp-fname"></span>
				</label>
				<div class="ofp-grid">
					<label>שם מלא *<input type="text" name="name" required autocomplete="name"></label>
					<label>טלפון *<input type="tel" name="phone" required autocomplete="tel"></label>
					<label>אימייל<input type="email" name="email" autocomplete="email"></label>
					<label>מה מדפיסים? (לא חובה)<input type="text" name="notes" placeholder="למשל: 50 חולצות שחורות, הדפס גב"></label>
				</div>
				<input type="text" name="website" class="ofp-hp" tabindex="-1" autocomplete="off" aria-hidden="true">
				<label class="ofp-consent"><input type="checkbox" name="consent" value="1" required> <?php echo esc_html( OFP_Settings::get( 'consent_text' ) ); ?></label>
				<button type="submit" class="ofp-btn">שליחה לשדרוג והגהה</button>
				<div class="ofp-progress" hidden><div class="ofp-bar"><span></span></div><p class="ofp-ptxt">מעלה…</p></div>
				<p class="ofp-msg" role="status" aria-live="polite"></p>
			</form>
			<ul class="ofp-steps" aria-hidden="true">
				<li><b>1</b> הגדלת AI ל-4K</li>
				<li><b>2</b> בדיקת נאמנות למקור</li>
				<li><b>3</b> הגהה ובדיקת איות</li>
				<li><b>4</b> PDF מוכן להדפסה</li>
			</ul>
		</div>
		<?php
		return ob_get_clean();
	}

	/** Validate an uploaded file array. Returns array( ext, safe original name ) or WP_Error. */
	public static function validate_upload( $f ) {
		if ( empty( $f ) || ! isset( $f['error'] ) || UPLOAD_ERR_OK !== (int) $f['error'] ) {
			$code = isset( $f['error'] ) ? (int) $f['error'] : -1;
			$why  = in_array( $code, array( UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE ), true ) ? 'הקובץ גדול ממגבלת ההעלאה של השרת (' . size_format( wp_max_upload_size() ) . ')' : 'לא התקבל קובץ (קוד ' . $code . ')';
			return new WP_Error( 'ofp_upload', $why );
		}
		$max  = (int) OFP_Settings::get( 'max_upload_mb' ) * MB_IN_BYTES;
		$orig = sanitize_file_name( wp_unslash( $f['name'] ) );
		if ( $f['size'] > $max ) {
			return new WP_Error( 'ofp_upload', 'הקובץ גדול מ-' . size_format( $max ) );
		}
		$check = wp_check_filetype_and_ext(
			$f['tmp_name'],
			$orig,
			array(
				'png'      => 'image/png',
				'jpg|jpeg' => 'image/jpeg',
				'webp'     => 'image/webp',
				'pdf'      => 'application/pdf',
			)
		);
		$ext   = strtolower( (string) $check['ext'] );
		if ( ! $ext || ! in_array( $ext, self::EXT, true ) ) {
			return new WP_Error( 'ofp_upload', 'סוג קובץ לא נתמך – אפשר להעלות PNG, JPG, WEBP או PDF' );
		}
		if ( 'pdf' !== $ext ) {
			$sz = @getimagesize( $f['tmp_name'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			if ( ! $sz || $sz[0] < 16 || $sz[1] < 16 || $sz[0] * $sz[1] > 60000000 ) {
				return new WP_Error( 'ofp_upload', 'מידות התמונה לא נתמכות (מינימום 16 פיקסלים, מקסימום 60 מגה-פיקסל)' );
			}
		}
		return array( $ext, $orig );
	}

	private static function ip() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		return substr( $ip, 0, 64 );
	}

	public static function upload() {
		if ( ! check_ajax_referer( 'ofp_upload', 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => 'פג תוקף הטופס – רעננו את העמוד' ), 403 );
		}
		// phpcs:disable WordPress.Security.NonceVerification -- verified above.
		if ( ! empty( $_POST['website'] ) ) {
			wp_send_json_success( array( 'token' => '' ) ); // Honeypot – silently ignore bots.
		}
		$ip = self::ip();
		if ( OFP_Jobs::count_recent_by_ip( $ip ) >= (int) OFP_Settings::get( 'rate_limit_hour' ) ) {
			wp_send_json_error( array( 'message' => 'יותר מדי העלאות בשעה האחרונה. נסו שוב מאוחר יותר.' ), 429 );
		}
		$name  = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
		$phone = isset( $_POST['phone'] ) ? sanitize_text_field( wp_unslash( $_POST['phone'] ) ) : '';
		$email = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
		$notes = isset( $_POST['notes'] ) ? sanitize_textarea_field( wp_unslash( $_POST['notes'] ) ) : '';
		if ( ! $name || ! $phone || empty( $_POST['consent'] ) ) {
			wp_send_json_error( array( 'message' => 'נא למלא שם, טלפון ולאשר את התנאים' ), 400 );
		}
		if ( empty( $_FILES['file'] ) || UPLOAD_ERR_OK !== $_FILES['file']['error'] ) {
			wp_send_json_error( array( 'message' => 'לא התקבל קובץ' ), 400 );
		}
		$f    = $_FILES['file']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$max  = (int) OFP_Settings::get( 'max_upload_mb' ) * MB_IN_BYTES;
		$orig = sanitize_file_name( wp_unslash( $f['name'] ) );
		// phpcs:enable
		if ( $f['size'] > $max ) {
			wp_send_json_error( array( 'message' => 'הקובץ גדול מדי' ), 400 );
		}
		$check = wp_check_filetype_and_ext(
			$f['tmp_name'],
			$orig,
			array(
				'png'      => 'image/png',
				'jpg|jpeg' => 'image/jpeg',
				'webp'     => 'image/webp',
				'pdf'      => 'application/pdf',
			)
		);
		$ext = strtolower( (string) $check['ext'] );
		if ( ! $ext || ! in_array( $ext, self::EXT, true ) ) {
			wp_send_json_error( array( 'message' => 'סוג קובץ לא נתמך' ), 400 );
		}
		if ( 'pdf' !== $ext ) {
			$sz = @getimagesize( $f['tmp_name'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			if ( ! $sz || $sz[0] < 16 || $sz[1] < 16 || $sz[0] * $sz[1] > 60000000 ) {
				wp_send_json_error( array( 'message' => 'מידות התמונה לא נתמכות' ), 400 );
			}
		}
		$id  = OFP_Jobs::create(
			array(
				'customer_name'  => $name,
				'customer_phone' => $phone,
				'customer_email' => $email,
				'notes'          => $notes,
				'original_name'  => $orig,
				'ip'             => $ip,
				'status'         => 'receiving',
			)
		);
		$job = OFP_Jobs::get( $id );
		$dir = OFP_Jobs::job_dir( $job );
		$dst = $dir . '/original.' . $ext;
		if ( ! move_uploaded_file( $f['tmp_name'], $dst ) ) {
			OFP_Jobs::update( $id, array( 'status' => 'failed' ) );
			wp_send_json_error( array( 'message' => 'שמירת הקובץ נכשלה' ), 500 );
		}
		OFP_Jobs::update(
			$id,
			array(
				'original_path' => $dst,
				'status'        => 'queued',
			)
		);
		OFP_Jobs::log( $id, 'התקבל קובץ ' . $orig . ' (' . size_format( filesize( $dst ) ) . ')' );
		OFP_Processor::kick();
		wp_send_json_success(
			array(
				'token'   => $job->token,
				'message' => OFP_Settings::get( 'success_text' ),
			)
		);
	}

	/** Customer-safe progress only – no report, no files. */
	public static function status() {
		$token = isset( $_GET['t'] ) ? sanitize_text_field( wp_unslash( $_GET['t'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$job   = $token ? OFP_Jobs::by_token( $token ) : null;
		if ( ! $job ) {
			wp_send_json_error( array(), 404 );
		}
		$map = array(
			'queued'    => array( 10, 'מכינים את הקובץ…' ),
			'enhancing' => array( 22, 'מנוע ה-AI משפר תאורה ואיכות…' ),
			'upscaling' => array( 35, 'מנוע ה-AI מחדד את הגרפיקה ל-4K…' ),
			'verifying' => array( 55, 'בודקים שלא השתנה אף פרט…' ),
			'proofing'  => array( 75, 'סוכני ההגהה עוברים על הטקסט…' ),
			'building'  => array( 90, 'מכינים PDF להדפסה…' ),
			'mailing'   => array( 95, 'שולחים לצוות…' ),
			'done'      => array( 100, 'הושלם! הצוות קיבל את הקובץ המשודרג ויחזור אליכם.' ),
			'failed'    => array( 100, 'הקובץ התקבל והצוות יטפל בו ידנית.' ),
		);
		$s   = isset( $map[ $job->status ] ) ? $map[ $job->status ] : array( 5, 'בתור…' );
		wp_send_json_success(
			array(
				'pct'  => $s[0],
				'text' => $s[1],
				'done' => in_array( $job->status, array( 'done', 'failed' ), true ),
			)
		);
	}
}
