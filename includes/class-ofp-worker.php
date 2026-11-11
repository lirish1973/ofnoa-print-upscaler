<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * REST bridge for the local PC worker (realesrgan-ncnn-vulkan on your own computer).
 * The worker polls /next, downloads the source, upscales it locally and posts the PNG back.
 * Auth: header X-OFP-Worker-Key (generated in settings, embedded in the downloadable script).
 */
class OFP_Worker {

	const NS = 'ofp/v1';

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
		add_action( 'admin_post_ofp_worker_script', array( __CLASS__, 'download_script' ) );
		add_action( 'admin_post_ofp_worker_rotate', array( __CLASS__, 'rotate_key' ) );
	}

	public static function routes() {
		$auth = array( __CLASS__, 'auth' );
		register_rest_route(
			self::NS,
			'/worker/next',
			array(
				'methods'             => 'GET',
				'permission_callback' => $auth,
				'callback'            => array( __CLASS__, 'next' ),
			)
		);
		register_rest_route(
			self::NS,
			'/worker/source/(?P<id>\d+)',
			array(
				'methods'             => 'GET',
				'permission_callback' => $auth,
				'callback'            => array( __CLASS__, 'source' ),
			)
		);
		register_rest_route(
			self::NS,
			'/worker/result/(?P<id>\d+)',
			array(
				'methods'             => 'POST',
				'permission_callback' => $auth,
				'callback'            => array( __CLASS__, 'result' ),
			)
		);
		register_rest_route(
			self::NS,
			'/worker/fail/(?P<id>\d+)',
			array(
				'methods'             => 'POST',
				'permission_callback' => $auth,
				'callback'            => array( __CLASS__, 'fail' ),
			)
		);
	}

	public static function auth( $req ) {
		$key  = (string) OFP_Settings::get( 'worker_key' );
		$sent = (string) $req->get_header( 'x_ofp_worker_key' );
		if ( ! $key || ! $sent || ! hash_equals( $key, $sent ) ) {
			return new WP_Error( 'ofp_auth', 'bad worker key', array( 'status' => 403 ) );
		}
		update_option( 'ofp_worker_seen', time(), false );
		return true;
	}

	private static function report( $job ) {
		$r = json_decode( (string) $job->report, true );
		return is_array( $r ) ? $r : array();
	}

	private static function save( $job, $r ) {
		OFP_Jobs::update( $job->id, array( 'report' => wp_json_encode( $r, JSON_UNESCAPED_UNICODE ) ) );
	}

	/** Oldest job waiting for the local engine that is not claimed (claims expire after 15 minutes). */
	public static function next() {
		global $wpdb;
		$t    = OFP_Jobs::table();
		$rows = $wpdb->get_results( "SELECT * FROM {$t} WHERE status = 'upscaling' AND prediction_id LIKE 'local|%' ORDER BY id ASC LIMIT 10" ); // phpcs:ignore
		foreach ( $rows as $job ) {
			$r = self::report( $job );
			if ( ! empty( $r['local_claimed'] ) && $r['local_claimed'] > time() - 900 ) {
				continue;
			}
			if ( empty( $r['local_input'] ) || ! file_exists( $r['local_input'] ) ) {
				continue;
			}
			$r['local_claimed'] = time();
			self::save( $job, $r );
			OFP_Jobs::log( $job->id, 'המחשב המקומי לקח את העבודה' );
			return self::nocache(
				array(
					'job'   => (int) $job->id,
					'scale' => isset( $r['local_scale'] ) ? (int) $r['local_scale'] : 4,
					'name'  => 'job-' . (int) $job->id . '.png',
				)
			);
		}
		return self::nocache( array( 'job' => 0 ) );
	}

	/** Never let a page cache (LiteSpeed caches REST GETs by default) serve a stale queue. */
	private static function nocache( $data ) {
		do_action( 'litespeed_control_set_nocache', 'ofp worker' );
		$res = new WP_REST_Response( $data );
		$res->header( 'Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0' );
		return $res;
	}

	public static function source( $req ) {
		$job = OFP_Jobs::get( (int) $req['id'] );
		$r   = $job ? self::report( $job ) : array();
		if ( ! $job || empty( $r['local_input'] ) || ! file_exists( $r['local_input'] ) ) {
			return new WP_Error( 'ofp_nf', 'not found', array( 'status' => 404 ) );
		}
		$f = $r['local_input'];
		do_action( 'litespeed_control_set_nocache', 'ofp worker' );
		nocache_headers();
		header( 'Content-Type: image/png' );
		header( 'Content-Length: ' . filesize( $f ) );
		readfile( $f ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
		exit;
	}

	public static function result( $req ) {
		$job = OFP_Jobs::get( (int) $req['id'] );
		if ( ! $job || 'upscaling' !== $job->status || 0 !== strpos( (string) $job->prediction_id, 'local|' ) ) {
			return new WP_Error( 'ofp_state', 'job is not waiting for a local result', array( 'status' => 409 ) );
		}
		$body = $req->get_body();
		if ( strlen( $body ) < 100 ) {
			return new WP_Error( 'ofp_empty', 'empty body', array( 'status' => 400 ) );
		}
		$dest = OFP_Jobs::job_dir( $job ) . '/ai-raw-local.png';
		$tmp  = $dest . '.part';
		file_put_contents( $tmp, $body ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		$info = @getimagesize( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( ! $info || ! in_array( $info[2], array( IMAGETYPE_PNG, IMAGETYPE_JPEG, IMAGETYPE_WEBP ), true ) ) {
			wp_delete_file( $tmp );
			return new WP_Error( 'ofp_img', 'not an image', array( 'status' => 400 ) );
		}
		rename( $tmp, $dest ); // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
		OFP_Jobs::log( $job->id, 'התקבלה תוצאה מהמחשב המקומי: ' . $info[0] . '×' . $info[1] );
		OFP_Processor::kick();
		return array( 'ok' => true );
	}

	public static function fail( $req ) {
		$job = OFP_Jobs::get( (int) $req['id'] );
		if ( ! $job ) {
			return new WP_Error( 'ofp_nf', 'not found', array( 'status' => 404 ) );
		}
		$r                = self::report( $job );
		$r['local_error'] = sanitize_text_field( substr( (string) $req->get_param( 'message' ), 0, 300 ) );
		self::save( $job, $r );
		OFP_Processor::kick();
		return array( 'ok' => true );
	}

	/** Human readable "last seen" of the worker. */
	public static function last_seen() {
		$t = (int) get_option( 'ofp_worker_seen' );
		if ( ! $t ) {
			return 'עדיין לא התחבר';
		}
		$ago = time() - $t;
		return $ago < 120 ? 'מחובר עכשיו' : 'נראה לאחרונה לפני ' . human_time_diff( $t );
	}

	public static function rotate_key() {
		check_admin_referer( 'ofp_worker_rotate' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'forbidden' );
		}
		$o               = get_option( OFP_Settings::OPTION, array() );
		$o['worker_key'] = wp_generate_password( 40, false, false );
		update_option( OFP_Settings::OPTION, $o );
		wp_safe_redirect( admin_url( 'admin.php?page=ofp-settings&ofp_rotated=1' ) );
		exit;
	}

	/** Downloads a ready-to-run PowerShell worker with this site's URL and key embedded. */
	public static function download_script() {
		check_admin_referer( 'ofp_worker_script' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'forbidden' );
		}
		$tpl = file_get_contents( OFP_DIR . 'worker/ofp-worker.ps1' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$ps  = strtr(
			$tpl,
			array(
				'__OFP_API__' => esc_url_raw( rest_url( self::NS . '/worker' ) ),
				'__OFP_KEY__' => (string) OFP_Settings::get( 'worker_key' ),
			)
		);
		nocache_headers();
		header( 'Content-Type: application/octet-stream' );
		header( 'Content-Disposition: attachment; filename="ofp-worker.ps1"' );
		// UTF-8 BOM so Windows PowerShell 5.1 reads the Hebrew messages correctly.
		echo "\xEF\xBB\xBF" . $ps; // phpcs:ignore WordPress.Security.EscapeOutput
		exit;
	}
}
