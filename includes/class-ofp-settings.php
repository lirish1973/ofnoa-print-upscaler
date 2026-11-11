<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Settings storage + settings page.
 */
class OFP_Settings {

	const OPTION = 'ofp_settings';

	/** Default enhancement instruction (editable in the settings page). */
	public static function default_enhance_prompt() {
		return "Apply these instructions to the supplied image whatever it is: a portrait, a product photo, a logo, an illustration or a print graphic. Where they mention a face or a portrait and the image has none, apply them to the main subject instead. Every piece of text, every logo, every shape and the full layout must stay exactly as in the reference image: same letters, same digits, same positions, same proportions, same framing, no cropping.\n\n"
			. "Enhance the portrait while strictly preserving the subject's identity with accurate facial geometry. Do not change their expression or face shape. Only allow subtle feature cleanup without altering who they are. Keep the exact same background from the reference image. No replacements, no changes, no new objects, no layout shifts. The environment must look identical. The image must be recreated as if it was shot on a Sony A1, using an 85mm f1.4 lens, at f1.6, ISO 100, 1/200 shutter speed, cinematic shallow depth of field, perfect facial focus, and an editorial-neutral color profile. This Sony A1 + 85mm f1.4 setup is mandatory. The final image must clearly look like premium full-frame Sony A1 quality. Lighting must match the exact direction, angle, and mood of the reference photo. Upgrade the lighting into a cinematic, subject-focused style: soft directional light, warm highlights, cool shadows, deeper contrast, expanded dynamic range, micro-contrast boost, smooth gradations, and zero harsh shadows. Maintain neutral premium color tone, cinematic contrast curve, natural saturation, real skin texture (not plastic), and subtle film grain. No fake glow, no runway lighting, no oversmoothing. Render in 4K resolution, 10-bit color, cinematic editorial style, premium clarity, portrait crop, and keep the original environmental vibe untouched. Re-render the subject with improved realism, depth, texture, and lighting while keeping identity and background fully preserved.";
	}

	public static function default_enhance_negative() {
		return 'No new background. No background change. No overly dramatic lighting. No face morphing. No fake glow. No flat lighting. No over-smooth skin. No altered text. No cropping.';
	}

	public static function defaults() {
		return array(
			'prio_freeai'        => 1,        // engine priority, 0 = off
			'prio_deapi'         => 2,
			'prio_local'         => 0,
			'prio_replicate'     => 3,
			'freeai_key'         => '',
			'deapi_key'          => '',
			'deapi_model'        => 'RealESRGAN_x4',
			'worker_key'         => '',
			'local_timeout_min'  => 10,
			'replicate_token'    => '',
			'replicate_model'    => 'nightmareai/real-esrgan',
			'enhance_mode'       => 'auto',   // auto (photos of people only) | on (every file) | off
			'enhance_model'      => 'QwenImageEdit_Plus_NF4',
			'enhance_prompt'     => self::default_enhance_prompt(),
			'enhance_negative'   => self::default_enhance_negative(),
			'enhance_timeout_min' => 15,
			'auto_update'        => 'on',     // on | off – install new GitHub releases in the background
			'anthropic_key'      => '',
			'claude_model'       => 'claude-sonnet-5-5',
			'notify_emails'      => get_option( 'admin_email' ),
			'target_long_side'   => 3840,
			'max_upload_mb'      => 25,
			'max_input_px'       => 2400,
			'pdf_mode'           => 'auto',   // auto | jpeg | lossless
			'attach_limit_mb'    => 18,
			'fid_global_max'     => 6,        // mean abs diff (0-255) after downscale back
			'fid_tile_max'       => 16,       // worst tile mean abs diff
			'spelling_policy'    => 'report', // report | hold
			'rate_limit_hour'    => 10,
			'retention_days'     => 30,
			'consent_text'       => 'אני מאשר/ת שהקבצים שלי יעובדו לצורך הגדלה והגהה ויישלחו לצוות styleprint.',
			'success_text'       => 'הקובץ התקבל! הגרפיקה בטיפול – אנחנו נחזור אליכם בהקדם.',
		);
	}

	public static function get( $key = null ) {
		$o = wp_parse_args( get_option( self::OPTION, array() ), self::defaults() );
		if ( null === $key ) {
			return $o;
		}
		return isset( $o[ $key ] ) ? $o[ $key ] : null;
	}

	/** Secret fields => optional wp-config.php constant that overrides the stored value. */
	public static function secret_keys() {
		return array(
			'freeai_key'      => 'OFP_FREEAI_KEY',
			'deapi_key'       => 'OFP_DEAPI_KEY',
			'replicate_token' => 'OFP_REPLICATE_TOKEN',
			'anthropic_key'   => 'OFP_ANTHROPIC_KEY',
		);
	}

	/** Secrets may be overridden in wp-config.php so they never live in the DB. */
	public static function secret( $key ) {
		$const = self::secret_keys();
		if ( isset( $const[ $key ] ) && defined( $const[ $key ] ) && constant( $const[ $key ] ) ) {
			return constant( $const[ $key ] );
		}
		return (string) self::get( $key );
	}

	public static function recipients() {
		$list = array_filter( array_map( 'trim', explode( ',', (string) self::get( 'notify_emails' ) ) ), 'is_email' );
		return $list ? $list : array( get_option( 'admin_email' ) );
	}

	/** 1.3.0: "every file" proved wrong for graphics – move sites that never chose to the by-type mode, once. */
	public static function migrate() {
		if ( '1' === get_option( 'ofp_enh_migrated' ) ) {
			return;
		}
		$o = get_option( self::OPTION, array() );
		if ( is_array( $o ) && isset( $o['enhance_mode'] ) && 'on' === $o['enhance_mode'] ) {
			$o['enhance_mode'] = 'auto';
			update_option( self::OPTION, $o );
		}
		update_option( 'ofp_enh_migrated', '1', false );
	}

	public static function init() {
		self::migrate();
		add_action( 'admin_init', array( __CLASS__, 'register' ) );
		add_action( 'admin_init', array( __CLASS__, 'ensure_worker_key' ) );
	}

	/** The local-worker key is generated once; rotate it from the settings page. */
	public static function ensure_worker_key() {
		$o = get_option( self::OPTION, array() );
		if ( empty( $o['worker_key'] ) ) {
			$o                = is_array( $o ) ? $o : array();
			$o['worker_key']  = wp_generate_password( 40, false, false );
			update_option( self::OPTION, $o );
		}
	}

	public static function register() {
		register_setting(
			'ofp_settings_group',
			self::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize' ),
			)
		);
	}

	public static function sanitize( $in ) {
		$old = self::get();
		$d   = self::defaults();
		$out = array();
		foreach ( $d as $k => $v ) {
			if ( ! isset( $in[ $k ] ) ) {
				$out[ $k ] = $old[ $k ];
				continue;
			}
			$val = $in[ $k ];
			if ( 'worker_key' === $k ) {
				// Not a form field: only set internally (first run / rotate). Keep the old one otherwise.
				$out[ $k ] = preg_match( '/^[A-Za-z0-9]{32,64}$/', (string) $val ) ? (string) $val : $old[ $k ];
				continue;
			}
			if ( array_key_exists( $k, self::secret_keys() ) ) {
				$val = trim( (string) $val );
				// Empty / masked value keeps the stored secret.
				$out[ $k ] = ( '' === $val || false !== strpos( $val, '•' ) ) ? $old[ $k ] : sanitize_text_field( $val );
			} elseif ( is_int( $v ) ) {
				$out[ $k ] = max( 0, (int) $val );
			} elseif ( in_array( $k, array( 'consent_text', 'success_text', 'enhance_prompt', 'enhance_negative' ), true ) ) {
				$out[ $k ] = sanitize_textarea_field( $val );
			} elseif ( 'notify_emails' === $k ) {
				$out[ $k ] = implode( ', ', array_filter( array_map( 'sanitize_email', explode( ',', $val ) ) ) );
			} else {
				$out[ $k ] = sanitize_text_field( $val );
			}
		}
		$out['pdf_mode']        = in_array( $out['pdf_mode'], array( 'auto', 'jpeg', 'lossless' ), true ) ? $out['pdf_mode'] : 'auto';
		$out['spelling_policy'] = in_array( $out['spelling_policy'], array( 'report', 'hold' ), true ) ? $out['spelling_policy'] : 'report';
		$out['target_long_side'] = min( 8000, max( 1920, $out['target_long_side'] ) );
		foreach ( array( 'prio_freeai', 'prio_deapi', 'prio_local', 'prio_replicate' ) as $pk ) {
			$out[ $pk ] = min( 9, max( 0, (int) $out[ $pk ] ) );
		}
		$out['local_timeout_min'] = min( 240, max( 1, (int) $out['local_timeout_min'] ) );
		$out['enhance_mode']        = in_array( $out['enhance_mode'], array( 'auto', 'on', 'off' ), true ) ? $out['enhance_mode'] : 'auto';
		$out['auto_update']         = in_array( $out['auto_update'], array( 'on', 'off' ), true ) ? $out['auto_update'] : 'on';
		$out['enhance_timeout_min'] = min( 60, max( 2, (int) $out['enhance_timeout_min'] ) );
		$out['enhance_model']       = preg_replace( '/[^A-Za-z0-9_\-.]/', '', $out['enhance_model'] );
		if ( '' === $out['enhance_model'] ) {
			$out['enhance_model'] = $d['enhance_model'];
		}
		if ( '' === trim( $out['enhance_prompt'] ) ) {
			$out['enhance_prompt'] = $d['enhance_prompt'];
		}
		delete_transient( 'ofp_deapi_models' );
		$out['deapi_model']       = preg_replace( '/[^A-Za-z0-9_\-.]/', '', $out['deapi_model'] );
		if ( '' === $out['deapi_model'] ) {
			$out['deapi_model'] = $d['deapi_model'];
		}
		delete_transient( 'ofp_replicate_version' );
		return $out;
	}

	public static function mask( $secret ) {
		if ( ! $secret ) {
			return '';
		}
		return str_repeat( '•', 8 ) . substr( $secret, -4 );
	}
}
