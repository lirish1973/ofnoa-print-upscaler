<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Image utilities (GD first, Imagick when needed for PDF input).
 * Every operation here is deterministic and never alters the artwork's content –
 * only resampling, colour-space flattening and format conversion.
 */
class OFP_Image {

	/** Load PNG/JPEG/WEBP into a true-colour GD image flattened on white. */
	public static function load( $path ) {
		$info = @getimagesize( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( ! $info ) {
			return new WP_Error( 'ofp_img', 'לא ניתן לקרוא את התמונה' );
		}
		switch ( $info[2] ) {
			case IMAGETYPE_PNG:
				$src = imagecreatefrompng( $path );
				break;
			case IMAGETYPE_JPEG:
				$src = imagecreatefromjpeg( $path );
				break;
			case IMAGETYPE_WEBP:
				$src = function_exists( 'imagecreatefromwebp' ) ? imagecreatefromwebp( $path ) : false;
				break;
			default:
				$src = false;
		}
		if ( ! $src ) {
			return new WP_Error( 'ofp_img', 'פורמט תמונה לא נתמך' );
		}
		$w   = imagesx( $src );
		$h   = imagesy( $src );
		$dst = imagecreatetruecolor( $w, $h );
		imagefill( $dst, 0, 0, imagecolorallocate( $dst, 255, 255, 255 ) );
		imagealphablending( $dst, true );
		imagecopy( $dst, $src, 0, 0, 0, 0, $w, $h );
		imagedestroy( $src );
		return $dst;
	}

	/**
	 * Normalise any accepted upload into a flattened RGB PNG ("source").
	 * PDF / AI / EPS are rasterised at 300 DPI through Imagick (first page).
	 */
	public static function normalise( $in_path, $out_path ) {
		$ext = strtolower( pathinfo( $in_path, PATHINFO_EXTENSION ) );
		if ( in_array( $ext, array( 'pdf', 'ai', 'eps' ), true ) ) {
			if ( ! class_exists( 'Imagick' ) ) {
				return new WP_Error( 'ofp_pdf_in', 'השרת לא תומך בהמרת PDF (Imagick חסר). העלו PNG או JPG.' );
			}
			try {
				$im = new Imagick();
				$im->setResolution( 300, 300 );
				$im->readImage( $in_path . '[0]' );
				$im->setImageBackgroundColor( 'white' );
				$im = $im->mergeImageLayers( Imagick::LAYERMETHOD_FLATTEN );
				$im->transformImageColorspace( Imagick::COLORSPACE_SRGB );
				$im->setImageFormat( 'png' );
				$im->writeImage( $out_path );
				$im->clear();
			} catch ( Exception $e ) {
				return new WP_Error( 'ofp_pdf_in', 'המרת ה-PDF נכשלה: ' . $e->getMessage() );
			}
			return true;
		}
		$img = self::load( $in_path );
		if ( is_wp_error( $img ) ) {
			return $img;
		}
		imagepng( $img, $out_path, 6 );
		imagedestroy( $img );
		return true;
	}

	public static function size( $path ) {
		$i = @getimagesize( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		return $i ? array( (int) $i[0], (int) $i[1] ) : array( 0, 0 );
	}

	public static function resample( $img, $w, $h ) {
		$dst = imagecreatetruecolor( $w, $h );
		imagefill( $dst, 0, 0, imagecolorallocate( $dst, 255, 255, 255 ) );
		if ( function_exists( 'imagesetinterpolation' ) ) {
			imagesetinterpolation( $dst, IMG_BICUBIC );
		}
		imagecopyresampled( $dst, $img, 0, 0, 0, 0, $w, $h, imagesx( $img ), imagesy( $img ) );
		return $dst;
	}

	/** Resize a file so its long side equals $long (keeps aspect ratio). */
	public static function fit_long_side( $in, $out, $long ) {
		$img = self::load( $in );
		if ( is_wp_error( $img ) ) {
			return $img;
		}
		$w = imagesx( $img );
		$h = imagesy( $img );
		$r = $long / max( $w, $h );
		$nw = max( 1, (int) round( $w * $r ) );
		$nh = max( 1, (int) round( $h * $r ) );
		if ( $nw === $w && $nh === $h ) {
			imagepng( $img, $out, 6 );
			imagedestroy( $img );
			return array( $w, $h );
		}
		if ( class_exists( 'Imagick' ) ) {
			imagedestroy( $img );
			try {
				$im = new Imagick( $in );
				$im->resizeImage( $nw, $nh, Imagick::FILTER_LANCZOS, 1 );
				$im->setImageFormat( 'png' );
				$im->writeImage( $out );
				$im->clear();
				return array( $nw, $nh );
			} catch ( Exception $e ) {
				$img = self::load( $in );
			}
		}
		$dst = self::resample( $img, $nw, $nh );
		imagepng( $dst, $out, 6 );
		imagedestroy( $img );
		imagedestroy( $dst );
		return array( $nw, $nh );
	}

	/** Resize a file to exact pixel dimensions (used to restore the source aspect ratio). */
	public static function resize_exact( $in, $out, $w, $h ) {
		$img = self::load( $in );
		if ( is_wp_error( $img ) ) {
			return $img;
		}
		$w = max( 1, (int) $w );
		$h = max( 1, (int) $h );
		if ( imagesx( $img ) === $w && imagesy( $img ) === $h ) {
			imagepng( $img, $out, 6 );
			imagedestroy( $img );
			return array( $w, $h );
		}
		$dst = self::resample( $img, $w, $h );
		imagepng( $dst, $out, 6 );
		imagedestroy( $img );
		imagedestroy( $dst );
		return array( $w, $h );
	}

	/**
	 * Deterministic fidelity check: shrink the result back to the original geometry and
	 * measure pixel differences. Upscaling should only sharpen; content changes
	 * (letters, shapes, colours) show up as large localised tile differences.
	 */
	public static function fidelity( $orig_path, $up_path, $grid = 24 ) {
		$a = self::load( $orig_path );
		$b = self::load( $up_path );
		if ( is_wp_error( $a ) || is_wp_error( $b ) ) {
			return new WP_Error( 'ofp_fid', 'לא ניתן להשוות את הקבצים' );
		}
		$w   = imagesx( $a );
		$h   = imagesy( $a );
		$cap = 1200;
		$r   = min( 1, $cap / max( $w, $h ) );
		$cw  = max( 1, (int) round( $w * $r ) );
		$ch  = max( 1, (int) round( $h * $r ) );
		$a2  = self::resample( $a, $cw, $ch );
		$b2  = self::resample( $b, $cw, $ch );
		imagedestroy( $a );
		imagedestroy( $b );

		$tw    = max( 1, (int) ceil( $cw / $grid ) );
		$th    = max( 1, (int) ceil( $ch / $grid ) );
		$tiles = array();
		$sum   = 0;
		$n     = 0;
		for ( $y = 0; $y < $ch; $y++ ) {
			for ( $x = 0; $x < $cw; $x++ ) {
				$p = imagecolorat( $a2, $x, $y );
				$q = imagecolorat( $b2, $x, $y );
				$d = ( abs( ( ( $p >> 16 ) & 255 ) - ( ( $q >> 16 ) & 255 ) ) + abs( ( ( $p >> 8 ) & 255 ) - ( ( $q >> 8 ) & 255 ) ) + abs( ( $p & 255 ) - ( $q & 255 ) ) ) / 3;
				$sum += $d;
				$n++;
				$k = (int) floor( $y / $th ) * $grid + (int) floor( $x / $tw );
				if ( ! isset( $tiles[ $k ] ) ) {
					$tiles[ $k ] = array( 0, 0 );
				}
				$tiles[ $k ][0] += $d;
				$tiles[ $k ][1]++;
			}
		}
		imagedestroy( $a2 );
		imagedestroy( $b2 );
		$worst = 0;
		$wk    = 0;
		foreach ( $tiles as $k => $t ) {
			$m = $t[1] ? $t[0] / $t[1] : 0;
			if ( $m > $worst ) {
				$worst = $m;
				$wk    = $k;
			}
		}
		$mean = $n ? $sum / $n : 0;
		$psnr = $mean > 0 ? 20 * log10( 255 / max( 0.0001, $mean ) ) : 99;
		return array(
			'mean_diff'  => round( $mean, 2 ),
			'worst_tile' => round( $worst, 2 ),
			'worst_at'   => array(
				'row' => (int) floor( $wk / $grid ) + 1,
				'col' => ( $wk % $grid ) + 1,
				'of'  => $grid,
			),
			'approx_psnr' => round( $psnr, 1 ),
		);
	}

	/** JPEG base64 of an image (or a crop) scaled to max $max px – for the vision agents. */
	public static function b64_view( $path, $max = 1568, $crop = null ) {
		$img = self::load( $path );
		if ( is_wp_error( $img ) ) {
			return '';
		}
		if ( $crop ) {
			list( $cx, $cy, $cw, $ch ) = $crop;
			$c = imagecreatetruecolor( $cw, $ch );
			imagecopy( $c, $img, 0, 0, $cx, $cy, $cw, $ch );
			imagedestroy( $img );
			$img = $c;
		}
		$w = imagesx( $img );
		$h = imagesy( $img );
		$r = min( 1, $max / max( $w, $h ) );
		if ( $r < 1 ) {
			$d = self::resample( $img, max( 1, (int) round( $w * $r ) ), max( 1, (int) round( $h * $r ) ) );
			imagedestroy( $img );
			$img = $d;
		}
		ob_start();
		imagejpeg( $img, null, 90 );
		$bin = ob_get_clean();
		imagedestroy( $img );
		return base64_encode( $bin ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
	}

	/** 2x2 overlapping crop rectangles (relative to image size). */
	public static function quad_crops( $w, $h ) {
		$ov = 0.06;
		$cw = (int) round( $w * ( 0.5 + $ov ) );
		$ch = (int) round( $h * ( 0.5 + $ov ) );
		return array(
			'למעלה-ימין'  => array( $w - $cw, 0, $cw, $ch ),
			'למעלה-שמאל' => array( 0, 0, $cw, $ch ),
			'למטה-ימין'   => array( $w - $cw, $h - $ch, $cw, $ch ),
			'למטה-שמאל'  => array( 0, $h - $ch, $cw, $ch ),
		);
	}
}
