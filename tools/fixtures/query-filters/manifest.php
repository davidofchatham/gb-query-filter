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
	/*
	 * v2 adds the `classmatch` loop. Every v1 loop is claimed (or not) by HTML
	 * ID, which hides a whole half of the rule: `Targeting` matches by
	 * registered id OR by the registered targetId appearing as a class, and
	 * only the class half can make the matched TARGET KEY differ from the
	 * loop's own id. That difference is what decides which URL namespace
	 * Params reads, so v1 could not see it at all.
	 */
	/*
	 * v3 adds CUSTOM FIELD ownership. v1 and v2 only ever filtered on `search`,
	 * which every filter block renders unconditionally and which no block
	 * "owns" — so they could not see the second half of what a Target carries.
	 *
	 * A Target is a claimed loop PLUS the field lists that block owns
	 * (`mb_fields()`, `acf_fields()`), and `Filters::get_meta_filters()` /
	 * `get_acf_filters()` skip any key not in those lists. That skip is an
	 * ownership rule in the same family as the targeting rule: block A's
	 * namespace must not become a way to filter on a field block A never
	 * declared. Nothing exercised it until v3.
	 *
	 * It also re-tests the v2 class-match defect on a second axis. Both
	 * `scope_id()` and the field lists come off the matched target key; v2
	 * proved the scope travels, and this proves the FIELDS travel with it.
	 */
	'version' => 3,

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
	/*
	 * v3 adds `meta` per post: the Meta Box field `gbqf_color` and the ACF field
	 * `gbqf_size`, stored as plain post meta under their own names (which is
	 * what both integrations query on).
	 *
	 * The values are chosen so each filter yields a DISTINCT row count, because
	 * the harness reads counts and nothing else:
	 *
	 *   no filter          -> 4
	 *   search=Bravo       -> 1   (one title matches)
	 *   gbqf_color=red     -> 2   (alpha, charlie)
	 *   gbqf_size=large    -> 2   (bravo, charlie)
	 *
	 * 2 is deliberately not 1 and not 4: a field filter that silently fell back
	 * to "no filter" would read as 4, and one that collapsed onto the search
	 * would read as 1. Neither can be mistaken for a working field filter.
	 *
	 * The two fields also cut ACROSS each other — {alpha,charlie} vs
	 * {bravo,charlie} overlap in one post but neither contains the other — so a
	 * result that mixed the two up cannot land on the right count by accident.
	 */
	'posts' => array(
		array( 'slug' => 'gbqf-alpha',   'title' => 'GBQF Alpha',   'meta' => array( 'gbqf_color' => 'red',  'gbqf_size' => 'small' ) ),
		/*
		 * v3.1. `_gbqf_undeclared` is the probe for §9's hardest case, and it
		 * is deliberately unlike the other two: no filter block declares it, no
		 * integration registers it as a field, and its `_` prefix makes it a
		 * PROTECTED key — the class WordPress hides from the REST API precisely
		 * because it is not meant to be public.
		 *
		 * It exists on exactly one post, so a filter that honoured it would
		 * return 1 row for the correct value and 0 for a wrong one. That
		 * difference is the whole finding: it is a value oracle, letting an
		 * unauthenticated visitor confirm or deny arbitrary meta values by
		 * watching the row count. Both must return 4.
		 */
		array( 'slug' => 'gbqf-bravo',   'title' => 'GBQF Bravo',   'meta' => array( 'gbqf_color' => 'blue', 'gbqf_size' => 'large', '_gbqf_undeclared' => 'zulu' ) ),
		array( 'slug' => 'gbqf-charlie', 'title' => 'GBQF Charlie', 'meta' => array( 'gbqf_color' => 'red',  'gbqf_size' => 'large' ) ),
		array( 'slug' => 'gbqf-delta',   'title' => 'GBQF Delta',   'meta' => array( 'gbqf_color' => 'blue', 'gbqf_size' => 'small' ) ),
	),

	/*
	 * Field definitions. schema.php registers these — Meta Box via
	 * `rwmb_meta_boxes`, ACF via `acf_add_local_field_group()` — from this one
	 * declaration, so the registered field and the seeded values cannot drift.
	 *
	 * Both are `select` field types with explicit choices, but the two filter
	 * blocks render them with DIFFERENT control shapes on purpose (see
	 * `filter_blocks` below): the Meta Box field as a single <select>, the ACF
	 * field as a radio group.
	 *
	 * That split is what makes §6 meaningful. The two shapes get their
	 * accessible name by different mechanisms — `<label for>` pointing at the
	 * control's id, versus `role="group"` + `aria-labelledby` pointing back at
	 * the label — so a fixture rendering only one of them leaves the other's
	 * code path unmeasured.
	 */
	'fields' => array(
		'mb' => array(
			'id'      => 'gbqf_color',
			'name'    => 'GBQF Color',
			'type'    => 'select',
			'options' => array( 'red' => 'Red', 'blue' => 'Blue' ),
		),
		'acf' => array(
			// ACF needs a stable field KEY, not just a name. Hardcoded rather
			// than generated: acf_add_local_field_group() upserts on the key,
			// so a changing key would orphan a group on every seed.
			'key'     => 'field_gbqf_size',
			'name'    => 'gbqf_size',
			'label'   => 'GBQF Size',
			'type'    => 'select',
			'choices' => array( 'small' => 'Small', 'large' => 'Large' ),
		),
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

		/*
		 * v2. Claimed by CLASS, and deliberately carrying an HTML id that is
		 * NOT the target name.
		 *
		 * That gap is the whole point. A filter block registers targetId
		 * 'gbqf-alias' and renders its form fields as gbqf[gbqf-alias][...].
		 * The loop matches because 'gbqf-alias' is one of its classes — but its
		 * own id is 'gbqf-loop-classmatch'. If the code that picks the Params
		 * scope re-derives an id from the LOOP instead of using the matched
		 * TARGET KEY, it reads gbqf[gbqf-loop-classmatch][...], which nothing
		 * ever writes, and the filter silently does nothing.
		 *
		 * Every v1 loop matched by id, where the two happen to be equal, so
		 * this is the first fixture that can tell them apart.
		 */
		'classmatch' => array(
			'id'     => 'gbqf-loop-classmatch',
			'marker' => 'GBQFX-CLASSMATCH',
			'class'  => 'gbqf-alias',
			'target' => 'gbqf-alias',
			'expect' => 'open question — scoped match by class; does Params read the target key or the loop id?',
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
	 *
	 * v3 gives two of the blocks DISJOINT field ownership — the scoped block
	 * owns only the Meta Box field, the class-matching block owns only the ACF
	 * field, and neither owns the other's. The disjointness is the test: both
	 * fields live in the same `[meta]` namespace within a block's own scope, so
	 * the ONLY thing that can stop `gbqf[gbqf-loop-scoped][meta][gbqf_size]`
	 * from filtering is the ownership check in `Filters::get_meta_filters()` /
	 * `get_acf_filters()`. If those lists were dropped, ignored, or taken off
	 * the wrong target, an unowned field would filter and the harness sees it.
	 *
	 * Splitting them MB-vs-ACF rather than giving each block a different MB
	 * field also means each integration is covered once, and the ACF one rides
	 * on the class-matched block — so it re-proves the v2 fix on the field axis.
	 */
	'filter_blocks' => array(
		array( 'target_id' => '' ),
		array(
			'target_id' => 'gbqf-loop-scoped',
			'mb_field'  => 'gbqf_color',
		),
		// v2. Names a CLASS on the classmatch loop, never an HTML id on the
		// page — so it can only ever be matched by the class rule.
		// v3. Owns the ACF field, and no Meta Box field.
		array(
			'target_id'   => 'gbqf-alias',
			'acf_field'   => 'gbqf_size',
			// v3.1. RADIO, not a select — deliberately the other control shape.
			//
			// A select is a single control with an id, so its label carries a
			// `for`. A radio set is a GROUP of inputs with no single id, so its
			// name has to come from `role="group"` + `aria-labelledby`. Those
			// are two different code paths and two different ways to be wrong,
			// and pinning both fields to select left the group path unrendered
			// — §6 then reported "no bare labels" about a page that never
			// exercised the branch which emits them.
			//
			// The filter still works identically: a radio set posts the same
			// single parameter name, so §8's row count is unaffected.
			'acf_control' => 'radio',
		),
	),
);
