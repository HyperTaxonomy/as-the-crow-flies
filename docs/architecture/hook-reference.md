---
title: Hook Reference
parent: Architecture
nav_order: 3
---

# Hook Reference

Where As the Crow Flies registers its callbacks, and the hooks it fires for other code. This page is generated from the code. Hook names that are built at runtime are shown as the PHP expression that builds them. For how to use the hooks As the Crow Flies fires, see the [Developer Reference](../developer-reference/).

## Callbacks registered

### Plugin root

**`as-the-crow-flies.php`**

| Hook | Callback | Priority |
|---|---|---|
| `init` | `*(anonymous function)*` | 10 |
| `'plugin_action_links_' . plugin_basename( __FILE__ )` | `atcf_plugin_action_links` | 10 |
| `plugins_loaded` | `atcf_init` | 10 |
| `atcf_location_changed` | `ATCF_Admin::flush_location_caches` | 10 |
| `deleted_term` | `*(anonymous function)*` | 10 |
| `delete_term_taxonomy` | `*(anonymous function)*` | 10 |

### Includes

**`includes/class-admin.php`**

| Hook | Callback | Priority |
|---|---|---|
| `admin_enqueue_scripts` | `ATCF_Admin::enqueue_assets` | 10 |
| `registered_taxonomy` | `ATCF_Admin::hook_term_edit` | 10 |
| `registered_taxonomy` | `ATCF_Admin::hook_term_list` | 10 |
| `terms_clauses` | `ATCF_Admin::sort_terms_by_locations` | 10 |
| `wp_ajax_atcf_clear_term_locations` | `ATCF_Admin::ajax_clear_term_locations` | 10 |
| `wp_ajax_atcf_upload_term_csv` | `ATCF_Admin::ajax_upload_term_csv` | 10 |
| `wp_ajax_atcf_geocode_location` | `ATCF_Admin::ajax_geocode_location` | 10 |
| `wp_ajax_atcf_geocode_term` | `ATCF_Admin::ajax_geocode_term` | 10 |
| `wp_ajax_atcf_save_location_inline` | `ATCF_Admin::ajax_save_location_inline` | 10 |
| `wp_ajax_atcf_clear_location_coords` | `ATCF_Admin::ajax_clear_location_coords` | 10 |
| `wp_ajax_atcf_clear_term_coords` | `ATCF_Admin::ajax_clear_term_coords` | 10 |
| `wp_ajax_atcf_gl_clear_all_coords` | `ATCF_Admin::ajax_gl_clear_all_coords` | 10 |
| `wp_ajax_atcf_gl_delete_all` | `ATCF_Admin::ajax_gl_delete_all` | 10 |
| `"{$taxonomy}_edit_form_fields"` | `ATCF_Admin::render_term_edit_panel` | 99 |
| `"manage_edit-{$taxonomy}_columns"` | `ATCF_Admin::add_locations_column` | 99 |
| `"manage_{$taxonomy}_custom_column"` | `ATCF_Admin::render_locations_column` | 99 |
| `"manage_edit-{$taxonomy}_sortable_columns"` | `ATCF_Admin::make_locations_column_sortable` | 99 |
| `"restrict_manage_{$taxonomy}"` | `ATCF_Admin::render_locations_filter` | 99 |
| `default_hidden_columns` | `*(anonymous function)*` | 10 |
| `parse_term_query` | `ATCF_Admin::apply_locations_filter` | 10 |

**`includes/class-blocks.php`**

| Hook | Callback | Priority |
|---|---|---|
| `block_categories_all` | `ATCF_Blocks::add_block_category` | 10 |
| `init` | `ATCF_Blocks::register_blocks` | 10 |
| `enqueue_block_editor_assets` | `ATCF_Blocks::enqueue_editor_data` | 10 |

**`includes/class-geocoder.php`**

| Hook | Callback | Priority |
|---|---|---|
| `atcf_geocode_pending` | `ATCF_Geocoder::run_batch` | 10 |

**`includes/class-ht-integration.php`**

| Hook | Callback | Priority |
|---|---|---|
| `htax_before_merge_terms` | `ATCF_HT_Integration::snapshot_before_merge` | 10 |
| `htax_after_merge_terms` | `ATCF_HT_Integration::sync_after_merge` | 10 |

**`includes/class-locations-global.php`**

| Hook | Callback | Priority |
|---|---|---|
| `admin_menu` | `ATCF_Locations_Global::add_menu` | 15 |
| `admin_post_atcf_export_all_csv` | `ATCF_Locations_Global::handle_export` | 10 |
| `admin_post_atcf_import_global_csv` | `ATCF_Locations_Global::handle_import` | 10 |
| `admin_post_atcf_download_global_template` | `ATCF_Locations_Global::handle_template` | 10 |
| `admin_post_atcf_geocode_now` | `ATCF_Locations_Global::handle_geocode` | 10 |
| `admin_post_atcf_gl_delete_location` | `ATCF_Locations_Global::handle_delete` | 10 |
| `admin_post_atcf_gl_bulk_delete` | `ATCF_Locations_Global::handle_bulk_delete` | 10 |
| `"load-{$hookname}"` | `ATCF_Locations_Page::register_screen_options` | 10 |

**`includes/class-locations-page.php`**

| Hook | Callback | Priority |
|---|---|---|
| `admin_menu` | `ATCF_Locations_Page::register_page` | 10 |
| `load-admin_page_atcf-locations` | `ATCF_Locations_Page::set_menu_state` | 10 |
| `admin_post_atcf_save_location` | `ATCF_Locations_Page::handle_save` | 10 |
| `admin_post_atcf_delete_location` | `ATCF_Locations_Page::handle_delete` | 10 |
| `admin_post_atcf_download_term_template` | `ATCF_Locations_Page::handle_template_download` | 10 |
| `admin_post_atcf_export_term_csv` | `ATCF_Locations_Page::handle_export` | 10 |
| `admin_post_atcf_bulk_locations` | `ATCF_Locations_Page::handle_bulk` | 10 |
| `parent_file` | `*(anonymous function)*` | 999 |
| `submenu_file` | `*(anonymous function)*` | 999 |
| `"manage_{$screen->id}_columns"` | `ATCF_Locations_Page::screen_columns` | 10 |

**`includes/class-rest-api.php`**

| Hook | Callback | Priority |
|---|---|---|
| `rest_api_init` | `ATCF_REST_API::register_routes` | 10 |

**`includes/class-settings-page.php`**

| Hook | Callback | Priority |
|---|---|---|
| `admin_menu` | `ATCF_Settings_Page::add_menu` | 10 |
| `admin_init` | `ATCF_Settings_Page::register_settings` | 10 |
| `admin_post_atcf_reimport_us` | `ATCF_Settings_Page::handle_reimport_us` | 10 |
| `admin_post_atcf_download_census` | `ATCF_Settings_Page::handle_download_census` | 10 |
| `admin_post_atcf_upload_zcta` | `ATCF_Settings_Page::handle_upload_zcta` | 10 |
| `admin_post_atcf_upload_geonames` | `ATCF_Settings_Page::handle_upload_geonames` | 10 |
| `admin_post_atcf_delete_country` | `ATCF_Settings_Page::handle_delete_country` | 10 |
| `admin_post_atcf_toggle_country` | `ATCF_Settings_Page::handle_toggle_country` | 10 |
| `admin_post_atcf_toggle_us` | `ATCF_Settings_Page::handle_toggle_us` | 10 |

**`includes/class-shortcodes.php`**

| Hook | Callback | Priority |
|---|---|---|
| `wp_enqueue_scripts` | `ATCF_Shortcodes::enqueue_frontend_assets` | 10 |

**`includes/class-wp-query.php`**

| Hook | Callback | Priority |
|---|---|---|
| `pre_get_posts` | `ATCF_WP_Query::handle_atcf_query` | 10 |
| `posts_orderby` | `ATCF_WP_Query::handle_orderby` | 10 |
| `the_posts` | `ATCF_WP_Query::cleanup` | 10 |

## Hooks fired

Actions and filters As the Crow Flies fires for other code to use.

| Hook | Type | File | Description |
|---|---|---|---|
| `atcf_taxonomy_enabled` | filter | `includes/class-admin.php` | Filters whether ATCF UI is enabled for a taxonomy. |
| `atcf_location_changed` | action | `includes/class-admin.php` | — |
| `atcf_term_location_changed` | action | `includes/class-admin.php` | — |
| `atcf_term_ids_within_radius` | filter | `includes/class-distance.php` | Filters the resolved term ID list. |
| `atcf_cdn_location` | filter | `includes/class-distance.php` | Filter the visitor location taken from CDN request headers. |
| `atcf_ip_headers` | filter | `includes/class-distance.php` | — |
| `atcf_term_locations_for_rest` | filter | `includes/class-rest-api.php` | Filters the raw location rows used to build the htax_locations REST field. |
