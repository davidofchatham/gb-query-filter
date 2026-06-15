<?php
namespace GBQF;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Handles applying URL-based filters to GenerateBlocks queries.
 */
class Filters {

    /**
     * Store target data from filter blocks that have rendered.
     *
     * Keyed by sanitized target ID (empty string for unscoped blocks).
     * Each value is an array:
     *   [ 'scoped' => bool, 'mb_fields' => string[], 'acf_fields' => string[] ]
     *
     * @var array
     */
    private static $active_targets = [];

    /**
     * Whether Meta Box integration is enabled from settings.
     *
     * @var bool
     */
    protected $meta_box_enabled;

    /**
     * Whether ACF integration is enabled from settings.
     *
     * @var bool
     */
    protected $acf_enabled;

    /**
     * Register a filter block's target ID and associated field lists.
     * Called when a filter block renders.
     *
     * @param string $target_id HTML ID (or class name) of target Query Loop. Empty string for unscoped.
     * @param array  $args {
     *     Optional. Context for this target.
     *     @type bool     $scoped     Whether to use scoped URL params. Default false.
     *     @type string[] $mb_fields  Meta Box field IDs owned by this block.
     *     @type string[] $acf_fields ACF field names owned by this block.
     * }
     */
    public static function register_target( $target_id, $args = [] ) {
        $key = sanitize_key( $target_id );
        self::$active_targets[ $key ] = [
            'scoped'     => ! empty( $args['scoped'] ),
            'mb_fields'  => isset( $args['mb_fields'] )  ? (array) $args['mb_fields']  : [],
            'acf_fields' => isset( $args['acf_fields'] ) ? (array) $args['acf_fields'] : [],
        ];
    }

    /**
     * Check if a Query Loop is being targeted by any filter block.
     *
     * @param string $id Query Loop's HTML ID.
     * @return bool
     */
    protected function is_targeted( $id ) {
        return array_key_exists( $id, self::$active_targets );
    }

    /**
     * Extract the block's HTML ID from attributes.
     *
     * Checks GenerateBlocks' htmlAttributes['id'] first, then standard WP 'anchor'.
     *
     * @param array $attributes Block attributes.
     * @return string Sanitized block ID, or empty string.
     */
    protected function get_block_id_from_attributes( $attributes ) {
        if ( isset( $attributes['htmlAttributes']['id'] ) ) {
            return sanitize_key( $attributes['htmlAttributes']['id'] );
        }
        if ( isset( $attributes['anchor'] ) ) {
            return sanitize_key( $attributes['anchor'] );
        }
        // GenerateBlocks disables the standard 'className' attribute and stores
        // its block-unique identifier in 'uniqueId'. The rendered HTML class is
        // 'gb-query-{uniqueId}', so reconstruct that string for matching.
        if ( ! empty( $attributes['uniqueId'] ) ) {
            return 'gb-query-' . sanitize_key( $attributes['uniqueId'] );
        }
        return '';
    }

    /**
     * Check whether a space-separated class string contains a whole-word class name.
     *
     * @param string $class_string Space-separated list of CSS class names.
     * @param string $class_name   Single class name to look for.
     * @return bool
     */
    protected function class_contains( $class_string, $class_name ) {
        if ( empty( $class_string ) || empty( $class_name ) ) {
            return false;
        }
        return in_array( $class_name, preg_split( '/\s+/', trim( $class_string ) ), true );
    }

    /**
     * Find the registered target struct that corresponds to the given Query Loop block.
     *
     * Matches by HTML ID first, then by class name (for class-based targeting),
     * then falls back to the unscoped placeholder if present.
     *
     * @param array $attributes Block attributes.
     * @return array Target struct: [ 'scoped' => bool, 'mb_fields' => [], 'acf_fields' => [] ]
     */
    protected function get_matched_target( $attributes ) {
        $block_id = $this->get_block_id_from_attributes( $attributes );

        // Direct match by registered ID.
        if ( '' !== $block_id && array_key_exists( $block_id, self::$active_targets ) ) {
            return self::$active_targets[ $block_id ];
        }

        // Check whether any registered targetId appears as a whole class name on this block.
        $class_name = isset( $attributes['className'] ) ? $attributes['className'] : '';
        foreach ( self::$active_targets as $key => $data ) {
            if ( '' === $key ) {
                continue; // Skip unscoped placeholder.
            }
            if ( $this->class_contains( $class_name, $key ) ) {
                return $data;
            }
        }

        // Unscoped fallback: return the '' entry if present.
        if ( array_key_exists( '', self::$active_targets ) ) {
            return self::$active_targets[''];
        }

        return [ 'scoped' => false, 'mb_fields' => [], 'acf_fields' => [] ];
    }

    /**
     * Debug logging helper.
     *
     * @param string $message Message to log.
     * @param array  $data    Optional data to include.
     */
    protected function debug_log( $message, $data = [] ) {
        if ( ! apply_filters( 'gbqf_enable_debug_logging', false ) ) {
            return;
        }

        error_log(
            sprintf(
                '[GB Query Filter] %s: %s',
                $message,
                print_r( $data, true )
            )
        );
    }

    public function __construct() {
        $this->meta_box_enabled = Settings::is_metabox_enabled();
        $this->acf_enabled      = Settings::is_acf_enabled();

        // Get filter priority - default 20 (runs after most plugins including BWS Portal System).
        $priority = Settings::get_filter_priority();

        // GenerateBlocks 1.x / original Query Loop filter.
        add_filter( 'generateblocks_query_loop_args', [ $this, 'apply_filters_to_gb_query' ], $priority, 2 );

        // GenerateBlocks 2.0+ filter (WP_Query args).
        add_filter( 'generateblocks_query_wp_query_args', [ $this, 'apply_filters_to_gb_query_v2' ], $priority, 4 );
    }

    /**
     * Get Meta Box meta filters from the unified meta parameter array.
     *
     * Both Meta Box and ACF fields share the gbqf_meta namespace (scoped or flat).
     * This method only processes keys that belong to configured Meta Box fields.
     *
     * Format (flat):   gbqf_meta[meta_key]=value
     * Format (scoped): gbqf[target][meta][meta_key]=value
     *
     * @param string[] $allowed_field_names Meta Box field IDs owned by this block.
     *                                      If empty, processes all keys (legacy behaviour).
     * @param array    $raw_meta            Pre-fetched raw meta array (from Params::get_meta()).
     * @return array[] Array of [ 'key' => string, 'value' => string ].
     */
    protected function get_meta_filters( $allowed_field_names = [], $raw_meta ) {
        if ( ! $this->meta_box_enabled ) {
            return [];
        }

        if ( empty( $raw_meta ) ) {
            return [];
        }

        $filters = [];

        foreach ( $raw_meta as $key => $value ) {
            $key = sanitize_key( $key );

            // Skip keys not belonging to this block's Meta Box fields.
            if ( ! empty( $allowed_field_names ) && ! in_array( $key, $allowed_field_names, true ) ) {
                continue;
            }

            $value = is_array( $value ) ? reset( $value ) : $value;
            $value = sanitize_text_field( $value );

            if ( '' === $key || '' === $value ) {
                continue;
            }

            $filters[] = [
                'key'   => $key,
                'value' => $value,
            ];
        }

        return $filters;
    }

    /**
     * Get ACF filters from the unified meta parameter array.
     *
     * Both Meta Box and ACF fields share the gbqf_meta namespace (scoped or flat).
     * This method only processes keys that belong to configured ACF fields.
     * ACF checkbox fields (multi-value) use LIKE comparison against serialized values.
     *
     * Format (flat, single):   gbqf_meta[field_name]=value
     * Format (flat, multi):    gbqf_meta[field_name][]=value1&gbqf_meta[field_name][]=value2
     * Format (scoped, single): gbqf[target][meta][field_name]=value
     * Format (scoped, multi):  gbqf[target][meta][field_name][]=value1&...
     *
     * @param string[] $allowed_field_names ACF field names owned by this block.
     *                                      If empty, processes all keys (legacy behaviour).
     * @param array    $raw_meta            Pre-fetched raw meta array (from Params::get_meta()).
     * @return array[] Array of [ 'key' => string, 'value' => string|array, 'compare' => string ].
     */
    protected function get_acf_filters( $allowed_field_names = [], $raw_meta ) {
        if ( ! $this->acf_enabled ) {
            return [];
        }

        if ( empty( $raw_meta ) ) {
            return [];
        }

        $filters = [];

        foreach ( $raw_meta as $field_name => $value ) {
            $field_name = sanitize_key( $field_name );

            // Skip keys not belonging to this block's ACF fields.
            if ( ! empty( $allowed_field_names ) && ! in_array( $field_name, $allowed_field_names, true ) ) {
                continue;
            }

            // Handle multi-value (checkboxes).
            if ( is_array( $value ) ) {
                $value = array_map( 'sanitize_text_field', $value );
                $value = array_filter( $value );

                if ( empty( $value ) ) {
                    continue;
                }

                // For each checkbox value, add a LIKE query for serialized array matching.
                foreach ( $value as $single_value ) {
                    $filters[] = [
                        'key'     => $field_name,
                        'value'   => serialize( strval( $single_value ) ),
                        'compare' => 'LIKE',
                    ];
                }
            } else {
                // Single value (select, radio, text).
                $value = sanitize_text_field( $value );

                if ( '' === $value ) {
                    continue;
                }

                $filters[] = [
                    'key'   => $field_name,
                    'value' => $value,
                ];
            }
        }

        return $filters;
    }

    /**
     * Check for data-gbqf-* attribute overrides on Query Loop block.
     *
     * @param array  $attributes Block attributes.
     * @param string $setting    Setting name (without 'data-gbqf-' prefix).
     * @param mixed  $default    Default value if not set.
     * @return mixed
     */
    protected function get_block_override( $attributes, $setting, $default = null ) {
        $attr_key = 'data-gbqf-' . $setting;

        // Check if attribute exists.
        if ( isset( $attributes[ $attr_key ] ) ) {
            return $attributes[ $attr_key ];
        }

        // Also check without 'data-' prefix (some blocks might store as 'gbqf-setting').
        $alt_key = 'gbqf-' . $setting;
        if ( isset( $attributes[ $alt_key ] ) ) {
            return $attributes[ $alt_key ];
        }

        return $default;
    }

    /**
     * Determine if this GB Query Loop should be affected, based on its attributes.
     *
     * Supports targeted mode (HTML ID or class matching) and per-block data attribute overrides.
     *
     * @param array $attributes Block attributes.
     * @return bool
     */
    protected function should_apply_to_attributes( $attributes ) {
        // Check for explicit enable/disable on this block.
        $block_enabled = $this->get_block_override( $attributes, 'enabled' );
        if ( 'false' === $block_enabled || false === $block_enabled ) {
            return false; // Explicitly disabled.
        }
        if ( 'true' === $block_enabled || true === $block_enabled ) {
            return true; // Explicitly enabled.
        }

        // Get scope with block-level override.
        $scope = $this->get_block_override( $attributes, 'scope' );
        if ( null === $scope ) {
            $scope = Settings::get_filter_scope();
        }

        // Developer override - allows explicit control per block.
        $should_apply = apply_filters( 'gbqf_should_apply_to_block', null, $attributes );
        if ( null !== $should_apply ) {
            return (bool) $should_apply;
        }

        // Mode: 'all' - filter all Query Loops.
        if ( 'all' === $scope ) {
            return true;
        }

        // Mode: 'targeted' - only filter Query Loops that match a filter block's targetId.
        if ( 'targeted' === $scope ) {
            // get_block_id_from_attributes() checks htmlAttributes['id'], anchor, and
            // GenerateBlocks' uniqueId (reconstructed as 'gb-query-{uniqueId}').
            $block_id = $this->get_block_id_from_attributes( $attributes );

            $class_name = isset( $attributes['className'] ) ? $attributes['className'] : '';

            // Apply if Query Loop HTML ID matches a registered target.
            if ( ! empty( $block_id ) && $this->is_targeted( $block_id ) ) {
                return true;
            }

            // Check if any registered targetId appears as a whole class name on this block.
            // This enables class-based targeting (e.g., GB's unique gb-query-* classes).
            if ( ! empty( $class_name ) ) {
                foreach ( array_keys( self::$active_targets ) as $target_key ) {
                    if ( '' === $target_key ) {
                        continue; // Skip unscoped placeholder.
                    }
                    if ( $this->class_contains( $class_name, $target_key ) ) {
                        return true;
                    }
                }
            }

            // Legacy fallback: also check for gbqf-target-* class.
            if ( ! empty( $class_name ) && strpos( $class_name, 'gbqf-target-' ) !== false ) {
                return true;
            }

            return false;
        }

        // Default: apply to all.
        return true;
    }

    /**
     * Apply search + taxonomy + meta filters to GenerateBlocks Query Loop args (GB 1.x style).
     *
     * @param array $query_args Existing query args.
     * @param array $attributes Block attributes.
     * @return array
     */
    public function apply_filters_to_gb_query( $query_args, $attributes ) {
        if ( ! $this->should_apply_to_attributes( $attributes ) ) {
            return $query_args;
        }

        $this->debug_log( 'Query args before GBQF (GB 1.x)', $query_args );

        // Determine whether this block uses scoped URL params and what fields it owns.
        $matched    = $this->get_matched_target( $attributes );
        $mb_fields  = $matched['mb_fields'];
        $acf_fields = $matched['acf_fields'];

        // Use the block's HTML ID only when scoped; flat mode must pass '' so Params
        // reads from the flat gbqf_* params instead of a non-existent scoped namespace.
        $target_for_params = ! empty( $matched['scoped'] ) ? $this->get_block_id_from_attributes( $attributes ) : '';
        $params       = new \GBQF\Params( $target_for_params );
        $search       = $params->get_search();
        $cat_ids      = $params->get_cat_ids();
        $tag_ids      = $params->get_tag_ids();
        $extra_tax    = $params->get_tax_terms();
        $raw_meta     = $params->get_meta();
        $meta_filters = $this->get_meta_filters( $mb_fields, $raw_meta );
        $acf_filters  = $this->get_acf_filters( $acf_fields, $raw_meta );

        // Apply search (with optional preservation of existing search terms).
        if ( '' !== $search ) {
            $preserve = Settings::should_preserve_search();

            if ( $preserve && ! empty( $query_args['s'] ) ) {
                // Combine search terms (WordPress uses AND logic).
                $existing        = trim( $query_args['s'] );
                $query_args['s'] = $existing . ' ' . $search;
            } else {
                // Default: replace existing search (if any).
                $query_args['s'] = $search;
            }
        }

        // Apply categories (if any selected).
        if ( ! empty( $cat_ids ) ) {

            if ( ! empty( $query_args['category__in'] ) ) {
                $existing = (array) $query_args['category__in'];
                $query_args['category__in'] = array_unique(
                    array_merge( $existing, $cat_ids )
                );
            } else {
                $query_args['category__in'] = $cat_ids;
            }
        }

        // Apply tags (if any selected).
        if ( ! empty( $tag_ids ) ) {

            if ( ! empty( $query_args['tag__in'] ) ) {
                $existing = (array) $query_args['tag__in'];
                $query_args['tag__in'] = array_unique(
                    array_merge( $existing, $tag_ids )
                );
            } else {
                $query_args['tag__in'] = $tag_ids;
            }
        }

        // Apply extra taxonomy filters via tax_query.
        if ( ! empty( $extra_tax ) ) {
            $tax_query = [];

            if ( ! empty( $query_args['tax_query'] ) && is_array( $query_args['tax_query'] ) ) {
                $tax_query = $query_args['tax_query'];
            }

            if ( ! empty( $tax_query ) && empty( $tax_query['relation'] ) ) {
                $tax_query['relation'] = 'AND';
            } elseif ( empty( $tax_query ) ) {
                $tax_query['relation'] = 'AND';
            }

            foreach ( $extra_tax as $taxonomy => $term_ids ) {
                $tax_query[] = [
                    'taxonomy' => $taxonomy,
                    'field'    => 'term_id',
                    'terms'    => $term_ids,
                    'operator' => 'IN',
                ];
            }

            $query_args['tax_query'] = $tax_query;
        }

        // Apply meta filters (Meta Box fields).
        if ( ! empty( $meta_filters ) ) {
            $meta_query = [];

            if ( ! empty( $query_args['meta_query'] ) && is_array( $query_args['meta_query'] ) ) {
                $meta_query = $query_args['meta_query'];
            }

            if ( ! empty( $meta_query ) && empty( $meta_query['relation'] ) ) {
                $meta_query['relation'] = 'AND';
            } elseif ( empty( $meta_query ) ) {
                $meta_query['relation'] = 'AND';
            }

            foreach ( $meta_filters as $filter ) {
                $meta_query[] = [
                    'key'   => $filter['key'],
                    'value' => $filter['value'],
                ];
            }

            $query_args['meta_query'] = $meta_query;
        }

        // Apply ACF filters (if any).
        if ( ! empty( $acf_filters ) ) {
            $meta_query = [];

            if ( ! empty( $query_args['meta_query'] ) && is_array( $query_args['meta_query'] ) ) {
                $meta_query = $query_args['meta_query'];
            }

            if ( ! empty( $meta_query ) && empty( $meta_query['relation'] ) ) {
                $meta_query['relation'] = 'AND';
            } elseif ( empty( $meta_query ) ) {
                $meta_query['relation'] = 'AND';
            }

            foreach ( $acf_filters as $filter ) {
                $meta_clause = [
                    'key'   => $filter['key'],
                    'value' => $filter['value'],
                ];

                // Add compare operator if specified (for LIKE queries).
                if ( isset( $filter['compare'] ) ) {
                    $meta_clause['compare'] = $filter['compare'];
                }

                $meta_query[] = $meta_clause;
            }

            $query_args['meta_query'] = $meta_query;
        }

        $this->debug_log( 'Query args after GBQF (GB 1.x)', $query_args );

        return $query_args;
    }

    /**
     * Apply filters to GenerateBlocks 2.0+ Query WP_Query args.
     *
     * @param array        $query_args Existing WP_Query args.
     * @param array        $attributes Block attributes.
     * @param array|null   $block      Block data (not needed here).
     * @param int|string   $query_id   GB query id (not needed here).
     * @return array
     */
    public function apply_filters_to_gb_query_v2( $query_args, $attributes, $block, $query_id ) {
        return $this->apply_filters_to_gb_query( $query_args, $attributes );
    }
}
