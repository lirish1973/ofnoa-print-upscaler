<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Proofing agents (Claude vision). Each agent works independently and returns JSON;
 * a final "chief proofreader" merges them with the deterministic metrics.
 *
 * Agents:
 *  1. transcriber  – reads every piece of text in the ORIGINAL, exactly as printed.
 *  2. transcriber  – same task, blind, on the UPSCALED result.
 *  3. inspector    – compares original vs. result tile by tile for any visual change.
 *  4. proofreader  – spelling / grammar check (Hebrew + English) of the original text.
 *  5. chief        – consolidates everything into a final verdict.
 */
class OFP_Claude {

	const API = 'https://api.anthropic.com/v1/messages';

	public static function call( $system, $content, $max_tokens = 3000 ) {
		$key = OFP_Settings::secret( 'anthropic_key' );
		if ( ! $key ) {
			return new WP_Error( 'ofp_claude', 'חסר מפתח Anthropic בהגדרות' );
		}
		$body = array(
			'model'       => OFP_Settings::get( 'claude_model' ),
			'max_tokens'  => $max_tokens,
			'system'      => $system,
			'messages'    => array(
				array(
					'role'    => 'user',
					'content' => $content,
				),
			),
		);
		$tries = 0;
		do {
			$res = wp_remote_post(
				self::API,
				array(
					'timeout' => 180,
					'headers' => array(
						'x-api-key'         => $key,
						'anthropic-version' => '2023-06-01',
						'content-type'      => 'application/json',
					),
					'body'    => wp_json_encode( $body ),
				)
			);
			$code = is_wp_error( $res ) ? 0 : wp_remote_retrieve_response_code( $res );
			$tries++;
			if ( in_array( $code, array( 429, 500, 502, 503, 529 ), true ) && $tries < 3 ) {
				sleep( 4 * $tries );
				continue;
			}
			break;
		} while ( true );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$j = json_decode( wp_remote_retrieve_body( $res ), true );
		if ( $code >= 300 ) {
			$m = isset( $j['error']['message'] ) ? $j['error']['message'] : 'HTTP ' . $code;
			return new WP_Error( 'ofp_claude', 'Claude: ' . $m );
		}
		$text = '';
		foreach ( (array) ( isset( $j['content'] ) ? $j['content'] : array() ) as $c ) {
			if ( isset( $c['type'], $c['text'] ) && 'text' === $c['type'] ) {
				$text .= $c['text'];
			}
		}
		$data = self::json( $text );
		if ( null === $data ) {
			return new WP_Error( 'ofp_claude', 'הסוכן החזיר תשובה שאינה JSON' );
		}
		$data['_usage'] = isset( $j['usage'] ) ? $j['usage'] : array();
		return $data;
	}

	/** Extract the first JSON object from a model reply. */
	public static function json( $text ) {
		$text = trim( (string) $text );
		$text = preg_replace( '/^```(?:json)?\s*|\s*```$/m', '', $text );
		$s    = strpos( $text, '{' );
		$e    = strrpos( $text, '}' );
		if ( false === $s || false === $e || $e < $s ) {
			return null;
		}
		$d = json_decode( substr( $text, $s, $e - $s + 1 ), true );
		return is_array( $d ) ? $d : null;
	}

	private static function img_block( $b64 ) {
		return array(
			'type'   => 'image',
			'source' => array(
				'type'       => 'base64',
				'media_type' => 'image/jpeg',
				'data'       => $b64,
			),
		);
	}

	private static function txt( $t ) {
		return array(
			'type' => 'text',
			'text' => $t,
		);
	}

	/** Full view + 4 detail crops of one image. */
	private static function views( $path, $label ) {
		list( $w, $h ) = OFP_Image::size( $path );
		$blocks        = array( self::txt( $label . ' – תצוגה מלאה:' ), self::img_block( OFP_Image::b64_view( $path ) ) );
		foreach ( OFP_Image::quad_crops( $w, $h ) as $name => $rect ) {
			$blocks[] = self::txt( $label . ' – הגדלה של רבע ' . $name . ':' );
			$blocks[] = self::img_block( OFP_Image::b64_view( $path, 1400, $rect ) );
		}
		return $blocks;
	}

	/**
	 * What kind of image is this? Decides whether the prompt-guided enhancement may run:
	 * the enhancement prompt is written for photographs of people and replaces graphics with an invented portrait.
	 */
	public static function classify( $path ) {
		$system = 'You classify an image for a print shop. Reply with JSON only: {"kind":"photo_person|photo_other|graphic","confidence":"high|medium|low","reason":"one short Hebrew sentence"}. photo_person = a real camera photograph in which one or more real people are a main subject (portrait, selfie, couple or group photo, event photo). photo_other = a real photograph without people as a main subject (product, landscape, building, food, animal). graphic = anything designed or drawn: logo, text artwork, illustration, cartoon, painting, AI-generated art, poster, flyer, screenshot, clip-art, or a design that merely contains a photo inside it. When in doubt choose graphic.';
		$b64    = OFP_Image::b64_view( $path, 1024 );
		if ( '' === $b64 ) {
			return new WP_Error( 'ofp_claude', 'לא ניתן לקרוא את התמונה לסיווג' );
		}
		$res = self::call( $system, array( self::img_block( $b64 ), self::txt( 'סווגו את התמונה. JSON בלבד.' ) ), 200 );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$kind = isset( $res['kind'] ) && in_array( $res['kind'], array( 'photo_person', 'photo_other', 'graphic' ), true ) ? $res['kind'] : 'graphic';
		return array(
			'kind'       => $kind,
			'confidence' => isset( $res['confidence'] ) ? (string) $res['confidence'] : 'low',
			'reason'     => isset( $res['reason'] ) ? (string) $res['reason'] : '',
		);
	}

	public static function kind_label( $kind ) {
		$m = array(
			'photo_person' => 'צילום עם אדם',
			'photo_other'  => 'צילום בלי אדם',
			'graphic'      => 'גרפיקה / לוגו',
		);
		return isset( $m[ $kind ] ) ? $m[ $kind ] : $kind;
	}

	public static function transcribe( $path, $label ) {
		$system = 'You are a meticulous print pre-press transcriber. Transcribe EVERY piece of visible text in the artwork exactly as it appears: keep original spelling (even if wrong), punctuation, numbers, phone numbers, emails, URLs, Hebrew niqqud and letter case. Do not correct anything and do not guess hidden text – mark unreadable parts with [?]. Hebrew is read right-to-left. Reply with JSON only: {"texts":[{"text":"...","lang":"he|en|other","where":"short location description in Hebrew"}],"has_text":true|false,"legibility":"good|fair|poor"}';
		$content   = self::views( $path, $label );
		$content[] = self::txt( 'תעתקו את כל הטקסט בגרפיקה. JSON בלבד.' );
		return self::call( $system, $content, 3000 );
	}

	public static function inspect( $orig, $up, $enhanced = false ) {
		list( $w, $h ) = OFP_Image::size( $orig );
		list( $W, $H ) = OFP_Image::size( $up );
		$content       = array(
			self::txt( 'מקור – תצוגה מלאה:' ),
			self::img_block( OFP_Image::b64_view( $orig ) ),
			self::txt( 'תוצאה מוגדלת – תצוגה מלאה:' ),
			self::img_block( OFP_Image::b64_view( $up ) ),
		);
		$oc = OFP_Image::quad_crops( $w, $h );
		$uc = OFP_Image::quad_crops( $W, $H );
		foreach ( $oc as $name => $rect ) {
			$content[] = self::txt( 'רבע ' . $name . ' – מקור:' );
			$content[] = self::img_block( OFP_Image::b64_view( $orig, 1100, $rect ) );
			$content[] = self::txt( 'רבע ' . $name . ' – תוצאה:' );
			$content[] = self::img_block( OFP_Image::b64_view( $up, 1100, $uc[ $name ] ) );
		}
		$content[] = self::txt( 'השוו בין המקור לתוצאה. JSON בלבד.' );
		$system    = 'You are a strict print quality inspector. The second image is an AI super-resolution of the first. Upscaling may ONLY increase sharpness/resolution. Report ANY change in content: altered, missing or added letters/digits, changed words, deformed logos or icons, changed shapes, moved elements, colour shifts, invented textures or artefacts, cropped edges. Sharper edges and less blur/noise are NOT differences. Be precise and conservative: only report what you can actually see. Reply in JSON only: {"verdict":"identical|minor|changed","differences":[{"severity":"low|medium|high","where":"location in Hebrew","description":"what changed, in Hebrew"}],"sharpness_gain":"none|some|clear","notes":"Hebrew"}';
		if ( $enhanced ) {
			$system = 'You are a strict print quality inspector. The second image is an AI photographic ENHANCEMENT plus upscale of the first. It was produced on purpose with an instruction to improve lighting, contrast, colour grading, depth of field, texture, grain and sharpness while keeping identity, background and layout. Therefore differences in lighting, tone, colour grade, contrast, sharpness, grain and background blur are EXPECTED and must NOT be reported. Report ONLY changes of content: a person whose identity, facial geometry, expression, age, hair or clothing changed; objects added, removed, replaced or moved; a background whose content (not its blur or tone) changed; cropping or a layout shift; altered, missing or added letters/digits/words; deformed logos or icons. Be precise and conservative: only report what you can actually see. Use "identical" when there is no content change, "minor" for small or doubtful content deviations, "changed" for a clear content change. Reply in JSON only: {"verdict":"identical|minor|changed","differences":[{"severity":"low|medium|high","where":"location in Hebrew","description":"what changed, in Hebrew"}],"sharpness_gain":"none|some|clear","notes":"Hebrew"}';
		}
		return self::call( $system, $content, 2500 );
	}

	public static function proofread( $orig, $texts ) {
		$list = array();
		foreach ( (array) $texts as $t ) {
			if ( ! empty( $t['text'] ) ) {
				$list[] = '- ' . $t['text'];
			}
		}
		if ( ! $list ) {
			return array(
				'issues'  => array(),
				'summary' => 'אין טקסט בגרפיקה',
			);
		}
		$system  = 'You are a senior Hebrew and English proofreader for printed marketing material. Check the transcribed text for spelling mistakes, typos, wrong letters, grammar, gender/number agreement, punctuation, inconsistent spacing, and suspicious phone numbers/emails/URLs (e.g. wrong digit count for Israeli numbers, malformed emails). Brand names, slogans and intentional stylisation are NOT errors – flag them only if clearly a typo, with low confidence. Never rewrite style. Reply in JSON only: {"issues":[{"text":"the exact problematic text","suggestion":"suggested correction","type":"spelling|grammar|punctuation|contact|consistency","confidence":"high|medium|low","explanation":"short Hebrew explanation"}],"summary":"one Hebrew sentence"}';
		$content = array(
			self::txt( 'הגרפיקה המקורית לעיון:' ),
			self::img_block( OFP_Image::b64_view( $orig ) ),
			self::txt( "הטקסט שתועתק מהגרפיקה:\n" . implode( "\n", $list ) . "\n\nבדקו איות, דקדוק ופרטי קשר. JSON בלבד." ),
		);
		return self::call( $system, $content, 2500 );
	}

	public static function chief( $facts ) {
		$system = 'You are the chief proofreader who signs off print files. You receive independent agent reports plus deterministic pixel metrics. Decide: "approved" only if the upscale is faithful (no content changes, both transcriptions match) – spelling issues in the customer\'s ORIGINAL do not block approval but must be listed. Use "needs_review" if fidelity is doubtful, transcriptions disagree, or legibility is poor. Use "rejected" if the result clearly changed the artwork. Write everything for a Hebrew-speaking print shop owner. Reply in JSON only: {"status":"approved|needs_review|rejected","fidelity_ok":true|false,"text_match":true|false,"summary":"2-3 Hebrew sentences","action_items":["Hebrew"],"spelling_issues":[{"text":"","suggestion":"","confidence":""}]}';
		if ( ! empty( $facts['enhancement_applied'] ) ) {
			$system .= ' IMPORTANT: for this file enhancement_applied is true – the image was deliberately re-lit and colour graded by an AI enhancement before the upscale, so the pixel metrics are expected to be high and must be ignored. Judge fidelity only by content: identity of people, background content, objects, layout, text and logos, as reported by the visual inspection and the two transcriptions.';
		}
		return self::call( $system, array( self::txt( wp_json_encode( $facts, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT ) ) ), 2000 );
	}

	/** Normalised text comparison between the two independent transcriptions. */
	public static function text_diff( $a, $b ) {
		$norm = function ( $list ) {
			$s = array();
			foreach ( (array) $list as $t ) {
				$x = isset( $t['text'] ) ? $t['text'] : '';
				$x = preg_replace( '/[\x{0591}-\x{05C7}]/u', '', $x ); // niqqud / cantillation.
				$x = preg_replace( '/[\s\p{P}]+/u', ' ', mb_strtolower( $x ) );
				foreach ( preg_split( '/\s+/u', trim( $x ) ) as $w ) {
					if ( '' !== $w && '[?]' !== $w ) {
						$s[] = $w;
					}
				}
			}
			return $s;
		};
		$wa = $norm( $a );
		$wb = $norm( $b );
		$ca = array_count_values( $wa );
		$cb = array_count_values( $wb );
		$only_a = array();
		$only_b = array();
		foreach ( $ca as $w => $n ) {
			if ( ! isset( $cb[ $w ] ) || $cb[ $w ] < $n ) {
				$only_a[] = $w;
			}
		}
		foreach ( $cb as $w => $n ) {
			if ( ! isset( $ca[ $w ] ) || $ca[ $w ] < $n ) {
				$only_b[] = $w;
			}
		}
		$total = max( 1, max( count( $wa ), count( $wb ) ) );
		return array(
			'words_original' => count( $wa ),
			'words_result'   => count( $wb ),
			'only_in_original' => array_slice( $only_a, 0, 30 ),
			'only_in_result'   => array_slice( $only_b, 0, 30 ),
			'match_ratio'      => round( 1 - ( count( $only_a ) + count( $only_b ) ) / ( 2 * $total ), 3 ),
		);
	}
}
