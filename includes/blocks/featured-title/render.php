<?php
/**
 * Featured Title front end: the post's title over its featured image.
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

$mst_level = min( 6, max( 1, absint( $attributes['level'] ?? 2 ) ) );
$mst_title = trim( get_the_title( $mst_post ) );

MST_Blocks::render_cover( $mst_post, $attributes, 'h' . $mst_level, wp_kses_post( $mst_title ) );
