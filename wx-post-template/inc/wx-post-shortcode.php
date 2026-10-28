<?php
/**
 * Wawasanex post template — shortcode + renderer.
 *
 * [wx_post]                                   current post in the loop
 * [wx_post id="2387"]                         an explicit post
 * [wx_post layout="announcement" deck="..."]  attribute overrides
 *
 * Cascade: shortcode attribute > post meta > WP-native fallback > hidden.
 *
 * @package wx-post-template
 */

defined( 'ABSPATH' ) || exit;

/**
 * Where the template lives. Filterable so another client site can point elsewhere.
 *
 * @return string
 */
function wx_post_template_path() {
	return apply_filters( 'wx_post_template_path', get_stylesheet_directory() . '/templates/wx-post.html' );
}

/**
 * Read the template file once per request.
 *
 * @return string
 */
function wx_post_load_template() {
	static $html = null;
	if ( null !== $html ) {
		return $html;
	}
	$path = wx_post_template_path();
	$html = is_readable( $path ) ? (string) file_get_contents( $path ) : '';
	if ( '' === $html && current_user_can( 'manage_options' ) ) {
		return '<!-- wx_post: template not found at ' . esc_html( $path ) . ' -->';
	}
	return $html;
}

/**
 * Resolve a post id SAFELY.
 *
 * Walks nothing: non-posts, drafts, private and scheduled posts are refused
 * unless the current user may read them. This is the guard that stops
 * `id` walked 1..N from leaking unpublished press releases.
 *
 * @param int $id Candidate post id.
 * @return WP_Post|null
 */
function wx_post_resolve( $id ) {
	$id = absint( $id );
	if ( ! $id ) {
		return null;
	}

	$post = get_post( $id );
	if ( ! $post || 'post' !== $post->post_type ) {
		return null;
	}

	if ( 'publish' !== $post->post_status && ! current_user_can( 'read_post', $id ) ) {
		return null;
	}

	return $post;
}

/**
 * Estimated reading time in minutes (never below 1).
 *
 * @param string $content Post content.
 * @return int
 */
function wx_post_read_time( $content ) {
	$text  = wp_strip_all_tags( (string) $content );
	$words = preg_match_all( '/\S+/u', $text );
	return max( 1, (int) ceil( (int) $words / 200 ) );
}

/**
 * Key facts: one per line.
 *
 * @param string $raw Raw meta value.
 * @return array<int,string>
 */
function wx_post_keyfacts_list( $raw ) {
	$lines = preg_split( '/\r\n|\r|\n/', (string) $raw );
	$out   = array();
	foreach ( (array) $lines as $line ) {
		if ( '' !== trim( $line ) ) {
			$out[] = trim( $line );
		}
	}
	return $out;
}

/**
 * Build the related-posts list from a loop fragment.
 *
 * @param WP_Post $post     Current post.
 * @param string  $fragment Loop fragment containing %%item_*%% tokens.
 * @return string
 */
function wx_post_related( $post, $fragment ) {
	$cats = get_the_category( $post->ID );
	$args = array(
		'post__not_in'        => array( $post->ID ),
		'posts_per_page'      => 3,
		'ignore_sticky_posts' => true,
		'no_found_rows'       => true,
		'post_status'         => 'publish',
		'post_type'           => 'post',
	);

	if ( $cats ) {
		$args['category__in'] = array( (int) $cats[0]->term_id );
	} else {
		$args['post__not_in'] = array( $post->ID );
	}

	$q    = new WP_Query( $args );
	$html = '';

	foreach ( $q->posts as $rel ) {
		$rel_cats = get_the_category( $rel->ID );
		$thumb_id = get_post_thumbnail_id( $rel->ID );

		$item = array(
			'item_url'       => esc_url( (string) get_permalink( $rel ) ),
			'item_title'     => esc_html( get_the_title( $rel ) ),
			'item_date'      => esc_html( get_the_date( '', $rel ) ),
			'item_date_iso'  => esc_attr( get_the_date( 'c', $rel ) ),
			'item_cat'       => esc_html( $rel_cats ? $rel_cats[0]->name : '' ),
			'item_excerpt'   => esc_html( wp_trim_words( get_the_excerpt( $rel ), 18 ) ),
			'item_thumb_url' => $thumb_id ? esc_url( (string) wp_get_attachment_image_url( $thumb_id, 'medium_large' ) ) : '',
			'item_thumb_alt' => $thumb_id ? esc_attr( (string) get_post_meta( $thumb_id, '_wp_attachment_image_alt', true ) ) : '',
		);

		$prefixed = array();
		foreach ( $item as $k => $v ) {
			$prefixed[ '%%' . $k . '%%' ] = $v;
		}

		// if-blocks INSIDE the fragment resolve per item, with this item's values.
		$html .= wx_post_apply_ifs( strtr( $fragment, $prefixed ), $item );
	}

	return $html;
}

/**
 * Build the token map for one post.
 *
 * Escaping rule: text tokens carry escaped TEXT (use them in text nodes),
 * url tokens carry escaped URLs (use them in attributes). The template is ours,
 * so that split is fixed by convention — see README.
 *
 * @param WP_Post $post Post.
 * @param array   $atts Shortcode attributes.
 * @param array   $loops Loop fragments keyed by name.
 * @return array<string,string>
 */
function wx_post_tokens( $post, $atts, $loops ) {
	$meta = static function ( $key ) use ( $post ) {
		return (string) get_post_meta( $post->ID, $key, true );
	};

	// Eyebrow: att > meta > primary category.
	$cats     = get_the_category( $post->ID );
	$cat_name = $cats ? $cats[0]->name : '';
	$cat_url  = $cats ? get_category_link( $cats[0]->term_id ) : '';
	$eyebrow  = '' !== $atts['eyebrow'] ? $atts['eyebrow'] : ( '' !== $meta( '_wx_eyebrow' ) ? $meta( '_wx_eyebrow' ) : $cat_name );

	// Deck: att > meta > excerpt.
	$deck = '';
	if ( '' !== $atts['deck'] ) {
		$deck = esc_html( $atts['deck'] );
	} elseif ( '' !== $meta( '_wx_deck' ) ) {
		$deck = wp_kses_post( $meta( '_wx_deck' ) );
	} else {
		$deck = esc_html( wp_trim_words( get_the_excerpt( $post ), 25 ) );
	}

	// Hero: att > meta attachment > featured image.
	$hero_id = absint( $atts['hero'] ) ? absint( $atts['hero'] ) : absint( $meta( '_wx_hero' ) );
	if ( ! $hero_id ) {
		$hero_id = absint( get_post_thumbnail_id( $post->ID ) );
	}
	$hero_url = $hero_id ? (string) wp_get_attachment_image_url( $hero_id, 'full' ) : '';
	$hero_alt = $hero_id ? (string) get_post_meta( $hero_id, '_wp_attachment_image_alt', true ) : '';
	if ( '' === $hero_alt && $hero_id ) {
		$hero_alt = (string) get_the_title( $hero_id );
	}

	$boilerplate = '' !== $meta( '_wx_boilerplate_override' )
		? wp_kses_post( $meta( '_wx_boilerplate_override' ) )
		: wp_kses_post( (string) get_option( 'wx_post_boilerplate', '' ) );

	$tokens = array(
		'eyebrow'       => esc_html( $eyebrow ),
		'title'         => esc_html( get_the_title( $post ) ),
		'title_urlenc'  => esc_attr( rawurlencode( (string) get_the_title( $post ) ) ),
		'deck'          => $deck,
		'author'        => esc_html( (string) get_the_author_meta( 'display_name', (int) $post->post_author ) ),
		'author_role'   => esc_html( $meta( '_wx_author_role' ) ),
		'date'          => esc_html( get_the_date( '', $post ) ),
		'date_iso'      => esc_attr( get_the_date( 'c', $post ) ),
		'read_time'     => esc_html( (string) wx_post_read_time( $post->post_content ) ),
		'layout'        => esc_attr( $atts['layout'] ),
		'permalink'     => esc_url( (string) get_permalink( $post ) ),
		'category'      => esc_html( $cat_name ),
		'category_url'  => esc_url( (string) $cat_url ),
		'hero_url'      => esc_url( $hero_url ),
		'hero_alt'      => esc_attr( $hero_alt ),
		'pullquote'     => wp_kses_post( $meta( '_wx_pullquote' ) ),
		'boilerplate'   => $boilerplate,
		'content'       => apply_filters( 'the_content', $post->post_content ),
		'keyfacts'      => '',
		'related'       => '',
	);

	if ( isset( $loops['keyfacts'] ) ) {
		$facts_html = '';
		foreach ( wx_post_keyfacts_list( $meta( '_wx_keyfacts' ) ) as $fact ) {
			$facts_html .= strtr( $loops['keyfacts'], array( '%%fact%%' => esc_html( $fact ) ) );
		}
		$tokens['keyfacts'] = $facts_html;
	}

	if ( isset( $loops['related'] ) ) {
		$tokens['related'] = wx_post_related( $post, $loops['related'] );
	}

	return $tokens;
}

/**
 * Resolve <!--wx:if name--> blocks against a bare-name token map.
 *
 * Must run PER ITEM inside loops too — an if-block living inside an each-fragment
 * is never seen by the outer pass, so without this the comment leaks into the
 * output and e.g. an empty thumbnail renders a broken <img>.
 *
 * @param string $html   Markup.
 * @param array  $tokens Bare-name token map.
 * @return string
 */
function wx_post_apply_ifs( $html, $tokens ) {
	return (string) preg_replace_callback(
		'/<!--\s*wx:if\s+([a-z0-9_]+)\s*-->(.*?)<!--\s*\/wx:if\s*-->/s',
		static function ( $m ) use ( $tokens ) {
			$val = isset( $tokens[ $m[1] ] ) ? trim( wp_strip_all_tags( (string) $tokens[ $m[1] ] ) ) : '';
			return '' === $val ? '' : $m[2];
		},
		$html
	);
}

/**
 * Compile the template.
 *
 * Order matters:
 *   1. pull out <!--wx:each name--> fragments (outer keeps a %%name%% token)
 *   2. resolve <!--wx:if name--> blocks — removed entirely when the token is empty
 *   3. single-pass strtr of every remaining %%token%%
 *
 * @param string $html   Template source.
 * @param array  $tokens Token map (bare names).
 * @return string
 */
function wx_post_compile( $html, $tokens ) {
	$loops = array();

	// 1. each-fragments.
	$html = (string) preg_replace_callback(
		'/<!--\s*wx:each\s+([a-z0-9_]+)\s*-->(.*?)<!--\s*\/wx:each\s*-->/s',
		static function ( $m ) use ( &$loops ) {
			$loops[ $m[1] ] = $m[2];
			return '%%' . $m[1] . '%%';
		},
		$html
	);

	// 2. if-blocks.
	$html = wx_post_apply_ifs( $html, $tokens );

	// 3. tokens.
	$map = array();
	foreach ( $tokens as $name => $value ) {
		$map[ '%%' . $name . '%%' ] = (string) $value;
	}

	return strtr( $html, $map );
}

/**
 * Render a post through the template. Used by the shortcode and by single.php.
 *
 * @param array $atts Shortcode-style attributes.
 * @return string
 */
function wx_post_render( $atts = array() ) {
	static $rendering = false;

	// Guards against a post body that itself contains [wx_post].
	if ( $rendering ) {
		return '';
	}

	$atts = shortcode_atts(
		array(
			'id'      => 0,
			'layout'  => '',
			'eyebrow' => '',
			'deck'    => '',
			'hero'    => 0,
		),
		(array) $atts,
		'wx_post'
	);

	$post = wx_post_resolve( $atts['id'] ? $atts['id'] : get_the_ID() );
	if ( ! $post ) {
		return '';
	}

	// Layout: attribute > meta > default.
	$layout = $atts['layout'];
	if ( '' === $layout ) {
		$layout = (string) get_post_meta( $post->ID, '_wx_layout', true );
	}
	if ( '' === $layout ) {
		$layout = 'press';
	}
	$layout           = (string) apply_filters( 'wx_post_layout', $layout, $post );
	$atts['layout']   = $layout;

	// Raw = body only, for legacy posts that already carry their own hero.
	if ( 'raw' === $layout ) {
		return apply_filters( 'the_content', $post->post_content );
	}

	$html = wx_post_load_template();
	if ( '' === $html ) {
		return '';
	}

	$rendering = true;

	// Pull the loop fragments out first, then build the token map ONCE — calling
	// wx_post_tokens twice would run the_content filters twice.
	$loops = array();
	preg_match_all( '/<!--\s*wx:each\s+([a-z0-9_]+)\s*-->(.*?)<!--\s*\/wx:each\s*-->/s', $html, $m, PREG_SET_ORDER );
	foreach ( $m as $match ) {
		$loops[ $match[1] ] = $match[2];
	}

	$tokens = wx_post_tokens( $post, $atts, $loops );
	$out    = wx_post_compile( $html, $tokens );

	$rendering = false;

	return $out;
}
add_shortcode( 'wx_post', 'wx_post_render' );
