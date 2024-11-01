<?php
/*
Plugin Name: vhosts
Plugin URI: http://www.skippy.net/
Description: provides for robust support of virtual hosts
Version: 1.4
Author: Scott Merrill
Author URI: http://www.skippy.net/

copyright 2005 Scott Merrill (skippy@skippy.net)
This plugin is licensed under the terms of the GNU Public License, version 2.

Thanks to Owen Winkler (http://asymptomatic.net/) for his limitcats() plugin
*/

/*
CHANGELOG
1.4
swapped HTTP_HOST for SERVER_NAME
add filter for wp_list_pages() to show only pages owned by the author account assigned to the vhost
1.3
added user->vhost binding.  If the admin specifies a specific vhost in a user's profile, that vhost will be used for all administrative tasks performed by that user.
1.2
tightened up checks on query string
1.1
added vhost_query function, to sanitize any user-supplied query string
1.0
initial version
*/

// we register vhosts against plugins_loaded because the TEMPLATEPATH
// constant gets set immediately after.  As such, we need our vhosts
// in effect to ensure that the TEMPLATEPATH constant gets set
// correctly.
add_action('plugins_loaded', 'vhosts');
add_action('admin_head', 'vhost_redirect');

////////////////
function vhosts() {
global $cache_settings, $wpdb, $vhost_cat, $user_level;

get_currentuserinfo();
if ('10' == $user_level) {
	// we don't want to lock out the admin
	return;
}
$vhost = strtolower($_SERVER['HTTP_HOST']);
$site = parse_url(strtolower(get_settings('siteurl')));
if ($site['host'] != $vhost) {
	$cache_settings->siteurl = "http://$vhost";
	$cache_settings->home = "http://$vhost";
	$cache_settings->template = "$vhost";
	$cache_settings->stylesheet = "$vhost";
	$vhost_category = $wpdb->get_row("SELECT cat_ID, category_description FROM $wpdb->categories WHERE cat_name = '$vhost'");
	$vhost_cat = $vhost_category->cat_ID;
	list($name, $description) = explode("\n", $vhost_category->category_description);
	$cache_settings->blogname = $name;
	$cache_settings->blogdescription = $description;
	add_filter('posts_join', 'vhost_join');
	add_filter('query_string', 'vhost_query');
	add_filter('wp_list_pages', 'vhost_list_pages');
	add_action('admin_head', 'vhost_admin_head');
}
} // vhosts()

/////////////////////////////////
function vhost_query($query = '') {
global $vhost_cat;

$query = urldecode($query);
if ( (stristr($query, 'name=')) && (! stristr($query, 'cat=')) && (! stristr($query, 'category_name=')) ) {
	// looks like a single request, so let's just go there
	return $query;
}
if (stristr($query, 'category_name=')) {
	// a category name was supplied; get rid of it
	// (yes, this is extreme, because they could have requested our
	// vhost's category.  But even that is extraneous, as vhosts
	// don't support sub-categories, so they ought not display
	// their own category )
	$query = preg_replace("/category_name=([^&])+/i", '', $query);
	// Clean up dangling ampersands.
	trim($query, "&");
}
if (stristr($query, 'cat=')) {
	$query = preg_replace("/cat=([^&])+/i", '', $query);
	// Clean up dangling ampersands.
	trim($query, "&");
}
return $query;
}

///////////////////////////////
function vhost_join($join = '') {
global $wpdb, $vhost_cat;

if ('' == $vhost_cat) { return $join; }

// we don't want to join on pages
// because pages don't technically have categories
if (is_page()) { return; }

if (false === stristr($join, " INNER JOIN $wpdb->post2cat ON ")) {
	$join .= " INNER JOIN $wpdb->post2cat ON $wpdb->posts.ID=$wpdb->post2cat.post_id AND category_id=$vhost_cat ";
}
return $join;
} // vhost_join

/////////////////////////////////
function vhost_list_pages($output = '') {
global $wpdb, $vhost_cat;

if ('' == $vhost_cat) { return $output; }
if ('' == $output) { return $output; }

$foo = preg_replace('/<li class="page_item( current_page_item)?"><a href="[^"]+" title="[^"]+">[^<]+<\/a><\/li>\s+/', '%%', $output);

$vhost_owner = $wpdb->get_var("SELECT ID FROM $wpdb->users WHERE user_url = 'http://" . strtolower($_SERVER['HTTP_HOST']) . "'");

$vhost_pages = $wpdb->get_results("SELECT ID, post_title, post_name from $wpdb->posts WHERE post_status = 'static' AND post_author = $vhost_owner");

if (empty($vhost_pages)) { return ''; }

$text = '';
foreach ($vhost_pages as $p) {
	$text .= '<li class="page_item"><a href="' . get_permalink($p->ID) . '" title="' . $p->post_title . '">' . $p->post_title . '</a></li>';
}
$new_output = preg_replace('/%+/', $text, $foo);

return $new_output;
}

///////////////////////////////////
function vhost_admin_head ($unused) {
global $vhost_cat;

if ('' != $vhost_cat) {
	if (preg_match('|/wp-admin/post.php|', $_SERVER['REQUEST_URI'])) {
		ob_start('vhost_ob_post');
	}
	if (preg_match('|/wp-admin/profile.php|', $_SERVER['REQUEST_URI'])) {
		ob_start('vhost_ob_profile');
	}
}
}

////////////////////////////////
function vhost_ob_post($content) {
global $vhost_cat;
$cat = '<fieldset id="categorydiv"><legend><a href="http://wordpress.org/docs/reference/post/#category" title="' . __('Help on categories') . '">' . __('Categories') . '</a></legend><div>';
$cat .= "<label for='category-$vhost_cat' class='selectit'><input value='$vhost_cat' type='hidden' name='post_category[]' />" . wp_specialchars(get_catname($vhost_cat)) . '</label></div></fieldset>';

return preg_replace('|<fieldset id="categorydiv">.*?</fieldset>|si', $cat, $content);
}

///////////////////////////////////
function vhost_ob_profile($content) {
$vhost = $_SERVER['HTTP_HOST'];
$url = '<input type="hidden" name="newuser_url" id="newuser_url2" value="http://' . $vhost . '" />http://' . $vhost;

return preg_replace('|<input type="text" name="newuser_url" id="newuser_url2" value="[^"]+" />|si', $url, $content);
}

////////////////////////
function vhost_redirect() {
global $wpdb, $user_level, $user_url;

get_currentuserinfo();

$vhost = strtolower($_SERVER['HTTP_HOST']);
$site = parse_url(strtolower($user_url));

if ( ('10' != $user_level) && ($vhost != $site['host']) ) {
	header("Location: http://" . $site['host'] . "/wp-admin/");
	die;
}
}

?>
