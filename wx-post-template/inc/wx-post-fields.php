<?php
/**
 * Wawasanex post template — custom fields.
 *
 * Every field is OPTIONAL. Empty falls back to WordPress-native data, so a post
 * with nothing filled still renders a complete page.
 *
 * @package wx-post-template
 */

defined( 'ABSPATH' ) || exit;

/**
 * Field map: meta key => [ type, label, hint ].
 *
 * @return array<string,array>
 */
function wx_post_fields() {
	return array(
		'_wx_eyebrow'              => array( 'text', 'Eyebrow / kicker', 'Small label above the title. Falls back to the primary category.' ),
		'_wx_deck'                 => array( 'textarea', 'Deck / standfirst', 'One or two sentences under the title. Falls back to the excerpt.' ),
		'_wx_author_role'          => array( 'text', 'Author role', 'e.g. Chief Economist. Falls back to the author name alone.' ),
		'_wx_hero'                 => array( 'attachment', 'Hero image', 'Replaces the featured image for the hero only.' ),
		'_wx_pullquote'            => array( 'textarea', 'Pull-quote', 'Pulled out of the body. Hidden when empty.' ),
		'_wx_keyfacts'             => array( 'textarea', 'Key facts', 'One per line. Hidden when empty.' ),
		'_wx_boilerplate_override' => array( 'textarea', 'Boilerplate override', 'Replaces the site-wide About block for this post only.' ),
		'_wx_layout'               => array( 'select', 'Layout', 'press = full frame. raw = output the body only (for legacy posts that already have their own hero).' ),
	);
}

/**
 * Layout choices for the select.
 *
 * @return array<string,string>
 */
function wx_post_layouts() {
	return array(
		'press'        => 'Press release (full frame)',
		'announcement' => 'Announcement',
		'op-ed'        => 'Op-ed',
		'raw'          => 'Raw — body only, no frame',
	);
}

/**
 * Register post meta so the values are editor-visible AND REST-readable.
 */
function wx_post_register_meta() {
	$auth = static function () {
		return current_user_can( 'edit_posts' );
	};

	foreach ( wx_post_fields() as $key => $cfg ) {
		register_post_meta(
			'post',
			$key,
			array(
				'type'              => '_wx_hero' === $key ? 'integer' : 'string',
				'single'            => true,
				'show_in_rest'      => true,
				'sanitize_callback' => 'wx_post_sanitize_' . ( 'attachment' === $cfg[0] ? 'int' : 'text' ),
				'auth_callback'     => $auth,
			)
		);
	}
}
add_action( 'init', 'wx_post_register_meta' );

/**
 * Sanitize a text field.
 *
 * @param mixed $value Raw value.
 * @return string
 */
function wx_post_sanitize_text( $value ) {
	return wp_kses_post( wp_unslash( (string) $value ) );
}

/**
 * Sanitize an attachment id.
 *
 * @param mixed $value Raw value.
 * @return int
 */
function wx_post_sanitize_int( $value ) {
	return absint( $value );
}

/**
 * Add the editor meta box.
 */
function wx_post_add_meta_box() {
	add_meta_box( 'wx-post-fields', 'Post template (Wawasanex)', 'wx_post_render_meta_box', 'post', 'normal', 'high' );
}
add_action( 'add_meta_boxes', 'wx_post_add_meta_box' );

/**
 * Render the meta box.
 *
 * @param WP_Post $post Post being edited.
 */
function wx_post_render_meta_box( $post ) {
	wp_nonce_field( 'wx_post_fields_save', 'wx_post_fields_nonce' );
	echo '<style>.wx-f{display:block;margin:0 0 14px}.wx-f label{display:block;font-weight:600;margin-bottom:4px}.wx-f .hint{color:#666;font-weight:400}.wx-f input[type=text],.wx-f textarea,.wx-f select{width:100%}</style>';

	foreach ( wx_post_fields() as $key => $cfg ) {
		list( $type, $label, $hint ) = $cfg;
		$value                       = get_post_meta( $post->ID, $key, true );
		$id                          = esc_attr( $key );
		echo '<div class="wx-f">';
		printf( '<label for="%1$s">%2$s <span class="hint">— %3$s</span></label>', $id, esc_html( $label ), esc_html( $hint ) );

		if ( 'textarea' === $type ) {
			printf( '<textarea id="%1$s" name="%1$s" rows="3">%2$s</textarea>', $id, esc_textarea( (string) $value ) );
		} elseif ( 'select' === $type ) {
			printf( '<select id="%1$s" name="%1$s">', $id );
			foreach ( wx_post_layouts() as $opt => $opt_label ) {
				printf( '<option value="%1$s"%2$s>%3$s</option>', esc_attr( $opt ), selected( $value, $opt, false ), esc_html( $opt_label ) );
			}
			echo '</select>';
		} elseif ( 'attachment' === $type ) {
			$preview = $value ? wp_get_attachment_image( absint( $value ), 'medium', false, array( 'style' => 'max-width:220px;height:auto;display:block;margin:6px 0' ) ) : '';
			printf( '<input type="number" id="%1$s" name="%1$s" value="%2$s" placeholder="Attachment ID" min="0" step="1">', $id, esc_attr( (string) $value ) );
			echo $preview; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- core-generated markup.
			echo '<span class="hint">Copy the ID from Media Library. Leave 0 or empty to use the featured image.</span>';
		} else {
			printf( '<input type="text" id="%1$s" name="%1$s" value="%2$s">', $id, esc_attr( (string) $value ) );
		}

		echo '</div>';
	}

	echo '<p class="hint">All fields are optional. Empty fields fall back to the featured image, excerpt, category, author and date.</p>';
}

/**
 * Save the meta box.
 *
 * @param int $post_id Post id.
 */
function wx_post_save_fields( $post_id ) {
	if ( ! isset( $_POST['wx_post_fields_nonce'] ) ) {
		return;
	}
	$nonce = sanitize_text_field( wp_unslash( $_POST['wx_post_fields_nonce'] ) );
	if ( ! wp_verify_nonce( $nonce, 'wx_post_fields_save' ) ) {
		return;
	}
	if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
		return;
	}
	if ( ! current_user_can( 'edit_post', $post_id ) ) {
		return;
	}

	foreach ( wx_post_fields() as $key => $cfg ) {
		if ( ! isset( $_POST[ $key ] ) ) {
			continue;
		}
		$raw = wp_unslash( $_POST[ $key ] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- sanitized below.
		if ( 'attachment' === $cfg[0] ) {
			$clean = absint( $raw );
		} elseif ( 'select' === $cfg[0] ) {
			$clean = array_key_exists( $raw, wx_post_layouts() ) ? $raw : '';
		} else {
			$clean = wp_kses_post( (string) $raw );
		}

		if ( '' === $clean ) {
			delete_post_meta( $post_id, $key );
		} else {
			update_post_meta( $post_id, $key, $clean );
		}
	}
}
add_action( 'save_post_post', 'wx_post_save_fields' );

/**
 * Site-wide boilerplate: Settings → Wawasanex post template.
 */
function wx_post_register_settings() {
	register_setting(
		'wx_post_settings',
		'wx_post_boilerplate',
		array(
			'type'              => 'string',
			'sanitize_callback' => 'wp_kses_post',
			'default'           => '',
		)
	);
}
add_action( 'admin_init', 'wx_post_register_settings' );

/**
 * Settings page.
 */
function wx_post_settings_page() {
	add_options_page( 'Wawasanex post template', 'Wawasanex post template', 'manage_options', 'wx-post-template', 'wx_post_settings_render' );
}
add_action( 'admin_menu', 'wx_post_settings_page' );

/**
 * Settings page render.
 */
function wx_post_settings_render() {
	?>
	<div class="wrap">
		<h1>Wawasanex post template</h1>
		<form method="post" action="options.php">
			<?php settings_fields( 'wx_post_settings' ); ?>
			<p><label for="wx_post_boilerplate"><strong>About / boilerplate block</strong></label></p>
			<p class="description">Shown at the foot of every post. Override it per post with the "Boilerplate override" field.</p>
			<textarea id="wx_post_boilerplate" name="wx_post_boilerplate" rows="6" style="width:100%;max-width:720px"><?php echo esc_textarea( (string) get_option( 'wx_post_boilerplate', '' ) ); ?></textarea>
			<?php submit_button(); ?>
		</form>
	</div>
	<?php
}
