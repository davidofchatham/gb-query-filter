( function ( wp ) {
    const { registerBlockType } = wp.blocks;
    const { __, sprintf } = wp.i18n;
    const blockEditor = wp.blockEditor || wp.editor;
    const { InspectorControls, useBlockProps } = blockEditor;
    const { PanelBody, TextControl, ToggleControl, SelectControl, RadioControl, Button } = wp.components;
    const ServerSideRender = wp.serverSideRender || ( wp.components && wp.components.ServerSideRender );
    const { Fragment, createElement: el, createInterpolateElement, useState, useRef, useEffect } = wp.element;

    const blockIcon = el(
        'svg',
        {
            viewBox: '0 0 24 24',
            xmlns: 'http://www.w3.org/2000/svg',
            fillRule: 'evenodd',
            clipRule: 'evenodd',
            strokeLinejoin: 'round',
            strokeMiterlimit: '2',
        },
        el( 'path', {
            d: 'M9.484 15.696 8.773 15l-2.552 2.607-1.539-1.452-.698.709L6.234 19l3.25-3.304Zm0-5L8.773 10l-2.552 2.607-1.539-1.452-.698.709L6.234 14l3.25-3.304Zm0-5L8.773 5 6.221 7.607 4.682 6.155l-.698.709L6.234 9l3.25-3.304ZM20 17h-8v1h8v-1Zm0-5h-8v1h8v-1Zm0-5h-8v1h8V7Zm4-5H0v20h24V2Zm-1 19H1V3h22v18Z',
            fill: 'currentColor',
        } )
    );

    const metaBoxIntegrationEnabled =
        typeof window !== 'undefined' && typeof window.GBQF_ENABLE_METABOX !== 'undefined'
            ? !! window.GBQF_ENABLE_METABOX
            : true;

    const acfIntegrationEnabled =
        typeof window !== 'undefined' && typeof window.GBQF_ENABLE_ACF !== 'undefined'
            ? !! window.GBQF_ENABLE_ACF
            : true;

    const settingsUrl =
        typeof window !== 'undefined' && window.GBQF_SETTINGS_URL
            ? window.GBQF_SETTINGS_URL
            : '';

    // Options shown before the list scrolls; the rest load in steps of the same size.
    const PICKER_BATCH = 25;
    let pickerSeq = 0;

    /**
     * Searchable combobox paired with an Add button.
     *
     * Picking an option fills the box and arms Add; Add (or Enter once armed) commits.
     * The first PICKER_BATCH matches show on focus, typing searches the whole list, and
     * scrolling loads more, so a site with hundreds of custom fields stays usable.
     *
     * items: [ { value, label, sub?, tag? } ]
     */
    function Picker( { id, label, addLabel, items, onAdd, placeholder, emptyText } ) {
        const idRef = useRef( null );
        if ( ! idRef.current ) {
            idRef.current = id || 'gbqf-picker-' + ( ++pickerSeq );
        }
        const inputId = idRef.current;
        const inputRef = useRef( null );
        const listRef = useRef( null );
        const [ text, setText ] = useState( '' );
        const [ open, setOpen ] = useState( false );
        const [ active, setActive ] = useState( -1 );
        const [ limit, setLimit ] = useState( PICKER_BATCH );
        const [ picked, setPicked ] = useState( null );

        // Once an option is picked the box shows its label, so list the full set again.
        const needle = picked ? '' : text.toLowerCase();
        const matches = items.filter( ( it ) =>
            ( it.label + ' ' + ( it.sub || '' ) + ' ' + ( it.tag || '' ) ).toLowerCase().includes( needle )
        );
        const shown = matches.slice( 0, limit );

        useEffect( () => {
            const node = listRef.current && listRef.current.children[ active ];
            if ( node && node.scrollIntoView ) {
                node.scrollIntoView( { block: 'nearest' } );
            }
        }, [ active ] );

        const openList = () => {
            setActive( -1 );
            setLimit( PICKER_BATCH );
            setOpen( true );
        };

        const pick = ( item ) => {
            setPicked( item );
            setText( item.label + ( item.tag ? ' (' + item.tag + ')' : '' ) );
            setOpen( false );
        };

        const add = () => {
            if ( ! picked ) {
                return;
            }
            onAdd( picked.value );
            setPicked( null );
            setText( '' );
            setOpen( false );
            if ( inputRef.current ) {
                inputRef.current.focus();
            }
        };

        const onKeyDown = ( e ) => {
            if ( e.key === 'ArrowDown' ) {
                e.preventDefault();
                if ( open ) {
                    setActive( Math.min( shown.length - 1, active + 1 ) );
                } else {
                    openList();
                    setActive( 0 );
                }
            } else if ( e.key === 'ArrowUp' ) {
                e.preventDefault();
                setActive( Math.max( 0, active - 1 ) );
            } else if ( e.key === 'Enter' ) {
                e.preventDefault();
                if ( open && shown[ active ] ) {
                    pick( shown[ active ] );
                } else {
                    add();
                }
            } else if ( e.key === 'Escape' && open ) {
                e.stopPropagation();
                setOpen( false );
            }
        };

        const onScroll = ( e ) => {
            const list = e.target;
            if ( limit < matches.length && list.scrollTop + list.clientHeight >= list.scrollHeight - 24 ) {
                setLimit( limit + PICKER_BATCH );
            }
        };

        let status = '';
        if ( open ) {
            if ( ! matches.length ) {
                status = __( 'No results', 'gb-query-filter' );
            } else if ( matches.length > limit ) {
                status = sprintf(
                    /* translators: 1: total matching options, 2: options currently listed. */
                    __( '%1$d results available, showing %2$d. Scroll for more.', 'gb-query-filter' ),
                    matches.length,
                    limit
                );
            } else {
                status = sprintf( /* translators: %d: number of matching options. */ __( '%d results available', 'gb-query-filter' ), matches.length );
            }
        }

        return el(
            'div',
            { className: 'gbqf-picker' },
            el( 'label', { htmlFor: inputId, className: 'gbqf-picker__label' }, label ),
            el(
                'div',
                { className: 'gbqf-picker__row' },
                el(
                    'div',
                    { className: 'gbqf-picker__combo' },
                    el( 'input', {
                        ref: inputRef,
                        id: inputId,
                        type: 'text',
                        role: 'combobox',
                        'aria-expanded': open,
                        'aria-controls': open ? inputId + '-list' : undefined,
                        'aria-autocomplete': 'list',
                        'aria-activedescendant': open && shown[ active ] ? inputId + '-o' + active : undefined,
                        autoComplete: 'off',
                        placeholder: placeholder,
                        value: text,
                        onChange: ( e ) => {
                            setText( e.target.value );
                            setPicked( null );
                            setActive( 0 );
                            setLimit( PICKER_BATCH );
                            setOpen( true );
                        },
                        // Not opened on focus: Add and row removal refocus this input and must not
                        // pop the list. A click, ArrowDown or typing opens it.
                        onClick: () => {
                            if ( ! open ) {
                                openList();
                            }
                        },
                        onBlur: () => setOpen( false ),
                        onKeyDown: onKeyDown,
                    } ),
                    open &&
                        el(
                            'ul',
                            {
                                ref: listRef,
                                id: inputId + '-list',
                                role: 'listbox',
                                className: 'gbqf-picker__list',
                                'aria-label': label,
                                onScroll: onScroll,
                                // Keep focus in the input so grabbing the scrollbar doesn't blur and close the list.
                                onMouseDown: ( e ) => e.preventDefault(),
                            },
                            shown.map( ( it, k ) =>
                                el(
                                    'li',
                                    {
                                        key: it.value,
                                        id: inputId + '-o' + k,
                                        role: 'option',
                                        'aria-selected': k === active,
                                        onMouseDown: () => pick( it ),
                                    },
                                    it.label,
                                    it.sub || it.tag
                                        ? el( 'small', null, ( it.sub || '' ) + ( it.sub && it.tag ? ' · ' : '' ) + ( it.tag || '' ) )
                                        : null
                                )
                            ),
                            matches.length > limit &&
                                el(
                                    'li',
                                    { role: 'presentation', className: 'gbqf-picker__note' },
                                    sprintf(
                                        /* translators: 1: options shown, 2: total options. */
                                        __( 'Showing %1$d of %2$d. Scroll for more, or type to narrow.', 'gb-query-filter' ),
                                        limit,
                                        matches.length
                                    )
                                ),
                            ! matches.length &&
                                el(
                                    'li',
                                    { role: 'presentation', className: 'gbqf-picker__note' },
                                    items.length ? __( 'No matches', 'gb-query-filter' ) : emptyText
                                )
                        )
                ),
                el(
                    Button,
                    {
                        variant: 'primary',
                        icon: 'plus',
                        __next40pxDefaultSize: true,
                        label: addLabel,
                        disabled: ! picked,
                        // Keep Add focusable while disabled: `accessibleWhenDisabled` on WP 6.6+, the
                        // experimental name before that.
                        accessibleWhenDisabled: true,
                        __experimentalIsFocusable: true,
                        onClick: add,
                    },
                    __( 'Add', 'gb-query-filter' )
                )
            ),
            el( 'div', { className: 'screen-reader-text', role: 'status', 'aria-live': 'polite' }, status )
        );
    }

    /**
     * One configured filter: a name, an optional second line, a small remove button and
     * its control. `warn` replaces the second line when the filter's source is gone.
     */
    function FilterRow( { name, sub, warn, groupLabel, removeLabel, onRemove, children } ) {
        return el(
            'li',
            { className: 'gbqf-row' },
            el(
                'div',
                { role: 'group', 'aria-label': groupLabel },
                el(
                    'div',
                    { className: 'gbqf-row__head' },
                    el(
                        'span',
                        null,
                        el( 'strong', null, name ),
                        warn || sub
                            ? el( 'span', { className: 'gbqf-row__sub' + ( warn ? ' is-warn' : '' ) }, warn || sub )
                            : null
                    ),
                    el( Button, {
                        icon: 'no-alt',
                        size: 'small',
                        className: 'gbqf-row__remove',
                        label: removeLabel,
                        onClick: onRemove,
                    } )
                ),
                children
            )
        );
    }

    const TAXONOMY_CONTROLS = [
        { label: __( 'Checkboxes', 'gb-query-filter' ), value: 'checkboxes' },
        { label: __( 'Select dropdown', 'gb-query-filter' ), value: 'select' },
        { label: __( 'Buttons', 'gb-query-filter' ), value: 'buttons' },
    ];

    const META_BOX_CONTROLS = [
        { label: __( 'Auto (based on options)', 'gb-query-filter' ), value: 'auto' },
        { label: __( 'Select dropdown', 'gb-query-filter' ), value: 'select' },
        { label: __( 'Radio buttons', 'gb-query-filter' ), value: 'radio' },
        { label: __( 'Buttons', 'gb-query-filter' ), value: 'buttons' },
        { label: __( 'Text input', 'gb-query-filter' ), value: 'text' },
    ];

    const ACF_CONTROLS = [
        { label: __( 'Auto (based on field type)', 'gb-query-filter' ), value: 'auto' },
        { label: __( 'Select dropdown', 'gb-query-filter' ), value: 'select' },
        { label: __( 'Radio buttons', 'gb-query-filter' ), value: 'radio' },
        { label: __( 'Buttons', 'gb-query-filter' ), value: 'buttons' },
        { label: __( 'Checkboxes', 'gb-query-filter' ), value: 'checkboxes' },
        { label: __( 'Text input', 'gb-query-filter' ), value: 'text' },
    ];

    registerBlockType( 'gbqf/query-filter', {
        apiVersion: 3,
        title: __( 'GB Query Filter', 'gb-query-filter' ),
        icon: { src: blockIcon },
        category: 'widgets',

        // A newly inserted block starts with every filter and AJAX off. The registered defaults
        // for enableSearch and enableAjax stay true on purpose: blocks saved before this omit an
        // attribute when it equals the default, so flipping either would change all of them.
        variations: [
            {
                name: 'gbqf-query-filter',
                title: __( 'GB Query Filter', 'gb-query-filter' ),
                icon: { src: blockIcon },
                isDefault: true,
                attributes: { enableSearch: false, enableAjax: false },
                scope: [ 'inserter', 'transform' ],
            },
        ],

        attributes: {
            // Target Query Block ID (Element ID, no "#").
            targetId: {
                type: 'string',
                default: '',
            },

            // UI toggles.
            enableSearch: {
                type: 'boolean',
                default: true,
            },

            // Hide the visible search label (it stays for screen readers).
            hideSearchLabel: {
                type: 'boolean',
                default: false,
            },

            // Taxonomy filters: [ { slug, controlType } ]. No default on purpose: absent
            // means a block saved before this attribute existed, which the legacy
            // attributes below still describe.
            taxonomies: {
                type: 'array',
            },

            // Legacy taxonomy attributes. Read only while `taxonomies` is absent.
            enableCategories: {
                type: 'boolean',
                default: false,
            },
            enableTags: {
                type: 'boolean',
                default: false,
            },
            categoriesControlType: {
                type: 'string',
                default: 'checkboxes',
            },
            tagsControlType: {
                type: 'string',
                default: 'checkboxes',
            },
            extraTaxonomiesControlType: {
                type: 'string',
                default: 'checkboxes',
            },
            enableExtraTaxonomies: {
                type: 'boolean',
                default: false,
            },
            // Comma-separated list of additional taxonomies (slugs).
            extraTaxonomies: {
                type: 'string',
                default: '',
            },

            // Space-separated CSS classes for the `buttons` control and actions.
            buttonClass: {
                type: 'string',
                default: '',
            },
            applyClass: {
                type: 'string',
                default: '',
            },
            resetClass: {
                type: 'string',
                default: '',
            },

            // Matches the PHP registration. It was false here, so a block with AJAX switched off in
            // the editor omitted the attribute and the front end rendered AJAX on.
            enableAjax: {
                type: 'boolean',
                default: true,
            },

            // Apply button toggle.
            enableApplyButton: {
                type: 'boolean',
                default: false, // default: manual Apply button.
            },

            // Meta Box integration.
            enableMetaBoxFilter: {
                type: 'boolean',
                default: false,
            },

            // One or more Meta Box field IDs (comma-separated).
            metaBoxFieldId: {
                type: 'string',
                default: '',
            },
            // Repeater-style Meta Box fields with control overrides.
            metaBoxFields: {
                type: 'array',
                default: [],
            },

            // ACF integration.
            enableAcfFilter: {
                type: 'boolean',
                default: false,
            },

            // One or more ACF field names (comma-separated).
            acfFieldId: {
                type: 'string',
                default: '',
            },
            // Repeater-style ACF fields with control overrides.
            acfFields: {
                type: 'array',
                default: [],
            },
        },

        edit( props ) {
            const { attributes, setAttributes, clientId } = props;
            const {
                targetId,
                enableSearch,
                hideSearchLabel,
                taxonomies,
                enableCategories,
                enableTags,
                categoriesControlType,
                tagsControlType,
                extraTaxonomiesControlType,
                enableAjax,
                enableExtraTaxonomies,
                enableApplyButton,
                buttonClass,
                applyClass,
                resetClass,
                extraTaxonomies,
                enableMetaBoxFilter,
                metaBoxFieldId,
                metaBoxFields,
                enableAcfFilter,
                acfFieldId,
                acfFields,
            } = attributes;

            if ( ! metaBoxIntegrationEnabled && enableMetaBoxFilter ) {
                setAttributes( { enableMetaBoxFilter: false } );
            }

            if ( ! acfIntegrationEnabled && enableAcfFilter ) {
                setAttributes( { enableAcfFilter: false } );
            }

            const blockProps = useBlockProps ? useBlockProps() : {};

            const suggestedTargetId =
                targetId ||
                'gbqf-loop-' + ( clientId ? clientId.slice( 0, 8 ) : 'auto' );

            const cleanId = ( value ) => value.replace( /^#+/, '' ).trim();

            // Move focus to a picker after a row is removed; the removed button is gone.
            const focusPicker = ( pickerId ) =>
                window.requestAnimationFrame( () => {
                    const node = document.getElementById( pickerId );
                    if ( node ) {
                        node.focus();
                    }
                } );

            // ----- Taxonomies -----
            const taxonomyOptions = Array.isArray( window.GBQF_TAXONOMIES )
                ? window.GBQF_TAXONOMIES
                : [];

            const taxonomySlugToLabel = {};
            taxonomyOptions.forEach( ( tax ) => {
                taxonomySlugToLabel[ tax.slug ] = tax.label;
            } );

            // A block saved before `taxonomies` existed shows its legacy settings as the list;
            // the first edit writes `taxonomies`, which then wins on the server.
            const legacyTaxonomies = () => {
                const list = [];
                if ( enableCategories ) {
                    list.push( { slug: 'category', controlType: categoriesControlType } );
                }
                if ( enableTags ) {
                    list.push( { slug: 'post_tag', controlType: tagsControlType } );
                }
                if ( enableExtraTaxonomies && extraTaxonomies ) {
                    extraTaxonomies
                        .split( ',' )
                        .map( ( v ) => v.trim().toLowerCase() )
                        .filter( Boolean )
                        .forEach( ( slug ) => list.push( { slug, controlType: extraTaxonomiesControlType } ) );
                }
                return list.filter( ( t, i ) => list.findIndex( ( u ) => u.slug === t.slug ) === i );
            };

            const taxonomyList = Array.isArray( taxonomies ) ? taxonomies : legacyTaxonomies();
            const setTaxonomies = ( next ) => setAttributes( { taxonomies: next } );
            const taxPickerId = 'gbqf-add-taxonomy-' + clientId;

            const taxonomyPickerItems = taxonomyOptions
                .filter( ( tax ) => ! taxonomyList.some( ( t ) => t.slug === tax.slug ) )
                .map( ( tax ) => ( { value: tax.slug, label: tax.label, sub: tax.slug } ) );

            const renderTaxonomyRow = ( tax, index ) => {
                const known = !! taxonomySlugToLabel[ tax.slug ];
                const name = taxonomySlugToLabel[ tax.slug ] || tax.slug;
                return el(
                    FilterRow,
                    {
                        key: 'tax-' + tax.slug,
                        name,
                        sub: known && name !== tax.slug ? tax.slug : '',
                        warn: known ? '' : __( 'No longer exists. Remove it.', 'gb-query-filter' ),
                        groupLabel: sprintf( /* translators: %s: taxonomy name. */ __( '%s filter', 'gb-query-filter' ), name ),
                        removeLabel: sprintf( /* translators: %s: taxonomy name. */ __( 'Remove %s filter', 'gb-query-filter' ), name ),
                        onRemove: () => {
                            setTaxonomies( taxonomyList.filter( ( _, i ) => i !== index ) );
                            focusPicker( taxPickerId );
                        },
                    },
                    el( SelectControl, {
                        __next40pxDefaultSize: true,
                        __nextHasNoMarginBottom: true,
                        label: __( 'Control type', 'gb-query-filter' ),
                        value: tax.controlType || 'checkboxes',
                        options: TAXONOMY_CONTROLS,
                        onChange: ( v ) => {
                            const next = [ ...taxonomyList ];
                            next[ index ] = { ...tax, controlType: v };
                            setTaxonomies( next );
                        },
                    } )
                );
            };

            // ----- Custom fields (Meta Box + ACF share one list) -----
            const metaFieldOptions =
                metaBoxIntegrationEnabled && Array.isArray( window.GBQF_META_FIELDS ) ? window.GBQF_META_FIELDS : [];
            const acfFieldOptions =
                acfIntegrationEnabled && Array.isArray( window.GBQF_ACF_FIELDS ) ? window.GBQF_ACF_FIELDS : [];

            // Which plugin a field comes from only matters when both can supply fields.
            const showSource = metaFieldOptions.length > 0 && acfFieldOptions.length > 0;
            const sourceLabel = { mb: __( 'Meta Box', 'gb-query-filter' ), acf: __( 'ACF', 'gb-query-filter' ) };

            // Initialize the repeater arrays from the legacy CSV if needed.
            const csvToFields = ( csv ) =>
                ( csv || '' )
                    .split( ',' )
                    .map( ( v ) => v.trim() )
                    .filter( Boolean )
                    .map( ( id ) => ( { id, controlType: 'auto' } ) );

            if ( enableMetaBoxFilter && ( ! metaBoxFields || metaBoxFields.length === 0 ) && metaBoxFieldId && metaBoxFieldId.trim() ) {
                const ids = csvToFields( metaBoxFieldId );
                if ( ids.length ) {
                    setAttributes( { metaBoxFields: ids } );
                }
            }

            if ( enableAcfFilter && ( ! acfFields || acfFields.length === 0 ) && acfFieldId && acfFieldId.trim() ) {
                const ids = csvToFields( acfFieldId );
                if ( ids.length ) {
                    setAttributes( { acfFields: ids } );
                }
            }

            // A list is live only while its enable flag is on; fields left behind a false flag
            // are ignored by the server and hidden here.
            const mbLive = enableMetaBoxFilter && metaBoxIntegrationEnabled ? metaBoxFields || [] : [];
            const acfLive = enableAcfFilter && acfIntegrationEnabled ? acfFields || [] : [];

            // Writing a list also sets its enable flag: on while it has fields, off when it empties.
            const syncMetaBoxFields = ( fields ) =>
                setAttributes( {
                    metaBoxFieldId: fields.map( ( f ) => ( f.id || '' ).trim() ).filter( Boolean ).join( ', ' ),
                    metaBoxFields: fields,
                    enableMetaBoxFilter: fields.length > 0,
                } );
            const syncAcfFields = ( fields ) =>
                setAttributes( {
                    acfFieldId: fields.map( ( f ) => ( f.id || '' ).trim() ).filter( Boolean ).join( ', ' ),
                    acfFields: fields,
                    enableAcfFilter: fields.length > 0,
                } );

            const fieldRows = [
                ...mbLive.map( ( field, index ) => ( { src: 'mb', field, index } ) ),
                ...acfLive.map( ( field, index ) => ( { src: 'acf', field, index } ) ),
            ];
            const fieldPickerId = 'gbqf-add-field-' + clientId;

            const fieldPickerItems = [
                ...metaFieldOptions
                    .filter( ( f ) => ! mbLive.some( ( x ) => x.id === f.id ) )
                    .map( ( f ) => ( {
                        value: 'mb:' + f.id,
                        label: f.name || f.id,
                        sub: f.id,
                        tag: showSource ? sourceLabel.mb : '',
                    } ) ),
                ...acfFieldOptions
                    .filter( ( f ) => ! acfLive.some( ( x ) => x.id === f.name ) )
                    .map( ( f ) => ( {
                        value: 'acf:' + f.name,
                        label: f.label || f.name,
                        sub: f.name,
                        tag: showSource ? sourceLabel.acf : '',
                    } ) ),
            ];

            const addField = ( value ) => {
                const colon = value.indexOf( ':' );
                const src = value.slice( 0, colon );
                const entry = { id: value.slice( colon + 1 ), controlType: 'auto' };
                if ( src === 'mb' ) {
                    syncMetaBoxFields( [ ...mbLive, entry ] );
                } else {
                    syncAcfFields( [ ...acfLive, entry ] );
                }
            };

            const renderFieldRow = ( { src, field, index } ) => {
                const found = src === 'mb'
                    ? metaFieldOptions.find( ( f ) => f.id === field.id )
                    : acfFieldOptions.find( ( f ) => f.name === field.id );
                const name = found ? ( src === 'mb' ? found.name : found.label ) || field.id : field.id;
                const subParts = [ name !== field.id ? field.id : '', showSource ? sourceLabel[ src ] : '' ].filter( Boolean );
                const live = src === 'mb' ? mbLive : acfLive;
                const sync = src === 'mb' ? syncMetaBoxFields : syncAcfFields;
                return el(
                    FilterRow,
                    {
                        key: src + '-' + index + '-' + field.id,
                        name,
                        sub: subParts.join( ' · ' ),
                        warn: found ? '' : __( 'No longer exists. Remove it.', 'gb-query-filter' ),
                        groupLabel: sprintf( /* translators: %s: custom field name. */ __( '%s field filter', 'gb-query-filter' ), name ),
                        removeLabel: sprintf( /* translators: %s: custom field name. */ __( 'Remove %s field filter', 'gb-query-filter' ), name ),
                        onRemove: () => {
                            sync( live.filter( ( _, i ) => i !== index ) );
                            focusPicker( fieldPickerId );
                        },
                    },
                    el( SelectControl, {
                        __next40pxDefaultSize: true,
                        __nextHasNoMarginBottom: true,
                        label: __( 'Control type', 'gb-query-filter' ),
                        value: field.controlType || 'auto',
                        options: src === 'mb' ? META_BOX_CONTROLS : ACF_CONTROLS,
                        onChange: ( v ) => {
                            const next = [ ...live ];
                            next[ index ] = { ...field, controlType: v };
                            sync( next );
                        },
                    } )
                );
            };

            // Show "Button Styles" only when a control renders as buttons or Apply is on.
            const usesButtons =
                taxonomyList.some( ( t ) => t.controlType === 'buttons' ) ||
                mbLive.some( ( f ) => f.controlType === 'buttons' ) ||
                acfLive.some( ( f ) => f.controlType === 'buttons' );

            const classHelp = __( 'Space-separated CSS class names', 'gb-query-filter' );
            const classBox = ( label, key, help ) =>
                el( TextControl, {
                    __next40pxDefaultSize: true,
                    __nextHasNoMarginBottom: true,
                    label: label,
                    value: attributes[ key ],
                    onChange: ( v ) => setAttributes( { [ key ]: v } ),
                    help: help || classHelp,
                    className: 'gbqf-control-full',
                } );

            let updatesSummary;
            if ( enableApplyButton ) {
                updatesSummary = enableAjax
                    ? __( 'Visitors click Apply, and results update without a page reload.', 'gb-query-filter' )
                    : __( 'Visitors click Apply, and the page reloads with the filtered results.', 'gb-query-filter' );
            } else {
                updatesSummary = enableAjax
                    ? __( 'Results update without a page reload as soon as a visitor makes a selection. Search and text fields still need Enter.', 'gb-query-filter' )
                    : __( 'The page reloads as soon as a visitor makes a selection. Search and text fields still need Enter.', 'gb-query-filter' );
            }

            const noIntegration = ! metaBoxIntegrationEnabled && ! acfIntegrationEnabled;

            return el(
                Fragment,
                null,

                el(
                    InspectorControls,
                    null,
                    el(
                        PanelBody,
                        // Open until a target is set; after that it is configured and just in the way.
                        { title: __( 'Link Filter to GB Query', 'gb-query-filter' ), initialOpen: ! targetId },
                        el( 'div', { className: 'gbqf-control-group gbqf-control-group--advanced' },
                            el( TextControl, {
                                __next40pxDefaultSize: true,
                                __nextHasNoMarginBottom: true,
                                label: __( 'Target Query Block ID', 'gb-query-filter' ),
                                value: targetId,
                                onChange: ( v ) => setAttributes( { targetId: cleanId( v ) } ),
                                placeholder: __( 'e.g. projects-loop', 'gb-query-filter' ),
                                className: 'gbqf-control-full',
                            } )
                        ),
                        el(
                            'div',
                            { className: 'gbqf-control-group gbqf-control-group--advanced' },
                            el(
                                'p',
                                { className: 'gbqf-control-helper' },
                                __(
                                    'Copy this auto-generated ID and paste it into the GenerateBlocks Query Loop HTML Anchor.',
                                    'gb-query-filter'
                                )
                            ),
                            el(
                                'div',
                                { className: 'gbqf-suggested-id' },
                                el( 'code', null, suggestedTargetId ),
                                el(
                                    Button,
                                    {
                                        variant: 'secondary',
                                        onClick: async () => {
                                            const cleanIdVal = cleanId( suggestedTargetId );
                                            setAttributes( { targetId: cleanIdVal } );
                                            if ( navigator.clipboard && navigator.clipboard.writeText ) {
                                                try {
                                                    await navigator.clipboard.writeText( cleanIdVal );
                                                } catch ( e ) {
                                                    // Ignore clipboard errors.
                                                }
                                            }
                                        },
                                        style: { marginLeft: '10px' },
                                    },
                                    __( 'Copy ID', 'gb-query-filter' )
                                )
                            )
                        )
                    ),

                    el(
                        PanelBody,
                        // Every panel below starts open when populated or set away from its default.
                        // New blocks start with search off (see `variations`), so on counts as populated.
                        { title: __( 'Search', 'gb-query-filter' ), initialOpen: !! enableSearch },
                        el( ToggleControl, {
                            __nextHasNoMarginBottom: true,
                            label: __( 'Enable search filter', 'gb-query-filter' ),
                            checked: enableSearch,
                            onChange: ( checked ) => setAttributes( { enableSearch: checked } ),
                            help: __( 'A text box that searches post titles and content.', 'gb-query-filter' ),
                        } ),
                        enableSearch && el( ToggleControl, {
                            __nextHasNoMarginBottom: true,
                            label: __( 'Hide search label', 'gb-query-filter' ),
                            checked: !! hideSearchLabel,
                            onChange: ( checked ) => setAttributes( { hideSearchLabel: checked } ),
                            help: __( 'Shows "Search" as placeholder text instead of a visible label. Screen readers still announce the label. The placeholder must have 4.5:1 contrast on its background; your theme controls that.', 'gb-query-filter' ),
                        } )
                    ),

                    el(
                        PanelBody,
                        { title: __( 'Taxonomies', 'gb-query-filter' ), initialOpen: taxonomyList.length > 0 },
                        taxonomyList.length
                            ? el( 'ul', { className: 'gbqf-rows' }, taxonomyList.map( renderTaxonomyRow ) )
                            : el( 'p', { className: 'gbqf-control-helper' }, __( 'No taxonomies added.', 'gb-query-filter' ) ),
                        el( Picker, {
                            id: taxPickerId,
                            label: __( 'Add taxonomy', 'gb-query-filter' ),
                            addLabel: __( 'Add taxonomy', 'gb-query-filter' ),
                            items: taxonomyPickerItems,
                            placeholder: __( 'Search…', 'gb-query-filter' ),
                            emptyText: __( 'Every public taxonomy is already added', 'gb-query-filter' ),
                            onAdd: ( slug ) => setTaxonomies( [ ...taxonomyList, { slug, controlType: 'checkboxes' } ] ),
                        } )
                    ),

                    el(
                        PanelBody,
                        { title: __( 'Custom Fields', 'gb-query-filter' ), initialOpen: fieldRows.length > 0 },
                        noIntegration
                            ? el(
                                  'p',
                                  { className: 'gbqf-control-helper' },
                                  __( 'Turn on the Meta Box or ACF integration in the Query Filters settings to filter on custom fields.', 'gb-query-filter' ),
                                  settingsUrl ? ' ' : null,
                                  settingsUrl
                                      ? el( 'a', { href: settingsUrl, target: '_blank', rel: 'noopener noreferrer' }, __( 'Open settings', 'gb-query-filter' ) )
                                      : null
                              )
                            : el(
                                  Fragment,
                                  null,
                                  fieldRows.length
                                      ? el( 'ul', { className: 'gbqf-rows' }, fieldRows.map( renderFieldRow ) )
                                      : el( 'p', { className: 'gbqf-control-helper' }, __( 'No custom fields added.', 'gb-query-filter' ) ),
                                  el( Picker, {
                                      id: fieldPickerId,
                                      label: __( 'Add custom field', 'gb-query-filter' ),
                                      addLabel: __( 'Add custom field', 'gb-query-filter' ),
                                      items: fieldPickerItems,
                                      placeholder: __( 'Search fields…', 'gb-query-filter' ),
                                      emptyText: __( 'Every discovered field is already added', 'gb-query-filter' ),
                                      onAdd: addField,
                                  } )
                              )
                    ),

                    el(
                        PanelBody,
                        { title: __( 'Result Updates', 'gb-query-filter' ), initialOpen: enableApplyButton || enableAjax },
                        el( RadioControl, {
                            label: __( 'When filters apply', 'gb-query-filter' ),
                            selected: enableApplyButton ? 'apply' : 'change',
                            options: [
                                {
                                    label: createInterpolateElement(
                                        __( 'When <em>Apply</em> is clicked', 'gb-query-filter' ),
                                        { em: el( 'em' ) }
                                    ),
                                    value: 'apply',
                                },
                                { label: __( 'Immediately on selection', 'gb-query-filter' ), value: 'change' },
                            ],
                            onChange: ( value ) => setAttributes( { enableApplyButton: value === 'apply' } ),
                        } ),
                        el( ToggleControl, {
                            __nextHasNoMarginBottom: true,
                            label: __( 'Update results without page reload', 'gb-query-filter' ),
                            checked: enableAjax,
                            onChange: ( checked ) => setAttributes( { enableAjax: checked } ),
                        } ),
                        el( 'p', { className: 'gbqf-note', role: 'status' }, updatesSummary )
                    ),

                    ( usesButtons || enableApplyButton ) &&
                        el(
                            PanelBody,
                            { title: __( 'Button Styles', 'gb-query-filter' ), initialOpen: !! ( buttonClass || applyClass || resetClass ) },
                            usesButtons &&
                                classBox(
                                    __( 'Button class', 'gb-query-filter' ),
                                    'buttonClass',
                                    __(
                                        'Space-separated CSS class names (e.g. GenerateBlocks styles). Style the selected option with :has(input:checked).',
                                        'gb-query-filter'
                                    )
                                ),
                            enableApplyButton && classBox( __( 'Apply button class', 'gb-query-filter' ), 'applyClass' ),
                            classBox( __( 'Reset link class', 'gb-query-filter' ), 'resetClass' )
                        ),
                ),

                // BLOCK PREVIEW IN EDITOR: render front-end HTML.
                // blockProps must live on the outer wrapper for apiVersion 3 (iframe editor).
                el(
                    'div',
                    {
                        ...blockProps,
                        className: ( blockProps.className ? blockProps.className + ' ' : '' ) + 'gbqf-debug-test gbqf-editor-root',
                    },
                    ServerSideRender
                        ? el( ServerSideRender, {
                            block: 'gbqf/query-filter',
                            attributes,
                        } )
                        : __(
                            'Preview unavailable (ServerSideRender not present).',
                            'gb-query-filter'
                        )
                )
            );
        },

        save() {
            return null;
        },
    } );
} )( window.wp );
