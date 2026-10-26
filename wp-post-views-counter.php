<?php
/**
 * Plugin Name:       Post Views Counter
 * Description:       Lightweight, bot-aware post view counter with a sortable Views column and a [popular_posts] shortcode. No external services, no cookies.
 * Version:           1.0.0
 * Author:            Azan Umer
 * License:           MIT
 * Text Domain:       wpvc
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'WPVC_OPTION', 'wpvc_settings' );
define( 'WPVC_META', '_wpvc_views' );

/** Default settings. Filterable via `wpvc_default_settings`. */
function wpvc_defaults() {
    return apply_filters( 'wpvc_default_settings', array(
        'count_pages'       => 0, // Count pages as well as posts (and CPTs).
        'exclude_logged_in' => 1, // Do not count logged-in visitors (avoids admin/author inflation).
        'exclude_bots'      => 1,
    ) );
}

function wpvc_settings() {
    return wp_parse_args( get_option( WPVC_OPTION, array() ), wpvc_defaults() );
}

/** Very small bot detector: matches common crawler/bot user-agent tokens. */
function wpvc_is_bot() {
    $ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? strtolower( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) ) : '';
    if ( '' === $ua ) {
        return true; // No UA at all is almost never a real reader.
    }
    foreach ( array( 'bot', 'crawl', 'spider', 'slurp', 'facebookexternal', 'preview', 'headless', 'python-requests', 'curl', 'wget' ) as $token ) {
        if ( false !== strpos( $ua, $token ) ) {
            return true;
        }
    }
    return false;
}

/**
 * Count one view for the current singular post, once per request.
 * Runs on template_redirect so feeds, archives and previews are never counted.
 */
function wpvc_count_view() {
    if ( ! is_singular() || is_preview() || is_admin() ) {
        return;
    }
    $post_id = get_queried_object_id();
    $post    = get_post( $post_id );
    if ( ! $post || 'publish' !== $post->post_status ) {
        return;
    }
    $settings = wpvc_settings();
    if ( 'post' !== $post->post_type && empty( $settings['count_pages'] ) ) {
        return;
    }
    if ( ! empty( $settings['exclude_logged_in'] ) && is_user_logged_in() ) {
        return;
    }
    if ( ! empty( $settings['exclude_bots'] ) && wpvc_is_bot() ) {
        return;
    }
    // Atomic-ish increment: a single UPDATE avoids read-modify-write races.
    global $wpdb;
    $updated = $wpdb->query( $wpdb->prepare(
        "UPDATE {$wpdb->postmeta} SET meta_value = CAST(meta_value AS UNSIGNED) + 1 WHERE post_id = %d AND meta_key = %s",
        $post_id, WPVC_META
    ) );
    if ( ! $updated ) {
        // No row yet (or a race deleted it): insert only if still absent.
        $exists = $wpdb->get_var( $wpdb->prepare(
            "SELECT meta_id FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s LIMIT 1",
            $post_id, WPVC_META
        ) );
        if ( ! $exists ) {
            add_post_meta( $post_id, WPVC_META, 1, true );
        }
    }
}
add_action( 'template_redirect', 'wpvc_count_view' );

/** Public helper: get the view count for a post. */
function wpvc_get_views( $post_id ) {
    return (int) get_post_meta( $post_id, WPVC_META, true );
}

/** Sortable Views column on the post/page list screens. */
function wpvc_add_views_column( $columns ) {
    $columns['wpvc_views'] = __( 'Views', 'wpvc' );
    return $columns;
}
add_filter( 'manage_posts_columns', 'wpvc_add_views_column' );
add_filter( 'manage_pages_columns', 'wpvc_add_views_column' );

function wpvc_render_views_column( $column, $post_id ) {
    if ( 'wpvc_views' === $column ) {
        echo esc_html( number_format_i18n( wpvc_get_views( $post_id ) ) );
    }
}
add_action( 'manage_posts_custom_column', 'wpvc_render_views_column', 10, 2 );
add_action( 'manage_pages_custom_column', 'wpvc_render_views_column', 10, 2 );

function wpvc_sortable_views_column( $columns ) {
    $columns['wpvc_views'] = 'wpvc_views';
    return $columns;
}
add_filter( 'manage_edit-post_sortable_columns', 'wpvc_sortable_views_column' );

function wpvc_orderby_views( $query ) {
    if ( ! is_admin() || ! $query->is_main_query() || 'wpvc_views' !== $query->get( 'orderby' ) ) {
        return;
    }
    $query->set( 'meta_key', WPVC_META );
    $query->set( 'orderby', 'meta_value_num' );
}
add_action( 'pre_get_posts', 'wpvc_orderby_views' );

/**
 * [popular_posts count="5" post_type="post" show_views="1"]
 * Lists the most-viewed published posts, newest first on ties.
 */
function wpvc_popular_posts_shortcode( $atts ) {
    $atts = shortcode_atts( array(
        'count'      => 5,
        'post_type'  => 'post',
        'show_views' => 1,
    ), $atts, 'popular_posts' );
    $posts = get_posts( array(
        'post_type'      => sanitize_key( $atts['post_type'] ),
        'posts_per_page' => max( 1, min( 20, (int) $atts['count'] ) ),
        'post_status'    => 'publish',
        'meta_key'       => WPVC_META,
        'orderby'        => array( 'meta_value_num' => 'DESC', 'date' => 'DESC' ),
        'no_found_rows'  => true,
    ) );
    if ( empty( $posts ) ) {
        return '';
    }
    $out = '<ul class="wpvc-popular-posts">';
    foreach ( $posts as $post ) {
        $views = '';
        if ( ! empty( $atts['show_views'] ) ) {
            $views = ' <span class="wpvc-views">(' . esc_html( number_format_i18n( wpvc_get_views( $post->ID ) ) ) . ' ' . esc_html__( 'views', 'wpvc' ) . ')</span>';
        }
        $out .= '<li><a href="' . esc_url( get_permalink( $post ) ) . '">' . esc_html( get_the_title( $post ) ) . '</a>' . $views . '</li>';
    }
    return $out . '</ul>';
}
add_shortcode( 'popular_posts', 'wpvc_popular_posts_shortcode' );

/** Settings page under Settings -> Post Views. */
function wpvc_register_settings() {
    register_setting( 'wpvc_settings_group', WPVC_OPTION, array(
        'sanitize_callback' => function ( $input ) {
            return array(
                'count_pages'       => ! empty( $input['count_pages'] ) ? 1 : 0,
                'exclude_logged_in' => ! empty( $input['exclude_logged_in'] ) ? 1 : 0,
                'exclude_bots'      => ! empty( $input['exclude_bots'] ) ? 1 : 0,
            );
        },
    ) );
    add_options_page( __( 'Post Views', 'wpvc' ), __( 'Post Views', 'wpvc' ), 'manage_options', 'wpvc-settings', 'wpvc_settings_page' );
}
add_action( 'admin_menu', 'wpvc_register_settings' );

function wpvc_settings_page() {
    $s = wpvc_settings();
    ?>
    <div class="wrap"><h1><?php esc_html_e( 'Post Views Counter', 'wpvc' ); ?></h1>
    <form method="post" action="options.php">
        <?php settings_fields( 'wpvc_settings_group' ); ?>
        <table class="form-table">
            <tr><th scope="row"><?php esc_html_e( 'Count pages', 'wpvc' ); ?></th>
                <td><input type="checkbox" name="<?php echo esc_attr( WPVC_OPTION ); ?>[count_pages]" value="1" <?php checked( $s['count_pages'] ); ?> /> <?php esc_html_e( 'Also count pages and custom post types, not just posts.', 'wpvc' ); ?></td></tr>
            <tr><th scope="row"><?php esc_html_e( 'Exclude logged-in users', 'wpvc' ); ?></th>
                <td><input type="checkbox" name="<?php echo esc_attr( WPVC_OPTION ); ?>[exclude_logged_in]" value="1" <?php checked( $s['exclude_logged_in'] ); ?> /></td></tr>
            <tr><th scope="row"><?php esc_html_e( 'Exclude bots', 'wpvc' ); ?></th>
                <td><input type="checkbox" name="<?php echo esc_attr( WPVC_OPTION ); ?>[exclude_bots]" value="1" <?php checked( $s['exclude_bots'] ); ?> /></td></tr>
        </table>
        <?php submit_button(); ?>
    </form>
    <p><?php esc_html_e( 'Shortcode:', 'wpvc' ); ?> <code>[popular_posts count="5"]</code></p>
    </div>
    <?php
}
