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

	// Exactly two filter blocks, one unscoped and one scoped.
	$filter_count = substr_count( $content, 'wp:gbqf/query-filter' );
	2 === $filter_count
		? $ok( 'page carries exactly 2 filter blocks' )
		: $bad( "page carries {$filter_count} filter blocks, expected 2" );

	false !== strpos( $content, '"targetId":"' . $manifest['loops']['scoped']['id'] . '"' )
		? $ok( 'scoped filter block targets ' . $manifest['loops']['scoped']['id'] )
		: $bad( 'scoped filter block does not target ' . $manifest['loops']['scoped']['id'] );

	false !== strpos( $content, '"targetId":""' )
		? $ok( 'unscoped filter block present (targetId "")' )
		: $bad( 'unscoped filter block missing' );
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
