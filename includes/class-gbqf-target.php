<?php
namespace GBQF;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * A Query Loop that a filter block has claimed, together with what that filter
 * block owns.
 *
 * Immutable. Constructed only by {@see Targeting}; there is no reason for
 * anything else to make one, and making one by hand would assert a claim that
 * no filter block actually registered.
 *
 * THE SCOPE ID IS THE POINT. `scope_id()` returns the key the matching filter
 * block REGISTERED — never the Query Loop's own HTML id. The two are equal when
 * the match was by HTML id, and differ when it was by class:
 *
 *     filter block targetId  'my-projects'   -> form writes gbqf[my-projects][…]
 *     loop html id           'projects-grid'
 *     loop class list        '… my-projects' -> matched by class
 *
 * Reading the loop's id there produces `gbqf[projects-grid][…]`, a namespace
 * nothing writes, and the filter silently does nothing. That was a real defect
 * (fixed in 0.4.0, covered by tools/fixtures/query-filters section 5), and it
 * existed because the caller re-derived identity instead of being told it.
 * Holding the key here is what stops that returning: callers have no id-deriving
 * function in reach, so they cannot get it wrong.
 *
 * An empty scope id means FLAT mode — `Params` reads the `gbqf_*` params rather
 * than a `gbqf[…]` namespace. Targeting returns that in exactly one situation;
 * see {@see Targeting::match()}.
 */
final class Target {

    /**
     * Registered target key. Empty string for flat/unscoped mode.
     *
     * @var string
     */
    private $scope_id;

    /**
     * Meta Box field IDs owned by the matching filter block.
     *
     * @var string[]
     */
    private $mb_fields;

    /**
     * ACF field names owned by the matching filter block.
     *
     * @var string[]
     */
    private $acf_fields;

    /**
     * @param string   $scope_id   Registered target key, or '' for flat mode.
     * @param string[] $mb_fields  Meta Box field IDs owned by the filter block.
     * @param string[] $acf_fields ACF field names owned by the filter block.
     */
    public function __construct( $scope_id, array $mb_fields = [], array $acf_fields = [] ) {
        $this->scope_id   = (string) $scope_id;
        $this->mb_fields  = $mb_fields;
        $this->acf_fields = $acf_fields;
    }

    /**
     * The URL namespace this Target's filter state lives in.
     *
     * Pass straight to Params: non-empty selects scoped mode, '' selects flat.
     *
     * @return string
     */
    public function scope_id() {
        return $this->scope_id;
    }

    /**
     * Meta Box field IDs the matching filter block owns.
     *
     * An empty list means "no ownership declared" and the meta filter builders
     * fall back to processing every key — the pre-0.2.0 behaviour, still what
     * flat mode relies on.
     *
     * @return string[]
     */
    public function mb_fields() {
        return $this->mb_fields;
    }

    /**
     * ACF field names the matching filter block owns. Empty means all keys;
     * see {@see mb_fields()}.
     *
     * @return string[]
     */
    public function acf_fields() {
        return $this->acf_fields;
    }
}
