<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Upscale engines behind one interface + automatic fallback chain.
 *
 * Every upscale engine runs pure Real-ESRGAN super-resolution (no prompt, no face enhance):
 *   freeai    – free.ai self-hosted Real-ESRGAN, free daily token pool (synchronous)
 *   deapi     – deAPI RealESRGAN_x4, $5 free credit on signup (async job)
 *   local     – your own PC running realesrgan-ncnn-vulkan via the worker script (free)
 *   replicate – Replicate nightmareai/real-esrgan (paid, ~$0.0024/run)
 *
 * Separate from the upscale chain: an optional prompt-guided ENHANCEMENT step (deAPI Image Edit)
 * that runs before the upscale – see enhance_start() / enhance_poll().
 *
 * start() returns array( 'handle' => string ) for async engines or array( 'file' => path ) when the
 * result is already on disk. poll() returns 'processing', a path to the downloaded result, or WP_Error.
 */
class OFP_Engines {

	public static function all() {
		return array(
			'freeai'    => 'free.ai (חינם – מכסה יומית)',
			'deapi'     => 'deAPI ($5 קרדיט חינם)',
			'local'     => 'המחשב שלך (חינם – Real-ESRGAN מקומי)',
			'replicate' => 'Replicate (בתשלום)',
		);
	}

	public static function label( $engine ) {
		$a = self::all();
		return isset( $a[ $engine ] ) ? $a[ $engine ] : $engine;
	}

	/** Is the engine usable right now (key present / worker enabled)? */
	public static function configured( $engine ) {
		switch ( $engine ) {
			case 'freeai':
				return (bool) OFP_Settings::secret( 'freeai_key' );
			case 'deapi':
				return (bool) OFP_Settings::secret( 'deapi_key' );
			case 'replicate':
				return (bool) OFP_Settings::secret( 'replicate_token' );
			case 'local':
				return (bool) OFP_Settings::get( 'worker_key' );
		}
		return false;
	}

	/** Enabled engines ordered by priority (1 = first). Priority 0 = off. */
	public static function order() {
		$list = array();
		foreach ( array_keys( self::all() ) as $i => $e ) {
			$p = (int) OFP_Settings::get( 'prio_' . $e );
			if ( $p > 0 && self::configured( $e ) ) {
				$list[ $e ] = $p * 10 + $i;
			}
		}
		asort( $list );
		return array_keys( $list );
	}

	/** Next engine after the ones already tried in this pass. */
	public static function next( $tried ) {
		foreach ( self::order() as $e ) {
			if ( ! in_array( $e, (array) $tried, true ) ) {
				return $e;
			}
		}
		return '';
	}

	public static function start( $engine, $job, $input, $scale ) {
		switch ( $engine ) {
			case 'replicate':
				$url = OFP_Replicate::upload_file( $input );
				if ( is_wp_error( $url ) ) {
					return $url;
				}
				$pid = OFP_Replicate::create_prediction( $url, $scale );
				return is_wp_error( $pid ) ? $pid : array( 'handle' => $pid );
			case 'deapi':
				return self::deapi_start( $input );
			case 'freeai':
				return self::freeai_run( $job, $input, $scale );
			case 'local':
				$r                = self::report( $job );
				$r['local_input'] = $input;
				$r['local_scale'] = (int) $scale;
				unset( $r['local_claimed'] );
				self::save_report( $job, $r );
				$done = OFP_Jobs::job_dir( $job ) . '/ai-raw-local.png';
				if ( file_exists( $done ) ) {
					wp_delete_file( $done );
				}
				return array( 'handle' => (string) $job->id );
		}
		return new WP_Error( 'ofp_engine', 'מנוע לא מוכר: ' . $engine );
	}

	public static function poll( $engine, $handle, $job ) {
		$dir = OFP_Jobs::job_dir( $job );
		switch ( $engine ) {
			case 'replicate':
				$p = OFP_Replicate::get_prediction( $handle );
				if ( is_wp_error( $p ) ) {
					return $p;
				}
				if ( in_array( $p['status'], array( 'starting', 'processing' ), true ) ) {
					return 'processing';
				}
				if ( 'succeeded' !== $p['status'] || ! $p['output'] ) {
					return new WP_Error( 'ofp_engine', 'Replicate: ' . ( $p['error'] ? $p['error'] : $p['status'] ) );
				}
				$raw = $dir . '/ai-raw.png';
				$d   = OFP_Replicate::download( $p['output'], $raw );
				return is_wp_error( $d ) ? $d : $raw;
			case 'deapi':
				return self::deapi_poll( $handle, $dir );
			case 'local':
				$f = $dir . '/ai-raw-local.png';
				if ( file_exists( $f ) && filesize( $f ) > 100 ) {
					return $f;
				}
				$r = self::report( $job );
				if ( ! empty( $r['local_error'] ) ) {
					$msg = $r['local_error'];
					unset( $r['local_error'] );
					self::save_report( $job, $r );
					return new WP_Error( 'ofp_engine', 'המחשב המקומי: ' . $msg );
				}
				return 'processing';
		}
		return new WP_Error( 'ofp_engine', 'מנוע לא מוכר: ' . $engine );
	}

	/** Minutes to wait for an engine before falling back to the next one. */
	public static function timeout_minutes( $engine ) {
		if ( 'local' === $engine ) {
			return max( 1, (int) OFP_Settings::get( 'local_timeout_min' ) );
		}
		return 20;
	}

	/* ---------------- helpers ---------------- */

	private static function report( $job ) {
		$fresh = OFP_Jobs::get( $job->id );
		$r     = json_decode( (string) ( $fresh ? $fresh->report : $job->report ), true );
		return is_array( $r ) ? $r : array();
	}

	private static function save_report( $job, $r ) {
		OFP_Jobs::update( $job->id, array( 'report' => wp_json_encode( $r, JSON_UNESCAPED_UNICODE ) ) );
	}

	/** Build a multipart/form-data body. $files = array( field => array( path, mime ) ). */
	public static function multipart( $fields, $files ) {
		$b    = wp_generate_password( 24, false );
		$body = '';
		foreach ( $fields as $k => $v ) {
			$body .= '--' . $b . "\r\nContent-Disposition: form-data; name=\"" . $k . "\"\r\n\r\n" . $v . "\r\n";
		}
		foreach ( $files as $k => $f ) {
			$body .= '--' . $b . "\r\nContent-Disposition: form-data; name=\"" . $k . '"; filename="' . basename( $f[0] ) . "\"\r\nContent-Type: " . $f[1] . "\r\n\r\n"
				. file_get_contents( $f[0] ) . "\r\n"; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		}
		$body .= '--' . $b . "--\r\n";
		return array( $body, 'multipart/form-data; boundary=' . $b );
	}

	/** Some APIs cap uploads (deAPI: 10 MB). Above the cap send a max-quality JPEG copy. */
	private static function upload_copy( $input, $max_bytes ) {
		if ( filesize( $input ) <= $max_bytes ) {
			return array( $input, 'image/png', false );
		}
		$im = OFP_Image::load( $input );
		if ( is_wp_error( $im ) ) {
			return $im;
		}
		$tmp = preg_replace( '/\.png$/', '', $input ) . '-upload.jpg';
		imagejpeg( $im, $tmp, 98 );
		imagedestroy( $im );
		return array( $tmp, 'image/jpeg', true );
	}

	public static function download( $url, $dest, $headers = array() ) {
		$res = wp_remote_get(
			$url,
			array(
				'timeout'  => 120,
				'stream'   => true,
				'filename' => $dest,
				'headers'  => $headers,
			)
		);
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		if ( wp_remote_retrieve_response_code( $res ) >= 300 || ! file_exists( $dest ) || filesize( $dest ) < 100 || ! @getimagesize( $dest ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
			return new WP_Error( 'ofp_engine', 'הורדת התוצאה נכשלה' );
		}
		return $dest;
	}

	private static function json( $res, $who ) {
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$code = wp_remote_retrieve_response_code( $res );
		$raw  = wp_remote_retrieve_body( $res );
		$body = json_decode( $raw, true );
		if ( $code >= 300 || ! is_array( $body ) || ! empty( $body['error'] ) ) {
			$msg = '';
			if ( is_array( $body ) ) {
				foreach ( array( 'error', 'message', 'detail' ) as $k ) {
					if ( ! empty( $body[ $k ] ) ) {
						$msg = is_array( $body[ $k ] ) ? ( isset( $body[ $k ]['error'] ) ? $body[ $k ]['error'] : wp_json_encode( $body[ $k ], JSON_UNESCAPED_UNICODE ) ) : $body[ $k ];
						break;
					}
				}
			}
			if ( is_array( $body ) && ! empty( $body['errors'] ) && is_array( $body['errors'] ) ) {
				$first = reset( $body['errors'] );
				$field = key( $body['errors'] );
				$msg  .= ' [' . ( is_string( $field ) ? $field . ': ' : '' ) . ( is_array( $first ) ? implode( ' ', array_map( 'strval', $first ) ) : (string) $first ) . ']';
			}
			return new WP_Error( 'ofp_engine', $who . ' ' . $code . ': ' . wp_strip_all_tags( substr( (string) ( $msg ? $msg : $raw ), 0, 300 ) ) );
		}
		return $body;
	}

	/* ---------------- deAPI ---------------- */

	const DEAPI = 'https://api.deapi.ai/api/v2';

	private static function deapi_headers() {
		return array(
			'Accept'        => 'application/json',
			'Authorization' => 'Bearer ' . OFP_Settings::secret( 'deapi_key' ),
		);
	}

	public static function deapi_start( $input ) {
		$up = self::upload_copy( $input, 9 * MB_IN_BYTES );
		if ( is_wp_error( $up ) ) {
			return $up;
		}
		list( $body, $ctype ) = self::multipart(
			array( 'model' => (string) OFP_Settings::get( 'deapi_model' ) ),
			array( 'image' => array( $up[0], $up[1] ) )
		);
		$h                 = self::deapi_headers();
		$h['Content-Type'] = $ctype;
		$r                 = self::json(
			wp_remote_post(
				self::DEAPI . '/images/upscales',
				array(
					'headers' => $h,
					'body'    => $body,
					'timeout' => 90,
				)
			),
			'deAPI'
		);
		if ( $up[2] ) {
			wp_delete_file( $up[0] );
		}
		if ( is_wp_error( $r ) ) {
			return $r;
		}
		$id = isset( $r['data']['request_id'] ) ? $r['data']['request_id'] : ( isset( $r['request_id'] ) ? $r['request_id'] : '' );
		return $id ? array( 'handle' => $id ) : new WP_Error( 'ofp_engine', 'deAPI לא החזיר מזהה משימה' );
	}

	public static function deapi_poll( $id, $dir, $dest = '' ) {
		$r = self::json( wp_remote_get( self::DEAPI . '/jobs/' . rawurlencode( $id ), array( 'headers' => self::deapi_headers(), 'timeout' => 20 ) ), 'deAPI' );
		if ( is_wp_error( $r ) ) {
			return $r;
		}
		$d      = isset( $r['data'] ) ? $r['data'] : $r;
		$status = isset( $d['status'] ) ? $d['status'] : '';
		if ( in_array( $status, array( 'pending', 'processing', '' ), true ) ) {
			return 'processing';
		}
		if ( 'done' !== $status || empty( $d['result_url'] ) ) {
			$why = ! empty( $d['error_reason'] ) ? $d['error_reason'] : ( ! empty( $d['error_code'] ) ? $d['error_code'] : $status );
			return new WP_Error( 'ofp_engine', 'deAPI: ' . $why );
		}
		return self::download( $d['result_url'], $dest ? $dest : $dir . '/ai-raw-deapi.img' );
	}

	/* ---------------- prompt-guided enhancement (deAPI Image Edit) ---------------- */

	public static function enhance_ready() {
		return (bool) OFP_Settings::secret( 'deapi_key' );
	}

	/** Model entry from GET /models (cached), or WP_Error. */
	public static function deapi_model( $slug ) {
		$list = get_transient( 'ofp_deapi_models' );
		if ( ! is_array( $list ) ) {
			$list = array();
			for ( $page = 1; $page <= 5; $page++ ) {
				$r = self::json( wp_remote_get( self::DEAPI . '/models?limit=100&page=' . $page, array( 'headers' => self::deapi_headers(), 'timeout' => 20 ) ), 'deAPI' );
				if ( is_wp_error( $r ) ) {
					return $r;
				}
				foreach ( (array) ( isset( $r['data'] ) ? $r['data'] : array() ) as $m ) {
					if ( ! empty( $m['slug'] ) ) {
						$list[ $m['slug'] ] = $m;
					}
				}
				$last = isset( $r['meta']['last_page'] ) ? (int) $r['meta']['last_page'] : 1;
				if ( $page >= $last ) {
					break;
				}
			}
			if ( $list ) {
				set_transient( 'ofp_deapi_models', $list, 6 * HOUR_IN_SECONDS );
			}
		}
		if ( ! isset( $list[ $slug ] ) ) {
			return new WP_Error( 'ofp_engine', 'המודל ' . $slug . ' לא נמצא ברשימת המודלים של deAPI' );
		}
		return $list[ $slug ];
	}

	/**
	 * Output size for the edit model: same aspect ratio as the input, at least the model's default
	 * size (small logos are rendered larger), never above the model's limits, snapped to its step.
	 */
	public static function enhance_dims( $w, $h, $model ) {
		$lim  = isset( $model['info']['limits'] ) && is_array( $model['info']['limits'] ) ? $model['info']['limits'] : array();
		$def  = isset( $model['info']['defaults'] ) && is_array( $model['info']['defaults'] ) ? $model['info']['defaults'] : array();
		$g    = function ( $k, $fallback ) use ( $lim ) {
			return isset( $lim[ $k ] ) && is_numeric( $lim[ $k ] ) && $lim[ $k ] > 0 ? (float) $lim[ $k ] : $fallback;
		};
		$min_w = $g( 'min_width', 256 );
		$min_h = $g( 'min_height', 256 );
		$max_w = $g( 'max_width', 2048 );
		$max_h = $g( 'max_height', 2048 );
		$step  = (int) $g( 'resolution_step', $g( 'dimension_multiple', 16 ) );
		$step  = max( 1, $step );
		$ratio = max( $w, $h ) / max( 1, min( $w, $h ) );
		$max_r = $g( 'max_ratio', min( $max_w / $min_h, $max_h / $min_w ) );
		if ( $ratio > $max_r + 0.001 ) {
			return new WP_Error( 'ofp_engine', 'יחס הממדים של התמונה (' . round( $ratio, 2 ) . ':1) גדול ממה שהמודל תומך בו' );
		}
		$want = max( max( $w, $h ), max( isset( $def['width'] ) ? (int) $def['width'] : 1024, isset( $def['height'] ) ? (int) $def['height'] : 1024 ) );
		$s    = $want / max( $w, $h );
		$s    = min( $s, $max_w / $w, $max_h / $h );
		if ( isset( $lim['max_pixels'] ) && $lim['max_pixels'] > 0 ) {
			$s = min( $s, sqrt( (float) $lim['max_pixels'] / ( $w * $h ) ) );
		}
		$s  = max( $s, $min_w / $w, $min_h / $h );
		$ow = (int) ( floor( $w * $s / $step ) * $step );
		$oh = (int) ( floor( $h * $s / $step ) * $step );
		$ow = (int) max( ceil( $min_w / $step ) * $step, min( floor( $max_w / $step ) * $step, $ow ) );
		$oh = (int) max( ceil( $min_h / $step ) * $step, min( floor( $max_h / $step ) * $step, $oh ) );
		return array( $ow, $oh );
	}

	/** Start the enhancement. Returns array( 'handle', 'width', 'height', 'model' ) or WP_Error. */
	public static function enhance_start( $input ) {
		if ( ! self::enhance_ready() ) {
			return new WP_Error( 'ofp_engine', 'שיפור בפרומפט דורש מפתח deAPI' );
		}
		$slug  = (string) OFP_Settings::get( 'enhance_model' );
		$model = self::deapi_model( $slug );
		if ( is_wp_error( $model ) ) {
			return $model;
		}
		if ( ! empty( $model['inference_types'] ) && ! in_array( 'img2img', (array) $model['inference_types'], true ) ) {
			return new WP_Error( 'ofp_engine', 'המודל ' . $slug . ' לא תומך בעריכת תמונה (img2img)' );
		}
		list( $w, $h ) = OFP_Image::size( $input );
		if ( ! $w || ! $h ) {
			return new WP_Error( 'ofp_engine', 'לא ניתן לקרוא את התמונה' );
		}
		$dims = self::enhance_dims( $w, $h, $model );
		if ( is_wp_error( $dims ) ) {
			return $dims;
		}
		$feat   = isset( $model['info']['features'] ) && is_array( $model['info']['features'] ) ? $model['info']['features'] : array();
		$lim    = isset( $model['info']['limits'] ) && is_array( $model['info']['limits'] ) ? $model['info']['limits'] : array();
		$def    = isset( $model['info']['defaults'] ) && is_array( $model['info']['defaults'] ) ? $model['info']['defaults'] : array();
		$fields = array(
			'model'  => $slug,
			'prompt' => (string) OFP_Settings::get( 'enhance_prompt' ),
			'seed'   => (string) wp_rand( 1, 2147483646 ),
			'width'  => (string) $dims[0],
			'height' => (string) $dims[1],
		);
		$neg = trim( (string) OFP_Settings::get( 'enhance_negative' ) );
		if ( '' !== $neg && ( ! isset( $feat['supports_negative_prompt'] ) || $feat['supports_negative_prompt'] ) ) {
			$fields['negative_prompt'] = $neg;
		}
		if ( ! isset( $feat['supports_steps'] ) || $feat['supports_steps'] ) {
			$steps = isset( $def['steps'] ) ? (int) $def['steps'] : 20;
			$steps = max( isset( $lim['min_steps'] ) ? (int) $lim['min_steps'] : 1, min( isset( $lim['max_steps'] ) ? (int) $lim['max_steps'] : 100, $steps ) );
			$fields['steps'] = (string) $steps;
		}
		if ( ! empty( $feat['supports_guidance'] ) ) {
			$gd = isset( $def['guidance'] ) ? (float) $def['guidance'] : 4.0;
			$gd = max( isset( $lim['min_guidance'] ) ? (float) $lim['min_guidance'] : 0, min( isset( $lim['max_guidance'] ) ? (float) $lim['max_guidance'] : 30, $gd ) );
			$fields['guidance'] = (string) $gd;
		}
		$up = self::upload_copy( $input, 9 * MB_IN_BYTES );
		if ( is_wp_error( $up ) ) {
			return $up;
		}
		list( $body, $ctype ) = self::multipart( $fields, array( 'image' => array( $up[0], $up[1] ) ) );
		$hd                   = self::deapi_headers();
		$hd['Content-Type']   = $ctype;
		$r                    = self::json(
			wp_remote_post(
				self::DEAPI . '/images/edits',
				array(
					'headers' => $hd,
					'body'    => $body,
					'timeout' => 90,
				)
			),
			'deAPI'
		);
		if ( $up[2] ) {
			wp_delete_file( $up[0] );
		}
		if ( is_wp_error( $r ) ) {
			return $r;
		}
		$id = isset( $r['data']['request_id'] ) ? $r['data']['request_id'] : ( isset( $r['request_id'] ) ? $r['request_id'] : '' );
		if ( ! $id ) {
			return new WP_Error( 'ofp_engine', 'deAPI לא החזיר מזהה משימה' );
		}
		return array(
			'handle' => $id,
			'width'  => $dims[0],
			'height' => $dims[1],
			'model'  => $slug,
		);
	}

	/** 'processing', a path to the enhanced image, or WP_Error. */
	public static function enhance_poll( $handle, $dir ) {
		return self::deapi_poll( $handle, $dir, $dir . '/enhance-raw.img' );
	}

	public static function enhance_test() {
		$slug  = (string) OFP_Settings::get( 'enhance_model' );
		delete_transient( 'ofp_deapi_models' );
		$model = self::deapi_model( $slug );
		if ( is_wp_error( $model ) ) {
			return $model;
		}
		if ( ! empty( $model['inference_types'] ) && ! in_array( 'img2img', (array) $model['inference_types'], true ) ) {
			return new WP_Error( 'ofp_engine', 'המודל ' . $slug . ' לא תומך בעריכת תמונה' );
		}
		$d = self::enhance_dims( 1200, 800, $model );
		return 'המודל ' . $slug . ' זמין' . ( is_wp_error( $d ) ? '' : ' (פלט לדוגמה ' . $d[0] . '×' . $d[1] . ' לתמונת 1200×800)' );
	}

	public static function deapi_test() {
		$r = self::json( wp_remote_get( self::DEAPI . '/models', array( 'headers' => self::deapi_headers(), 'timeout' => 20 ) ), 'deAPI' );
		if ( is_wp_error( $r ) ) {
			return $r;
		}
		$want  = (string) OFP_Settings::get( 'deapi_model' );
		$found = false !== strpos( wp_json_encode( $r ), '"' . $want . '"' );
		return $found ? 'המודל ' . $want . ' זמין' : 'מחובר (לא אותר המודל ' . $want . ' ברשימה – בדוק את שם המודל)';
	}

	/* ---------------- free.ai ---------------- */

	const FREEAI = 'https://api.free.ai';

	/** free.ai answers synchronously with the upscaled image URL. */
	public static function freeai_run( $job, $input, $scale ) {
		$dest = OFP_Jobs::job_dir( $job ) . '/ai-raw-freeai.img';
		$r    = self::freeai_call( $input, $scale, $dest );
		if ( is_wp_error( $r ) ) {
			return $r;
		}
		if ( null !== $r['tokens'] ) {
			$job_r                  = self::report( $job );
			$job_r['freeai_tokens'] = $r['tokens'];
			self::save_report( $job, $job_r );
		}
		return array( 'file' => $dest );
	}

	/** Returns array( 'tokens' => int|null ) with the image written to $dest, or WP_Error. */
	public static function freeai_call( $input, $scale, $dest ) {
		list( $body, $ctype ) = self::multipart(
			array(
				'scale' => (string) (int) $scale,
				'tool'  => 'image-upscaler',
			),
			array( 'image' => array( $input, 'image/png' ) )
		);
		$r = self::json(
			wp_remote_post(
				self::FREEAI . '/v1/image/enhance/',
				array(
					'headers' => array(
						'Authorization' => 'Bearer ' . OFP_Settings::secret( 'freeai_key' ),
						'Content-Type'  => $ctype,
						'Accept'        => 'application/json',
					),
					'body'    => $body,
					'timeout' => 140,
				)
			),
			'free.ai'
		);
		if ( is_wp_error( $r ) ) {
			return $r;
		}
		$url = '';
		foreach ( array( 'output_url', 'image_url', 'url', 'result_url' ) as $k ) {
			if ( ! empty( $r[ $k ] ) && is_string( $r[ $k ] ) ) {
				$url = $r[ $k ];
				break;
			}
		}
		$tokens = isset( $r['tokens'] ) && is_numeric( $r['tokens'] ) ? (int) $r['tokens'] : null;
		if ( ! $url ) {
			foreach ( array( 'image', 'image_base64', 'b64_json' ) as $k ) {
				if ( ! empty( $r[ $k ] ) && is_string( $r[ $k ] ) ) {
					$bin = base64_decode( preg_replace( '#^data:image/\w+;base64,#', '', $r[ $k ] ), true );
					if ( $bin ) {
						file_put_contents( $dest, $bin ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
						return array( 'tokens' => $tokens );
					}
				}
			}
			return new WP_Error( 'ofp_engine', 'free.ai לא החזיר תמונה' );
		}
		if ( 0 === strpos( $url, '/' ) ) {
			$url = self::FREEAI . $url;
		}
		$host = wp_parse_url( $url, PHP_URL_HOST );
		$h    = ( $host && preg_match( '/(^|\.)free\.ai$/', $host ) ) ? array( 'Authorization' => 'Bearer ' . OFP_Settings::secret( 'freeai_key' ) ) : array();
		$d    = self::download( $url, $dest, $h );
		if ( is_wp_error( $d ) && $h ) {
			$d = self::download( $url, $dest ); // some CDNs reject auth headers.
		}
		return is_wp_error( $d ) ? $d : array( 'tokens' => $tokens );
	}

	/** Real end-to-end check on a tiny image (costs a few hundred free tokens). */
	public static function freeai_test() {
		$dir = OFP_Jobs::storage_dir( 'test' );
		$in  = $dir . '/ping.png';
		$im  = imagecreatetruecolor( 48, 32 );
		imagefill( $im, 0, 0, imagecolorallocate( $im, 255, 255, 255 ) );
		imagestring( $im, 3, 6, 9, 'OFP', imagecolorallocate( $im, 0, 0, 0 ) );
		imagepng( $im, $in );
		imagedestroy( $im );
		$out = $dir . '/ping-out.img';
		$r   = self::freeai_call( $in, 2, $out );
		$sz  = file_exists( $out ) ? @getimagesize( $out ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors
		wp_delete_file( $in );
		wp_delete_file( $out );
		if ( is_wp_error( $r ) ) {
			return $r;
		}
		return 'הגדלה ניסיונית עבדה' . ( $sz ? ' (' . $sz[0] . '×' . $sz[1] . ')' : '' ) . ( null !== $r['tokens'] ? ' – ' . $r['tokens'] . ' טוקנים' : '' );
	}
}
