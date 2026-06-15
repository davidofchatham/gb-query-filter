<?php
namespace GBQF;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Encapsulates all URL parameter reading and URL construction for a single filter scope.
 *
 * Instantiate with the sanitized target ID string:
 * - Empty string '' → flat mode (reads from gbqf_* params)
 * - Non-empty string → scoped mode (reads from gbqf[target_id][*] params)
 */
class Params {

    /** @var string Sanitized target ID; empty string for flat/unscoped mode. */
    private $target_id;

    /** @var bool True when target_id is non-empty (scoped mode). */
    private $is_scoped;

    /** @var array Cached scoped data from $_GET['gbqf'][$target_id], unslashed. Empty if flat or absent. */
    private $data;

    /**
     * @param string $target_id Sanitized target ID. Pass '' for flat/unscoped mode.
     */
    public function __construct( $target_id ) {
        $this->target_id = $target_id;
        $this->is_scoped = '' !== $target_id;
        if ( $this->is_scoped
            && isset( $_GET['gbqf'][ $target_id ] )
            && is_array( $_GET['gbqf'][ $target_id ] ) ) {
            $this->data = (array) wp_unslash( $_GET['gbqf'][ $target_id ] );
        } else {
            $this->data = [];
        }
    }

    /**
     * Returns the sanitized search term, or '' if none.
     *
     * @return string
     */
    public function get_search() {
        if ( $this->is_scoped ) {
            return isset( $this->data['search'] )
                ? sanitize_text_field( $this->data['search'] )
                : '';
        }
        return isset( $_GET['gbqf_search'] ) && '' !== $_GET['gbqf_search']
            ? sanitize_text_field( wp_unslash( $_GET['gbqf_search'] ) )
            : '';
    }

    /**
     * Returns selected category term IDs.
     *
     * @return int[]
     */
    public function get_cat_ids() {
        if ( $this->is_scoped ) {
            $raw = isset( $this->data['cat'] ) ? (array) $this->data['cat'] : [];
        } else {
            if ( ! isset( $_GET['gbqf_cat'] ) ) {
                return [];
            }
            $raw = wp_unslash( $_GET['gbqf_cat'] );
            if ( ! is_array( $raw ) ) {
                $raw = [ $raw ];
            }
        }
        return array_values( array_filter( array_map( 'absint', $raw ) ) );
    }

    /**
     * Returns selected tag term IDs.
     *
     * @return int[]
     */
    public function get_tag_ids() {
        if ( $this->is_scoped ) {
            $raw = isset( $this->data['tag'] ) ? (array) $this->data['tag'] : [];
        } else {
            if ( ! isset( $_GET['gbqf_tag'] ) ) {
                return [];
            }
            $raw = wp_unslash( $_GET['gbqf_tag'] );
            if ( ! is_array( $raw ) ) {
                $raw = [ $raw ];
            }
        }
        return array_values( array_filter( array_map( 'absint', $raw ) ) );
    }

    /**
     * Returns extra taxonomy terms keyed by taxonomy slug.
     *
     * @return array<string, int[]>
     */
    public function get_tax_terms() {
        if ( $this->is_scoped ) {
            $raw_tax = ( isset( $this->data['tax'] ) && is_array( $this->data['tax'] ) )
                ? $this->data['tax'] : [];
        } else {
            if ( ! isset( $_GET['gbqf_tax'] ) || ! is_array( $_GET['gbqf_tax'] ) ) {
                return [];
            }
            $raw_tax = wp_unslash( $_GET['gbqf_tax'] );
        }
        $result = [];
        foreach ( $raw_tax as $slug => $term_ids ) {
            $slug = sanitize_key( $slug );
            if ( '' === $slug ) {
                continue;
            }
            if ( ! is_array( $term_ids ) ) {
                $term_ids = [ $term_ids ];
            }
            $ids = array_values( array_filter( array_map( 'absint', $term_ids ) ) );
            if ( ! empty( $ids ) ) {
                $result[ $slug ] = $ids;
            }
        }
        return $result;
    }

    /**
     * Returns the raw meta key=>value(s) array, unslashed (not yet sanitized).
     * Used by get_meta_filters() and get_acf_filters().
     *
     * @return array<string, mixed>
     */
    public function get_meta() {
        if ( $this->is_scoped ) {
            return ( isset( $this->data['meta'] ) && is_array( $this->data['meta'] ) )
                ? (array) $this->data['meta']
                : [];
        }
        return ( isset( $_GET['gbqf_meta'] ) && is_array( $_GET['gbqf_meta'] ) )
            ? wp_unslash( $_GET['gbqf_meta'] )
            : [];
    }

    /**
     * Returns true if any filter value is present for this scope.
     *
     * @return bool
     */
    public function has_any_values() {
        return '' !== $this->get_search()
            || ! empty( $this->get_cat_ids() )
            || ! empty( $this->get_tag_ids() )
            || ! empty( $this->get_tax_terms() )
            || ! empty( $this->get_meta() );
    }

    /**
     * Returns all form input name strings for this scope.
     *
     * Keys: search, cat, tag, tax_fmt, meta_fmt, meta_arr_fmt
     * tax_fmt / meta_fmt / meta_arr_fmt contain a %s placeholder for the slug/field name.
     *
     * @return array<string, string>
     */
    public function get_field_names() {
        if ( $this->is_scoped ) {
            $p = 'gbqf[' . $this->target_id . ']';
            return [
                'search'       => $p . '[search]',
                'cat'          => $p . '[cat][]',
                'tag'          => $p . '[tag][]',
                'tax_fmt'      => $p . '[tax][%s][]',
                'meta_fmt'     => $p . '[meta][%s]',
                'meta_arr_fmt' => $p . '[meta][%s][]',
            ];
        }
        return [
            'search'       => 'gbqf_search',
            'cat'          => 'gbqf_cat[]',
            'tag'          => 'gbqf_tag[]',
            'tax_fmt'      => 'gbqf_tax[%s][]',
            'meta_fmt'     => 'gbqf_meta[%s]',
            'meta_arr_fmt' => 'gbqf_meta[%s][]',
        ];
    }

    /**
     * Builds the reset URL for this filter scope.
     * Scoped: removes only this block's gbqf[target_id] namespace.
     * Flat:   removes all gbqf_* params.
     *
     * Returns '' if $_SERVER['REQUEST_URI'] is unavailable.
     *
     * @return string Escaped URL safe for use in href attributes.
     */
    public function get_reset_url() {
        if ( ! isset( $_SERVER['REQUEST_URI'] ) ) {
            return '';
        }
        $current_url = home_url( wp_unslash( $_SERVER['REQUEST_URI'] ) );
        $current_url = html_entity_decode( $current_url, ENT_QUOTES | ENT_HTML5, 'UTF-8' );

        if ( $this->is_scoped ) {
            $parsed = wp_parse_url( $current_url );
            parse_str( isset( $parsed['query'] ) ? $parsed['query'] : '', $reset_params );
            if ( isset( $reset_params['gbqf'][ $this->target_id ] ) ) {
                unset( $reset_params['gbqf'][ $this->target_id ] );
                if ( empty( $reset_params['gbqf'] ) ) {
                    unset( $reset_params['gbqf'] );
                }
            }
            unset( $reset_params['gbqf_acf'] );
            $reset_query = http_build_query( $reset_params, '', '&' );
            return esc_url_raw(
                ( isset( $parsed['scheme'] ) ? $parsed['scheme'] : 'https' ) . '://' .
                ( isset( $parsed['host'] ) ? $parsed['host'] : '' ) .
                ( isset( $parsed['port'] ) ? ':' . $parsed['port'] : '' ) .
                ( isset( $parsed['path'] ) ? $parsed['path'] : '/' ) .
                ( $reset_query ? '?' . $reset_query : '' )
            );
        }

        return remove_query_arg(
            [ 'gbqf_search', 'gbqf_cat', 'gbqf_tag', 'gbqf_meta', 'gbqf_tax', 'gbqf_acf' ],
            $current_url
        );
    }
}
