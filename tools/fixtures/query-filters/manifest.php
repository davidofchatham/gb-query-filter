<?php
/**
 * query-filters blueprint — manifest (the data contract).
 *
 * Axis: WHICH GenerateBlocks Query Loop a filter block is allowed to touch.
 * That is the subject of [ADR-0001](../../../docs/adr/0001-targeted-scope-default.md)
 * and of security invariant 2 in CONTEXT.md ("no filtering an unconnected
 * loop"), and it is the one thing in this plugin that must not be wrong.
 *
 * The fixture is a single page carrying FOUR Query Loops over the same four
 * posts. They differ only in how (or whether) a filter block claims them, so a
 * difference in the rendered result set is attributable to the TARGETING RULE
 * and nothing else:
 *
 *   control    no filter block anywhere claims it        -> must never filter
 *   unscoped   a filter block with targetId ''           -> ?
 *   scoped     a filter block with targetId = its id     -> must filter
 *   legacy     class gbqf-target-*, no filter block      -> ?
 *
 * The two marked `?` are the point. `Filters::should_apply_to_attributes()` and
 * `Filters::get_matched_target()` walk the same match chain independently and
 * do not agree on either case; this blueprint exists to establish what actually
 * happens rather than reasoning about it from the source.
 *
 * Manifest owns DATA. Assertions live in render-surface.sh, because every
 * question here is about a RENDERED result set: the targeting decision happens
 * inside `generateblocks_query_wp_query_args` during a real request, and
 * `Filters::register_target()` only runs when a filter block renders. Under
 * wp-cli neither fires, so all four loops are indistinguishable there.
 *
 * REQUIRES GenerateBlocks 2.0+ (the `generateblocks/query` block and the
 * `generateblocks_query_wp_query_args` filter). seed.php asserts it.
 */

return array(
	'version' => 1,

	/*
	 * A dedicated category, so the loops can select exactly these four posts
	 * and nothing a sibling blueprint happens to have seeded.
	 */
	'category' => array(
		'slug' => 'gbqf-fixture',
		'name' => 'GBQF Fixture',
	),

	/*
	 * Four posts with unmistakable, single-word-distinct titles. Search is the
	 * filter under test because it is the one control every filter block
	 * renders by default (`enableSearch` defaults true), so the fixture needs
	 * no taxonomy or custom-field setup to exercise the targeting rule.
	 *
	 * 'Bravo' is the search term used throughout: it appears in exactly one
	 * title, and as a substring of no other.
	 */
	'posts' => array(
		array( 'slug' => 'gbqf-alpha',   'title' => 'GBQF Alpha' ),
		array( 'slug' => 'gbqf-bravo',   'title' => 'GBQF Bravo' ),
		array( 'slug' => 'gbqf-charlie', 'title' => 'GBQF Charlie' ),
		array( 'slug' => 'gbqf-delta',   'title' => 'GBQF Delta' ),
	),

	'page' => array(
		'slug'  => 'gbqf-targeting',
		'title' => 'GBQF targeting matrix',
	),

	/*
	 * Per-loop identity. `marker` is emitted into each loop item's text ahead
	 * of {{post_title}}, so the harness can attribute a rendered row to its
	 * loop with a plain string count and never has to parse HTML — four loops
	 * with identical item markup would otherwise be uncountable in a flat body.
	 *
	 * `id` is set BOTH as the block's htmlAttributes.id (what GBQF reads, via
	 * attributes) and on the saved wrapper element (what the browser sees).
	 * They must agree; a divergence between them is its own bug class and is
	 * not what this fixture is measuring.
	 */
	'loops' => array(
		'control' => array(
			'id'     => 'gbqf-loop-control',
			'marker' => 'GBQFX-CONTROL',
			'class'  => '',
			'expect' => 'never filtered — no filter block names it (ADR-0001, invariant 2)',
		),
		'unscoped' => array(
			'id'     => 'gbqf-loop-unscoped',
			'marker' => 'GBQFX-UNSCOPED',
			'class'  => '',
			'expect' => 'open question — an unscoped filter block registers target key \'\', which no non-empty block id can match',
		),
		'scoped' => array(
			'id'     => 'gbqf-loop-scoped',
			'marker' => 'GBQFX-SCOPED',
			'class'  => '',
			'expect' => 'filtered by its own scoped params only',
		),
		'legacy' => array(
			'id'     => 'gbqf-loop-legacy',
			'marker' => 'GBQFX-LEGACY',
			'class'  => 'gbqf-target-orphan',
			'expect' => 'open question — passes should_apply_to_attributes() on the legacy class prefix, but get_matched_target() has no entry for it',
		),
	),

	/*
	 * The filter blocks. Only two exist: one unscoped, one scoped. The control
	 * and legacy loops are deliberately UNCLAIMED — that is what makes them
	 * tests of invariant 2 rather than tests of the happy path.
	 *
	 * enableAjax is false throughout. The AJAX path re-derives targeting in JS
	 * (assets/js/gbqf-frontend.js), and this blueprint is measuring the PHP
	 * rule; leaving AJAX on would let a JS-side decision colour a PHP-side
	 * result.
	 */
	'filter_blocks' => array(
		array( 'target_id' => '' ),
		array( 'target_id' => 'gbqf-loop-scoped' ),
	),
);
