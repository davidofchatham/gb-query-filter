<?php
/**
 * query-filters blueprint — seed applier.
 *
 * Idempotent: reads manifest.php, upserts by slug (post_name / term slug).
 * Safe to re-run.
 *
 * Run (from the wp-litespeed env; path shown is the container mount):
 *   bin/wp.sh <site> eval-file /plugins/gb-query-filter/tools/fixtures/query-filters/seed.php
 * or, plugin-blind:
 *   bin/seed.sh <site> query-filters
 *
 * Composes on nothing. This blueprint defines its own category and posts rather
 * than borrowing core-structures' content, because its assertions count rows in
 * a result set — a shared post pool would make every count depend on what a
 * sibling blueprint last seeded.
 *
 * No schema.php and no mu-plugin stub: the only registrations involved are
 * WordPress core's `post` type and `category` taxonomy, which survive a
 * snapshot restore on their own.
 */

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	echo "Run via wp-cli eval-file.\n";
	exit( 1 );
}

$manifest = require __DIR__ . '/manifest.php';
$log      = function ( $msg ) {
	WP_CLI::log( '[query-filters] ' . $msg );
};

// ---------------------------------------------------------------------------
// 0. Environment preconditions.
//
// Hard errors, not warnings. Each of these turns the whole blueprint into
// fixtures that render but prove nothing, and the resulting harness run would
// be green — every "loop was not filtered" assertion passes when no filtering
// could have happened in the first place.
// ---------------------------------------------------------------------------
// class_exists, not is_plugin_active(): the latter lives in wp-admin/includes
// and is not loaded under `wp eval-file`. The class is the thing that matters
// anyway — it is what has to be in memory for any of this to run.
if ( ! class_exists( '\GBQF\Filters' ) ) {
	WP_CLI::error( 'gb-query-filter is not active. Activate it before seeding, or every targeting assertion passes vacuously.' );
}

if ( ! class_exists( 'GenerateBlocks_Block_Query' ) ) {
	WP_CLI::error(
		"GenerateBlocks 2.0+ not active (GenerateBlocks_Block_Query missing).\n"
		. "This blueprint's loops are `generateblocks/query` blocks and the targeting rule runs on\n"
		. '`generateblocks_query_wp_query_args`, which only GB 2.0+ fires.'
	);
}

if ( ! \WP_Block_Type_Registry::get_instance()->is_registered( 'gbqf/query-filter' ) ) {
	WP_CLI::error( 'Block gbqf/query-filter is not registered — the filter blocks would render as nothing.' );
}

// The scope this blueprint characterises. 'all' would make every loop filter,
// including the control, and the run would look like a catastrophic regression
// when it is only a setting.
$scope = \GBQF\Settings::get_filter_scope();
if ( 'targeted' !== $scope ) {
	WP_CLI::error( sprintf(
		"Filter scope is '%s', not 'targeted'. Every assertion here is about the targeted rule (ADR-0001);\n"
		. 'under \'all\' the control loop filters too and the results mean something else entirely.',
		$scope
	) );
}

// ---------------------------------------------------------------------------
// 1. Category.
// ---------------------------------------------------------------------------
$cat = $manifest['category'];
$term = get_term_by( 'slug', $cat['slug'], 'category' );
if ( ! $term ) {
	$created = wp_insert_term( $cat['name'], 'category', array( 'slug' => $cat['slug'] ) );
	if ( is_wp_error( $created ) ) {
		WP_CLI::error( 'category insert failed: ' . $created->get_error_message() );
	}
	$cat_id = (int) $created['term_id'];
	$log( "created category {$cat['slug']} (#{$cat_id})" );
} else {
	$cat_id = (int) $term->term_id;
	$log( "category {$cat['slug']} exists (#{$cat_id})" );
}

// ---------------------------------------------------------------------------
// 2. Posts.
// ---------------------------------------------------------------------------
foreach ( $manifest['posts'] as $spec ) {
	$existing = get_posts( array(
		'post_type'      => 'post',
		'name'           => $spec['slug'],
		'post_status'    => 'any',
		'posts_per_page' => 1,
		'fields'         => 'ids',
	) );

	$args = array(
		'post_type'    => 'post',
		'post_title'   => $spec['title'],
		'post_name'    => $spec['slug'],
		'post_status'  => 'publish',
		'post_content' => 'Fixture post for the gb-query-filter targeting matrix.',
	);

	if ( $existing ) {
		$args['ID'] = (int) $existing[0];
		$post_id    = wp_update_post( $args, true );
	} else {
		$post_id = wp_insert_post( $args, true );
	}

	if ( is_wp_error( $post_id ) ) {
		WP_CLI::error( "post {$spec['slug']} failed: " . $post_id->get_error_message() );
	}

	// Replace, not append — re-running must not accumulate categories.
	wp_set_post_terms( (int) $post_id, array( $cat_id ), 'category', false );
	$log( "post {$spec['slug']} -> #{$post_id}" );
}

// ---------------------------------------------------------------------------
// 3. Page markup.
//
// Built here rather than stored as a blob in the manifest so the four loops are
// provably identical apart from their id/class/marker — the whole design of the
// fixture rests on that, and a hand-maintained blob drifts.
// ---------------------------------------------------------------------------
$post_slugs = wp_list_pluck( $manifest['posts'], 'slug' );

/**
 * One Query Loop over exactly the fixture posts.
 *
 * post_name__in (not a tax_query) selects them: it is the selector the
 * GenerateBlocks fixtures on this stack already use successfully, and it
 * survives GB's own arg mapping. GBQF's search lands as `s` alongside it, and
 * WP_Query ANDs the two — which is precisely the merge behaviour under test.
 *
 * @param string   $id     HTML id, set on the block attribute AND the wrapper.
 * @param string   $class  Extra class for the wrapper (legacy-targeting case).
 * @param string   $marker Per-loop string emitted before the post title.
 * @param string[] $slugs  Fixture post slugs.
 * @param string   $uid    Block uniqueId (stable, so re-seeding does not churn).
 * @return string Block markup.
 */
$loop_markup = function ( $id, $class, $marker, $slugs, $uid ) {
	$query = array(
		'post_type'      => 'post',
		'post_name__in'  => array_values( $slugs ),
		'posts_per_page' => 20,
		'orderby'        => 'title',
		'order'          => 'ASC',
	);

	$query_attrs = array(
		'uniqueId'       => $uid,
		'tagName'        => 'div',
		'queryType'      => 'WP_Query',
		'query'          => $query,
		'htmlAttributes' => array( 'id' => $id ),
	);
	if ( '' !== $class ) {
		$query_attrs['className'] = $class;
	}

	$wrapper_class = '' !== $class ? ' class="' . esc_attr( $class ) . '"' : '';

	return sprintf(
		'<!-- wp:generateblocks/query %1$s -->' . "\n"
		. '<div id="%2$s"%3$s><!-- wp:generateblocks/looper {"uniqueId":"%4$slp","tagName":"ul"} -->' . "\n"
		. '<ul><!-- wp:generateblocks/loop-item {"uniqueId":"%4$sli","tagName":"li"} -->' . "\n"
		. '<li class="gb-loop-item"><!-- wp:generateblocks/text {"uniqueId":"%4$stx","tagName":"p"} -->' . "\n"
		. '<p class="gb-text">%5$s::{{post_title}}</p>' . "\n"
		. '<!-- /wp:generateblocks/text --></li>' . "\n"
		. '<!-- /wp:generateblocks/loop-item --></ul>' . "\n"
		. '<!-- /wp:generateblocks/looper --></div>' . "\n"
		. '<!-- /wp:generateblocks/query -->',
		wp_json_encode( $query_attrs ),
		esc_attr( $id ),
		$wrapper_class,
		$uid,
		$marker
	);
};

/**
 * One filter block. Dynamic (render_callback), so it is self-closing.
 *
 * Categories/tags controls are off: they would render term checkboxes for every
 * category on the site, which is noise in the response body the harness has to
 * count strings in. Search alone exercises the targeting rule.
 */
$filter_markup = function ( $target_id ) {
	return '<!-- wp:gbqf/query-filter ' . wp_json_encode( array(
		'targetId'         => $target_id,
		'enableSearch'     => true,
		'enableCategories' => false,
		'enableTags'       => false,
		'enableAjax'       => false,
	) ) . ' /-->';
};

$loops = $manifest['loops'];

$content = implode( "\n\n", array(
	'<!-- wp:heading --><h2 class="wp-block-heading">1. control — no filter block names this loop</h2><!-- /wp:heading -->',
	$loop_markup( $loops['control']['id'], $loops['control']['class'], $loops['control']['marker'], $post_slugs, 'gbqfctl0' ),

	'<!-- wp:heading --><h2 class="wp-block-heading">2. unscoped — filter block with targetId \'\'</h2><!-- /wp:heading -->',
	$filter_markup( $manifest['filter_blocks'][0]['target_id'] ),
	$loop_markup( $loops['unscoped']['id'], $loops['unscoped']['class'], $loops['unscoped']['marker'], $post_slugs, 'gbqfuns0' ),

	'<!-- wp:heading --><h2 class="wp-block-heading">3. scoped — filter block targeting this loop by id</h2><!-- /wp:heading -->',
	$filter_markup( $manifest['filter_blocks'][1]['target_id'] ),
	$loop_markup( $loops['scoped']['id'], $loops['scoped']['class'], $loops['scoped']['marker'], $post_slugs, 'gbqfscp0' ),

	'<!-- wp:heading --><h2 class="wp-block-heading">4. legacy — gbqf-target-* class, no filter block names it</h2><!-- /wp:heading -->',
	$loop_markup( $loops['legacy']['id'], $loops['legacy']['class'], $loops['legacy']['marker'], $post_slugs, 'gbqflgc0' ),
) );

$page_spec = $manifest['page'];
$existing  = get_posts( array(
	'post_type'      => 'page',
	'name'           => $page_spec['slug'],
	'post_status'    => 'any',
	'posts_per_page' => 1,
	'fields'         => 'ids',
) );

$page_args = array(
	'post_type'    => 'page',
	'post_title'   => $page_spec['title'],
	'post_name'    => $page_spec['slug'],
	'post_status'  => 'publish',
	'post_content' => $content,
);

if ( $existing ) {
	$page_args['ID'] = (int) $existing[0];
	$page_id         = wp_update_post( $page_args, true );
} else {
	$page_id = wp_insert_post( $page_args, true );
}

if ( is_wp_error( $page_id ) ) {
	WP_CLI::error( 'page insert failed: ' . $page_id->get_error_message() );
}

$log( "page {$page_spec['slug']} -> #{$page_id}" );
WP_CLI::success( '[query-filters] seeded (manifest v' . $manifest['version'] . ')' );
