<?php
/**
 * query-filters blueprint — fixture verifier.
 *
 * Asserts the FIXTURES are real. It does not, and cannot, assert the targeting
 * behaviour: `Filters::register_target()` runs when a filter block renders and
 * the targeting decision runs inside `generateblocks_query_wp_query_args`
 * during a real request. Under wp-cli neither happens, so all four loops look
 * identical here no matter what the plugin does. That is render-surface.sh's
 * job.
 *
 * Pattern 2 in seed-all.sh's VERIFY_SLUG note: query-independent, so no --url
 * entry is needed. Every read below is by slug against the DB.
 *
 * Run:
 *   bin/wp.sh <site> eval-file /plugins/gb-query-filter/tools/fixtures/query-filters/verify.php
 */

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	echo "Run via wp-cli eval-file.\n";
	exit( 1 );
}

$manifest = require __DIR__ . '/manifest.php';

$pass = 0;
$fail = 0;
$ok   = function ( $msg ) use ( &$pass ) {
	WP_CLI::log( '  PASS ' . $msg );
	$pass++;
};
$bad  = function ( $msg ) use ( &$fail ) {
	WP_CLI::log( '  FAIL ' . $msg );
	$fail++;
};

// --- category ---------------------------------------------------------------
$term = get_term_by( 'slug', $manifest['category']['slug'], 'category' );
$term ? $ok( "category {$manifest['category']['slug']} exists" )
      : $bad( "category {$manifest['category']['slug']} missing" );

// --- posts ------------------------------------------------------------------
foreach ( $manifest['posts'] as $spec ) {
	$ids = get_posts( array(
		'post_type'      => 'post',
		'name'           => $spec['slug'],
		'post_status'    => 'publish',
		'posts_per_page' => 1,
		'fields'         => 'ids',
	) );
	if ( ! $ids ) {
		$bad( "post {$spec['slug']} missing or unpublished" );
		continue;
	}
	$title = get_the_title( $ids[0] );
	$title === $spec['title']
		? $ok( "post {$spec['slug']} published as '{$title}'" )
		: $bad( "post {$spec['slug']} title is '{$title}', expected '{$spec['title']}'" );
}

// --- page + loop markup -----------------------------------------------------
//
// Checking each loop id and marker is present in the SOURCE guards the failure
// mode that would quietly hollow out the render harness: a page that seeded but
// lost a loop. The harness counts marker strings in a response, and a marker
// that was never authored counts zero — which reads there as "this loop was
// filtered to nothing", the most interesting possible result, arrived at by
// accident.
$pages = get_posts( array(
	'post_type'      => 'page',
	'name'           => $manifest['page']['slug'],
	'post_status'    => 'publish',
	'posts_per_page' => 1,
) );

if ( ! $pages ) {
	$bad( "page {$manifest['page']['slug']} missing or unpublished" );
} else {
	$ok( "page {$manifest['page']['slug']} published (#{$pages[0]->ID})" );
	$content = $pages[0]->post_content;

	foreach ( $manifest['loops'] as $name => $loop ) {
		false !== strpos( $content, 'id="' . $loop['id'] . '"' )
			? $ok( "loop '{$name}' wrapper carries id {$loop['id']}" )
			: $bad( "loop '{$name}' wrapper id {$loop['id']} not in page source" );

		false !== strpos( $content, $loop['marker'] . '::' )
			? $ok( "loop '{$name}' emits marker {$loop['marker']}" )
			: $bad( "loop '{$name}' marker {$loop['marker']} not in page source — the harness would read this loop as filtered-to-empty" );
	}

	// The legacy loop's whole reason for existing is its class.
	$legacy = $manifest['loops']['legacy'];
	false !== strpos( $content, $legacy['class'] )
		? $ok( "legacy loop carries class {$legacy['class']}" )
		: $bad( "legacy loop class {$legacy['class']} missing — section 4 of the harness would be vacuous" );

	// Exactly three filter blocks: unscoped, scoped-by-id, scoped-by-class.
	$expected_filters = count( $manifest['filter_blocks'] );
	$filter_count     = substr_count( $content, 'wp:gbqf/query-filter' );
	$expected_filters === $filter_count
		? $ok( "page carries exactly {$expected_filters} filter blocks" )
		: $bad( "page carries {$filter_count} filter blocks, expected {$expected_filters}" );

	// v2: the class-match pair. The target name must appear as a CLASS on the
	// classmatch loop and must NOT be its id — if they were equal the loop
	// would match by id and section 5 of the harness would prove nothing.
	$cm = $manifest['loops']['classmatch'];
	false !== strpos( $content, '"targetId":"' . $cm['target'] . '"' )
		? $ok( "class-match filter block targets {$cm['target']}" )
		: $bad( "no filter block targets {$cm['target']}" );

	$cm['target'] !== $cm['id']
		? $ok( "classmatch loop id ({$cm['id']}) differs from its target name ({$cm['target']}) — the gap is real" )
		: $bad( 'classmatch loop id equals its target name — section 5 would match by id and prove nothing' );

	// The target name must actually be a CLASS on that loop. Without this the
	// loop is claimed by nothing, section 5 reports "not filtered", and that
	// reads as the namespace-mismatch defect when the real cause is a fixture
	// that stopped emitting className — the exact vacuous pass this file exists
	// to prevent.
	false !== strpos( $content, $cm['class'] )
		? $ok( "classmatch loop carries class {$cm['class']}" )
		: $bad( "classmatch loop class {$cm['class']} missing — section 5 would fail for the wrong reason" );

	false !== strpos( $content, '"targetId":"' . $manifest['loops']['scoped']['id'] . '"' )
		? $ok( 'scoped filter block targets ' . $manifest['loops']['scoped']['id'] )
		: $bad( 'scoped filter block does not target ' . $manifest['loops']['scoped']['id'] );

	false !== strpos( $content, '"targetId":""' )
		? $ok( 'unscoped filter block present (targetId "")' )
		: $bad( 'unscoped filter block missing' );
}

// --- v3: custom fields ------------------------------------------------------
//
// Three separate things can be missing, and they fail the HTTP harness in ways
// that look nothing like each other:
//
//   values not seeded   -> field filters return 0 rows, not 2
//   field not REGISTERED-> control renders without options; the filter still
//                          works, so only section 6 notices
//   ownership not on the-> the owned-field sections report 4 (skipped as
//   block attributes       unowned) while section 9 passes for the wrong reason
//
// The third is the nastiest: section 9 asserts a field does NOT filter, so a
// fixture that declared no ownership at all makes it pass trivially. Assert the
// ownership is present here, where it is a fixture fact and cheap to read.
$fields = $manifest['fields'];

foreach ( $manifest['posts'] as $spec ) {
	$ids = get_posts( array(
		'post_type'      => 'post',
		'name'           => $spec['slug'],
		'post_status'    => 'publish',
		'posts_per_page' => 1,
		'fields'         => 'ids',
	) );
	if ( ! $ids ) {
		continue; // already reported above
	}

	foreach ( $spec['meta'] as $meta_key => $expected ) {
		$actual = get_post_meta( (int) $ids[0], $meta_key, true );
		$actual === $expected
			? $ok( "post {$spec['slug']} has {$meta_key}={$expected}" )
			: $bad( "post {$spec['slug']} {$meta_key} is '{$actual}', expected '{$expected}'" );
	}
}

// Row counts, computed from the manifest rather than hardcoded — the harness
// asserts 2 rows for each field filter, and that number is only correct while
// the values split the posts evenly. A manifest edit that broke the split would
// otherwise surface as a mysterious render-surface.sh failure.
foreach ( array( 'gbqf_color' => 'red', 'gbqf_size' => 'large' ) as $split_key => $split_value ) {
	$matching = 0;
	foreach ( $manifest['posts'] as $spec ) {
		if ( isset( $spec['meta'][ $split_key ] ) && $spec['meta'][ $split_key ] === $split_value ) {
			$matching++;
		}
	}
	2 === $matching
		? $ok( "{$split_key}={$split_value} selects exactly 2 of 4 posts (the count sections 7-9 assert)" )
		: $bad( "{$split_key}={$split_value} selects {$matching} posts, but the harness asserts 2" );
}

// Registration, not just values. Both are read at RENDER time off the
// mu-plugin stub; if the stub is missing this passes under wp-cli (seed.php
// required schema.php directly) — so it is a necessary check, not a sufficient
// one. render-surface.sh section 6 is what proves the HTTP request saw them.
if ( function_exists( 'rwmb_get_registry' ) ) {
	$mb_field = rwmb_get_registry( 'field' )->get( $fields['mb']['id'], 'post' );
	! empty( $mb_field )
		? $ok( "Meta Box field {$fields['mb']['id']} is registered" )
		: $bad( "Meta Box field {$fields['mb']['id']} is NOT registered — its control renders without options" );
} else {
	$bad( 'Meta Box is not active — sections 7 and 9 would pass vacuously' );
}

if ( function_exists( 'acf_get_field' ) ) {
	$acf_field = acf_get_field( $fields['acf']['name'] );
	! empty( $acf_field )
		? $ok( "ACF field {$fields['acf']['name']} is registered" )
		: $bad( "ACF field {$fields['acf']['name']} is NOT registered — its control renders without choices" );
} else {
	$bad( 'ACF is not active — sections 8 and 9 would pass vacuously' );
}

// GBQF's own toggles. Either one off silently disables a whole integration
// branch, which makes every "unowned field did not filter" assertion pass.
\GBQF\Settings::is_metabox_enabled()
	? $ok( 'GBQF Meta Box integration enabled' )
	: $bad( 'GBQF Meta Box integration DISABLED — Meta Box field filters no-op, section 9 passes vacuously' );

\GBQF\Settings::is_acf_enabled()
	? $ok( 'GBQF ACF integration enabled' )
	: $bad( 'GBQF ACF integration DISABLED — ACF field filters no-op, section 9 passes vacuously' );

// Ownership, as authored into the page. Read from the page content for the same
// reason the targeting assertions above are: it is what the block will actually
// be rendered with.
$page_ids = get_posts( array(
	'post_type'      => 'page',
	'name'           => $manifest['page']['slug'],
	'post_status'    => 'publish',
	'posts_per_page' => 1,
	'fields'         => 'ids',
) );

if ( $page_ids ) {
	$page_content = get_post_field( 'post_content', $page_ids[0] );

	false !== strpos( $page_content, '"id":"' . $fields['mb']['id'] . '"' )
		? $ok( "a filter block declares ownership of {$fields['mb']['id']}" )
		: $bad( "no filter block declares {$fields['mb']['id']} — section 7 would report 4 rows and section 9 would pass trivially" );

	false !== strpos( $page_content, '"id":"' . $fields['acf']['name'] . '"' )
		? $ok( "a filter block declares ownership of {$fields['acf']['name']}" )
		: $bad( "no filter block declares {$fields['acf']['name']} — section 8 would report 4 rows and section 9 would pass trivially" );

	// The DISJOINTNESS is the test. If one block owned both fields, section 9
	// would be asserting that an OWNED field does not filter, and it would fail
	// — but a fixture that gave both fields to both blocks would instead make
	// section 9 unfalsifiable in the other direction.
	$mb_owner_count  = substr_count( $page_content, '"enableMetaBoxFilter":true' );
	$acf_owner_count = substr_count( $page_content, '"enableAcfFilter":true' );

	1 === $mb_owner_count && 1 === $acf_owner_count
		? $ok( 'exactly one block owns Meta Box fields and one owns ACF fields (ownership is disjoint)' )
		: $bad( "ownership is not disjoint ({$mb_owner_count} MB owners, {$acf_owner_count} ACF owners) — section 9 cannot distinguish owned from unowned" );
}

// --- environment ------------------------------------------------------------
class_exists( '\GBQF\Filters' )
	? $ok( 'gb-query-filter loaded' )
	: $bad( 'gb-query-filter NOT loaded — the harness would report every loop unfiltered' );

$scope = class_exists( '\GBQF\Settings' ) ? \GBQF\Settings::get_filter_scope() : '?';
'targeted' === $scope
	? $ok( "filter scope is 'targeted'" )
	: $bad( "filter scope is '{$scope}', expected 'targeted'" );

WP_CLI::log( '' );
if ( $fail > 0 ) {
	WP_CLI::error( "[query-filters] {$fail} failed, {$pass} passed" );
}
WP_CLI::success( "[query-filters] {$pass} passed" );
