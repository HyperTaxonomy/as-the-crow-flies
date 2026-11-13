---
title: Hook Reference
parent: Architecture
nav_order: 3
---

# Hook Reference
{: .no_toc }

The hook registrations in As the Crow Flies that you can't guess from a callback's name: priorities chosen to run before or after other code, hooks registered per taxonomy, and the HyperTaxonomy hooks it uses. Routine registrations (menus, scripts, AJAX and REST handlers, shortcodes, blocks, notices) are left out; search the code for `add_action( 'hook_name'` to find any of them.

The hooks As the Crow Flies fires for other code are documented in the Developer Reference: [Actions & Filters](../developer-reference/actions-filters.html).

## Table of contents
{: .no_toc .text-delta }

1. TOC
{:toc}

---

## Loading

`atcf_init()` registers every component on `plugins_loaded`. Each component class has a static `register()` method that adds its hooks.

## Per-taxonomy registration

`ATCF_Admin` adds its term screen hooks on `registered_taxonomy`, once for each taxonomy as it is registered, so taxonomies that plugins and themes register later are covered too. The callbacks check whether locations are enabled for the taxonomy when they run.

| Hook | Callback | Priority | Why |
|---|---|---|---|
| `{$taxonomy}_edit_form_fields` | `ATCF_Admin::render_term_edit_panel` | 99 | Last on the Edit Term screen, after HyperTaxonomy's and TermConnect's fields. Not on the Add Term form |
| `manage_edit-{$taxonomy}_columns`, `…_sortable_columns`, `manage_{$taxonomy}_custom_column` | Locations column | 99 | After the columns of HyperTaxonomy and other plugins |
| `restrict_manage_{$taxonomy}` | `ATCF_Admin::render_locations_filter` | 99 | After other plugins' term list filters |

Sorting terms by location count uses one `terms_clauses` filter for all taxonomies.

## Admin menu

| Hook | Callback | Priority | Why |
|---|---|---|---|
| `admin_menu` | `ATCF_Locations_Global::add_menu` | 15 | After HyperTaxonomy adds its **Terms** menu at the default priority, so **Term Locations** can go under it. Without HyperTaxonomy, it adds a **Terms** menu of its own |
| `parent_file`, `submenu_file` | highlight the menu for the per-term Locations page | 999 | That page has no menu entry of its own, so these name the **Term Locations** entry instead; at 999 they run after other callbacks |

## HyperTaxonomy hooks used

`ATCF_HT_Integration::register()` always adds these; without HyperTaxonomy they never fire.

| Hook | What As the Crow Flies does |
|---|---|
| `htax_before_merge_terms` | Copies the source terms' locations before HyperTaxonomy's merge transaction starts; deleting the source terms deletes their locations |
| `htax_after_merge_terms` | Adds those locations to the surviving term, skipping any it already has under the Location Matching settings |

The rest of the integration runs the other way: HyperTaxonomy reads term locations through the public functions and hooks of As the Crow Flies (see HyperTaxonomy's [As the Crow Flies Integration](https://hypertaxonomy.org/hypertaxonomy/developer-reference/atcf.html)).
