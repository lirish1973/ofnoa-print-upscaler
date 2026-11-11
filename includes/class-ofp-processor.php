<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Background pipeline. Each "tick" advances jobs one step so no single PHP request
 * runs too long on shared hosting:
 *   queued → [enhancing] → upscaling → verifying → [proofing, agent by agent] → building → [mailing] → done
 * Steps in brackets are optional per job (report.opts) – see opts().
 */
class OFP_Processor {

	const HOOK = 'ofp_tick';

	public static function init() {
		add_filter( 'cron_schedules', array( __CLASS__, 'schedules' ) );
		add_action( self::HOOK, array( __CLASS__, 'tick' ) );
		add_action( 'ofp_daily_cleanup', array( 'OFP_Jobs', 'cleanup' ) );
		add_action( 'wp_ajax_nopriv_ofp_kick', array( __CLASS__, 'ajax_kick' ) );
		add_action( 'wp_ajax_ofp_kick', array( __CLASS__, 'ajax_kick' ) );
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			self::schedule();
		}
	}

	public static function schedules( $s ) {
		$s['ofp_minute'] = array(
			'interval' => 60,
			'display'  => 'Every minute (Ofnoa Print Upscaler)',
		);
		return $s;
	}

	public static function schedule() {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + 30, 'ofp_minute', self::HOOK );
		}
		if ( ! wp_next_scheduled( 'ofp_daily_cleanup' ) ) {
			wp_schedule_event( time() + 3600, 'daily', 'ofp_daily_cleanup' );
		}
	}

	public static function unschedule() {
		wp_clear_scheduled_hook( self::HOOK );
		wp_clear_scheduled_hook( 'ofp_daily_cleanup' );
	}

	private static function kick_key() {
		$k = get_option( 'ofp_kick_key' );
		if ( ! $k ) {
			$k = wp_generate_password( 32, false, false );
			update_option( 'ofp_kick_key', $k, false );
		}
		return $k;
	}

	/** Fire-and-forget loopback so a new job starts immediately instead of waiting for cron. */
	public static function kick() {
		wp_remote_post(
			admin_url( 'admin-ajax.php' ),
			array(
				'timeout'   => 0.5,
				'blocking'  => false,
				'sslverify' => apply_filters( 'https_local_ssl_verify', false ),
				'body'      => array(
					'action' => 'ofp_kick',
					'k'      => self::kick_key(),
				),
			)
		);
	}

	public static function ajax_kick() {
		$k = isset( $_POST['k'] ) ? sanitize_text_field( wp_unslash( $_POST['k'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		if ( ! hash_equals( self::kick_key(), $k ) ) {
			wp_die( '', '', array( 'response' => 403 ) );
		}
		ignore_user_abort( true );
		self::tick();
		wp_die( 'ok' );
	}

	public static function tick() {
		if ( get_transient( 'ofp_lock' ) ) {
			return;
		}
		set_transient( 'ofp_lock', 1, 320 );
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
		wp_raise_memory_limit( 'image' );
		$start = time();
		try {
			$guard = 0;
			while ( time() - $start < 150 && $guard++ < 20 ) {
				$jobs = OFP_Jobs::pending( 3 );
				if ( ! $jobs ) {
					break;
				}
				$progress = false;
				foreach ( $jobs as $job ) {
					$before = $job->status . '|' . $job->updated_at;
					self::step( $job );
					$after = OFP_Jobs::get( $job->id );
					if ( $after && $after->status . '|' . $after->updated_at !== $before && ! in_array( $after->status, array( 'upscaling', 'enhancing' ), true ) ) {
						$progress = true;
					}
					if ( time() - $start > 150 ) {
						break 2;
					}
				}
				if ( ! $progress ) {
					break; // Only waiting on an engine – let the next tick poll again.
				}
			}
		} finally {
			delete_transient( 'ofp_lock' );
		}
	}

	const ACTIVE = array( 'queued', 'enhancing', 'upscaling', 'verifying', 'proofing', 'building', 'mailing' );

	/**
	 * Advance ONE job by ONE step right now (used by the admin dashboard so progress never
	 * depends on WP-Cron or loopback requests). Returns false when another run holds the lock.
	 */
	public static function drive( $id ) {
		if ( get_transient( 'ofp_lock' ) ) {
			return false;
		}
		set_transient( 'ofp_lock', 1, 320 );
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
		wp_raise_memory_limit( 'image' );
		try {
			$job = OFP_Jobs::get( $id );
			if ( $job && in_array( $job->status, self::ACTIVE, true ) ) {
				self::step( $job );
			}
		} finally {
			delete_transient( 'ofp_lock' );
		}
		return true;
	}

	/** Per-job options. Customer uploads use the defaults; the admin dashboard sets them per job. */
	public static function opts( $report ) {
		$o = isset( $report['opts'] ) && is_array( $report['opts'] ) ? $report['opts'] : array();
		return array(
			'enhance' => isset( $o['enhance'] ) ? (bool) $o['enhance'] : ( 'off' !== OFP_Settings::get( 'enhance_mode' ) ),
			'proof'   => isset( $o['proof'] ) ? (bool) $o['proof'] : true,
			'mail'    => isset( $o['mail'] ) ? (bool) $o['mail'] : true,
		);
	}

	public static function enhanced( $report ) {
		return isset( $report['enhance']['status'] ) && 'done' === $report['enhance']['status'];
	}

	/** Target long side of this job (an enhanced job never ends smaller than its source). */
	private static function target( $report ) {
		return ! empty( $report['target'] ) ? (int) $report['target'] : (int) OFP_Settings::get( 'target_long_side' );
	}

	private static function fail( $job, $msg ) {
		OFP_Jobs::log( $job->id, 'שגיאה: ' . $msg );
		$tries = (int) $job->step_attempts + 1;
		if ( $tries >= 3 ) {
			OFP_Jobs::update(
				$job->id,
				array(
					'status'        => 'failed',
					'step_attempts' => $tries,
				)
			);
			$failed = OFP_Jobs::get( $job->id );
			$o      = self::opts( self::report( $failed ) );
			if ( $o['mail'] ) {
				OFP_Mailer::send_failure( $failed, $msg );
			}
		} else {
			OFP_Jobs::update( $job->id, array( 'step_attempts' => $tries ) );
		}
	}

	private static function advance( $job, $status, $extra = array() ) {
		OFP_Jobs::update( $job->id, array_merge( $extra, array( 'status' => $status, 'step_attempts' => 0 ) ) );
	}

	private static function report( $job ) {
		$r = json_decode( (string) $job->report, true );
		return is_array( $r ) ? $r : array();
	}

	private static function save_report( $job, $r ) {
		OFP_Jobs::update( $job->id, array( 'report' => wp_json_encode( $r, JSON_UNESCAPED_UNICODE ) ) );
	}

	public static function step( $job ) {
		switch ( $job->status ) {
			case 'queued':
				return self::step_prepare( $job );
			case 'enhancing':
				return self::step_enhance( $job );
			case 'upscaling':
				return self::step_poll( $job );
			case 'verifying':
				return self::step_verify( $job );
			case 'proofing':
				return self::step_proof( $job );
			case 'building':
				return self::step_build( $job );
			case 'mailing':
				return self::step_mail( $job );
		}
	}

	private static function step_prepare( $job ) {
		$dir    = OFP_Jobs::job_dir( $job );
		$source = $dir . '/source.png';
		$r      = OFP_Image::normalise( $job->original_path, $source );
		if ( is_wp_error( $r ) ) {
			return self::fail( $job, $r->get_error_message() );
		}
		list( $w, $h ) = OFP_Image::size( $source );
		$report        = self::report( $job );
		OFP_Jobs::update(
			$job->id,
			array(
				'source_path' => $source,
				'width'       => $w,
				'height'      => $h,
			)
		);
		OFP_Jobs::log( $job->id, "מקור: {$w}×{$h}" );

		$o = self::opts( $report );
		unset( $report['enhance'], $report['target'], $report['classify'] );
		$skip = '';
		if ( $o['enhance'] && 'on' !== OFP_Settings::get( 'enhance_mode' ) ) {
			// By-type mode: the prompt is only safe for photographs of people.
			if ( ! OFP_Engines::enhance_ready() ) {
				$skip = 'שיפור בפרומפט דורש מפתח Gemini או deAPI';
			} else {
				$c = OFP_Claude::classify( $source );
				if ( is_wp_error( $c ) ) {
					$skip = 'סיווג התמונה נכשל (' . $c->get_error_message() . ')';
				} else {
					$report['classify'] = $c;
					OFP_Jobs::log( $job->id, 'סיווג: ' . OFP_Claude::kind_label( $c['kind'] ) . ' (' . $c['confidence'] . ')' . ( $c['reason'] ? ' – ' . $c['reason'] : '' ) );
					if ( 'photo_person' !== $c['kind'] || 'low' === $c['confidence'] ) {
						$skip = 'התמונה סווגה כ"' . OFP_Claude::kind_label( $c['kind'] ) . '" – הפרומפט מיועד לצילומים עם אדם, ולכן בוצעה הגדלה בלבד';
					}
				}
			}
		}
		if ( '' !== $skip ) {
			$report['enhance'] = array(
				'status' => 'skipped',
				'error'  => $skip,
			);
			self::save_report( $job, $report );
			OFP_Jobs::log( $job->id, 'שיפור בפרומפט דולג: ' . $skip );
		} elseif ( $o['enhance'] ) {
			$res = OFP_Engines::enhance_start( $source, $dir );
			foreach ( is_wp_error( $res ) ? array() : (array) $res['notes'] as $note ) {
				OFP_Jobs::log( $job->id, 'שיפור בפרומפט – ' . $note . ' – עוברים למנוע הבא' );
			}
			if ( is_wp_error( $res ) ) {
				$report['enhance'] = array(
					'status' => 'skipped',
					'error'  => $res->get_error_message(),
				);
				self::save_report( $job, $report );
				OFP_Jobs::log( $job->id, 'שיפור בפרומפט דולג: ' . $res->get_error_message() . ' – ממשיכים להגדלה רגילה' );
			} else {
				$report['enhance'] = array(
					'status'  => 'running',
					'started' => time(),
					'engine'  => $res['engine'],
					'model'   => $res['model'],
					'width'   => $res['width'],
					'height'  => $res['height'],
				);
				self::save_report( $job, $report );
				if ( ! empty( $res['file'] ) ) {
					// Synchronous engine (Gemini): the enhanced image is already here.
					OFP_Jobs::log( $job->id, 'שיפור בפרומפט בוצע ב-' . OFP_Engines::enhance_label( $res['engine'] ) . ' (' . $res['model'] . ')' );
					return self::finish_enhance( OFP_Jobs::get( $job->id ), $res['file'] );
				}
				OFP_Jobs::log( $job->id, 'שיפור בפרומפט התחיל ב-' . OFP_Engines::enhance_label( $res['engine'] ) . ' (' . $res['model'] . ', ' . $res['width'] . '×' . $res['height'] . ')' );
				return self::advance( $job, 'enhancing', array( 'prediction_id' => substr( 'enhance|' . $res['handle'], 0, 80 ) ) );
			}
		} else {
			self::save_report( $job, $report );
		}
		return self::begin_upscale( OFP_Jobs::get( $job->id ), $source );
	}

	/** Poll the prompt-guided enhancement. Any failure falls back to a plain upscale of the source. */
	private static function step_enhance( $job ) {
		$dir     = OFP_Jobs::job_dir( $job );
		$report  = self::report( $job );
		$parts   = explode( '|', (string) $job->prediction_id, 2 );
		$handle  = isset( $parts[1] ) ? $parts[1] : '';
		$p       = $handle ? OFP_Engines::enhance_poll( $handle, $dir ) : new WP_Error( 'ofp_engine', 'חסר מזהה משימה' );
		$started = isset( $report['enhance']['started'] ) ? (int) $report['enhance']['started'] : strtotime( $job->updated_at . ' UTC' );
		$limit   = max( 2, (int) OFP_Settings::get( 'enhance_timeout_min' ) );
		if ( 'processing' === $p && time() - $started > 60 * $limit ) {
			$p = new WP_Error( 'ofp_engine', 'לא הסתיים תוך ' . $limit . ' דקות' );
		}
		if ( 'processing' === $p ) {
			return;
		}
		return self::finish_enhance( $job, $p );
	}

	/** $p is the enhanced file from any engine, or a WP_Error. Falls back to a plain upscale on failure. */
	private static function finish_enhance( $job, $p ) {
		$dir    = OFP_Jobs::job_dir( $job );
		$report = self::report( $job );
		if ( ! is_wp_error( $p ) ) {
			// The model snaps its output to a size grid – restore the exact aspect ratio of the source.
			list( $sw, $sh ) = OFP_Image::size( $job->source_path );
			list( $ew, $eh ) = OFP_Image::size( $p );
			if ( ! $sw || ! $ew ) {
				$p = new WP_Error( 'ofp_engine', 'תוצאת השיפור אינה תמונה תקינה' );
			} elseif ( abs( ( $ew / $eh ) / ( $sw / $sh ) - 1 ) > 0.04 ) {
				// A different shape means the model re-framed or cropped the picture – never stretch that back.
				wp_delete_file( $p );
				$p = new WP_Error( 'ofp_engine', "המודל החזיר תמונה ביחס ממדים אחר ({$ew}×{$eh} מול מקור {$sw}×{$sh})" );
			} else {
				$long = max( $ew, $eh );
				$nw   = $sw >= $sh ? $long : max( 1, (int) round( $long * $sw / $sh ) );
				$nh   = $sw >= $sh ? max( 1, (int) round( $long * $sh / $sw ) ) : $long;
				$fit  = OFP_Image::resize_exact( $p, $dir . '/enhanced.png', $nw, $nh );
				wp_delete_file( $p );
				$p = is_wp_error( $fit ) ? $fit : $dir . '/enhanced.png';
			}
		}
		if ( is_wp_error( $p ) ) {
			$report['enhance']['status'] = 'failed';
			$report['enhance']['error']  = $p->get_error_message();
			self::save_report( $job, $report );
			OFP_Jobs::log( $job->id, 'שיפור בפרומפט נכשל: ' . $p->get_error_message() . ' – ממשיכים להגדלה רגילה של המקור' );
			return self::begin_upscale( OFP_Jobs::get( $job->id ), $job->source_path );
		}
		list( $fw, $fh )             = OFP_Image::size( $p );
		$report['enhance']['status'] = 'done';
		$report['enhance']['out']    = array( $fw, $fh );
		self::save_report( $job, $report );
		OFP_Jobs::log( $job->id, "שיפור בפרומפט הסתיים: {$fw}×{$fh}" );
		return self::begin_upscale( OFP_Jobs::get( $job->id ), $p );
	}

	/** Bring $base (the source, or its enhanced version) up to the target resolution. */
	private static function begin_upscale( $job, $base ) {
		$dir             = OFP_Jobs::job_dir( $job );
		$source          = $base;
		list( $w, $h )   = OFP_Image::size( $base );
		$long            = max( $w, $h );
		$report          = self::report( $job );
		$target          = (int) OFP_Settings::get( 'target_long_side' );
		if ( self::enhanced( $report ) ) {
			$target = min( 8000, max( $target, (int) $job->width, (int) $job->height ) );
		}
		$report['target'] = $target;
		self::save_report( $job, $report );

		if ( $long >= $target ) {
			$report['engine'] = 'none';
			$report['engine_note'] = 'הקובץ כבר ברזולוציה של 4K ומעלה – לא בוצעה הגדלה, רק הגהה והמרה ל-PDF.';
			if ( $long > $target ) {
				$res = OFP_Image::fit_long_side( $source, $dir . '/upscaled.png', self::enhanced( $report ) ? $target : $long );
				if ( is_wp_error( $res ) ) {
					return self::fail( $job, $res->get_error_message() );
				}
			} else {
				copy( $source, $dir . '/upscaled.png' );
			}
			self::save_report( $job, $report );
			return self::advance( $job, 'verifying', array( 'upscaled_path' => $dir . '/upscaled.png' ) );
		}
		if ( $long > (int) OFP_Settings::get( 'max_input_px' ) ) {
			// Too large for the GPU model and only needs a small step – classic Lanczos (zero hallucination).
			$res = OFP_Image::fit_long_side( $source, $dir . '/upscaled.png', $target );
			if ( is_wp_error( $res ) ) {
				return self::fail( $job, $res->get_error_message() );
			}
			$report['engine']      = 'lanczos';
			$report['engine_note'] = 'הקובץ גדול מדי למודל ה-AI וקרוב ליעד – הוגדל באלגוריתם Lanczos קלאסי, שאינו ממציא פרטים.';
			self::save_report( $job, $report );
			return self::advance( $job, 'verifying', array( 'upscaled_path' => $dir . '/upscaled.png' ) );
		}
		return self::start_ai( $job, $source, $long, $target, 1 );
	}

	/**
	 * Start an AI pass on the first engine (by priority) not yet tried in this pass.
	 * Engine errors / timeouts fall through to the next engine; if every engine fails the image is
	 * enlarged with classic Lanczos so the customer's job never gets stuck.
	 */
	private static function start_ai( $job, $input, $long, $target, $pass, $tried = array() ) {
		$scale = ( $target / $long ) <= 2 ? 2 : 4;
		while ( true ) {
			$engine = OFP_Engines::next( $tried );
			if ( '' === $engine ) {
				return self::lanczos_fallback( $job, $input, $target, $tried );
			}
			$res = OFP_Engines::start( $engine, $job, $input, $scale );
			if ( is_wp_error( $res ) ) {
				OFP_Jobs::log( $job->id, OFP_Engines::label( $engine ) . ' נכשל: ' . $res->get_error_message() . ' – עובר למנוע הבא' );
				$tried[] = $engine;
				continue;
			}
			$report                    = self::report( OFP_Jobs::get( $job->id ) );
			$report['engine']          = 'real-esrgan';
			$report['engine_provider'] = $engine;
			$report['ai_pass']         = $pass;
			$report['ai_input']        = $input;
			$report['ai_long']         = $long;
			$report['ai_tried']        = $tried;
			$report['ai_started']      = time();
			if ( 1 === (int) $pass ) {
				$report['ai_scale'] = array();
			}
			$report['ai_scale'][ $pass - 1 ] = $scale;
			$report['ai_providers'][ $pass - 1 ] = $engine;
			self::save_report( $job, $report );
			if ( isset( $res['file'] ) ) {
				OFP_Jobs::log( $job->id, OFP_Engines::label( $engine ) . " – מעבר {$pass} ×{$scale} הסתיים" );
				return self::finish_ai( OFP_Jobs::get( $job->id ), $res['file'] );
			}
			OFP_Jobs::log( $job->id, OFP_Engines::label( $engine ) . " – מעבר {$pass} ×{$scale} התחיל" );
			return self::advance( $job, 'upscaling', array( 'prediction_id' => substr( $engine . '|' . $res['handle'], 0, 80 ) ) );
		}
	}

	private static function lanczos_fallback( $job, $input, $target, $tried ) {
		$dir = OFP_Jobs::job_dir( $job );
		$res = OFP_Image::fit_long_side( $input, $dir . '/upscaled.png', $target );
		if ( is_wp_error( $res ) ) {
			return self::fail( $job, $res->get_error_message() );
		}
		$report                = self::report( OFP_Jobs::get( $job->id ) );
		$report['engine']      = 'lanczos';
		$report['engine_note'] = $tried
			? 'כל מנועי ה-AI נכשלו (' . implode( ', ', array_map( array( 'OFP_Engines', 'label' ), $tried ) ) . ') – הוגדל באלגוריתם Lanczos קלאסי, שאינו ממציא פרטים.'
			: 'לא מוגדר אף מנוע AI פעיל – הוגדל באלגוריתם Lanczos קלאסי, שאינו ממציא פרטים.';
		self::save_report( $job, $report );
		OFP_Jobs::log( $job->id, 'הגדלה ב-Lanczos (גיבוי)' );
		return self::advance( $job, 'verifying', array( 'upscaled_path' => $dir . '/upscaled.png' ) );
	}

	private static function step_poll( $job ) {
		$pid = (string) $job->prediction_id;
		if ( false === strpos( $pid, '|' ) ) {
			$pid = 'replicate|' . $pid; // jobs started by v1.0.
		}
		list( $engine, $handle ) = explode( '|', $pid, 2 );
		$report  = self::report( $job );
		$p       = OFP_Engines::poll( $engine, $handle, $job );
		$started = isset( $report['ai_started'] ) ? (int) $report['ai_started'] : strtotime( $job->updated_at . ' UTC' );
		if ( 'processing' === $p && time() - $started > 60 * OFP_Engines::timeout_minutes( $engine ) ) {
			$p = new WP_Error( 'ofp_engine', 'לא הסתיים תוך ' . OFP_Engines::timeout_minutes( $engine ) . ' דקות' );
		}
		if ( 'processing' === $p ) {
			return;
		}
		if ( is_wp_error( $p ) ) {
			OFP_Jobs::log( $job->id, OFP_Engines::label( $engine ) . ': ' . $p->get_error_message() . ' – עובר למנוע הבא' );
			$input = ! empty( $report['ai_input'] ) && file_exists( $report['ai_input'] ) ? $report['ai_input'] : $job->source_path;
			$long  = ! empty( $report['ai_long'] ) ? (int) $report['ai_long'] : max( (int) $job->width, (int) $job->height );
			$tried = isset( $report['ai_tried'] ) ? (array) $report['ai_tried'] : array();
			$tried[] = $engine;
			$pass  = isset( $report['ai_pass'] ) ? (int) $report['ai_pass'] : 1;
			return self::start_ai( $job, $input, $long, self::target( $report ), $pass, $tried );
		}
		return self::finish_ai( $job, $p );
	}

	/** A pass finished: maybe run a second pass, otherwise fit to the exact target size. */
	private static function finish_ai( $job, $raw ) {
		$dir             = OFP_Jobs::job_dir( $job );
		$size            = OFP_Image::size( $raw );
		if ( ! $size || ! $size[0] ) {
			return self::fail( $job, 'תוצאת ההגדלה אינה תמונה תקינה' );
		}
		list( $rw, $rh ) = $size;
		$long            = max( $rw, $rh );
		$report          = self::report( OFP_Jobs::get( $job->id ) );
		$target          = self::target( $report );
		$pass            = isset( $report['ai_pass'] ) ? (int) $report['ai_pass'] : 1;
		if ( $long < $target && $pass < 2 && $long <= (int) OFP_Settings::get( 'max_input_px' ) ) {
			$next = $dir . '/pass1.png';
			$res  = OFP_Image::fit_long_side( $raw, $next, $long ); // normalise to PNG for the 2nd pass.
			if ( is_wp_error( $res ) ) {
				return self::fail( $job, $res->get_error_message() );
			}
			if ( $raw !== $next ) {
				wp_delete_file( $raw );
			}
			return self::start_ai( $job, $next, $long, $target, 2 );
		}
		$fit = OFP_Image::fit_long_side( $raw, $dir . '/upscaled.png', $target );
		if ( is_wp_error( $fit ) ) {
			return self::fail( $job, $fit->get_error_message() );
		}
		wp_delete_file( $raw );
		if ( file_exists( $dir . '/pass1.png' ) ) {
			wp_delete_file( $dir . '/pass1.png' );
		}
		OFP_Jobs::log( $job->id, 'הגדלה הסתיימה: ' . $fit[0] . '×' . $fit[1] );
		self::advance(
			$job,
			'verifying',
			array(
				'upscaled_path' => $dir . '/upscaled.png',
				'out_width'     => $fit[0],
				'out_height'    => $fit[1],
			)
		);
	}

	private static function step_verify( $job ) {
		if ( self::enhanced( self::report( $job ) ) ) {
			// Rounding through the edit model's size grid can leave the result a pixel off – make the
			// final file match the source's aspect ratio exactly.
			list( $sw, $sh ) = OFP_Image::size( $job->source_path );
			list( $uw, $uh ) = OFP_Image::size( $job->upscaled_path );
			if ( $sw && $uw ) {
				$long = max( $uw, $uh );
				$nw   = $sw >= $sh ? $long : max( 1, (int) round( $long * $sw / $sh ) );
				$nh   = $sw >= $sh ? max( 1, (int) round( $long * $sh / $sw ) ) : $long;
				if ( $nw !== $uw || $nh !== $uh ) {
					$fix = OFP_Image::resize_exact( $job->upscaled_path, $job->upscaled_path, $nw, $nh );
					if ( is_wp_error( $fix ) ) {
						return self::fail( $job, $fix->get_error_message() );
					}
				}
			}
		}
		$m = OFP_Image::fidelity( $job->source_path, $job->upscaled_path );
		if ( is_wp_error( $m ) ) {
			return self::fail( $job, $m->get_error_message() );
		}
		$m['pass'] = $m['mean_diff'] <= (float) OFP_Settings::get( 'fid_global_max' ) && $m['worst_tile'] <= (float) OFP_Settings::get( 'fid_tile_max' );
		list( $ow, $oh ) = OFP_Image::size( $job->upscaled_path );
		$report             = self::report( $job );
		$enh                = self::enhanced( $report );
		$m['enhanced']      = $enh; // lighting / colour were changed on purpose – the pixel diff is informative only.
		$report['fidelity'] = $m;
		self::save_report( $job, $report );
		OFP_Jobs::log( $job->id, 'בדיקת נאמנות: ממוצע ' . $m['mean_diff'] . ', אריח גרוע ' . $m['worst_tile'] . ( $enh ? ' (תמונה משופרת – למידע בלבד)' : ( $m['pass'] ? ' ✓' : ' ✗' ) ) );
		$o = self::opts( $report );
		if ( ! $o['proof'] ) {
			OFP_Jobs::log( $job->id, 'הגהת סוכנים דולגה (לפי בחירה)' );
		}
		self::advance(
			$job,
			$o['proof'] ? 'proofing' : 'building',
			array(
				'out_width'  => $ow,
				'out_height' => $oh,
				'verdict'    => $o['proof'] ? '' : 'unchecked',
			)
		);
	}

	/** One agent per call so a slow model never hits the PHP time limit. */
	private static function step_proof( $job ) {
		$r      = self::report( $job );
		$agents = isset( $r['agents'] ) ? $r['agents'] : array();
		if ( ! OFP_Settings::secret( 'anthropic_key' ) ) {
			return self::fail( $job, 'חסר מפתח Anthropic בהגדרות' );
		}
		if ( ! isset( $agents['transcribe_original'] ) ) {
			$res = OFP_Claude::transcribe( $job->source_path, 'מקור' );
			$key = 'transcribe_original';
		} elseif ( ! isset( $agents['transcribe_result'] ) ) {
			$res = OFP_Claude::transcribe( $job->upscaled_path, 'תוצאה' );
			$key = 'transcribe_result';
		} elseif ( ! isset( $agents['inspect'] ) ) {
			$res = OFP_Claude::inspect( $job->source_path, $job->upscaled_path, self::enhanced( $r ) );
			$key = 'inspect';
		} elseif ( ! isset( $agents['proofread'] ) ) {
			$texts = isset( $agents['transcribe_original']['texts'] ) ? $agents['transcribe_original']['texts'] : array();
			$res   = OFP_Claude::proofread( $job->source_path, $texts );
			$key   = 'proofread';
		} elseif ( ! isset( $agents['chief'] ) ) {
			$diff              = OFP_Claude::text_diff(
				isset( $agents['transcribe_original']['texts'] ) ? $agents['transcribe_original']['texts'] : array(),
				isset( $agents['transcribe_result']['texts'] ) ? $agents['transcribe_result']['texts'] : array()
			);
			$r['text_diff']    = $diff;
			$facts             = array(
				'engine'              => isset( $r['engine'] ) ? $r['engine'] : '',
				'enhancement_applied' => self::enhanced( $r ),
				'pixel_fidelity'      => isset( $r['fidelity'] ) ? $r['fidelity'] : array(),
				'pixel_thresholds'    => array(
					'mean_max' => (float) OFP_Settings::get( 'fid_global_max' ),
					'tile_max' => (float) OFP_Settings::get( 'fid_tile_max' ),
				),
				'transcription_diff'  => $diff,
				'transcribe_original' => self::strip_usage( $agents['transcribe_original'] ),
				'transcribe_result'   => self::strip_usage( $agents['transcribe_result'] ),
				'visual_inspection'   => self::strip_usage( $agents['inspect'] ),
				'proofreading'        => self::strip_usage( $agents['proofread'] ),
			);
			$res = OFP_Claude::chief( $facts );
			$key = 'chief';
		} else {
			return self::advance( $job, 'building' );
		}
		if ( is_wp_error( $res ) ) {
			return self::fail( $job, 'סוכן ' . $key . ': ' . $res->get_error_message() );
		}
		$agents[ $key ] = $res;
		$r['agents']    = $agents;
		self::save_report( $job, $r );
		OFP_Jobs::update( $job->id, array( 'step_attempts' => 0 ) );
		OFP_Jobs::log( $job->id, 'סוכן ' . $key . ' סיים' );
		if ( 'chief' === $key ) {
			$verdict = self::final_verdict( $r );
			self::advance( $job, 'building', array( 'verdict' => $verdict ) );
		}
	}

	private static function strip_usage( $a ) {
		if ( is_array( $a ) ) {
			unset( $a['_usage'] );
		}
		return $a;
	}

	/** Deterministic guard-rails on top of the chief's opinion – the stricter one wins. */
	public static function final_verdict( $r ) {
		$chief = isset( $r['agents']['chief']['status'] ) ? $r['agents']['chief']['status'] : 'needs_review';
		$rank  = array(
			'approved'     => 0,
			'needs_review' => 1,
			'rejected'     => 2,
		);
		$v     = isset( $rank[ $chief ] ) ? $chief : 'needs_review';
		$fid   = isset( $r['fidelity']['pass'] ) ? $r['fidelity']['pass'] : false;
		$insp  = isset( $r['agents']['inspect']['verdict'] ) ? $r['agents']['inspect']['verdict'] : 'minor';
		$match = isset( $r['text_diff']['match_ratio'] ) ? (float) $r['text_diff']['match_ratio'] : 1;
		$floor = 'approved';
		if ( self::enhanced( $r ) ) {
			$fid = true; // an enhanced image differs in pixels by design; content is judged by the agents.
		}
		if ( ! $fid || 'minor' === $insp || $match < 0.97 ) {
			$floor = 'needs_review';
		}
		if ( 'changed' === $insp ) {
			$floor = 'rejected';
		}
		return $rank[ $floor ] > $rank[ $v ] ? $floor : $v;
	}

	private static function step_build( $job ) {
		$dir   = OFP_Jobs::job_dir( $job );
		$pdf   = $dir . '/styleprint-' . $job->id . '.pdf';
		$title = 'styleprint #' . $job->id . ' – ' . $job->original_name;
		$res   = OFP_PDF::build( $job->upscaled_path, $pdf, OFP_Settings::get( 'pdf_mode' ), $title, (int) OFP_Settings::get( 'attach_limit_mb' ) * MB_IN_BYTES );
		if ( is_wp_error( $res ) ) {
			return self::fail( $job, $res->get_error_message() );
		}
		$r        = self::report( $job );
		$r['pdf'] = $res;
		self::save_report( $job, $r );
		OFP_Jobs::log( $job->id, 'PDF נוצר (' . $res['mode'] . ', ' . size_format( $res['bytes'] ) . ')' );
		self::advance( $job, 'mailing', array( 'pdf_path' => $pdf ) );
	}

	private static function step_mail( $job ) {
		$o = self::opts( self::report( $job ) );
		if ( ! $o['mail'] ) {
			OFP_Jobs::log( $job->id, 'הושלם – ללא שליחת מייל (לפי בחירה)' );
			return self::advance( $job, 'done' );
		}
		$ok = OFP_Mailer::send_result( $job );
		if ( is_wp_error( $ok ) ) {
			return self::fail( $job, $ok->get_error_message() );
		}
		OFP_Jobs::log( $job->id, 'נשלח במייל' );
		self::advance( $job, 'done' );
	}
}
