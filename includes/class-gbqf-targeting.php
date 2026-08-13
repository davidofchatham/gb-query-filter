<?php
namespace GBQF;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Decides which Query Loop a filter block is allowed to touch.
 *
 * This is the enforcement point for security invariant 2 in CONTEXT.md — "no
 * filtering an unconnected loop" — and for ADR-0001. One question, one answer,
 * one place:
 *
 *     $target = $targeting->match( $attributes );   // Target|null
 *
 * null means "leave this loop alone". Anything else is both the permission AND
 * the context needed to act on it, which is the whole design.
 *
 * WHY ONE METHOD, AND NOT TWO
 * ---------------------------
 * Before 0.4.0 this was split: `should_apply_to_attributes()` answered "may I
 * touch this loop?" and `get_matched_target()` answered "with what?". They
 * walked the same match chain independently and did not agree, and the
 * disagreement was not theoretical — it filtered a Query Loop that no filter
 * block had claimed, straight from a URL parameter. A caller then re-derived
 * the loop's id a THIRD time to pick the Params namespace, which broke
 * class-based targeting in a different direction.
 *
 * Both are structurally impossible here: there is one derivation, and the
 * caller is handed the answer rather than the means to recompute it. Any future
 * "just check X quickly" helper on the caller's side reopens the same hole.
 *
 * ORDERING IS PART OF THE INTERFACE. Registration happens when a filter block
 * RENDERS; matching happens when a Query Loop renders. A filter block must
 * therefore appear before its loop in the document, or the registry is still
 * empty when the loop asks. That coupling predates this class and is unchanged
 * by it.
 *
 * The registry is static because {@see Blocks::render_query_filter_block()}
 * registers without holding an instance; the decision is an instance method so
 * its scope can be supplied rather than fetched.
 */
class Targeting {

    /**
     * Filter blocks that have rendered this request, keyed by sanitized target
     * ID (empty string for unscoped blocks).
     *
     * Each value: [ 'scoped' => bool, 'mb_fields' => string[], 'acf_fields' => string[] ]
     *
     * @var array
     */
    private static $registered = [];

    /**
     * Default filter scope, from Settings. A block may override it per-loop.
     *
     * @var string
     */
    private $default_scope;

    /**
     * @param string $default_scope Scope to use when a block does not override
     *                              it. Pass Settings::get_filter_scope().
     */
    public function __construct( $default_scope ) {
        $this->default_scope = $default_scope;
    }

    /**
     * Register a filter block's target ID and the fields it owns.
     *
     * Called from the filter block's render callback. Static because the caller
     * holds no instance — see the class docblock on ordering.
     *
     * @param string $target_id HTML ID or class name of the target Query Loop.
     *                          Empty string for an unscoped block.
     * @param array  $args {
     *     Optional. Context for this target.
     *     @type bool     $scoped     Whether this block uses scoped URL params.
     *     @type string[] $mb_fields  Meta Box field IDs owned by this block.
     *     @type string[] $acf_fields ACF field names owned by this block.
     * }
     */
    public static function register( $target_id, $args = [] ) {
        $key = sanitize_key( $target_id );

        self::$registered[ $key ] = [
            'scoped'     => ! empty( $args['scoped'] ),
            'mb_fields'  => isset( $args['mb_fields'] )  ? (array) $args['mb_fields']  : [],
            'acf_fields' => isset( $args['acf_fields'] ) ? (array) $args['acf_fields'] : [],
        ];
    }

    /**
     * Every registered target key. Diagnostics only.
     *
     * @return string[]
     */
    public static function registered_keys() {
        return array_keys( self::$registered );
    }

    /**
     * Forget every registration. Tests only — a request never needs this.
     */
    public static function reset() {
        self::$registered = [];
    }

    /**
     * Resolve a Query Loop to the Target that claims it, if any.
     *
     * Decision order, and each step is load-bearing:
     *
     *   1. Per-block `data-gbqf-enabled` override — an explicit yes or no from
     *      the loop itself wins outright.
     *   2. Scope: `data-gbqf-scope` on the block, else the default from
     *      Settings.
     *   3. The `gbqf_should_apply_to_block` developer filter, when it returns
     *      non-null.
     *   4. 'all'      — the legacy escape hatch: every loop is fair game.
     *   5. 'targeted' — the contract: a registered id or class must claim it.
     *   6. Anything else — treated as 'targeted'.
     *
     * Step 6 used to `return true`, i.e. filter everything. A typo in a
     * `gbqf_filter_scope` callback or a stale `data-gbqf-scope` string was
     * therefore enough to reinstate the blanket behaviour ADR-0001 exists to
     * forbid. Unknown scope now fails CLOSED.
     *
     * @param array $attributes Query Loop block attributes.
     * @return Target|null The claiming Target, or null to leave the loop alone.
     */
    public function match( $attributes ) {
        // 1. Explicit per-block override.
        $enabled = $this->block_override( $attributes, 'enabled' );
        if ( 'false' === $enabled || false === $enabled ) {
            return null;
        }
        $forced = ( 'true' === $enabled || true === $enabled );

        // 2. Scope, with per-block override.
        $scope = $this->block_override( $attributes, 'scope' );
        if ( null === $scope ) {
            $scope = $this->default_scope;
        }

        // 3. Developer filter. Kept below the block override to preserve the
        //    pre-0.4.0 precedence; odd, but reordering it is a separate change.
        if ( ! $forced ) {
            $should = apply_filters( 'gbqf_should_apply_to_block', null, $attributes );
            if ( null !== $should ) {
                if ( ! $should ) {
                    return null;
                }
                $forced = true;
            }
        }

        // The registry lookup, which is the only thing that can produce a
        // SCOPED Target. Runs even when something above forced a yes, because a
        // forced yes still wants the right namespace and field ownership if a
        // filter block does happen to claim this loop.
        $matched = $this->matched_key( $attributes );

        if ( null !== $matched ) {
            return $this->build( $matched );
        }

        // Nothing claimed it. Only two things may still filter it: the legacy
        // 'all' scope, and an explicit force. Both fall back to FLAT mode,
        // which is the sole route by which a Target with an empty scope id is
        // ever returned.
        if ( 'all' === $scope || $forced ) {
            return $this->build_flat();
        }

        // 5 and 6: targeted (or an unrecognised scope, treated as targeted).
        return null;
    }

    /**
     * Find the registered key that claims this Query Loop.
     *
     * Two rules, in order:
     *   1. The loop's HTML id is a registered key.
     *   2. A registered key appears as a whole class name on the loop.
     *
     * Rule 2 is how GenerateBlocks' own unique classes are targeted.
     *
     * There used to be a third rule: any class containing `gbqf-target-`
     * matched, with no check that a filter block had registered that name.
     * Removed in 0.4.0 — it was the invariant-2 violation. It also protected
     * nothing: upstream never gated on it (`should_apply_to_attributes()`
     * returned true unconditionally), its only written form was a stale
     * docblock naming a bare `gbqf-target` class that the `gbqf-target-` prefix
     * does not even match, and the check was introduced by this fork in 0.2.0.
     *
     * The unscoped key '' is deliberately unreachable here: a loop's derived id
     * is never empty, and '' is skipped in the class scan. Unscoped filter
     * blocks therefore claim nothing under targeted scope — which is ADR-0001
     * working, not a defect.
     *
     * @param array $attributes Query Loop block attributes.
     * @return string|null Registered key, or null if unclaimed.
     */
    private function matched_key( $attributes ) {
        $block_id = $this->block_id( $attributes );

        if ( '' !== $block_id && array_key_exists( $block_id, self::$registered ) ) {
            return $block_id;
        }

        $class_name = isset( $attributes['className'] ) ? $attributes['className'] : '';
        if ( '' !== $class_name ) {
            foreach ( array_keys( self::$registered ) as $key ) {
                if ( '' === $key ) {
                    continue;
                }
                if ( $this->class_contains( $class_name, $key ) ) {
                    return $key;
                }
            }
        }

        return null;
    }

    /**
     * Build a Target from a registered key.
     *
     * A registration that did not ask for scoped params yields a flat Target,
     * keeping its field ownership.
     *
     * @param string $key Registered target key.
     * @return Target
     */
    private function build( $key ) {
        $data = self::$registered[ $key ];

        return new Target(
            ! empty( $data['scoped'] ) ? $key : '',
            $data['mb_fields'],
            $data['acf_fields']
        );
    }

    /**
     * Build the flat Target used when nothing claimed the loop but something
     * authorised filtering anyway ('all' scope, or a forced yes).
     *
     * Field ownership comes from the unscoped registration if a filter block
     * made one; otherwise the lists are empty, which the meta filter builders
     * read as "process every key" — the legacy blanket behaviour that 'all'
     * scope has always had.
     *
     * @return Target
     */
    private function build_flat() {
        if ( isset( self::$registered[''] ) ) {
            $data = self::$registered[''];
            return new Target( '', $data['mb_fields'], $data['acf_fields'] );
        }

        return new Target( '' );
    }

    /**
     * The Query Loop's HTML id, sanitized.
     *
     * GenerateBlocks stores it at htmlAttributes['id'], not the standard
     * 'anchor'; both are checked. Failing those, GB disables the standard
     * 'className' attribute and keeps a per-block identifier in 'uniqueId',
     * whose rendered class is 'gb-query-{uniqueId}' — reconstructed here so it
     * can be matched as a key.
     *
     * Private on purpose. This function being reachable from the caller is what
     * produced the class-match defect: the caller used it to pick a Params
     * namespace, getting the LOOP's id where the matched TARGET key was needed.
     * Callers now receive a {@see Target} and have no way to re-derive.
     *
     * @param array $attributes Block attributes.
     * @return string Sanitized id, or ''.
     */
    private function block_id( $attributes ) {
        if ( isset( $attributes['htmlAttributes']['id'] ) ) {
            return sanitize_key( $attributes['htmlAttributes']['id'] );
        }
        if ( isset( $attributes['anchor'] ) ) {
            return sanitize_key( $attributes['anchor'] );
        }
        if ( ! empty( $attributes['uniqueId'] ) ) {
            return 'gb-query-' . sanitize_key( $attributes['uniqueId'] );
        }
        return '';
    }

    /**
     * Whether a space-separated class string contains a whole class name.
     *
     * Whole-word, not substring: 'my-projects' must not match
     * 'my-projects-archive'.
     *
     * @param string $class_string Space-separated class names.
     * @param string $class_name   Single class name to find.
     * @return bool
     */
    private function class_contains( $class_string, $class_name ) {
        if ( empty( $class_string ) || empty( $class_name ) ) {
            return false;
        }
        return in_array( $class_name, preg_split( '/\s+/', trim( $class_string ) ), true );
    }

    /**
     * Read a per-block `data-gbqf-*` override from block attributes.
     *
     * @param array  $attributes Block attributes.
     * @param string $setting    Setting name, without the 'data-gbqf-' prefix.
     * @param mixed  $default    Returned when the attribute is absent.
     * @return mixed
     */
    private function block_override( $attributes, $setting, $default = null ) {
        $attr_key = 'data-gbqf-' . $setting;
        if ( isset( $attributes[ $attr_key ] ) ) {
            return $attributes[ $attr_key ];
        }

        // Some blocks store it without the 'data-' prefix.
        $alt_key = 'gbqf-' . $setting;
        if ( isset( $attributes[ $alt_key ] ) ) {
            return $attributes[ $alt_key ];
        }

        return $default;
    }
}
