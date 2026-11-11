<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin screens: jobs list / job detail / settings.
 */
class OFP_Admin {

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_post_ofp_job_action', array( __CLASS__, 'job_action' ) );
		add_action( 'wp_ajax_ofp_test', array( __CLASS__, 'test_connections' ) );
	}

	public static function menu() {
		add_menu_page( 'שדרוג גרפיקה', 'שדרוג גרפיקה', 'manage_options', 'ofp-jobs', array( __CLASS__, 'jobs_page' ), 'dashicons-format-image', 58 );
		add_submenu_page( 'ofp-jobs', 'עבודות', 'עבודות', 'manage_options', 'ofp-jobs', array( __CLASS__, 'jobs_page' ) );
		add_submenu_page( 'ofp-jobs', 'הגדרות', 'הגדרות', 'manage_options', 'ofp-settings', array( __CLASS__, 'settings_page' ) );
	}

	private static function badge( $s ) {
		$c = array(
			'done'         => '#0F7B3F',
			'failed'       => '#B00020',
			'approved'     => '#0F7B3F',
			'needs_review' => '#B36B00',
			'rejected'     => '#B00020',
			'unchecked'    => '#555',
		);
		$col = isset( $c[ $s ] ) ? $c[ $s ] : '#555';
		return '<span style="display:inline-block;padding:2px 8px;border-radius:4px;background:' . $col . ';color:#fff;font-size:12px">' . esc_html( $s ? $s : '—' ) . '</span>';
	}

	public static function jobs_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$id = isset( $_GET['job'] ) ? absint( $_GET['job'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification
		echo '<div class="wrap" dir="rtl">';
		if ( $id && ( $job = OFP_Jobs::get( $id ) ) ) { // phpcs:ignore
			self::job_detail( $job );
			echo '</div>';
			return;
		}
		echo '<h1>שדרוג גרפיקה – עבודות</h1>';
		if ( ! OFP_Engines::order() || ! OFP_Settings::secret( 'anthropic_key' ) ) {
			echo '<div class="notice notice-warning"><p>' . ( OFP_Engines::order() ? 'חסר מפתח Anthropic לסוכני ההגהה' : 'לא מוגדר אף מנוע הגדלה פעיל (קבצים יוגדלו ב-Lanczos בלבד)' ) . ' – <a href="' . esc_url( admin_url( 'admin.php?page=ofp-settings' ) ) . '">להגדרות</a>.</p></div>';
		}
		echo '<p><a class="button button-primary" href="' . esc_url( OFP_Quick::url() ) . '">שדרוג מהיר – העלאה וצפייה בקבצים</a></p>';
		echo '<p>טופס ההעלאה ללקוחות: שורטקוד <code>[ofnoa_upscaler]</code> או הווידג\'ט "שדרוג גרפיקה 4K" באלמנטור.</p>';
		$rows = OFP_Jobs::list_recent( 100 );
		echo '<table class="widefat striped"><thead><tr><th>#</th><th>קובץ</th><th>לקוח</th><th>רזולוציה</th><th>סטטוס</th><th>הגהה</th><th>נוצר</th></tr></thead><tbody>';
		if ( ! $rows ) {
			echo '<tr><td colspan="7">עדיין אין עבודות.</td></tr>';
		}
		foreach ( $rows as $r ) {
			$url = admin_url( 'admin.php?page=ofp-jobs&job=' . (int) $r->id );
			echo '<tr><td><a href="' . esc_url( $url ) . '">' . (int) $r->id . '</a></td><td><a href="' . esc_url( $url ) . '">' . esc_html( $r->original_name ) . '</a></td><td>' . esc_html( $r->customer_name ) . '</td><td>' . (int) $r->width . '×' . (int) $r->height . ' → ' . (int) $r->out_width . '×' . (int) $r->out_height . '</td><td>' . self::badge( $r->status ) . '</td><td>' . self::badge( $r->verdict ) . '</td><td>' . esc_html( get_date_from_gmt( $r->created_at, 'd/m/Y H:i' ) ) . '</td></tr>';
		}
		echo '</tbody></table></div>';
	}

	private static function action_form( $job, $do, $label, $class = 'button' ) {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline-block;margin-left:6px">';
		wp_nonce_field( 'ofp_job_' . $job->id );
		echo '<input type="hidden" name="action" value="ofp_job_action"><input type="hidden" name="job" value="' . (int) $job->id . '"><input type="hidden" name="do" value="' . esc_attr( $do ) . '">';
		echo '<button class="' . esc_attr( $class ) . '">' . esc_html( $label ) . '</button></form>';
	}

	private static function job_detail( $job ) {
		echo '<p><a href="' . esc_url( admin_url( 'admin.php?page=ofp-jobs' ) ) . '">← לכל העבודות</a></p>';
		echo '<h1>עבודה #' . (int) $job->id . ' ' . self::badge( $job->status ) . ' ' . self::badge( $job->verdict ) . '</h1>'; // phpcs:ignore
		echo '<p>';
		self::action_form( $job, 'rerun_proof', 'הרצת הגהה מחדש' );
		self::action_form( $job, 'restart', 'עיבוד מחדש מההתחלה' );
		self::action_form( $job, 'resend', 'שליחת המייל שוב' );
		self::action_form( $job, 'delete', 'מחיקה', 'button button-link-delete' );
		echo '</p>';
		echo '<div style="background:#fff;border:1px solid #ddd;border-radius:8px;padding:20px;max-width:900px">' . OFP_Mailer::report_html( $job ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput -- built with escaping.
		echo '<h2>לוג</h2><pre style="background:#111;color:#ddd;padding:14px;border-radius:6px;max-height:340px;overflow:auto;direction:rtl;white-space:pre-wrap">' . esc_html( $job->log ) . '</pre>';
	}

	public static function job_action() {
		$id = isset( $_POST['job'] ) ? absint( $_POST['job'] ) : 0;
		check_admin_referer( 'ofp_job_' . $id );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'forbidden' );
		}
		$job = OFP_Jobs::get( $id );
		$do  = isset( $_POST['do'] ) ? sanitize_key( $_POST['do'] ) : '';
		if ( $job ) {
			if ( 'restart' === $do ) {
				$old  = json_decode( (string) $job->report, true );
				$keep = is_array( $old ) && isset( $old['opts'] ) ? wp_json_encode( array( 'opts' => $old['opts'] ), JSON_UNESCAPED_UNICODE ) : '';
				OFP_Jobs::update( $id, array( 'status' => 'queued', 'step_attempts' => 0, 'verdict' => '', 'report' => $keep ) );
				OFP_Jobs::log( $id, 'הופעל מחדש ידנית' );
				OFP_Processor::kick();
			} elseif ( 'rerun_proof' === $do ) {
				$r = json_decode( (string) $job->report, true );
				$r = is_array( $r ) ? $r : array();
				unset( $r['agents'], $r['text_diff'] );
				OFP_Jobs::update( $id, array( 'status' => 'proofing', 'step_attempts' => 0, 'verdict' => '', 'report' => wp_json_encode( $r, JSON_UNESCAPED_UNICODE ) ) );
				OFP_Jobs::log( $id, 'הגהה מחדש הופעלה ידנית' );
				OFP_Processor::kick();
			} elseif ( 'resend' === $do ) {
				$ok = OFP_Mailer::send_result( $job );
				OFP_Jobs::log( $id, is_wp_error( $ok ) ? 'שליחה חוזרת נכשלה' : 'נשלח שוב ידנית' );
			} elseif ( 'delete' === $do ) {
				OFP_Jobs::delete( $job );
				wp_safe_redirect( admin_url( 'admin.php?page=ofp-jobs' ) );
				exit;
			}
		}
		wp_safe_redirect( admin_url( 'admin.php?page=ofp-jobs&job=' . $id ) );
		exit;
	}

	public static function test_connections() {
		check_ajax_referer( 'ofp_test' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error();
		}
		$out   = array();
		$order = OFP_Engines::order();
		$out['order'] = $order ? 'סדר המנועים: ' . implode( ' ← ', array_map( array( 'OFP_Engines', 'label' ), $order ) ) . ' ← Lanczos (גיבוי)' : '⚠️ אין מנוע AI פעיל – רק Lanczos';
		if ( OFP_Settings::secret( 'freeai_key' ) ) {
			$t             = OFP_Engines::freeai_test();
			$out['freeai'] = is_wp_error( $t ) ? '❌ free.ai: ' . $t->get_error_message() : '✅ free.ai: ' . $t;
		} else {
			$out['freeai'] = '— free.ai: אין מפתח';
		}
		if ( OFP_Settings::secret( 'deapi_key' ) ) {
			$t            = OFP_Engines::deapi_test();
			$out['deapi'] = is_wp_error( $t ) ? '❌ deAPI: ' . $t->get_error_message() : '✅ deAPI: ' . $t;
		} else {
			$out['deapi'] = '— deAPI: אין מפתח';
		}
		if ( 'off' !== OFP_Settings::get( 'enhance_mode' ) ) {
			if ( OFP_Engines::enhance_ready() ) {
				foreach ( OFP_Engines::enhance_tests() as $i => $line ) {
					$out[ 'enhance' . $i ] = $line;
				}
			} else {
				$out['enhance'] = '⚠️ שיפור בפרומפט: מופעל אבל אין מפתח Gemini או deAPI – ידולג';
			}
		} else {
			$out['enhance'] = '— שיפור בפרומפט: כבוי';
		}
		$out['local'] = ( (int) OFP_Settings::get( 'prio_local' ) > 0 ? '' : '— ' ) . 'מחשב מקומי: ' . OFP_Worker::last_seen() . ( (int) OFP_Settings::get( 'prio_local' ) > 0 ? '' : ' (כבוי)' );
		delete_transient( 'ofp_replicate_version' );
		if ( OFP_Settings::secret( 'replicate_token' ) ) {
			$v                = OFP_Replicate::version();
			$out['replicate'] = is_wp_error( $v ) ? '❌ Replicate: ' . $v->get_error_message() : '✅ Replicate: מחובר (גרסת מודל ' . substr( $v, 0, 10 ) . '…)';
		} else {
			$out['replicate'] = '— Replicate: אין טוקן';
		}
		if ( OFP_Settings::secret( 'anthropic_key' ) ) {
			$c            = OFP_Claude::call( 'Reply with JSON only: {"ok":true}', array( array( 'type' => 'text', 'text' => 'ping' ) ), 20 );
			$out['claude'] = is_wp_error( $c ) ? '❌ ' . $c->get_error_message() : '✅ מחובר (' . esc_html( OFP_Settings::get( 'claude_model' ) ) . ')';
		} else {
			$out['claude'] = '❌ אין מפתח';
		}
		$u             = OFP_Updater::status_line();
		$out['update'] = is_wp_error( $u ) ? '⚠️ עדכונים: ' . $u->get_error_message() : '✅ עדכונים מ-GitHub: ' . $u . ( 'on' === OFP_Settings::get( 'auto_update' ) ? ' · עדכון אוטומטי פעיל' : ' · עדכון אוטומטי כבוי' );
		$out['gd']      = function_exists( 'imagecreatetruecolor' ) ? '✅ GD' : '❌ GD חסר';
		$out['imagick'] = class_exists( 'Imagick' ) ? '✅ Imagick (קבלת PDF כקלט)' : '⚠️ אין Imagick – קבצי PDF כקלט לא ייתמכו';
		$out['cron']    = wp_next_scheduled( OFP_Processor::HOOK ) ? '✅ תזמון פעיל' : '⚠️ תזמון לא פעיל';
		wp_send_json_success( $out );
	}

	public static function settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$o = OFP_Settings::get();
		$n = OFP_Settings::OPTION;
		$f = function ( $key, $label, $type = 'text', $help = '', $attrs = '' ) use ( $o, $n ) {
			$val = $o[ $key ];
			$secrets = OFP_Settings::secret_keys();
			if ( isset( $secrets[ $key ] ) ) {
				$const = $secrets[ $key ];
				if ( defined( $const ) ) {
					echo '<tr><th>' . esc_html( $label ) . '</th><td>מוגדר ב-wp-config.php (' . esc_html( $const ) . ')</td></tr>';
					return;
				}
				$val = OFP_Settings::mask( $val );
			}
			echo '<tr><th><label for="ofp_' . esc_attr( $key ) . '">' . esc_html( $label ) . '</label></th><td>';
			if ( 'textarea' === $type ) {
				echo '<textarea class="large-text" rows="2" id="ofp_' . esc_attr( $key ) . '" name="' . esc_attr( $n . '[' . $key . ']' ) . '">' . esc_textarea( $val ) . '</textarea>';
			} else {
				echo '<input class="regular-text" type="' . esc_attr( $type ) . '" id="ofp_' . esc_attr( $key ) . '" name="' . esc_attr( $n . '[' . $key . ']' ) . '" value="' . esc_attr( $val ) . '" ' . $attrs . ' autocomplete="off">'; // phpcs:ignore
			}
			if ( $help ) {
				echo '<p class="description">' . wp_kses_post( $help ) . '</p>';
			}
			echo '</td></tr>';
		};
		echo '<div class="wrap" dir="rtl"><h1>שדרוג גרפיקה – הגדרות</h1><form method="post" action="options.php">';
		settings_fields( 'ofp_settings_group' );
		if ( isset( $_GET['ofp_rotated'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			echo '<div class="notice notice-success"><p>נוצר מפתח חדש למחשב המקומי – הורד שוב את סקריפט ה-worker.</p></div>';
		}
		echo '<h2>מנועי הגדלה (Real-ESRGAN)</h2>';
		echo '<p class="description" style="max-width:820px">כל המנועים מריצים את אותו מודל – Real-ESRGAN, הגדלה טהורה בלי הנחיות טקסט ובלי שיפור פנים. העבודה נשלחת למנוע עם העדיפות הנמוכה ביותר (1 = ראשון). אם הוא נכשל, אזלה המכסה או לא ענה בזמן – עוברים אוטומטית למנוע הבא, ואם כולם נכשלו הקובץ מוגדל ב-Lanczos קלאסי. 0 = כבוי. מנוע בלי מפתח מדולג.</p>';
		echo '<table class="widefat striped" style="max-width:820px;margin-bottom:12px"><thead><tr><th>מנוע</th><th style="width:110px">עדיפות</th><th>עלות</th></tr></thead><tbody>';
		$costs = array(
			'freeai'    => '30,000 טוקנים חינם ביום (~500–1,000 לתמונה) · 1,000 בקשות API בחודש',
			'deapi'     => '$5 קרדיט חינם בהרשמה, אחר כך לפי תמונה · מגבלה 1–10 בקשות לדקה בחשבון החינמי',
			'local'     => 'חינם לגמרי · המחשב צריך להיות דלוק עם הסקריפט רץ',
			'replicate' => '~$0.0024 לתמונה',
		);
		foreach ( OFP_Engines::all() as $ek => $el ) {
			echo '<tr><td><b>' . esc_html( $el ) . '</b></td><td><input type="number" min="0" max="9" style="width:70px" name="' . esc_attr( $n . '[prio_' . $ek . ']' ) . '" value="' . (int) $o[ 'prio_' . $ek ] . '"></td><td>' . esc_html( $costs[ $ek ] ) . '</td></tr>';
		}
		echo '</tbody></table><table class="form-table">';
		$f( 'freeai_key', 'מפתח free.ai', 'text', 'נרשמים ומאשרים מייל ב-free.ai, ואז יוצרים מפתח ב-<a href="https://free.ai/account/?tab=api-keys" target="_blank" rel="noopener">free.ai/account → API keys</a> (מתחיל ב-<code>sk-free-</code>). או ב-wp-config: <code>define(\'OFP_FREEAI_KEY\', \'...\');</code>' );
		$f( 'deapi_key', 'מפתח deAPI', 'text', 'נרשמים ב-<a href="https://deapi.ai" target="_blank" rel="noopener">deapi.ai</a> (בלי כרטיס אשראי) ויוצרים מפתח API בדשבורד. או ב-wp-config: <code>define(\'OFP_DEAPI_KEY\', \'...\');</code>' );
		$f( 'deapi_model', 'מודל deAPI', 'text', 'ברירת מחדל: <code>RealESRGAN_x4</code>' );
		echo '<tr><th>המחשב שלך (worker)</th><td>';
		echo '<p>סטטוס: <b>' . esc_html( OFP_Worker::last_seen() ) . '</b></p>';
		echo '<p><a class="button" href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=ofp_worker_script' ), 'ofp_worker_script' ) ) . '">הורדת סקריפט ה-worker לווינדוס</a> ';
		echo '<a class="button button-link-delete" href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=ofp_worker_rotate' ), 'ofp_worker_rotate' ) ) . '" onclick="return confirm(\'ליצור מפתח חדש? הסקריפט הקיים יפסיק לעבוד עד שתוריד אותו שוב.\')">החלפת מפתח</a></p>';
		echo '<p class="description">שומרים את הקובץ בתיקייה במחשב, לוחצים עליו קליק ימני ← Run with PowerShell. בהרצה הראשונה הוא מוריד את Real-ESRGAN (כ-45MB) ומאז מושך עבודות מהאתר כל 15 שניות. צריך כרטיס מסך עם תמיכה ב-Vulkan (כמעט כל כרטיס מ-8 השנים האחרונות). המפתח מוטמע בקובץ – אל תשתף אותו.</p></td></tr>';
		$f( 'local_timeout_min', 'זמן המתנה למחשב המקומי (דקות)', 'number', 'אם המחשב לא החזיר תוצאה בזמן הזה – עוברים למנוע הבא.', 'min="1" max="240"' );
		$f( 'replicate_token', 'טוקן Replicate', 'text', 'מ-replicate.com/account/api-tokens. אפשר גם להגדיר ב-wp-config: <code>define(\'OFP_REPLICATE_TOKEN\', \'...\');</code>' );
		$f( 'replicate_model', 'מודל Replicate', 'text', 'ברירת מחדל: <code>nightmareai/real-esrgan</code> (גרסה אחרונה נמשכת אוטומטית). אפשר לנעול גרסה: <code>owner/model:versionhash</code>' );
		echo '</table><h2>שיפור תמונה בפרומפט (לפני ההגדלה)</h2>';
		echo '<p class="description" style="max-width:820px">שלב נפרד ממנועי ההגדלה: מודל עריכת תמונה (Gemini של Google או deAPI) מקבל את התמונה יחד עם הפרומפט שלמטה ומחזיר גרסה משופרת (תאורה, עומק, חדות), ורק אז היא מוגדלת ל-4K. <b>שים לב:</b> בניגוד להגדלה, זה מודל יוצר – הוא מצייר את התמונה מחדש, ולכן טקסט קטן, לוגואים ופנים עלולים להשתנות. סוכני ההגהה בודקים בדיוק את זה ומסמנים "דורש בדיקה" או "נדחה". אם השיפור נכשל בכל המנועים או שאין מפתח – ממשיכים להגדלה רגילה. כל תמונה עולה כסף או קרדיט אצל הספק.</p><table class="form-table">';
		echo '<tr><th>שיפור בפרומפט</th><td><select name="' . esc_attr( $n ) . '[enhance_mode]"><option value="auto" ' . selected( $o['enhance_mode'], 'auto', false ) . '>לפי סוג התמונה – רק צילומים עם אדם (מומלץ)</option><option value="on" ' . selected( $o['enhance_mode'], 'on', false ) . '>לכל קובץ שעולה – גם גרפיקה ולוגואים</option><option value="off" ' . selected( $o['enhance_mode'], 'off', false ) . '>כבוי – הגדלה בלבד, בלי שום שינוי בתמונה</option></select><p class="description">במצב "לפי סוג התמונה" Claude מסווג כל קובץ (צילום עם אדם / צילום בלי אדם / גרפיקה) לפני השיפור; דורש מפתח Anthropic. בבדיקה אמיתית הפרומפט החליף גרפיקה בפורטרט מומצא, ולכן "לכל קובץ" לא מומלץ. בדאשבורד "שדרוג מהיר" אפשר לכבות לכל קובץ בנפרד.</p></td></tr>';
		echo '<tr><th>מנוע השיפור</th><td><select name="' . esc_attr( $n ) . '[enhance_engine]"><option value="auto" ' . selected( $o['enhance_engine'], 'auto', false ) . '>אוטומטי – Gemini, ואם נכשל deAPI</option><option value="gemini" ' . selected( $o['enhance_engine'], 'gemini', false ) . '>Gemini בלבד</option><option value="deapi" ' . selected( $o['enhance_engine'], 'deapi', false ) . '>deAPI בלבד</option></select><p class="description">מנוע בלי מפתח מדולג. Gemini מחזיר תוצאה בתוך שניות עד דקה; deAPI לקח בבדיקה כ-4 דקות.</p></td></tr>';
		$f( 'gemini_key', 'מפתח Gemini', 'text', 'יוצרים ב-<a href="https://aistudio.google.com/apikey" target="_blank" rel="noopener">Google AI Studio</a>. מודלי התמונה של Gemini בתשלום לפי תמונה – צריך חשבון עם חיוב פעיל. אפשר גם ב-wp-config: <code>define(\'OFP_GEMINI_KEY\', \'...\');</code>' );
		$f( 'gemini_model', 'מודל Gemini', 'text', 'ברירת מחדל: <code>gemini-3.1-flash-image</code> (Nano Banana 2). לאיכות מרבית: <code>gemini-3-pro-image</code>. הזול והמהיר: <code>gemini-3.1-flash-lite-image</code> (רק 1K).' );
		echo '<tr><th>רזולוציית הפלט של Gemini</th><td><select name="' . esc_attr( $n ) . '[gemini_size]">';
		foreach ( array( '1K', '2K', '4K' ) as $sz ) {
			echo '<option value="' . esc_attr( $sz ) . '" ' . selected( $o['gemini_size'], $sz, false ) . '>' . esc_html( $sz ) . '</option>';
		}
		echo '</select><p class="description">אחרי השיפור התמונה מוגדלת בכל מקרה ליעד (4K). רזולוציה גבוהה יותר כאן עולה יותר לתמונה.</p></td></tr>';
		$f( 'enhance_model', 'מודל עריכה ב-deAPI', 'text', 'ברירת מחדל: <code>QwenImageEdit_Plus_NF4</code>. חלופה: מודל FLUX-2 Klein – השם המדויק מופיע ברשימת המודלים בחשבון deAPI.' );
		echo '<tr><th><label for="ofp_enhance_prompt">הפרומפט</label></th><td><textarea class="large-text code" dir="ltr" rows="12" id="ofp_enhance_prompt" name="' . esc_attr( $n . '[enhance_prompt]' ) . '">' . esc_textarea( $o['enhance_prompt'] ) . '</textarea><p class="description">נשלח כמו שהוא עם כל תמונה. ריק = חזרה לברירת המחדל.</p></td></tr>';
		echo '<tr><th><label for="ofp_enhance_negative">הנחיות שליליות</label></th><td><textarea class="large-text code" dir="ltr" rows="3" id="ofp_enhance_negative" name="' . esc_attr( $n . '[enhance_negative]' ) . '">' . esc_textarea( $o['enhance_negative'] ) . '</textarea></td></tr>';
		$f( 'enhance_timeout_min', 'זמן המתנה לשיפור (דקות)', 'number', 'אם המודל לא החזיר תוצאה בזמן הזה – ממשיכים להגדלה רגילה של המקור.', 'min="2" max="60"' );
		echo '</table><h2 id="ofp-staff">קישור לצוות – דאשבורד בלי התחברות</h2>';
		if ( isset( $_GET['ofp_staff_rotated'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			echo '<div class="notice notice-success inline"><p>נוצר מפתח חדש – הקישור הקודם כבר לא עובד.</p></div>';
		}
		echo '<p class="description" style="max-width:820px">מי שמחזיק בקישור הזה יכול להעלות קבצים (כל עבודה עולה קרדיט) ולראות ולהוריד את כל הקבצים שנוצרו – בלי להתחבר לוורדפרס. לשתף רק עם הצוות. אם הקישור דלף, מחליפים מפתח והקישור הישן מפסיק לעבוד מיד.</p><table class="form-table">';
		echo '<tr><th>הקישור</th><td><input type="text" readonly class="large-text code" dir="ltr" onclick="this.select()" value="' . esc_attr( OFP_Quick::staff_url() ) . '"><p style="margin-top:8px"><a class="button" target="_blank" rel="noopener noreferrer" href="' . esc_url( OFP_Quick::staff_url() ) . '">פתיחה</a> <a class="button button-link-delete" href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=ofp_staff_rotate' ), 'ofp_staff_rotate' ) ) . '" onclick="return confirm(\'להחליף מפתח? הקישור הנוכחי יפסיק לעבוד.\')">החלפת מפתח</a></p></td></tr>';
		echo '<tr><th>בתוך עמוד באתר</th><td>שורטקוד <code>[ofnoa_upscaler_dashboard]</code>. מנהל מחובר רואה אותו ישר; מי שלא מחובר צריך להוסיף לכתובת העמוד <code dir="ltr">?ofp_key=' . esc_html( OFP_Quick::staff_key() ) . '</code>. בלי מפתח מוצג "אין הרשאה".</td></tr>';
		echo '</table><h2>סוכני הגהה</h2><table class="form-table">';
		$f( 'anthropic_key', 'מפתח Anthropic', 'text', 'מ-console.anthropic.com. או ב-wp-config: <code>define(\'OFP_ANTHROPIC_KEY\', \'...\');</code>' );
		$f( 'claude_model', 'מודל Claude לסוכנים', 'text', 'ברירת מחדל: <code>claude-sonnet-5-5</code>. להגהה הכי מדויקת: <code>claude-opus-5-5</code> (יקר יותר).' );
		echo '</table><h2>תוצאה ומשלוח</h2><table class="form-table">';
		$f( 'notify_emails', 'מייל לקבלת ה-PDF', 'text', 'אפשר כמה כתובות מופרדות בפסיק.' );
		$f( 'target_long_side', 'רוחב יעד (הצלע הארוכה, px)', 'number', '3840 = 4K. מקסימום 8000.', 'min="1920" max="8000"' );
		echo '<tr><th>מצב PDF</th><td><select name="' . esc_attr( $n ) . '[pdf_mode]">';
		foreach ( array(
			'auto'     => 'אוטומטי – ללא איבוד איכות אם נכנס במייל, אחרת JPEG 97',
			'lossless' => 'תמיד ללא איבוד איכות (קובץ גדול)',
			'jpeg'     => 'תמיד JPEG באיכות מקסימלית',
		) as $k => $l ) {
			echo '<option value="' . esc_attr( $k ) . '" ' . selected( $o['pdf_mode'], $k, false ) . '>' . esc_html( $l ) . '</option>';
		}
		echo '</select></td></tr>';
		$f( 'attach_limit_mb', 'גודל מקסימלי לצירוף (MB)', 'number', 'מעל הגודל הזה נשלח קישור הורדה מאובטח במקום קובץ מצורף.' );
		echo '<tr><th>שגיאות כתיב</th><td><select name="' . esc_attr( $n ) . '[spelling_policy]"><option value="report" ' . selected( $o['spelling_policy'], 'report', false ) . '>לא לתקן – רק לדווח במייל</option><option value="hold" ' . selected( $o['spelling_policy'], 'hold', false ) . '>להחזיק את ה-PDF כשיש שגיאה בוודאות גבוהה</option></select><p class="description">שגיאות כתיב לעולם לא מתוקנות בגרפיקה – רק מדווחות.</p></td></tr>';
		echo '</table><h2>בקרת נאמנות</h2><p class="description" style="max-width:820px">הספים האלה חלים על הגדלה בלבד. בתמונה שעברה שיפור בפרומפט ההפרש בפיקסלים גדול בכוונה, ולכן שם ההחלטה נקבעת לפי סוכני ההגהה (זהות, רקע, טקסט, לוגואים).</p><table class="form-table">';
		$f( 'fid_global_max', 'סף הפרש ממוצע', 'number', 'הפרש פיקסלים ממוצע (0–255) אחרי הקטנה חזרה לגודל המקור. ערך נמוך = קפדני יותר.', 'step="0.5"' );
		$f( 'fid_tile_max', 'סף לאזור בודד', 'number', 'מזהה שינוי מקומי (אות, אייקון) גם כשהממוצע נמוך.', 'step="0.5"' );
		$f( 'max_input_px', 'מקסימום קלט למודל ה-AI (px)', 'number', 'קבצים גדולים מזה וקטנים מהיעד יוגדלו ב-Lanczos קלאסי.' );
		echo '</table><h2>עדכונים</h2><table class="form-table">';
		echo '<tr><th>עדכון אוטומטי מ-GitHub</th><td><select name="' . esc_attr( $n ) . '[auto_update]"><option value="on" ' . selected( $o['auto_update'], 'on', false ) . '>פעיל – גרסה חדשה מותקנת לבד ברקע</option><option value="off" ' . selected( $o['auto_update'], 'off', false ) . '>כבוי – העדכון מוצג בדף התוספים ומתקינים בלחיצה</option></select><p class="description">התוסף בודק פעם בשעה את <a href="https://github.com/' . esc_attr( OFP_Updater::REPO ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( OFP_Updater::REPO ) . '</a>. גרסה מותקנת: ' . esc_html( OFP_VERSION ) . '. ההתקנה ברקע נעשית על ידי מנגנון העדכונים של וורדפרס (בדרך כלל פעמיים ביום).</p></td></tr>';
		echo '</table><h2>טופס והגבלות</h2><table class="form-table">';
		$f( 'max_upload_mb', 'גודל העלאה מקסימלי (MB)', 'number' );
		$f( 'rate_limit_hour', 'העלאות לשעה מאותה כתובת IP', 'number' );
		$f( 'retention_days', 'מחיקת קבצים אחרי (ימים)', 'number', 'הדוח נשמר; הקבצים נמחקים. 0 = לא למחוק.' );
		$f( 'consent_text', 'טקסט אישור', 'textarea' );
		$f( 'success_text', 'הודעת הצלחה ללקוח', 'textarea' );
		echo '</table>';
		submit_button( 'שמירה' );
		echo '</form>';
		echo '<h2>בדיקת חיבורים</h2><p><button type="button" class="button" id="ofp-test">בדיקה</button></p><ul id="ofp-test-out"></ul>';
		$nonce = wp_create_nonce( 'ofp_test' );
		echo "<script>document.getElementById('ofp-test').onclick=function(){var o=document.getElementById('ofp-test-out');o.innerHTML='<li>בודק…</li>';fetch(ajaxurl,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:'action=ofp_test&_ajax_nonce=" . esc_js( $nonce ) . "'}).then(r=>r.json()).then(j=>{o.innerHTML='';Object.values(j.data||{}).forEach(v=>{var li=document.createElement('li');li.textContent=v;o.appendChild(li);});});};</script>";
		echo '</div>';
	}
}
