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
     * Decides which Query Loops this instance may touch.
     *
     * @var Targeting
     */
    protected $targeting;

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
     *
     * @deprecated 0.4.0 Use {@see Targeting::register()}.
     *
     * @param string $target_id HTML ID (or class name) of target Query Loop. Empty string for unscoped.
     * @param array  $args      See Targeting::register().
     */
    public static function register_target( $target_id, $args = [] ) {
        _deprecated_function( __METHOD__, '0.4.0', 'GBQF\Targeting::register' );
        Targeting::register( $target_id, $args );
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
        // No scope argument: Targeting resolves it per loop, at render time.
        // Passing Settings::get_filter_scope() here would freeze the
        // `gbqf_filter_scope` filter at plugins_loaded, before themes and
        // before init — silently ignoring the documented way to set it.
        $this->targeting        = new Targeting();

        // Get filter priority - default 20 (runs after most query-modifying plugins).
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
    protected function get_meta_filters( $allowed_field_names = [], $raw_meta = [] ) {
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
    protected function get_acf_filters( $allowed_field_names = [], $raw_meta = [] ) {
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
     * Apply search + taxonomy + meta filters to GenerateBlocks Query Loop args (GB 1.x style).
     *
     * @param array $query_args Existing query args.
     * @param array $attributes Block attributes.
     * @return array
     */
    public function apply_filters_to_gb_query( $query_args, $attributes ) {
        // One question, one answer. null means this loop is not ours to touch;
        // anything else carries both the permission and the context.
        //
        // Do not reintroduce a separate "may I?" check here. This used to be a
        // gate plus a second lookup that re-derived the same match, and the two
        // disagreed — which filtered a Query Loop no filter block had claimed
        // (security invariant 2). See ADR-0001 and Targeting's class docblock.
        $target = $this->targeting->match( $attributes );

        if ( null === $target ) {
            return $query_args;
        }

        $this->debug_log( 'Query args before GBQF (GB 1.x)', $query_args );

        // scope_id() is the key the matching filter block REGISTERED — never
        // this loop's own HTML id. They differ whenever the match was by class,
        // and reading the loop's id there is exactly the bug that made
        // class-based targeting a silent no-op before 0.4.0.
        $params       = new \GBQF\Params( $target->scope_id() );
        $search       = $params->get_search();
        $cat_ids      = $params->get_cat_ids();
        $tag_ids      = $params->get_tag_ids();
        $extra_tax    = $params->get_tax_terms();
        $raw_meta     = $params->get_meta();
        $meta_filters = $this->get_meta_filters( $target->mb_fields(), $raw_meta );
        $acf_filters  = $this->get_acf_filters( $target->acf_fields(), $raw_meta );

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
