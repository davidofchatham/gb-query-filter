# Developer Filters & Compatibility

GB Query Filter is designed to coexist with other plugins that modify GenerateBlocks Query Loop queries. It runs at hook priority 20 (after the default 10) so it merges on top of other plugins' query modifications rather than overwriting them.

## Available Filters

| Filter | Type | Default | Purpose |
|--------|------|---------|---------|
| `gbqf_filter_priority` | int | `20` | Hook priority. Higher runs later. |
| `gbqf_filter_scope` | string | `'targeted'` | `'targeted'` (match by HTML ID/class) or `'all'` (every Query Loop, legacy). |
| `gbqf_preserve_search` | bool | `false` | Combine GBQF search with search terms from other plugins instead of replacing. |
| `gbqf_should_apply_to_block` | bool\|null | — | Per-block override; receives `$attributes`. |
| `gbqf_enable_debug_logging` | bool | `false` | Log query args (PHP `error_log`) and enable browser console output. Also settable via the admin page, which writes the `gbqf_enable_debug_logging` option; the filter overrides the option. |

## Per-Block Data Attributes

Query Loop blocks can override global settings (Query Loop block → Advanced → HTML attributes):

- `data-gbqf-enabled="true|false"` — force enable/disable filtering for this block
- `data-gbqf-scope="all|targeted"` — override scope for this block
- `data-gbqf-priority="15"` — override priority for this block (advanced)

## Query Argument Merging

Existing query modifications are preserved, not clobbered:

- `tax_query` — new clauses appended, existing relation preserved
- `meta_query` — new clauses appended, existing relation preserved
- `category__in` / `tag__in` — merged with existing IDs (`array_merge`)
- Search (`s`) — replaced by default; combined with a space delimiter when `gbqf_preserve_search` is enabled

When two plugins each add `tax_query` clauses, both apply (AND/OR per the existing relation). Enable debug logging to inspect query args before and after each plugin's modifications.

## Examples

**Targeted mode (default).** Set `targetId` on the filter block (e.g. `my-projects`), set the same value as the HTML ID on the Query Loop block. The filter then applies only to that loop.

**Switch to all mode:**
```php
add_filter( 'gbqf_filter_scope', function () {
    return 'all';
} );
```

**Merge search terms from other plugins:**
```php
add_filter( 'gbqf_preserve_search', '__return_true' );
```

**Adjust priority:**
```php
add_filter( 'gbqf_filter_priority', function () {
    return 5;  // run earlier; or 30 to run later
} );
```

**Per-block control — skip blocks with a `no-gbqf` class:**
```php
add_filter( 'gbqf_should_apply_to_block', function ( $should_apply, $attributes ) {
    if ( isset( $attributes['className'] )
        && strpos( $attributes['className'], 'no-gbqf' ) !== false ) {
        return false;
    }
    return $should_apply;
}, 10, 2 );
```

**Enable debug logging:**
```php
add_filter( 'gbqf_enable_debug_logging', '__return_true' );
```
