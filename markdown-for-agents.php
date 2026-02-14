<?php
/**
 * Plugin Name: Markdown for Agents
 * Description: Serves clean markdown to AI agents that send Accept: text/markdown. Drop in wp-content/mu-plugins/.
 * Version: 1.0.0
 * Author: Gaurav Tiwari
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'template_redirect', 'mfa_maybe_serve_markdown', 1 );

function mfa_maybe_serve_markdown() {
	if ( ! is_singular() ) {
		return;
	}

	$accept = isset( $_SERVER['HTTP_ACCEPT'] ) ? $_SERVER['HTTP_ACCEPT'] : '';

	if ( strpos( $accept, 'text/markdown' ) === false ) {
		return;
	}

	$post = get_queried_object();

	if ( ! $post || ! is_a( $post, 'WP_Post' ) ) {
		return;
	}

	$markdown = mfa_post_to_markdown( $post );
	$tokens   = mfa_estimate_tokens( $markdown );

	header( 'Content-Type: text/markdown; charset=utf-8' );
	header( 'X-Markdown-Tokens: ' . $tokens );
	header( 'Content-Signal: ai-train=yes, search=yes, ai-input=yes' );
	header( 'Cache-Control: public, max-age=3600' );
	header( 'Vary: Accept' );

	echo $markdown;
	exit;
}

function mfa_post_to_markdown( WP_Post $post ) {
	$parts = array();

	$parts[] = '# ' . get_the_title( $post );
	$parts[] = '';

	$meta = array();
	$meta[] = '- **Published**: ' . get_the_date( 'Y-m-d', $post );
	$meta[] = '- **Modified**: ' . get_the_modified_date( 'Y-m-d', $post );
	$meta[] = '- **Author**: ' . get_the_author_meta( 'display_name', $post->post_author );
	$meta[] = '- **URL**: ' . get_permalink( $post );

	$categories = get_the_category( $post->ID );
	if ( $categories ) {
		$cat_names = wp_list_pluck( $categories, 'name' );
		$meta[]    = '- **Categories**: ' . implode( ', ', $cat_names );
	}

	$tags = get_the_tags( $post->ID );
	if ( $tags ) {
		$tag_names = wp_list_pluck( $tags, 'name' );
		$meta[]    = '- **Tags**: ' . implode( ', ', $tag_names );
	}

	$parts[] = implode( "\n", $meta );
	$parts[] = '';
	$parts[] = '---';
	$parts[] = '';

	$description = get_post_meta( $post->ID, 'rank_math_description', true );
	if ( ! $description ) {
		$description = get_post_meta( $post->ID, '_yoast_wpseo_metadesc', true );
	}
	if ( $description ) {
		$parts[] = '> ' . $description;
		$parts[] = '';
	}

	$content = apply_filters( 'the_content', $post->post_content );
	$content = mfa_html_to_markdown( $content );

	$parts[] = $content;

	return implode( "\n", $parts );
}

function mfa_html_to_markdown( $html ) {
	$code_blocks = array();
	$html = preg_replace_callback(
		'/<pre[^>]*>\s*<code[^>]*(?:class=["\'].*?language-(\w+).*?["\'])?[^>]*>(.*?)<\/code>\s*<\/pre>/si',
		function ( $m ) use ( &$code_blocks ) {
			$lang    = $m[1] ?: '';
			$code    = $m[2];
			$code    = str_replace( array( '&lt;', '&gt;', '&amp;', '&quot;', '&#039;' ), array( '<', '>', '&', '"', "'" ), $code );
			$token   = '%%CODEBLOCK_' . count( $code_blocks ) . '%%';
			$code_blocks[ $token ] = "\n```" . $lang . "\n" . trim( $code ) . "\n```\n";
			return $token;
		},
		$html
	);

	$html = preg_replace( '/<script[^>]*>.*?<\/script>/si', '', $html );
	$html = preg_replace( '/<style[^>]*>.*?<\/style>/si', '', $html );
	$html = preg_replace( '/<nav[^>]*>.*?<\/nav>/si', '', $html );
	$html = preg_replace( '/<form[^>]*>.*?<\/form>/si', '', $html );
	$html = preg_replace( '/<iframe[^>]*>.*?<\/iframe>/si', '', $html );
	$html = preg_replace( '/<svg[^>]*>.*?<\/svg>/si', '', $html );
	$html = preg_replace( '/<noscript[^>]*>.*?<\/noscript>/si', '', $html );

	$html = wp_kses( $html, array(
		'h1'         => array(),
		'h2'         => array(),
		'h3'         => array(),
		'h4'         => array(),
		'h5'         => array(),
		'h6'         => array(),
		'p'          => array(),
		'a'          => array( 'href' => true, 'title' => true ),
		'img'        => array( 'src' => true, 'alt' => true ),
		'strong'     => array(),
		'b'          => array(),
		'em'         => array(),
		'i'          => array(),
		'ul'         => array(),
		'ol'         => array( 'start' => true ),
		'li'         => array(),
		'blockquote' => array(),
		'pre'        => array(),
		'code'       => array( 'class' => true ),
		'br'         => array(),
		'hr'         => array(),
		'table'      => array(),
		'thead'      => array(),
		'tbody'      => array(),
		'tr'         => array(),
		'th'         => array(),
		'td'         => array(),
		'figure'     => array(),
		'figcaption' => array(),
	) );

	$html = preg_replace( '/<!--.*?-->/s', '', $html );

	for ( $i = 6; $i >= 1; $i-- ) {
		$html = preg_replace_callback(
			'/<h' . $i . '[^>]*>(.*?)<\/h' . $i . '>/si',
			function ( $m ) use ( $i ) {
				return "\n" . str_repeat( '#', $i ) . ' ' . trim( strip_tags( $m[1] ) ) . "\n";
			},
			$html
		);
	}

	$html = preg_replace_callback(
		'/<a\s[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)<\/a>/si',
		function ( $m ) {
			$url  = $m[1];
			$text = trim( strip_tags( $m[2] ) );
			if ( ! $text ) {
				return $url;
			}
			return '[' . $text . '](' . $url . ')';
		},
		$html
	);

	$html = preg_replace_callback(
		'/<img\s[^>]*src=["\']([^"\']+)["\'][^>]*alt=["\']([^"\']*)["\'][^>]*\/?>/si',
		function ( $m ) {
			return '![' . $m[2] . '](' . $m[1] . ')';
		},
		$html
	);
	$html = preg_replace_callback(
		'/<img\s[^>]*alt=["\']([^"\']*)["\'][^>]*src=["\']([^"\']+)["\'][^>]*\/?>/si',
		function ( $m ) {
			return '![' . $m[1] . '](' . $m[2] . ')';
		},
		$html
	);

	$html = preg_replace( '/<strong>(.*?)<\/strong>/si', '**$1**', $html );
	$html = preg_replace( '/<b>(.*?)<\/b>/si', '**$1**', $html );
	$html = preg_replace( '/<em>(.*?)<\/em>/si', '*$1*', $html );
	$html = preg_replace( '/<i>(.*?)<\/i>/si', '*$1*', $html );

	$html = preg_replace( '/<code>(.*?)<\/code>/si', '`$1`', $html );

	$html = preg_replace_callback(
		'/<blockquote[^>]*>(.*?)<\/blockquote>/si',
		function ( $m ) {
			$inner = strip_tags( $m[1], '<p><br>' );
			$inner = trim( strip_tags( $inner ) );
			$lines = explode( "\n", $inner );
			$quoted = array();
			foreach ( $lines as $line ) {
				$line = trim( $line );
				if ( $line !== '' ) {
					$quoted[] = '> ' . $line;
				}
			}
			return "\n" . implode( "\n", $quoted ) . "\n";
		},
		$html
	);

	$html = mfa_convert_tables( $html );

	$html = preg_replace_callback(
		'/<(ul|ol)[^>]*>(.*?)<\/\1>/si',
		function ( $m ) {
			return mfa_convert_list( $m[0], $m[1] === 'ol' );
		},
		$html
	);

	$html = preg_replace( '/<hr\s*\/?>/i', "\n---\n", $html );
	$html = preg_replace( '/<br\s*\/?>/i', "\n", $html );
	$html = preg_replace( '/<p[^>]*>(.*?)<\/p>/si', "\n$1\n", $html );

	$html = preg_replace( '/<figure[^>]*>(.*?)<\/figure>/si', '$1', $html );
	$html = preg_replace( '/<figcaption[^>]*>(.*?)<\/figcaption>/si', '*$1*', $html );

	$html = strip_tags( $html );
	$html = html_entity_decode( $html, ENT_QUOTES, 'UTF-8' );

	foreach ( $code_blocks as $token => $block ) {
		$html = str_replace( $token, $block, $html );
	}

	$html = preg_replace( '/\n{3,}/', "\n\n", $html );

	return trim( $html );
}

function mfa_convert_list( $html, $ordered = false ) {
	$output = "\n";
	$index  = 1;

	preg_match_all( '/<li[^>]*>(.*?)<\/li>/si', $html, $matches );

	foreach ( $matches[1] as $item ) {
		$text = trim( strip_tags( $item ) );
		if ( $text === '' ) {
			continue;
		}
		if ( $ordered ) {
			$output .= $index . '. ' . $text . "\n";
			$index++;
		} else {
			$output .= '- ' . $text . "\n";
		}
	}

	return $output;
}

function mfa_convert_tables( $html ) {
	return preg_replace_callback(
		'/<table[^>]*>(.*?)<\/table>/si',
		function ( $m ) {
			$table_html = $m[1];
			$rows       = array();
			$is_first   = true;

			preg_match_all( '/<tr[^>]*>(.*?)<\/tr>/si', $table_html, $tr_matches );

			foreach ( $tr_matches[1] as $tr ) {
				preg_match_all( '/<(?:td|th)[^>]*>(.*?)<\/(?:td|th)>/si', $tr, $td_matches );
				$cells = array();
				foreach ( $td_matches[1] as $cell ) {
					$cells[] = trim( strip_tags( $cell ) );
				}
				if ( empty( $cells ) ) {
					continue;
				}
				$rows[] = '| ' . implode( ' | ', $cells ) . ' |';

				if ( $is_first ) {
					$sep    = array();
					foreach ( $cells as $c ) {
						$sep[] = '---';
					}
					$rows[]   = '| ' . implode( ' | ', $sep ) . ' |';
					$is_first = false;
				}
			}

			return "\n" . implode( "\n", $rows ) . "\n";
		},
		$html
	);
}

function mfa_estimate_tokens( $text ) {
	return (int) ceil( mb_strlen( $text, 'UTF-8' ) / 4 );
}
