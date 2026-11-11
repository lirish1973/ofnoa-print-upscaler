<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Minimal, dependency-free PDF writer for a single full-page image at 300 DPI.
 *  - lossless: embeds the PNG's IDAT stream as-is (FlateDecode + PNG predictor) – bit-exact pixels.
 *  - jpeg:     DCTDecode at very high quality – smaller file for e-mail.
 */
class OFP_PDF {

	/** Parse an 8-bit RGB, non-interlaced PNG and return [w, h, idat] or WP_Error. */
	private static function png_parts( $path ) {
		$f = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( ! $f || substr( $f, 0, 8 ) !== "\x89PNG\r\n\x1a\n" ) {
			return new WP_Error( 'ofp_pdf', 'קובץ PNG לא תקין' );
		}
		$p    = 8;
		$idat = '';
		$w    = 0;
		$h    = 0;
		$len  = strlen( $f );
		while ( $p + 8 <= $len ) {
			$n    = unpack( 'N', substr( $f, $p, 4 ) )[1];
			$type = substr( $f, $p + 4, 4 );
			$data = substr( $f, $p + 8, $n );
			if ( 'IHDR' === $type ) {
				$h_ = unpack( 'Nw/Nh/Cbit/Ccolor/Ccomp/Cfilter/Cinterlace', $data );
				if ( 8 !== $h_['bit'] || 2 !== $h_['color'] || 0 !== $h_['interlace'] ) {
					return new WP_Error( 'ofp_pdf', 'PNG לא במבנה RGB 8bit' );
				}
				$w = $h_['w'];
				$h = $h_['h'];
			} elseif ( 'IDAT' === $type ) {
				$idat .= $data;
			} elseif ( 'IEND' === $type ) {
				break;
			}
			$p += 12 + $n;
		}
		return $w && $idat ? array( $w, $h, $idat ) : new WP_Error( 'ofp_pdf', 'PNG ריק' );
	}

	/** Make sure the PNG is plain RGB (re-save through GD flattened on white). */
	private static function ensure_rgb_png( $src, $tmp ) {
		$img = OFP_Image::load( $src );
		if ( is_wp_error( $img ) ) {
			return $img;
		}
		imagesavealpha( $img, false );
		imageinterlace( $img, false );
		imagepng( $img, $tmp, 6 );
		imagedestroy( $img );
		return $tmp;
	}

	public static function build( $png_path, $pdf_path, $mode, $title, $limit_bytes ) {
		if ( 'auto' === $mode || 'lossless' === $mode ) {
			$rgb = self::ensure_rgb_png( $png_path, $pdf_path . '.rgb.png' );
			if ( is_wp_error( $rgb ) ) {
				return $rgb;
			}
			$parts = self::png_parts( $rgb );
			wp_delete_file( $rgb );
			if ( is_wp_error( $parts ) ) {
				return $parts;
			}
			list( $w, $h, $idat ) = $parts;
			if ( 'lossless' === $mode || strlen( $idat ) < $limit_bytes * 0.92 ) {
				$dict = '/Filter /FlateDecode /DecodeParms << /Predictor 15 /Colors 3 /BitsPerComponent 8 /Columns ' . $w . ' >>';
				self::write( $pdf_path, $w, $h, $dict, $idat, $title );
				return array(
					'mode'  => 'lossless',
					'bytes' => filesize( $pdf_path ),
				);
			}
		}
		$img = OFP_Image::load( $png_path );
		if ( is_wp_error( $img ) ) {
			return $img;
		}
		$w = imagesx( $img );
		$h = imagesy( $img );
		foreach ( array( 97, 93, 90 ) as $q ) {
			ob_start();
			imagejpeg( $img, null, $q );
			$jpg = ob_get_clean();
			if ( strlen( $jpg ) < $limit_bytes * 0.92 || 90 === $q ) {
				break;
			}
		}
		imagedestroy( $img );
		self::write( $pdf_path, $w, $h, '/Filter /DCTDecode', $jpg, $title );
		return array(
			'mode'    => 'jpeg-q' . $q,
			'bytes'   => filesize( $pdf_path ),
		);
	}

	private static function write( $path, $w, $h, $filter, $stream, $title ) {
		$pw  = round( $w * 72 / 300, 3 ); // 300 DPI.
		$ph  = round( $h * 72 / 300, 3 );
		$t   = self::pdf_text( $title );
		$obj = array();

		$obj[1] = '<< /Type /Catalog /Pages 2 0 R >>';
		$obj[2] = '<< /Type /Pages /Kids [3 0 R] /Count 1 >>';
		$obj[3] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 {$pw} {$ph}] /Resources << /XObject << /Im0 4 0 R >> >> /Contents 5 0 R >>";
		$obj[4] = "<< /Type /XObject /Subtype /Image /Width {$w} /Height {$h} /ColorSpace /DeviceRGB /BitsPerComponent 8 {$filter} /Length " . strlen( $stream ) . " >>\nstream\n" . $stream . "\nendstream";
		$content = "q {$pw} 0 0 {$ph} 0 0 cm /Im0 Do Q";
		$obj[5] = '<< /Length ' . strlen( $content ) . " >>\nstream\n" . $content . "\nendstream";
		$obj[6] = '<< /Title ' . $t . ' /Producer (Ofnoa Print Upscaler ' . OFP_VERSION . ') /CreationDate (D:' . gmdate( 'YmdHis' ) . "Z) >>";

		$out     = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
		$offsets = array();
		foreach ( $obj as $i => $o ) {
			$offsets[ $i ] = strlen( $out );
			$out          .= "{$i} 0 obj\n{$o}\nendobj\n";
		}
		$xref = strlen( $out );
		$out .= 'xref' . "\n0 " . ( count( $obj ) + 1 ) . "\n0000000000 65535 f \n";
		foreach ( $offsets as $off ) {
			$out .= sprintf( "%010d 00000 n \n", $off );
		}
		$out .= 'trailer << /Size ' . ( count( $obj ) + 1 ) . " /Root 1 0 R /Info 6 0 R >>\nstartxref\n{$xref}\n%%EOF\n";
		file_put_contents( $path, $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	}

	/** UTF-16BE hex string so Hebrew titles survive. */
	private static function pdf_text( $s ) {
		$u = function_exists( 'mb_convert_encoding' ) ? mb_convert_encoding( $s, 'UTF-16BE', 'UTF-8' ) : $s;
		return '<FEFF' . strtoupper( bin2hex( $u ) ) . '>';
	}
}
