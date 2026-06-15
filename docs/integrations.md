# Meta Box & ACF Integrations

Both integrations are optional, enabled per-plugin via the admin settings page, and surface custom fields as filter controls. Selected values flow through the unified `gbqf_meta[field_name]=value` URL namespace regardless of source — the field's source (Meta Box vs ACF) is determined internally from the block configuration.

## Meta Box

Active only when the Meta Box plugin is present and the integration is enabled.

- **Field discovery:** queries the Meta Box registries `rwmb_get_registry('meta_box')` and `rwmb_get_registry('field')`; falls back to `rwmb_get_field_settings()` when a registry lookup fails.
- **Control type:** `auto` (detect from `options`), `select`, `radio`, or `text`. A field with `options` defined renders as select/radio; otherwise a text input.
- **Query filtering:** applied via `meta_query` with exact value matching.

## ACF

Active only when ACF is present and the integration is enabled.

- **Field discovery:** `acf_get_field_groups()` then `acf_get_fields($group['key'])`.
- **Field identification:** uses the ACF field **name** (e.g. `project_color`), not the field key (`field_abc123`), for stability across import/export.
- **Control type:** `auto`, `select`, `radio`, `checkboxes`, or `text`.

Auto-detection:

| ACF field type | Detected control |
|----------------|------------------|
| `select`, `button_group` | `select` if it has choices, else `text` |
| `checkbox` | `checkboxes` if it has choices, else `text` |
| `radio` | `radio` if it has choices, else `text` |
| `true_false` | `radio` with Yes/No/Any |
| `taxonomy`, `post_object`, `relationship`, `user` | `select` |
| `date_picker`, `number`, `text` | `text` |

- **Checkbox handling:** ACF checkboxes allow multiple selections, sent as URL arrays (`?gbqf_meta[colors][]=red&gbqf_meta[colors][]=blue`). ACF stores checkbox values as serialized PHP arrays, so filtering uses a `LIKE` comparison against serialized value patterns.
- **Query filtering:** via `meta_query`. Single values use exact matching; checkbox arrays use `LIKE` against the serialized values.

## Edge case

If the same field name is configured in both integrations on one block, both code paths process it. This is not specially handled — avoid configuring a field name in both.
