<?php
/**
 * Featured Excerpt front end: the post's excerpt over its featured image.
 *
 * @var array    $attributes Block attributes.
 * @var string   $content    Inner content (unused; the block has none).
 * @var WP_Block $block      Block instance, for the post ID context.
 */

defined( 'ABSPATH' ) || exit;

$mst_post = get_post( $block->context['postId'] ?? get_the_ID() );

if ( ! $mst_post ) {
	return;
}

$mst_excerpt = post_password_required( $mst_post ) ? '' : trim( get_the_excerpt( $mst_post ) );

MST_Blocks::render_cover( $mst_post, $attributes, 'p', wp_kses_post( $mst_excerpt ) );
