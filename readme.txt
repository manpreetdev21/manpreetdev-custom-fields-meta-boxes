=== MetaFields - Custom Fields and Meta Boxes ===
Contributors: manpreetdev21
Tags: custom fields, meta box, repeater, options page, custom post type
Requires at least: 6.8
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 1.9.1
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Field groups, meta boxes and a developer-friendly field API: 51 field types, repeaters, options pages, blocks and public forms.

== Description ==

MetaFields adds custom fields to posts, pages, custom post types, terms, users, comments, options pages and public forms. Build a field group in the admin, choose where it appears, and read the values back with one function.

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

Yes. Every string is translatable under the `metafields-custom-fields` text domain, and the layout uses logical CSS properties so right-to-left needs no second stylesheet.

== Screenshots ==

1. The field group editor: fields, their settings, and the rules that decide where the group appears.
2. Fields on a post, in the block editor.
3. An options page, created from a location rule.
4. A repeater, with rows that reorder, duplicate and import from CSV.
5. The field group list, with each group's key, field count and location.

== Changelog ==

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
