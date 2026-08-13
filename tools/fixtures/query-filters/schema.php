<?php
/**
 * query-filters blueprint — schema (code, not data).
 *
 * Registers the two custom fields the v3 assertions filter on: a Meta Box field
 * and an ACF field, both defined once in manifest.php.
 *
 * WHY THIS FILE EXISTS AT ALL. Seeding a post meta VALUE is a one-off write, so
 * seed.php can do it. Registering a field DEFINITION is not: both integrations
 * read their registries on every request, and GBQF reads them at render time —
 * `Blocks::render_query_filter_block()` calls `rwmb_get_registry('field')` and
 * `acf_get_field()` to discover a field's label and options. A field registered
 * only during a wp-cli run does not exist on the HTTP request that renders the
 * page, so the control renders as a bare text input with no options, or not at
 * all, and the ownership assertions measure the wrong thing while still going
 * green. Hence the mu-plugin stub seed.php installs, which loads this file on
 * every request and survives snapshot restores.
 *
 * Function-based, like the sibling blueprints, so seed.php can call the
 * registration directly after `init` has already fired in a CLI run.
 *
 * NOT the plugin's job and deliberately not mocked: these are real Meta Box and
 * real ACF registrations. A fake registry would test the fixture's idea of those
 * plugins rather than GBQF's integration with them, which is the entire point of
 * the two integration branches under test.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The one manifest read. Kept in a function so the file can be included more
 * than once (mu-plugin stub + a direct require from seed.php) without
 * re-reading or redeclaring anything.
 *
 * @return array The `fields` section of the manifest.
 */
function gbqf_fixture_query_filters_fields() {
	static $fields = null;

	if ( null === $fields ) {
		$manifest = require __DIR__ . '/manifest.php';
		$fields   = $manifest['fields'];
	}

	return $fields;
}

/**
 * Meta Box: register the field group.
 *
 * `post_types` is `post` because the fixture loops query posts. The group is
 * `context => 'normal'` and otherwise unremarkable — nothing here is under
 * test except that the field EXISTS in the registry with options, which is what
 * GBQF reads to render a <select> rather than falling back to a text input.
 *
 * @param array $meta_boxes Existing Meta Box definitions.
 * @return array
 */
function gbqf_fixture_query_filters_mb_boxes( $meta_boxes ) {
	$mb = gbqf_fixture_query_filters_fields()['mb'];

	$meta_boxes[] = array(
		'id'         => 'gbqf_fixture_box',
		'title'      => 'GBQF Fixture Fields',
		'post_types' => array( 'post' ),
		'context'    => 'normal',
		'fields'     => array(
			array(
				'id'      => $mb['id'],
				'name'    => $mb['name'],
				'type'    => $mb['type'],
				'options' => $mb['options'],
			),
		),
	);

	return $meta_boxes;
}

/**
 * ACF: register the field group as a LOCAL group.
 *
 * Local rather than a stored `acf-field-group` post: local groups are code, so
 * they cannot drift from the manifest, they need no import step, and they
 * survive a database snapshot restore along with this file. `acf_add_local_field_group()`
 * upserts on `key`, which is why the manifest hardcodes one.
 *
 * `location` is required by ACF even though nothing here edits a post in
 * wp-admin — without it the group is registered but attached to nothing, and
 * `acf_get_field()` still resolves the field, so this is belt and braces.
 */
function gbqf_fixture_query_filters_acf_group() {
	if ( ! function_exists( 'acf_add_local_field_group' ) ) {
		return;
	}

	$acf = gbqf_fixture_query_filters_fields()['acf'];

	acf_add_local_field_group( array(
		'key'      => 'group_gbqf_fixture',
		'title'    => 'GBQF Fixture Fields',
		'fields'   => array(
			array(
				'key'     => $acf['key'],
				'name'    => $acf['name'],
				'label'   => $acf['label'],
				'type'    => $acf['type'],
				'choices' => $acf['choices'],
			),
		),
		'location' => array(
			array(
				array(
					'param'    => 'post_type',
					'operator' => '==',
					'value'    => 'post',
				),
			),
		),
	) );
}

/**
 * Register both.
 *
 * Meta Box hooks `rwmb_meta_boxes` (a filter, so it must be added before Meta
 * Box builds its registry — mu-plugin load time is early enough). ACF is
 * registered on `acf/init`, the point ACF documents as safe for local field
 * groups.
 *
 * Guarded on each plugin being present rather than asserted here: this file is
 * loaded on every request including ones where the assertion would be noise.
 * seed.php does the asserting, once, where a missing plugin is actionable.
 */
function gbqf_fixture_query_filters_register() {
	add_filter( 'rwmb_meta_boxes', 'gbqf_fixture_query_filters_mb_boxes' );

	// Hooked UNCONDITIONALLY, and this is the whole subtlety of the file.
	//
	// The mu-plugin stub loads this before any regular plugin, so ACF is not in
	// memory yet and `function_exists( 'acf_add_local_field_group' )` is false
	// here on every web request. Guarding the hook on it therefore skipped
	// registration entirely and the ACF control rendered with no choices, while
	// the Meta Box half — a filter, evaluated later — worked fine. The guard
	// belongs inside the callback, which runs late, not around the hook.
	//
	// `acf/init` simply never fires when ACF is absent, so the hook is free.
	add_action( 'acf/init', 'gbqf_fixture_query_filters_acf_group' );

	// Already past `acf/init` (seed.php requiring this file mid-CLI-run, where
	// ACF loaded long ago). Adding the hook above was a no-op in that case, so
	// register directly. Not both: an action added after it fired never runs.
	if ( did_action( 'acf/init' ) ) {
		gbqf_fixture_query_filters_acf_group();
	}
}

gbqf_fixture_query_filters_register();
