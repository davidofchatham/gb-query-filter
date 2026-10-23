(function () {
    function initGBQFFilters() {
        var filterBlocks = document.querySelectorAll('.gbqf-query-filter-block');

        if (!filterBlocks.length) {
            return;
        }

        filterBlocks.forEach(function (filterEl) {
            var targetId = filterEl.getAttribute('data-gbqf-target-id') || '';
            var autoApplyFlag = filterEl.getAttribute('data-gbqf-auto-apply');
            var autoApply = autoApplyFlag === '1';
            var ajaxEnabled = filterEl.getAttribute('data-gbqf-enable-ajax') !== '0';

            var targetQueryEl = null;
            var ajaxSubmit = null;
            var resetLink = null;

            if (targetId) {
                // If targetId starts with 'gb-query-', it is a GenerateBlocks unique class —
                // try class selector first, then fall back to ID.
                // Otherwise, try ID first (custom anchor), then class as fallback.
                if (targetId.indexOf('gb-query-') === 0) {
                    targetQueryEl = document.querySelector('.' + targetId)
                                 || document.querySelector('#' + targetId);
                } else {
                    targetQueryEl = document.querySelector('#' + targetId)
                                 || document.querySelector('.' + targetId);
                }

                if (targetQueryEl) {
                    if (window.GBQF_DEBUG) {
                        console.log('GBQF: Linked filter block → Query Loop:', {
                            filterBlock: filterEl,
                            targetId: targetId,
                            queryElement: targetQueryEl,
                            autoApply: autoApply
                        });
                    }
                } else {
                    if (window.GBQF_DEBUG) {
                        console.warn(
                            'GBQF: Target ID set but no matching element found on the page.',
                            { filterBlock: filterEl, targetId: targetId }
                        );
                    }
                }
            } else {
                if (window.GBQF_DEBUG) {
                    console.warn(
                        'GBQF: No target ID set for this filter block. Filtering will still work via URL, but Query Loop is not explicitly linked.',
                        { filterBlock: filterEl }
                    );
                }
            }

            /**
             * Returns true if a URLSearchParams key belongs to THIS filter block.
             * Handles both scoped (gbqf[targetId][...]) and flat (gbqf_*) formats.
             */
            function isOwnGbqfKey( key ) {
                return targetId
                    ? key.indexOf( 'gbqf[' + targetId + ']' ) === 0
                    : key.indexOf( 'gbqf_' ) === 0;
            }

            /**
             * Removes all GBQF params belonging to this filter block from a URLSearchParams.
             * Mutates params in place.
             */
            function removeOwnGbqfParams( params ) {
                var keysToDelete = [];
                params.forEach( function ( _v, key ) {
                    if ( isOwnGbqfKey( key ) ) {
                        keysToDelete.push( key );
                    }
                } );
                keysToDelete
                    .filter( function ( v, i, a ) { return a.indexOf( v ) === i; } )
                    .forEach( function ( key ) { params.delete( key ); } );
            }

            /**
             * Removes the target Query's pagination param (query-{uniqueId}-page) so a
             * filter change or Reset lands on page 1 instead of a page that may no
             * longer exist. The uniqueId comes from the target's own gb-query-{id}
             * class (works for custom-anchor targets too); with no such class, every
             * query-*-page key goes. Other Queries' pagination is otherwise untouched.
             * Mutates params in place.
             */
            function removePageParams( params ) {
                // GB uniqueIds are 8 characters; a longer or shorter gb-query-* class is a
                // user class, and more than one match is ambiguous. Either way, strip all.
                var ids = [];
                if ( targetQueryEl ) {
                    Array.prototype.forEach.call( targetQueryEl.classList, function ( cls ) {
                        var m = /^gb-query-([A-Za-z0-9]{8})$/.exec( cls );
                        if ( m ) {
                            ids.push( m[1] );
                        }
                    } );
                }
                var pageKey = 1 === ids.length ? 'query-' + ids[0] + '-page' : null;
                if ( pageKey ) {
                    params.delete( pageKey );
                    return;
                }
                var keys = [];
                params.forEach( function ( _v, key ) {
                    // Mirrors Params::PAGE_KEY_PATTERN in class-gbqf-params.php.
                    if ( /^query(?:-.+)?-page$/.test( key ) ) {
                        keys.push( key );
                    }
                } );
                keys.forEach( function ( key ) { params.delete( key ); } );
            }

            // IMPORTANT: Do NOT bail out if targetQueryEl is missing.
            // Auto-apply and Enter-to-submit should still work because they
            // only submit the form and rely on PHP/pre_get_posts + URL params.
            var form = filterEl.querySelector('form.gbqf-filter-form');
            if (form) {
                resetLink = form.querySelector('.gbqf-filter-reset');
            }

            var toggleResetVisibility = function () {
                if (!resetLink) {
                    return;
                }
                var formData = new FormData(form);
                var hasValue = false;
                formData.forEach(function (value) {
                    if (value !== null && value !== undefined && String(value).trim() !== '') {
                        hasValue = true;
                    }
                });
                if (hasValue) {
                    resetLink.classList.remove('is-hidden');
                } else {
                    resetLink.classList.add('is-hidden');
                }
            };

            // Recompute this block's reset href from the live URL so it removes only
            // this block's params, preserving any other scoped filters in the URL.
            // Called after AJAX replaceState (on all blocks via GBQF_resetUpdaters).
            var updateResetUrl = function () {
                if (!resetLink) {
                    return;
                }
                var currentUrl = new URL(window.location.href);
                // Strip any URL fragment that may have been introduced by double-encoded
                // ampersands (e.g. &#038; decoded by the browser as a fragment marker).
                currentUrl.hash = '';
                var params = new URLSearchParams(currentUrl.search);
                removeOwnGbqfParams( params );
                removePageParams( params );
                currentUrl.search = params.toString();
                resetLink.setAttribute('href', currentUrl.toString());
            };

            // Register so every AJAX submit on any block refreshes all reset links.
            window.GBQF_resetUpdaters = window.GBQF_resetUpdaters || [];
            window.GBQF_resetUpdaters.push(updateResetUrl);

            // PHP renders the Reset href without knowing the Query's uniqueId, so it
            // clears every pagination key. Narrow it to this target's key now.
            updateResetUrl();

            // If we have a target element and fetch support, use AJAX to swap results.
            if (ajaxEnabled && form && targetQueryEl && window.fetch && window.DOMParser) {
                ajaxSubmit = function (event) {
                    if (event) {
                        event.preventDefault();
                    }

                    var formData = new FormData(form);
                    var requestUrl = new URL(window.location.href);
                    // Strip any URL fragment that may have been introduced by double-encoded
                    // ampersands (e.g. &#038; decoded by the browser as a fragment marker).
                    requestUrl.hash = '';
                    var params = new URLSearchParams(requestUrl.search); // Start with EXISTING URL params

                    // Remove stale params for THIS filter block only. A previous page-reload
                    // may have left indexed keys (e.g. gbqf[id][tax][foo][0]=31) that differ
                    // from the form's [] keys and would prevent clearing the filter via AJAX.
                    removeOwnGbqfParams( params );

                    // A new filter set starts at page 1; a stale page key can point past the
                    // end of the new results and render an empty loop.
                    removePageParams( params );

                    // Append fresh form values. Use append (not set) so multiple checkbox
                    // values with the same name are all included. Skip empty strings so
                    // cleared text inputs and "any" select options don't pollute the URL.
                    formData.forEach(function (value, key) {
                        if (String(value).trim() !== '') {
                            params.append(key, value);
                        }
                    });

                    requestUrl.search = params.toString();

                    if (targetQueryEl) {
                        targetQueryEl.classList.add('gbqf-loading');
                    }

                    fetch(requestUrl.toString(), {
                        headers: { 'X-Requested-With': 'XMLHttpRequest' }
                    })
                        .then(function (resp) {
                            return resp.text();
                        })
                        .then(function (html) {
                            var parser = new DOMParser();
                            var doc = parser.parseFromString(html, 'text/html');
                            var fresh = targetId.indexOf('gb-query-') === 0
                                ? (doc.querySelector('.' + targetId) || doc.querySelector('#' + targetId))
                                : (doc.querySelector('#' + targetId) || doc.querySelector('.' + targetId));

                            if (fresh && targetQueryEl) {
                                targetQueryEl.innerHTML = fresh.innerHTML;
                            }

                            if (window.history && window.history.replaceState) {
                                window.history.replaceState({}, '', requestUrl.toString());
                            }

                            // Refresh reset href on ALL filter blocks so each one
                            // removes only its own params from the now-updated URL.
                            if (window.GBQF_resetUpdaters) {
                                window.GBQF_resetUpdaters.forEach(function (fn) { fn(); });
                            }

                            toggleResetVisibility();
                        })
                        .catch(function () {
                            form.submit();
                        })
                        .finally(function () {
                            if (targetQueryEl) {
                                targetQueryEl.classList.remove('gbqf-loading');
                            }
                        });
                };

                form.addEventListener('submit', ajaxSubmit);
            }

            // Buttons control: swap the inactive/active class sets (data attributes,
            // already sanitized by PHP) on every label of the group. Independent of
            // auto-apply; a reload re-renders the server-side state.
            if (form) {
                form.querySelectorAll('.gbqf-filter-buttons').forEach(function (group) {
                    var tokens = function (attr) {
                        return (group.getAttribute(attr) || '').split(/\s+/).filter(Boolean);
                    };
                    var inactive = tokens('data-gbqf-class');
                    var active = tokens('data-gbqf-active-class');

                    group.addEventListener('change', function () {
                        group.querySelectorAll('label').forEach(function (label) {
                            var input = label.querySelector('input');
                            inactive.concat(active).forEach(function (cls) { label.classList.remove(cls); });
                            (input && input.checked ? active : inactive).forEach(function (cls) { label.classList.add(cls); });
                        });
                    });
                });
            }

            if (autoApply) {
                if (!form) {
                    return;
                }

                var submitForm = function () {
                    if (ajaxSubmit) {
                        ajaxSubmit();
                        return;
                    }
                    form.submit();
                };

                // Auto-apply for checkboxes / radios.
                var toggles = form.querySelectorAll('input[type="checkbox"], input[type="radio"]');
                toggles.forEach(function (input) {
                    input.addEventListener('change', submitForm);
                    input.addEventListener('change', toggleResetVisibility);
                });

                // Auto-apply for selects (e.g. Meta Box select field).
                var selects = form.querySelectorAll('select');
                selects.forEach(function (select) {
                    select.addEventListener('change', submitForm);
                    select.addEventListener('change', toggleResetVisibility);
                });

                // Text inputs (search, Meta Box text fallback, etc.): submit on Enter.
                var textInputs = form.querySelectorAll(
                    'input[type="text"], input[type="search"], input[type="email"], input[type="number"], input[type="url"]'
                );

                textInputs.forEach(function (input) {
                    input.addEventListener('keydown', function (event) {
                        var key = event.key || event.keyCode;
                        if (key === 'Enter' || key === 'Return' || key === 13) {
                            event.preventDefault();
                            submitForm();
                        }
                    });
                    input.addEventListener('input', toggleResetVisibility);
                });

                // NOTE: no auto-submit on every keystroke; only on change/Enter.
            }

            if (form) {
                toggleResetVisibility();
            }
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initGBQFFilters);
    } else {
        initGBQFFilters();
    }
})();
