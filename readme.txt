=== Manpreetdev Custom Fields and Meta Boxes ===
Contributors: manpreetdev21
Tags: custom fields, meta box, repeater, options page, custom post type
Requires at least: 6.8
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 1.17.3
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Field groups, meta boxes and a developer-friendly field API: 51 field types, repeaters, options pages, blocks and public forms.

== Description ==

Add custom fields to posts, pages, custom post types, terms, users, comments, options pages and public forms. Build a field group in the admin, choose where it appears, and read the values back with one function.

**Fifty-one field types**, grouped by what they do rather than by name: text, number, email, URL, password, range, colour, date, time, phone, slug, UUID, currency, textarea, select, checkbox, radio, toggle, button group, rating, country, region, file, image, gallery, video, audio, post object, page link, relationship, taxonomy, user, link, rich text, code, JSON, HTML, repeater, flexible content, message, separator, tab, accordion, group, icon picker, map, signature, QR code, barcode, embed and address.

**Where a group appears is a rule, not code.** Twenty-five location parameters cover post type, template, status, format, category, taxonomy, a specific post or page, page type and parent, the current user, user role and profile form, terms, attachments, comments, menus, menu items, widgets, blocks, options pages, and WooCommerce product types and order statuses.

**Values live in native WordPress storage.** A field on a post is post meta; a field on a term is term meta; an options page writes prefixed options. Nothing is kept in a custom table, so every value stays readable with `get_post_meta()` and reaches the REST API, exports and migrations like any other metadata.

= For developers =

    wpcmb_the_field( 'subtitle' );                          // escaped output
    $value = wpcmb_get_field( 'subtitle', $post_id );       // the stored value
    $all   = wpcmb_get_fields( 'options_site-settings' );   // a whole options page

Field groups can be registered in PHP with `wpcmb_register_field_group()` and kept in version control, exported as JSON or PHP from the Tools screen, and extended with your own field types through `wpcmb/register_field_types`. Validation, conditional logic, sanitizing and the save path are shared by every type, including your own.

= Required fields are enforced =

A required field stops a post or page reaching a public status — from either editor, from Quick Edit and from Bulk Edit. The browser holds the Publish button while a field is invalid, and the server refuses the status change if the browser is bypassed. An already-published post is never unpublished over a validation error; the problem is reported instead.

= Options pages =

Set a group's location to an options page and the screen is created, rendered and saved for you. No `add_menu_page`, no render callback, nothing in the theme.

= Settings on every group and every field =

A field group sets where it renders, how its labels and instructions are placed, which core elements to hide on the edit screen, whether its fields reach the REST API and the block editor, and — for a group that creates an options page — that page's menu location, capability and icon.

Each field type adds its own: prepend and append text, minimum and maximum, a validation pattern and message, layout, an optional empty choice, free-typed values, return format, and for media fields the library to pick from, the allowed file types, minimum and maximum file size, and minimum and maximum image dimensions.

File and image limits are checked when the value is saved, not only when the picker is open, so they hold for a public form, the REST API and code as well.

= Public forms =

    [wpcmb_form group="group_abc123"]                 signed-in visitors
    [wpcmb_form group="group_abc123" guests="1"]      anyone

Submissions are stored in their own custom post type per group, with the values shown on the submission screen. The form's configuration travels signed with the site's own salts, so a visitor cannot edit the markup to change what a submission does.

== Installation ==

1. Upload the plugin to `/wp-content/plugins/`, or install it from the Plugins screen.
2. Activate it.
3. Open **Meta Boxes → Field Groups** and add a group.
4. Add fields, choose where the group appears, and publish it.

No configuration is required.

== Frequently Asked Questions ==

= Where are the values stored? =

In WordPress's own tables. A field on a post is post meta under the field's own name, so `get_post_meta( $id, 'subtitle', true )` works whether or not this plugin is active. Term, user and comment fields use their own meta tables; options pages use options named `wpcmb_{page}_{field}`.

= What happens to my data if I deactivate the plugin? =

Nothing. Values stay where they are, and the template functions are the only thing that stops working. Deleting the plugin leaves the data too, unless you opt in under **Meta Boxes → Settings**.

= Can I define field groups in code? =

Yes. Export a group as PHP from the Tools screen, drop it into your theme or a plugin, and it is registered without touching the database. A stored group with the same key takes precedence, so you can export to code and carry on editing in the admin.

= Does it work with the block editor? =

Yes, in both editors. A group can also be registered as a block, in which case its fields are edited in the block sidebar and the values live in the block's attributes.

= Can visitors submit a form without an account? =

Only if you ask for it. `guests="1"` on the shortcode accepts submissions from anyone; without it a visitor is asked to sign in. Uploads always need an account.

= Is it translation ready? =

Yes. Every string is translatable under the `manpreetdev-custom-fields-meta-boxes` text domain, and the layout uses logical CSS properties so right-to-left needs no second stylesheet.

== Screenshots ==

1. The field group editor: fields, their settings, and the rules that decide where the group appears.
2. Fields on a post, in the block editor.
3. An options page, created from a location rule.
4. A repeater, with rows that reorder, duplicate and import from CSV.
5. The field group list, with each group's key, field count and location.

== Changelog ==

= 1.17.3 =
* Fixed: the Conditional logic panel was laid out wrongly. Its rule rows are drawn in the same six column grid the location rules use — joiner, icon, field, operator, value, remove — and the rewrite in 1.17.0 added a seventh child for the "and" label, pushing every control one column right. The field and operator menus landed in the 34px and 20px columns and rendered as bare chevrons with no readable text.
* The "and" between two conditions in a group is now the connector the stylesheet already draws for location rules, rather than an extra element in the grid.
* Fixed: "Show this field when …" was being laid out as a rule row too, which squeezed its menu into the 34px first column and wrapped the sentence beside it one word per line. It is its own row now.
* Fixed: clicking "+ Add condition" threw the field back to its General tab. Every change rebuilds the row, and the rebuilt tab set always opened on its first tab, so each condition had to be added from scratch. A row now reopens on the tab it was on.


= 1.17.2 =
* Fixed: the Conditional logic panel was unusable while building a new field group. It said "Add another field first to use conditional logic" even once several fields existed, because it only offered fields that already had a name — and a field gains its name after its neighbours' rows were drawn, with nothing redrawing them. Reloading the editor was the only way to get the panel working.
* A condition references a field by its key, which exists from the moment the field is added, so a name is no longer required to point at one. A field being named right now is listed as "New Field" until it has a label of its own.


= 1.17.1 =
* Security: a password field's value is no longer exposed over the REST API. `register_meta()` with `show_in_rest` puts a value in the post response, a published post's response is readable by anybody, and groups are exposed unless the author turns it off — so the one field type whose purpose is to hold a secret was being handed to anonymous visitors in plain text. It still renders, still saves, and is still readable in a template with `wpcmb_get_field()`; it no longer leaves the site on its own.
* Security: a value posted for a field that conditional logic was hiding is no longer stored. The browser disables hidden inputs, so an honest submission never carries them, but that is advice to a browser rather than a rule about what may arrive — anybody could write to a field a form had never offered them, which on a public form means a visitor filling in something the site only reveals to certain answers. Both save paths, the edit screen and the front-end form, now drop them.
* The same guard applies inside repeaters and flexible content, judged per row: a row answering "A" hides a sub field that a row answering "B" still shows, so the decision is made for each row against its own answers.
* A field that is visible can still be cleared, and a field hidden again keeps the value it had rather than being wiped.
* The admin stopped introducing itself by the name the plugin had two renames ago. The menu page title, the Overview and Settings headings, the dashboard widget, the block category and the Elementor and Bricks labels all said "Custom Meta Box"; they now say "Manpreetdev Custom Fields".

= 1.17.0 =
* New: local JSON. Every field group you save also writes `wpcmb-json/{key}.json` in your theme, so the fields a template reads live in version control beside the template that reads them.
* Those files are read back as groups registered in code, which means a fresh checkout works with no import step: the fields exist because the files do. A group also in the database wins over its file, so the editor stays authoritative while you are working in it.
* Switched on by creating the `wpcmb-json` directory in your theme, and off by removing it. One less setting, and the plugin never writes into a repository it was not invited to.
* When a file is newer than the group here — somebody else's change, newly pulled — the Field Groups screen says so and offers to import it, per group or all at once. Nothing is imported without being asked, and an existing group keeps the active state it has on this site.
* The file carries its own modification stamp rather than relying on the filesystem, because a checkout gives every file the time it was checked out. Syncing records what it took, so a file written on a machine whose clock is ahead is not offered for ever.
* Filters: `wpcmb/json/save_path`, `wpcmb/json/load_paths` (a child theme reads its parent's groups by default). Actions: `wpcmb/json/written`, `wpcmb/json/synced`.
* New: conditional logic rule groups. A field's conditions were one list plus an all/any switch, which could say "A and B" or "A or B" but never "A and B, or C" — which is what most real conditions turn out to be. Conditions are now groups: all the rules in a group must hold, and any one group holding is enough. Location rules have worked this way since the first release.
* Logic saved by an earlier version keeps the behaviour it had, with nothing to migrate: "all" becomes one group of every rule, "any" becomes one group per rule. The conversion happens when the logic is read, not only when it is saved, because a stored field group is handed to the renderer exactly as it was written.
* New: submission CSV export. The submissions list has an "Export all to CSV" button, with one column per field, taken from the field group so every row has the same columns in the same order — including for a field added after some submissions arrived.
* Repeater and multi-value answers are flattened rather than dropped: values within a row are joined with a pipe and rows with a semicolon, so an export of a form with a repeater in it still holds the answers.
* Cells beginning `=`, `+`, `-`, `@`, a tab or a return are prefixed with a quote, so a submission someone filled in with `=HYPERLINK(...)` is text in a spreadsheet rather than a live formula. The repeater's own CSV export and this one now share one definition of that escaping.
* The file is written with a byte order mark, because the main thing anyone does with it is open it in Excel, which otherwise reads UTF-8 as the local codepage.

= 1.16.0 =
* Renamed to Manpreetdev Custom Fields and Meta Boxes, under the slug `manpreetdev-custom-fields-meta-boxes`. The code prefix is unchanged, so every stored field group, value, option and submission is untouched and nothing needs migrating.
* The text domain is now `manpreetdev-custom-fields-meta-boxes`. Translations against the old domain need regenerating.
* Template overrides move with the slug: a theme folder named `metafields-custom-fields` holding `form.php`, `block.php` or `blocks/{name}.php` has to be renamed `manpreetdev-custom-fields-meta-boxes`. The `wpcmb/form/template` and `wpcmb/block/template` filters are unaffected.
* Exports written under either former slug still import. The marker inside the file is the slug the plugin had when it was written, so a rename would otherwise refuse every file made before it.
* New: the row loop, for reading repeaters and flexible content in a template. `wpcmb_have_rows()`, `wpcmb_the_row()`, `wpcmb_get_row()`, `wpcmb_get_sub_field()`, `wpcmb_the_sub_field()`, `wpcmb_get_sub_field_object()`, `wpcmb_get_row_layout()`, `wpcmb_get_row_index()`, `wpcmb_count_rows()` and `wpcmb_reset_rows()`.
* A repeater value was always a list of rows a foreach could walk. What that could not do is reach a sub field's definition — its label, choices or default are not in the top-level name index — or walk a repeater nested inside a repeater without nesting the loops by hand. Both now read the same two lines deep or shallow.
* A sub field added to a repeater after its rows were saved now falls back to its default when read, instead of coming back as nothing.
* Row writers: `wpcmb_add_row()`, `wpcmb_update_row()` and `wpcmb_delete_row()`, counting rows from one as the loop reports them. They go through the same sanitizing an edit screen does, so a script cannot store what the admin would refuse.

= 1.15.0 =
* Export and import now cover everything the plugin holds, not just field groups. Post types, taxonomies, block types, forms and options pages were added over the last five releases and none of them could leave the site they were built on, so a site built on staging had to be rebuilt by hand on production.
* One file, one envelope. Tools → Export writes field groups and all five record types, with a checkbox per section and a count beside it, and a collapsed list for picking individual field groups.
* Importing the same file twice updates what is here instead of making a second copy of everything. Records are matched on their own identity — a post type by its key, a taxonomy by its key, an options page by its slug.
* Anything new arrives inactive. A file somebody uploads cannot start changing post screens before it has been looked at. wp wpcmb import --activate publishes what it creates, for a deploy script that already knows what it is importing.
* Exports from every earlier version still import: the envelope this version writes, the bare list of field groups older versions wrote, and a single field group on its own.
* The PHP export covers all of it too. It registers the field groups through wpcmb_register_field_group() and the records through the wpcmb/records/* filter each record type already applies, so the records it replaces can be deleted and the configuration deploys with your code.
* wp wpcmb export gained --only, to export named sections: wp wpcmb export --only=post_types,taxonomies. wp wpcmb import takes the same files the Tools screen does. Both commands and the Tools screen now share one definition of what an export holds.
* Fixed: a field group whose key was written by hand rather than generated — group_site_header, say — had that key replaced on import, which renamed the group, orphaned its stored values, and made the next import of the same file create a second group instead of updating the first.
* New filter wpcmb/export and action wpcmb/imported.

= 1.14.0 =
* The last location rule that drew nothing now draws: a field group located on a menu renders under Menu Settings on the Menus screen, beside Auto add pages and Menu location, and saves with the Save Menu button.
* Menu values are term meta on the menu, because a menu is a term. Read them with wpcmb_get_field( 'note', 'term_' . $menu_id ).
* New location choice: All menus, matching the All widgets choice added last release.
* WordPress provides no hook inside the menu editor — its only action on that screen belongs to another tab, and the panels that look like the menu's own are in a second form whose action is "add menu item". The fields are therefore printed at the end of the page and moved into the menu form by a short inline script. With that script blocked they say so rather than appearing to save and not saving.

= 1.13.0 =
* Widgets and nav menu items now draw their fields. Both location rules have matched since the first release and had nothing hooked to the form, so a group located on either showed nothing anywhere.
* Widget values live inside the widget instance, where WordPress keeps the rest of a widget's settings: they are copied when a widget is duplicated, carried when it moves sidebar, and deleted with the widget. Read them with wpcmb_get_field( 'name', 'options_widget_text-3' ).
* Customizer support comes with it. A widget form in the Customizer is the same form drawn by the same hook, and it saves through the same callback — which is all "Customizer support" has ever meant.
* Legacy widgets in the block widgets editor work too. Their forms arrive over REST and are injected into the page, so the field script now watches for markup that turns up after load and wires it up.
* New location choice: All widgets. The list offered one entry per widget type and no way to say every one of them.
* Fixed: wpcmb.initFields() did nothing when handed a single field group rather than a container of them, so tabs and accordions were skipped in any injected markup.
* Nav menu item values are post meta on the item, since a menu item is a post — nothing new to store, and wpcmb_get_field( 'icon', $item->ID ) works in a walker.

= 1.12.1 =
* Fixed: a field group located on a taxonomy appeared when editing a term but not on the Add New Term form. Only the edit hook was ever registered, and the location rule could not match on that form anyway because it works out the taxonomy from the term — of which there is none yet. The screen now supplies the taxonomy, the same way it already supplies which user form or widget it is.
* Fixed: the field stylesheet and scripts did not load on a taxonomy screen at all, so even once the fields rendered there they would have been unstyled, with no media picker or repeater.

= 1.12.0 =
* New: Block Types, Forms and Options Pages each have a screen of their own. A block, a form or an options page is now a record you create and name, pointing at the field group whose fields it shows.
* Forms are configured once instead of being described by shortcode attributes on every page that uses them. Paste [wpcmb_form id="123"] and every setting travels with it; an attribute written alongside it still overrides that one setting in that one place.
* An options page can now be a sub-page of another options page, and can carry its own page title — neither of which a location rule could express.
* Existing configuration is migrated automatically on upgrade, and nothing is deleted or rewritten. Block settings on a field group are still read for any group no record has claimed, [wpcmb_form group="..."] still means exactly what it did, and a page named only by a location rule is still built.
* Fixed: a list table refusing to publish something was announced on the plugin's own list screens too, where it read as a complaint about the records on them.
* The plugin menu now reads: Overview, Field Groups, Post Types, Taxonomies, Block Types, Forms, Options Pages, Settings, Tools — then the submissions and options pages a site has accumulated.

= 1.11.0 =
* New: Taxonomies. Register custom taxonomies from a screen of their own — nested like categories or flat like tags — with labels, the post types they attach to, permalinks and visibility. The list screen says what each registers, how many terms it holds, and whether it is attached to anything.
* The Taxonomies box on a post type now lists the taxonomies you have defined yourself, not just categories and tags, and it includes ones you have saved but not published yet.
* The join works from either screen. A post type naming a taxonomy used to attach nothing, because both halves register at the same moment and the taxonomy did not exist yet when the post type asked for it.
* The Taxonomies box is a searchable dropdown instead of a column of checkboxes, and degrades to a plain multiple select without JavaScript.
* Menu icon on a post type is now the plugin's own icon picker: a searchable grid of every Dashicon the installed WordPress ships, a Media Library tab and a URL box.
* The icon picker gained a search box, which it needed at 350 icons. Every icon field benefits.
* Both record screens list only real content types and taxonomies. Other plugins keep their configuration in non-public post types with a UI, and those were burying the handful anybody classifies.

= 1.10.0 =
* New: Post Types. Register custom post types from a screen of their own, with labels, features, taxonomies, permalinks, capabilities, menu icon and position. The list screen says whether each one is actually registered, and how many items exist under it.
* Records are stored as posts like field groups are, so search, trash, revisions, capabilities and WXR export all work on them without anything new being written.
* Fixed: the plugin stylesheet did not load on any admin screen except field groups, so a new record screen rendered with none of the design tokens. Any wpcmb_ post type screen now counts as one of ours.
* The admin menu order now puts what you build first: Overview, Field Groups, Post Types, then Settings and Tools, then the forms and options pages a site accumulates.

= 1.9.3 =
* Block and Front-end Form are now sections of their own in the field group editor, in the main column, instead of being tucked into the side. The form shortcodes had no heading at all before this.
* Fixed: the "fields builder needs JavaScript" notice stayed on screen after the builder had loaded. It is now hidden by the builder itself, so it still shows when the script genuinely does not run.
* Fixed: an empty red bar under the fields builder on every field group. The hidden attribute gets its display:none from the browser, and any class of ours that sets a display silently outranked it. Added an isolation check that fails when a display-setting class can be hidden without an override in the same stylesheet.
* The build now removes the previous version's zip, so there is only ever one package in the plugin root to upload.

= 1.9.2 =
* Security - A file, image, gallery, video or audio field now accepts only its own kind of file even when no file types are configured. The media picker was already filtered this way; the check on save was not, so a value arriving from a crafted request, the REST API or code could put a PDF in an image field.
* Security - A relationship, post object or page link field now offers and returns only what the reader is allowed to see. Drafts and private posts are still offered on an edit screen, where they are being chosen deliberately, and no longer on a public form, where every option is a title handed to whoever asked for the page.
* Security - A user field no longer returns the whole WP_User object — with its email address, login name and registration date — to a reader without the `list_users` capability. They get the user ID instead.
* Security - An attachment attached to a non-public post is no longer returned with its caption, description and parent id to a reader who cannot see that post. Files in the media library with no parent read as published and are unaffected.
* Security - "Other values" on a checkbox list is capped at 1000 values per field, filterable with `wpcmb/field/max_choices`. It is the one place a choice field takes free text, and so the one place a single request decided how much went into a meta row.
* New - Settings now carries a Media upload types list: the file types a media field can be restricted to, written once rather than retyped into every field. Each line becomes a choice in the new Upload type setting on file, image, gallery, video and audio fields.

= 1.9.1 =
* The plugin menu now keeps one order on every screen: Overview, Field Groups, Settings, Tools, then the forms and options pages a site has made. It used to rearrange itself, because post types register at a different moment from admin pages and a form's submissions type does not exist until its first submission.
* A form's menu entry shows its post type under its name, the way an options page shows its slug.
* An options page can be placed under the plugin's own Meta Boxes menu, alongside Settings and Tools.
* Fixed: choices set to the horizontal layout still stacked in a column. The base rule is a flex column and the modifier did not reset the direction.

= 1.9.0 =
* New field type: separator, a rule with an optional legend.
* Field groups: display title, instruction placement, show in REST API. Hide on screen now also covers the Custom Fields and Page Attributes boxes — and the Permalink and Content Editor options, which had never hidden anything.
* Options pages built from a location rule can now set their menu location, capability and icon. The permission check follows the capability, so a page can be handed to a role below administrator.
* Text fields take prepend and append text.
* Choice fields: optional empty choice, horizontal layout, "Select all", and free-typed values on a checkbox list.
* Media fields: restrict the library to what was uploaded to the item, restrict file types, set minimum and maximum file size, set minimum and maximum image width and height, and choose the preview size. Limits are enforced on save, not only in the picker.
* Relationship fields: minimum and maximum selections, optional empty choice, and a taxonomy field can now assign its terms to the post or read them back from it.
* Embed fields take a preferred width and height.
* Field types can now reject a value and act on the object it was saved to, so a third-party type gets server-side validation the same way a built-in one does.

= 1.8.0 =
* Renamed to MetaFields - Custom Fields and Meta Boxes. The Plugin Directory does not allow "WordPress" in a plugin name, in any position. The slug, the text domain and every stored value are unchanged.
* Removed the `Domain Path` header and the `load_plugin_textdomain()` call. Translations come from the directory's language packs, or from a .mo dropped in `wp-content/languages/plugins/`.
* The submission checks now cover restricted name terms and translation headers that describe nothing shipped.

= 1.7.0 =
* Renamed to MetaFields - Custom Fields for WordPress, under the slug `metafields-custom-fields`.
* The text domain is now `metafields-custom-fields`. Translations against the old domain will need regenerating; stored field groups, values and settings are untouched.
* Added readme.txt and the full GPLv2 licence text.
* Added the Plugin Directory submission checks, and a build that refuses to package anything they reject.

= 1.6.0 =
* Required fields now stop a publish from Quick Edit and Bulk Edit, not only from the editors.
* A list table that refuses to publish something now says why.
* Fixed: an options page kept showing a failed save's errors to whoever opened it next, for five minutes, with no notice to explain them.
* Fixed: refreshing a failed options save reported that it had succeeded.

= 1.5.0 =
* Form submissions are stored in their own post type per field group, with the values readable on the submission screen.
* Fixed: the `comment` location rule matched post screens and never comment screens.
* Fixed: a location rule could be saved with no value while the screen showed one, so the group appeared nowhere.
* The field row controls in the group editor are drawn as icons rather than text glyphs.

= 1.4.0 =
* Fixed: the form shortcode could not store anything with its default settings.
* Fixed: repeaters did nothing on a public form — the script was never loaded, and its rows could not be fetched without an editing account.
* The group editor now offers both form shortcodes and says which is which.

= 1.3.0 =
* Options pages are created automatically from the location rules that name them.

= 1.2.0 =
* Required fields block a publish, in both editors and on the server.
* Validation reaches sub fields inside repeaters, groups and flexible content layouts.

= 1.1.0 =
* Modernised admin and field styling, dark mode, and a searchable multiple select.
* Hardened the AJAX endpoints, term capabilities and field name handling.

= 1.0.0 =
* First release.

== Upgrade Notice ==

= 1.5.0 =
A field name beginning with an underscore is now stored without it. If you have one, rename the field or migrate its meta key before upgrading.
