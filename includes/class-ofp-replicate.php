<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Replicate client for Real-ESRGAN (pure super-resolution: no generative prompt,
 * no face enhancement – it sharpens existing pixels instead of inventing content).
 */
class OFP_Replicate {

	const API = 'https://api.replicate.com/v1';

	private static function headers( $json = true ) {
		$h = array( 'Authorization' => 'Bearer ' . OFP_Settings::secret( 'replicate_token' ) );
		if ( $json ) {
			$h['Content-Type'] = 'application/json';
		}
		return $h;
	}

	private static function decode( $res ) {
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$code = wp_remote_retrieve_response_code( $res );
		$body = json_decode( wp_remote_retrieve_body( $res ), true );
		if ( $code >= 300 ) {
			$msg = is_array( $body ) && isset( $body['detail'] ) ? $body['detail'] : wp_remote_retrieve_body( $res );
			return new WP_Error( 'ofp_replicate', 'Replicate ' . $code . ': ' . wp_strip_all_tags( substr( (string) $msg, 0, 300 ) ) );
		}
		return is_array( $body ) ? $body : new WP_Error( 'ofp_replicate', 'תשובה לא תקינה מ-Replicate' );
	}

	/** Latest version id of the configured model (cached 12h). */
	public static function version() {
		$cached = get_transient( 'ofp_replicate_version' );
		if ( $cached ) {
			return $cached;
		}
		$model = trim( OFP_Settings::get( 'replicate_model' ), '/ ' );
		if ( preg_match( '/^[^\/]+\/[^:]+:([a-f0-9]{20,})$/', $model, $m ) ) {
			return $m[1];
		}
		$r = self::decode( wp_remote_get( self::API . '/models/' . $model, array( 'headers' => self::headers(), 'timeout' => 20 ) ) );
		if ( is_wp_error( $r ) ) {
			return $r;
		}
		if ( empty( $r['latest_version']['id'] ) ) {
			return new WP_Error( 'ofp_replicate', 'לא נמצאה גרסה למודל ' . $model );
		}
		set_transient( 'ofp_replicate_version', $r['latest_version']['id'], 12 * HOUR_IN_SECONDS );
		return $r['latest_version']['id'];
	}

	/** Upload a local file to Replicate's file store, returns a URL usable as model input. */
	public static function upload_file( $path ) {
		$boundary = wp_generate_password( 24, false );
		$name     = basename( $path );
		$body     = '--' . $boundary . "\r\n"
			. 'Content-Disposition: form-data; name="content"; filename="' . $name . "\"\r\n"
			. "Content-Type: image/png\r\n\r\n"
			. file_get_contents( $path ) . "\r\n" // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			. '--' . $boundary . "--\r\n";
		$headers                 = self::headers( false );
		$headers['Content-Type'] = 'multipart/form-data; boundary=' . $boundary;
		$r                       = self::decode(
			wp_remote_post(
				self::API . '/files',
				array(
					'headers' => $headers,
					'body'    => $body,
					'timeout' => 60,
				)
			)
		);
		if ( is_wp_error( $r ) ) {
			return $r;
		}
		return isset( $r['urls']['get'] ) ? $r['urls']['get'] : new WP_Error( 'ofp_replicate', 'העלאת הקובץ ל-Replicate נכשלה' );
	}

	public static function create_prediction( $image_url, $scale ) {
		$version = self::version();
		if ( is_wp_error( $version ) ) {
			return $version;
		}
		$r = self::decode(
			wp_remote_post(
				self::API . '/predictions',
				array(
					'headers' => self::headers(),
					'timeout' => 30,
					'body'    => wp_json_encode(
						array(
							'version' => $version,
							'input'   => array(
								'image'        => $image_url,
								'scale'        => (int) $scale,
								'face_enhance' => false,
							),
						)
					),
				)
			)
		);
		if ( is_wp_error( $r ) ) {
			return $r;
		}
		return isset( $r['id'] ) ? $r['id'] : new WP_Error( 'ofp_replicate', 'לא התקבל מזהה משימה' );
	}

	/** Returns array( status, output_url|null, error|null ). */
	public static function get_prediction( $id ) {
		$r = self::decode( wp_remote_get( self::API . '/predictions/' . rawurlencode( $id ), array( 'headers' => self::headers(), 'timeout' => 20 ) ) );
		if ( is_wp_error( $r ) ) {
			return $r;
		}
		$out = isset( $r['output'] ) ? $r['output'] : null;
		if ( is_array( $out ) ) {
			$out = reset( $out );
		}
		return array(
			'status' => isset( $r['status'] ) ? $r['status'] : 'unknown',
			'output' => $out,
			'error'  => isset( $r['error'] ) ? $r['error'] : null,
		);
	}

	public static function download( $url, $dest ) {
		$res = wp_remote_get(
			$url,
			array(
				'timeout'  => 120,
				'stream'   => true,
				'filename' => $dest,
				'headers'  => ( false !== strpos( $url, 'replicate.' ) ) ? self::headers( false ) : array(),
			)
		);
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		if ( wp_remote_retrieve_response_code( $res ) >= 300 || ! file_exists( $dest ) || filesize( $dest ) < 100 ) {
			return new WP_Error( 'ofp_replicate', 'הורדת התוצאה נכשלה' );
		}
		return true;
	}
}
