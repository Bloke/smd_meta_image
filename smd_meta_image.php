<?php

// This is a PLUGIN TEMPLATE for Textpattern CMS.

// Copy this file to a new name like abc_myplugin.php.  Edit the code, then
// run this file at the command line to produce a plugin for distribution:
// $ php abc_myplugin.php > abc_myplugin-0.1.txt

// Plugin name is optional.  If unset, it will be extracted from the current
// file name. Plugin names should start with a three letter prefix which is
// unique and reserved for each plugin author ("abc" is just an example).
// Uncomment and edit this line to override:
$plugin['name'] = 'smd_meta_image';

// Allow raw HTML help, as opposed to Textile.
// 0 = Plugin help is in Textile format, no raw HTML allowed (default).
// 1 = Plugin help is in raw HTML.  Not recommended.
# $plugin['allow_html_help'] = 1;

$plugin['version'] = '0.6.0';
$plugin['author'] = 'Stef Dawson';
$plugin['author_uri'] = 'https://stefdawson.com/';
$plugin['description'] = 'A Textpattern CMS plugin for importing images using IPTC metadata to populate content.';

// Plugin load order:
// The default value of 5 would fit most plugins, while for instance comment
// spam evaluators or URL redirectors would probably want to run earlier
// (1...4) to prepare the environment for everything else that follows.
// Values 6...9 should be considered for plugins which would work late.
// This order is user-overrideable.
$plugin['order'] = '5';

// Plugin 'type' defines where the plugin is loaded
// 0 = public              : only on the public side of the website (default)
// 1 = public+admin        : on both the public and admin side
// 2 = library             : only when include_plugin() or require_plugin() is called
// 3 = admin               : only on the admin side (no AJAX)
// 4 = admin+ajax          : only on the admin side (AJAX supported)
// 5 = public+admin+ajax   : on both the public and admin side (AJAX supported)
$plugin['type'] = '4';

// Plugin "flags" signal the presence of optional capabilities to the core plugin loader.
// Use an appropriately OR-ed combination of these flags.
// The four high-order bits 0xf000 are available for this plugin's private use
if (!defined('PLUGIN_HAS_PREFS')) define('PLUGIN_HAS_PREFS', 0x0001); // This plugin wants to receive "plugin_prefs.{$plugin['name']}" events
if (!defined('PLUGIN_LIFECYCLE_NOTIFY')) define('PLUGIN_LIFECYCLE_NOTIFY', 0x0002); // This plugin wants to receive "plugin_lifecycle.{$plugin['name']}" events

$plugin['flags'] = '3';

// Plugin 'textpack' is optional. It provides i18n strings to be used in conjunction with gTxt().
// Syntax:
// ## arbitrary comment
// #@event
// #@language ISO-LANGUAGE-CODE
// abc_string_name => Localized String

$plugin['textpack'] = <<<EOT
#@language en, en-gb, en-us
#@image
smd_meta_image_read_iptc => Parse IPTC data
#@prefs
smd_meta_image => Meta Image Import
smd_meta_image_cat_none => Disable category creation
smd_meta_image_cat_parent => Article parent category
smd_meta_image_choices => General options
smd_meta_image_custom => Custom value
smd_meta_image_map_01img => Image mapping
smd_meta_image_map_02art => Article mapping
smd_meta_image_img_alt => Alt text
smd_meta_image_img_caption => Caption
smd_meta_image_img_category => Category
smd_meta_image_img_name => Name
smd_meta_image_art_body => Body
smd_meta_image_art_category1 => Category 1
smd_meta_image_art_category2 => Category 2
smd_meta_image_art_excerpt => Excerpt
smd_meta_image_art_keywords => Keywords
smd_meta_image_art_posted => Posted date
smd_meta_image_art_section => Section
smd_meta_image_art_title => Title
EOT;

if (!defined('txpinterface'))
        @include_once('zem_tpl.php');

# --- BEGIN PLUGIN CODE ---
/**
 * smd_meta_image
 *
 * A Textpattern CMS plugin for uploading multiple images and read IPTC metadata.
 *
 * Features:
 * * Hooks into the core multiple image upload process.
 * * Any IPTC data fields can be mapped to Txp Image fields allowing for automatic population of image metadata.
 * * IPTC data fields can also be mapped to Article fields. If so:
 * -> a corresponding article is automatically created for each uploaded image
 * -> the image is associated with the article in its Article Image field
 * * Perfect for photobloggers.
 * * Custom data can be brought in from any supported IPTC field.
 *
 * @author Stef Dawson
 * @link   https://stefdawson.com/
 */
if (txpinterface === 'admin') {
    new smd_meta_image();
}

/**
 * Admin interface.
 */
class smd_meta_image
{
    /**
     * The plugin's event as registered in Txp.
     *
     * @var string
     */
    protected $plugin_event = 'smd_meta_image';

    /**
     * The plugin's privileges.
     *
     * @var string
     */
    protected $privs = '1,2,3';

    /**
     * Constructor to set up callbacks and environment.
     */
    public function __construct()
    {
        add_privs($this->plugin_event, $this->privs);
        add_privs('plugin_prefs.'.$this->plugin_event, $this->privs);
        add_privs('prefs.'.$this->plugin_event.'.'.$this->plugin_event.'_choices', $this->privs);
        add_privs('prefs.'.$this->plugin_event.'.'.$this->plugin_event.'_map_01img', $this->privs);
        add_privs('prefs.'.$this->plugin_event.'.'.$this->plugin_event.'_map_02art', $this->privs);
        register_callback(array($this, 'prefs'), 'prefs', '', 1);
        register_callback(array($this, 'options'), 'plugin_prefs.'.$this->plugin_event);
        register_callback(array($this, 'welcome'), 'plugin_lifecycle.' . $this->plugin_event);
        register_callback(array($this, 'render_ui'), 'image_ui', 'upload_form');
        register_callback(array($this, 'inject_head'), 'admin_side', 'head_end');
        register_callback(array($this, 'update_image'), 'image_uploaded');
        register_callback(array($this, 'fetch_custom_val'), $this->plugin_event, 'fetch_custom_val');
    }

    /**
     * Install/uninstall jumpoff point.
     *
     * @param  string $evt  Textpattern event (lifecycle)
     * @param  string $stp  Textpattern step (action)
     */
    public function welcome($evt, $stp)
    {
        switch ($stp) {
            case 'deleted':
                safe_delete('txp_lang', "event like '" . $this->plugin_event . "%'");
                break;
        }

        return;
    }

    /**
     * Inject style rules / header material into the &lt;head&gt; of the page.
     *
     * @param  string $evt Textpattern event (panel)
     * @param  string $stp Textpattern step (action)
     * @return string      Content to inject, or nothing if not the plugin's $event
     */
    public function inject_head($evt, $stp)
    {
        global $event;

        if ($event === 'image') {
            echo '<style>
</style>';
        }

        if ($event === 'prefs') {
            echo script_js(<<<EOJS
jQuery(function() {
    jQuery('.smd_meta_image_map').find('select').on('change', function(ev) {
        var me = $(this);
        me.closest('.smd_meta_image_map').find('.smd_meta_image_custom').remove();

        if (me.val() === 'custom') {
            var name = me.attr('name');
            var spinner = jQuery('<span />').addClass('spinner');

            // Show feedback while processing.
            me.addClass('busy').attr('disabled', true).after(spinner);

            sendAsyncEvent({
                event : '{$this->plugin_event}',
                step  : 'fetch_custom_val',
                key   : name
            }, function() {}, 'json')
            .done(function (data, textStatus, jqXHR) {
                me.after($('<input class="smd_meta_image_custom" name="' + name + '" value="' + data.value + '" />'));
            })
            .always(function () {
                me.removeClass('busy').removeAttr('disabled');
                spinner.remove();
            });
        }
    }).change();
})
EOJS
            );
        }

        return;
    }

    /**
     * Add checkbox to the image upload field.
     *
     * @param  string $evt  Textpattern event (panel)
     * @param  string $stp  Textpattern step (action)
     * @param  string $data Original markup
     * @param  array  $rs   Accompanyng record set
     * @return string       HTML
     */
    public function render_ui($evt, $stp, $data, $rs)
    {
        if (in_array($rs[2], array('image_insert', 'image_replace'))) {
            $btnID = $this->plugin_event.'_read_iptc';
            $btn = checkbox($btnID, 1, get_pref($btnID, 0, true), '', $btnID) . sp .
                tag(gTxt($btnID), 'label', array('for' => $btnID));

            return str_replace('</form>', $btn.'</form>', $data);
        } else {
            return '';
        }
    }

    /**
     * Overwrites image metadata from IPTC headers, optionally creating article(s).
     *
     * @param string $evt Textpattern event (panel)
     * @param string $stp Textpattern step (action)
     * @param int    $id  Image to operate upon
     */
    public function update_image($evt, $stp, $id)
    {
        global $txp_user;

        $checkboxRef = $this->plugin_event.'_read_iptc';
        $checked = gps($checkboxRef);
        set_pref($checkboxRef, $checked, $this->plugin_event, PREF_HIDDEN, 'text_input', 0, PREF_PRIVATE);

        // Only parse the image if the checkbox is on.
        if (!$checked) {
            return;
        }

        if (!has_privs('image.edit.own')) {
            require_privs('image.edit.own');
            return;
        }

        // Fetch IPTC data and set $meta from it.
        $imgid = assert_int($id);
        $imgmatch = "id = '" . $imgid . "'";
        $artmatch = '';
        $artposted = '';
        $user = doSlash($txp_user);
        $img = safe_row('*', 'txp_image', $imgmatch);
        $imgCatFallback = gps('category');
        $artCatParent = get_pref($this->plugin_event . '_cat_parent', '');

        if ($img) {
            $imgpayload = array();
            $artpayload = array();
            $size = getimagesize(IMPATH . $img['id'] . $img['ext'], $info);

            if (isset($info['APP13'])) {
                $iptc = iptcparse($info['APP13']);
                $plugPrefs = $this->prefList();

                foreach ($plugPrefs as $prefKey => $prefObj) {
                    if ($prefObj['event'] === $this->plugin_event . '.' . $this->plugin_event . '_map_01img') {
                        $imgVal = get_pref($prefKey, '');

                        switch ($prefKey) {
                            case $this->plugin_event . '_img_category':
                                $catData = array(
                                    'type'   => 'image',
                                    'parent' => $imgCatFallback,
                                );

                                if ($imgVal && ($val = $this->replaceIptc($imgVal, $iptc, $catData))) {
                                    $imgpayload[] = "category = '".doSlash($val)."'";
                                } elseif ($imgCatFallback) {
                                    $imgpayload[] = "category = '".doSlash($imgCatFallback)."'";
                                }

                                break;
                            case $this->plugin_event . '_img_alt':
                            case $this->plugin_event . '_img_caption':
                            case $this->plugin_event . '_img_name':
                                if ($imgVal && ($val = $this->replaceIptc($imgVal, $iptc))) {
                                    if ($val) {
                                        $safeVal = doSlash($val);
                                        $safeKey = substr(strrchr($prefKey, '_'), 1);
                                        $imgpayload[] = "$safeKey = '$safeVal'";
                                    }
                                }

                                break;
                        }
                    } elseif ($prefObj['event'] === $this->plugin_event . '.' . $this->plugin_event . '_map_02art') {
                        $imgVal = get_pref($prefKey, '');
                        $safeKey = preg_replace('/'.$this->plugin_event.'_art_/', '', $prefKey);

                        switch ($prefKey) {
                            case $this->plugin_event . '_art_category1':
                            case $this->plugin_event . '_art_category2':
                                $catData = array(
                                    'type'   => 'article',
                                    'parent' => $artCatParent,
                                );

                                if ($imgVal && ($val = $this->replaceIptc($imgVal, $iptc, $catData))) {
                                    if ($val) {
                                        $artpayload[] = "$safeKey = '".doSlash($val)."'";
                                    }
                                }

                                break;
                            case $this->plugin_event . '_art_title':
                                if ($imgVal && ($val = $this->replaceIptc($imgVal, $iptc))) {
                                    $safeVal = doSlash($val);

                                    if ($safeVal) {
                                        $artmatch = "Title = '$safeVal'";
                                        $artpayload[] = "url_title = '" . stripSpace(trim($safeVal), 1) . "'";
                                    }
                                }

                                break;
                            case $this->plugin_event . '_art_posted':
                                if ($imgVal && ($val = $this->replaceIptc($imgVal, $iptc))) {
                                    if ($val) {
                                        if (strtotime($val) > 0) {
                                            try {
                                                $date = new DateTime($val);
                                            } catch (Exception $e) {
                                                $date = new DateTime();
                                            }
                                        } else {
                                            $date = new DateTime();
                                        }

                                        $artposted = $date->format('Y-m-d H:i:s');
                                    }
                                }

                                break;
                            case $this->plugin_event . '_art_body':
                            case $this->plugin_event . '_art_excerpt':
                                if ($imgVal && ($val = $this->replaceIptc($imgVal, $iptc))) {
                                    if ($val) {
                                        $safeVal = doSlash($val);
                                        $safeKeyHtml = $safeKey .'_html';
                                        $artpayload[] = "$safeKey = '$safeVal'";
                                        $artpayload[] = "$safeKeyHtml = '$safeVal'";
                                    }
                                }

                                break;
                            default:
                                if ($imgVal && ($val = $this->replaceIptc($imgVal, $iptc))) {
                                    if ($val) {
                                        $safeVal = doSlash($val);
                                        $artpayload[] = "$safeKey = '$safeVal'";
                                    }
                                }

                                break;
                        }
                    }
                }

                // Update image row with new metadata.
                if ($imgpayload) {
                    safe_update('txp_image', implode(',', $imgpayload), $imgmatch);
                }

                // Insert/update article with given metadata.
                if ($artmatch) {
                    $section = get_pref($this->plugin_event . '_art_section', '');

                    if (!$section) {
                        $section = get_pref('default_section');
                    }

                    $status = get_pref('default_publish_status', STATUS_LIVE);

                    $artpayload[] = "Image = '" . $imgid . "'";
                    $artpayload[] = "AuthorID = '$user'";
                    $artpayload[] = "LastModID = '$user'";
                    $artpayload[] = "LastMod = NOW()";
                    $artpayload[] = "feed_time = NOW()";
                    $artpayload[] = "uid = '" . md5(uniqid(rand(), true)) . "'";

                    $this->upsertArticle(
                        implode(',', $artpayload),
                        $artmatch,
                        array(
                            'Posted'  => $artposted,
                            'Section' => $section,
                            'Status'  => $status,
                        )
                    );
                }
            }
        }
    }


/*
//for UTF-8 encoding??

    $IPTC_Caption = str_replace( "\000", "", $iptc["2#120"][0] );

    if(isset($iptc["1#090"]) && $iptc["1#090"][0] == "\x1B%G") {
        $IPTC_Caption = utf8_decode($IPTC_Caption);
    }

 */

    /**
     * Special version of article upsert to only set posted date on insert
     *
     * @param  string|array $set    The set clause
     * @param  string|array $where  The where clause
     * @param  array        $insert Stuff that should only be added on insert (e.g. posted datetime and section)
     * @return int|bool     The last generated ID or FALSE on error. If the ID is 0, returns TRUE
     */
    public function upsertArticle($set, $where, $insert)
    {
        global $DB;

        $table = 'textpattern';

        if (is_array($set)) {
            $set = join_qs(quote_list($set), ',');
        }

        $whereset = is_array($where) ? join_qs(quote_list($where), null) : array($where);
        $where = implode(' AND ', $whereset);

        $r = safe_update($table, $set, $where);

        if ($r && (mysqli_affected_rows($DB->link) || safe_count($table, $where))) {
            return $r;
        } else {
            foreach ($insert as $key => $data) {
                switch ($key) {
                    case 'Posted':
                        $data = ($data ? doQuote(doSlash($data)) : 'NOW()');
                        break;
                    case 'Section':
                    case 'Status':
                        $data = doQuote(doSlash($data));
                        break;
                }

                $whereset[] = "$key = $data";
            }

            return safe_insert($table, join(', ', array(implode(', ', $whereset), $set)));
        }
    }

    /**
     * Replace a string containing IPTC {#nnn} codes with its corresponding data
     *
     * @param  string $val     The string in which replacements may be needed
     * @param  array  $iptc    The IPTC array that contains replacement mapping keys and their values
     * @param  array  $catData If supplied, will _only_ use the first category in a list, creating it if necessary
     * @return string          The string with its replacements made
     */
    protected function replaceIptc($val, $iptc, $catData = array())
    {
        $arrayTypes = array('2#012', '2#020', '2#025', '2#080');
        $catTypes = array('2#012', '2#015', '2#020');
        $dateLookup = array(
            '{2#055}' => '{2#055} {2#060}',
        );

        // Automatically bring Times along for the ride if Date Created is chosen.
        $val = strtr($val, $dateLookup);

        preg_match_all('/\{(.*?)\}/', $val, $match);

        if (!empty($match[1])) {
            foreach ($match[1] as $idx => $toReplace) {
                $sub = '';

                // Note any sub-array offset required.
                if (preg_match('/(\:\d+)/', $toReplace, $parts, PREG_OFFSET_CAPTURE)) {
                    $sub = substr($parts[1][0], 1) - 1;
                    $toReplace = str_replace($parts[1][0], '', $toReplace);
                }

                if (empty($iptc[$toReplace])) {
                    $replacement = '';
                } else {
                    // Create category out of _first_ element if necessary.
                    // @todo: In future, relax/remove this if cats become unlimited/tags.
                    if ($catData && in_array($toReplace, $catTypes)) {
                        $firstItem = $iptc[$toReplace][0];
                        $replacement = $this->createCategory($firstItem, $catData['type'], $catData['parent']);
                    } else {
                        if (in_array($toReplace, $arrayTypes)) {
                            if (is_numeric($sub) && !empty($iptc[$toReplace][$sub])) {
                                $replacement = $iptc[$toReplace][$sub];
                            } else {
                                $replacement = join(',', $iptc[$toReplace]);
                            }
                        } else {
                            $replacement = $iptc[$toReplace][0];
                        }
                    }
                }

                $val = str_replace($match[0][$idx], $replacement, $val);
            }
        }

        return $val;
    }

    /**
     * Create a category with the given title of the given type under the given parent
     *
     * @param  string $title  Human-friendly title of the new category (name will be surmised)
     * @param  string $type   Type of category ('article' or 'image' in this case)
     * @param  string $parent Parent category _name_ (not title)
     * @return string         The name of the (matching or created) category
     */
    protected function createCategory($title, $type = 'article', $parent = null)
    {
        $name = strtolower(sanitizeForUrl($title));

        $exists = safe_field("name", 'txp_category', "name = '".doSlash($name)."' AND type = '".doSlash($type)."'");

        if ($exists !== false) {
            return $exists;
        }

        if (!$name || ($parent === $this->plugin_event . '_cat_none')) {
            return '';
        }

        $parent = strtolower(sanitizeForUrl($parent));
        $parent_exists = safe_field("name", 'txp_category', "name = '".doSlash($parent)."' AND type = '".doSlash($type)."'");
        $parent = ($parent_exists !== false) ? $parent_exists : 'root';

        $q = safe_insert('txp_category', "name = '".doSlash($name)."', title = '".doSlash($title)."', type = '".doSlash($type)."', parent = '".doSlash($parent)."'");

        if ($q) {
            rebuild_tree_full($type);
        }

        return $name;
    }

    /**
     * Prefs panel - create plugin key-values if they don't exist
     */
    public function prefs() {
        $plugPrefs = $this->prefList();

        foreach ($plugPrefs as $key => $prefobj) {
            if (get_pref($key, null) === null) {
                set_pref($key, doSlash($prefobj['default']), $prefobj['event'], $prefobj['type'], $prefobj['html'], $prefobj['position'], $prefobj['visibility']);

            }
        }
    }

    /**
     * Redirects to the preferences panel
     */
    public function options()
    {
        header('Location: ?event=prefs#prefs_group_'.$this->plugin_event);
        echo
            '<p id="message">'.n.
            '   <a href="?event=prefs#prefs_group_'.$this->plugin_event.'">'.gTxt('continue').'</a>'.n.
            '</p>';
    }

    /**
     * Return the pref value for the given key (POSTed)
     *
     * @param  string $evt Textpattern event
     * @param  string $stp Textpattern step (action)
     * @return array       'value': preference data for the given key
     */
    public function fetch_custom_val($evt, $stp)
    {
        $key = assert_string(ps('key'));
        $val = get_pref($key, '');

        send_json_response(array('value' => $val));
    }

    /**
     * Fetch the main (core) supported IPTC keys.
     *
     * Note the following are deprecated in the IIM spec but are
     * still supported in the plugin:
     *  -> 2#010
     *  -> 2#015
     *  -> 2#020
     *
     * @return array
     */
    protected function getIptcMap()
    {
        return array(
            '{2#004}' => 'Genre',
            '{2#005}' => 'Document Title',
            '{2#007}' => 'Usable',
            '{2#010}' => 'Urgency',
            '{2#012}' => 'Subject Reference',
            '{2#015}' => 'Category',
            '{2#020}' => 'Subcategories',
            '{2#025}' => 'Keywords',
            '{2#040}' => 'Special Instructions',
            '{2#055}' => 'Creation Date+Time',
            '{2#080}' => 'Author Byline',
            '{2#085}' => 'Author Title',
            '{2#090}' => 'City',
            '{2#092}' => 'Sublocation',
            '{2#095}' => 'State',
            '{2#100}' => 'Country Code',
            '{2#101}' => 'Country',
            '{2#103}' => 'Original Transmission Reference',
            '{2#105}' => 'Headline',
            '{2#110}' => 'Credit Line',
            '{2#115}' => 'Photo Source',
            '{2#116}' => 'Copyright',
            '{2#120}' => 'Caption',
            '{2#122}' => 'Caption Writer',
        );
    }

    /**
     * Create a select list of IPTC keys
     *
     * @param string $name  The select name
     * @param string $value The selected value
     *
     * @todo  i18n
     * @return HTML
     */
    protected function getIptcMapList($name, $value)
    {
        $options = $this->getIptcMap() + array('custom' => gTxt('smd_meta_image_custom'));

        return '<div class="smd_meta_image_map">'.selectInput($name, $options, $value, true, false, $name) . '</div>';
    }

    /**
     * Render article Sections pref
     *
     * @param  string $key The preference key being displayed
     * @param  string $val The current preference value
     * @return string      HTML
     */
    public function sectionList($key, $val)
    {
        $sections = safe_rows("name, title", 'txp_section', "name != 'default' ORDER BY title, name");
        $vals = array();

        foreach ($sections as $row) {
            $vals[$row['name']] = $row['title'];
        }

        return selectInput($key, $vals, $val, true, false, $key);
    }

    /**
     * Render category tree pref
     *
     * @param  string $key The preference key being displayed
     * @param  string $val The current preference value
     * @return string      HTML
     */
    public function catTree($key, $val)
    {
        $noVal = $this->plugin_event . '_cat_none';
        $options[] = array(
            'name'     => $noVal,
            'title'    => gTxt($noVal),
            'level'    => 0,
        );

        $options += getTree('root', 'article');

        return treeSelectInput($key, $options, $val, $key);
    }

    /**
     * Render a list of mappable IPTC field options
     *
     * @param  string $key The preference key being displayed
     * @param  string $val The current preference value
     * @return string      HTML
     */
    public function mapOpts($key, $val)
    {
        $plugPrefs = $this->prefList();
        $obj = $plugPrefs[$key];
        $allOpts = $this->getIptcMap();

        // Get current value from prefs.
        $current = get_pref($key, null);

        if ($current === null) {
            $val = $obj['default'];
        } elseif ($current === '') {
            $val = '';
        } elseif (!array_key_exists($current, $allOpts)) {
            $val = 'custom';
        }

        $thisID = $obj['event'];

        return $this->getIptcMapList($key, $val);
    }

    /**
     * Settings for the plugin
     *
     * @return array  Preference set
     */
    protected function prefList()
    {
        $imgOptions = array(
            'name'     => '',
            'category' => '',
            'alt'      => '{2#105}',
            'caption'  => '{2#105}',
        );

        // Case is intentional: column names.
        $artOptions = array(
            'Title'     => '{2#105}',
            'Body'      => '{2#120}',
            'Excerpt'   => '',
            'Posted'    => '{2#055}',
            'Category1' => '{2#015}',
            'Category2' => '{2#020}',
            'Keywords'  => '{2#025}',
        );

        $cfs = getCustomFields();

        foreach ($cfs as $i => $cf_name) {
            $var = "custom_$i";
            $artOptions[$var] = '';
        }

        $plugPrefs = array();
        $plugPrefs['smd_meta_image_cat_parent'] = array(
                'html'       => $this->plugin_event . '->catTree',
                'type'       => PREF_PLUGIN,
                'position'   => 10,
                'default'    => $this->plugin_event.'_cat_none',
                'event'      => $this->plugin_event . '.'. $this->plugin_event . '_choices',
                'visibility' => PREF_GLOBAL,
            );

        $plugPrefs['smd_meta_image_art_section'] = array(
                'html'       => $this->plugin_event . '->sectionList',
                'type'       => PREF_PLUGIN,
                'position'   => 20,
                'default'    => '',
                'event'      => $this->plugin_event . '.'. $this->plugin_event . '_choices',
                'visibility' => PREF_GLOBAL,
            );

        $counter = 50;

        foreach ($imgOptions as $key => $dflt) {
            $thisKey = $this->plugin_event . '_img_'.strtolower($key);
            $plugPrefs[$thisKey] = array(
                'html'       => $this->plugin_event . '->mapOpts',
                'type'       => PREF_PLUGIN,
                'position'   => $counter++,
                'default'    => $dflt,
                'event'      => $this->plugin_event . '.' . $this->plugin_event . '_map_01img',
                'column'     => $key,
                'visibility' => PREF_GLOBAL,
            );
        }

        foreach ($artOptions as $key => $dflt) {
            $thisKey = $this->plugin_event . '_art_'.strtolower($key);
            $plugPrefs[$thisKey] = array(
                'html'       => $this->plugin_event . '->mapOpts',
                'type'       => PREF_PLUGIN,
                'position'   => $counter++,
                'default'    => $dflt,
                'event'      => $this->plugin_event . '.' . $this->plugin_event . '_map_02art',
                'column'     => $key,
                'visibility' => PREF_GLOBAL,
            );
        }

        return $plugPrefs;
    }
}
# --- END PLUGIN CODE ---
if (0) {
?>
<!--
# --- BEGIN PLUGIN HELP ---
h1. smd_meta_image

Upload images and populate their metadata from IPTC header information. Very handy for people who run photoblog or image-heavy sites, or those who categorise images for inclusion in articles. If configured, the plugin will automatically create an article for each uploaded image.

h2. Features

* Map image IPTC fields to Textpattern image/article fields.
* Articles may be created automatically based on embedded IPTC data.
* Article/image categories may be created automatically if required.
* Upload/replace images to update Textpattern metadata.

h2. Installation / Uninstallation

p(information). Requires Textpattern 4.7+

"Download the plugin":#, paste the code into the Textpattern _Admin->Plugins_ panel, install and enable the plugin. For bug reports, please "raise an issue":#.

To uninstall, delete the plugin from the _Admin->Plugins_ panel.

h2. Configuration

Visit the Admin->Prefs panel and click the Meta Image Import group to configure the plugin. There are three sections as follows:

h3. General options

*Article parent category*

The parent category in your current article tree where all _new_ categories read in from the IPTC data will be created. If set to _Disable category creation_ then no categories will be created and assignment will only occur for those categories that already exist. If set to the empty (blank) option, new categories will be created at the root (top level).

*Section*

The Section in which articles will be created. If left blank, the Default Section (as defined in the Sections panel) will be used.

h3. Image mapping

One configuration item per Textpattern image field. Select an IPTC field from the list for each Textpattern field so that it may be mapped when images are uploaded.

Leave any select list blank to indicate this field should be ignored - i.e. have its data set to whatever Textpattern usually assigns on image upload.

If you leave the *Name* field blank, or the nominated IPTC field in the image is empty, Textpattern will use the filename as usual.

h3. Article mapping

As above, there is one configuration item per Textpattern article field. Select an IPTC field from the list for each Textpattern field so that it may be mapped when images are uploaded. Any fields you specify here will result in one article being created for every image.

There are some special cases as follows:

h4. Title

This is the key field that ties your image to your article. If you don't map this field (i.e. set it blank) then no article will be created when you upload an image. This is handy as a "turn off article creation" switch that leaves all other fields intact, or for people who just want to map image data on import, and not create articles.

Once set, it's a good idea not to alter it. If you do, the article won't be "found" again if you subsequently replace an existing image.

h4. Article image

This is set to the image ID of the uploaded image.

h4. Section

This is only set on article creation, _not_ altered when an image is replaced.

If the Section is set in the plugin's general options, the nominated Section will be used when creating articles from each uploaded image. If the Section is left empty in the plugin's general options, the current _Default Publishing Section_ (as defined on the Sections panel) will be used when creating articles.

h4. Status

The value from your 'Default publication status' pref is used. The status is only set when images are first uploaded. It is _not_ updated when replacing images.

h4. Keywords

These are concatenated as a comma-separated list and updated when an image is replaced. The list will be truncated if they don't fit in the 255 char limit.

h4. Posted date

If assigned from the image Creation Date IPTC field, it behaves as follows:

# It automatically adds the Creation Time IPTC field as well, to form a full date-time value.
# It is only assigned at article creation time. If the image is replaced, the article posted date remains the same.
# If the field cannot be read or is mangled then the upload date/time is used (a.k.a. 'now').
# If the Posted date is not mapped, then the upload date/time is used as article creation datestamp.

h4. Author/LastMod info

This is always set on insert and update of its corresponding image. The current logged-in user is used, and the article's modification date is set to 'now'.

h3. Category assignment

Categories are treated differently depending on where (to which field) they are assigned. IPTC category fields are:

* (2#012) Subject Reference
* (2#015) Category
* (2#020) Subcategory

They will be treated like regular fields if they are assigned to any non-Category Textpattern field (e.g. custom fields). In these cases, single category values will be inserted as regular fields and category lists will be inserted as a comma-separated list.

If, however, you assign one of the category fields to a Textpattern Category field, it behaves like this:

h4. Image categories

* If the category (or _first category_ if the nominated IPTC field represents a list) does not exist, it will be created as an image category.
* If you have specified an existing Textpattern category in the Upload form, that will be used as the _parent_ of any created categories.
* If any category already exists, no changes will be made to it.
* If the nominated IPTC field is empty, the category used in the upload form will be assigned to the image as fallback.
* If that's empty, no category will be assigned to the image.

h4. Article categories

* If the category (or _first category_ if the nominated IPTC field represents a list) does not exist, it will be created as an article category as long as the parent category is not set to _Disable category creation_.
* New categories read from image data will be created beneath the parent category set in the plugin's general options. If the parent category is unset, new categories are assigned to the article root.
* If a category already exists, its definition and parent remain the same - no changes are made, only category (re)assignment to the article is performed.

h3. Custom values

If you wish to combine field data or make your own content to be inserted into a field, choose the last _Custom value_ option from the configuration item list for any corresponding Textpattern field. A new input box will appear below the selector for you to type in the text you wish to be inserted into that field.

If you wish to insert a particular field code within your custom text, specify its name in curly braces. For example: @{2#004}@ is Genre, @{2#090}@ is City and @{2#105}@ is Headline. See "Section 6 of the IPTC Spec":https://www.iptc.org/std/photometadata/specification/IPTC-PhotoMetadata#metadata-properties and look at the _IIM Spec_ values to get the codes. Simply pad the values to the right of the colon with zeroes to three digits and replace the colon with a hash (#) sign. So, for example, 'Creator' has an IIM Spec designator @2:80@. To import this value into your nominated field, use @{2#080}@.

If you're unsure of the values, either a) inspect the browser page source code of the prefs panel and look at the values in one of the configuration item select lists, or b) check the plugin code - there's a function called @getIptcMap()@ which lists the main supported data fields and their values.

For lists of values that are treated as arrays (Subcategories, Subject Reference, Keywords and Author Byline) it's possible to use custom values to extract individual entries instead of the entire set as a comma-separated list. To do this, append a colon and offset to the field code. For example, to extract just the third subcategory value, specify:

bc. {2#020:3}

Or the 6th Keyword:

bc. {2#025:6}

Note:

* If the contents of the field at the given offset value is missing (e.g. no value, or set to 0), you will get _the entire field_ comma separated as if you hadn't used the offset. This allows you to manually edit the result later to remove the parts you don't want.
* A single space character in the field is _not_ treated as "empty" so if you wish to skip the entry and have your Textpattern destination field appear blank, specify a single space in the image metadata.

h2. Usage

# Visit the Images panel.
# Browse/drag one or more images to the upload field.
# Optionally select a category for it (or under which its new categories will be created).
# Ensure the *Parse IPTC data* checkbox is set.
# Upload the images.

Images will be uploaded, and have their metadata set according to the mapping rules set in the plugin preferences. If article mapping is configured, one article will be created per image too with the nominated data copied from the corresponding image's IPTC field into the article.

h2. Caveats / known issues / other stuff

* The custom field names in the Prefs panel are not translated and do not read the values for their names as defined in the CF prefs.
* The 'Parse IPTC' checkbox is remembered after you have performed an upload. The same state is applied for new uploads and for replacements - it uses the same pref value.
* The plugin plays nicely with smd_thumbnail.
* Only a loose link between the image and its article is enforced after creation. If the Title remains unchanged and the Title mapping field is unchanged, updating an image will update the corresponding article data (with the exceptions noted above). But if you delete an image or delete an article, they are treated independently.

# --- END PLUGIN HELP ---
-->
<?php
}
?>