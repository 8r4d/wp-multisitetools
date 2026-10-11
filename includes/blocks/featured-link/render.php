<?php
/**
 * Featured Link front end: linked text over a chosen image, with a
 * description below.
 *
 * @var array    $attributes Block attributes.
 * @var string   $content    The description: the inner blocks' HTML.
 * @var WP_Block $block      Block instance (unused).
 */

defined( 'ABSPATH' ) || exit;

$mst_image = (int) ( $attributes['imageId'] ?? 0 );
if ( $mst_image && ! wp_attachment_is_image( $mst_image ) ) {
	$mst_image = 0;
}

$mst_text = trim(
	wp_kses(
		(string) ( $attributes['text'] ?? '' ),
		array(
			'strong' => array(),
			'em'     => array(),
			'b'      => array(),
			'i'      => array(),
			'br'     => array(),
		)
	)
);

// An empty paragraph left from the template doesn't count.
$mst_description = '' === trim( wp_strip_all_tags( $content ) ) ? '' : $content;

if ( ! $mst_image && '' === $mst_text && '' === $mst_description ) {
	return;
}

$mst_url   = esc_url_raw( (string) ( $attributes['url'] ?? '' ) );
$mst_level = min( 6, max( 1, absint( $attributes['level'] ?? 2 ) ) );
$mst_style = MST_Blocks::support_styles( $attributes );
?>
<div <?php echo get_block_wrapper_attributes( array( 'class' => 'mst-promo' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
	<?php
	if ( $mst_image || '' !== $mst_text ) {
		MST_Blocks::cover(
			$mst_image,
			$attributes,
			'h' . $mst_level,
			$mst_text,
			array(
				'angle' => empty( $attributes['tilt'] ) ? 0 : max( -45, min( 45, (int) ( $attributes['angle'] ?? -5 ) ) ),
				'link'  => '' === $mst_url ? null : array(
					'href'    => $mst_url,
					'new_tab' => ! empty( $attributes['opensInNewTab'] ),
					'whole'   => ! empty( $attributes['isLink'] ),
				),
				'class' => $mst_style['class'],
				'style' => $mst_style['style'],
			)
		);
	}
	?>
	<?php if ( '' !== $mst_description ) : ?>
		<div class="mst-promo__description"><?php echo $mst_description; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- rendered inner blocks. ?></div>
	<?php endif; ?>
</div>
