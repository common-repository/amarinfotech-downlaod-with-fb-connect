<?php
/*  Copyright 2012 Amarinfotech (email : info at amarinfotech.com)

    This program is free software; you can redistribute it and/or modify
    it under the terms of the GNU General Public License as published by
    the Free Software Foundation; either version 2 of the License, or
    (at your option) any later version.

    This program is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.

    You should have received a copy of the GNU General Public License
    along with this program; if not, write to the Free Software
    Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA
*/

/*
Plugin Name:  Facebook Connect with downloadable file
Author: Amarinfotech
Author URI: http://amarinfotech.com/
Description: Integrate Facebook and Wordpress with Facebook Connect.  Provides post on users wall and register them on your blog. It will check in the content for extension specified for fb login require. It will restric users to download file extension specified by Admin and allow user to download that files after log into wordpress as well as when ever admin publish new post or page it will be post on user's wall. It requires a <a href="http://www.facebook.com/developers/">Facebook API Key</a> for use.
Version: 1.0
*/

require_once('common.php');
require_once('config.php');


define('FBC_APP_KEY_OPTION', 'fbc_app_key_option');
define('FBC_APP_SECRET_OPTION', 'fbc_app_secret_option');
define('FBC_APP_EXTENSIONS_OPTION', 'fbc_app_extensions_option');
define('FBC_LAST_UPDATED_CACHE_OPTION', 'fbc_last_updated_cache_option');
define('FBC_REMOVE_NOFOLLOW_OPTION', 'fbc_remove_nofollow_option');

function fbc_get_fbconnect_user() {
  return fbc_facebook_client()->get_loggedin_user();
}

function fbc_login_header($login_state, $fbuid, $wpuid) {
  if (FBC_DEBUG_LOGIN_HEADER) {
    header("X-FBC-Login: $login_state fbuid=$fbuid, wpuid=$wpuid");
  }
}

function fbc_init_auth() {

  $fbuid = fbc_facebook_client()->get_loggedin_user();
  $user = wp_get_current_user();
  $assoc_fbuid = fbc_get_fbuid($user->ID);

  if ($assoc_fbuid) {
    if ($fbuid == $assoc_fbuid) {
       // user is already logged in to both
      fbc_login_header('logged in', $fbuid, $user->ID);
      return;
    } else {
      //wp session, no fbsession = logout of wp and reload page
      // or, user is logged in under a different fb account
      wp_logout();
      header('Location: ' . $_SERVER['REQUEST_URI']);
      fbc_login_header('logging user out assoc='. $assoc_fbuid,
                       $fbuid, $user->ID);
      exit();
    }
  } else {
     if ($user->ID) {
       fbc_login_header('non-facebook user', $fbuid, $user->ID);
       // wpuser not associated w/ fb.  do nothing
       return;
     } else if($fbuid) {
       // not a wp user, but fb authed = log them in to wp
       $res = fbc_login_if_necessary();
       if ($res > 0) {
		$wp_uid = fbc_fbuser_to_wpuser($res);
         wp_set_current_user($wp_uid);
         fbc_login_header('login sucessful', $fbuid, $wp_uid);
       } else {
         fbc_login_header('login error ' . $res, $fbuid, $user->ID);
       }
     } else {
       fbc_login_header('anonymous', 0, 0);
       // neither facebook nor wordpress, do nothing
       return;
     }
  }
}

function fbc_init() {

  if (fbc_is_configured()) {

    add_action('init', 'fbc_init_auth');

    /* Includes any necessary js in the page, registers onload handlers,
       and prints the absolutely positioned "Welcome, username" div.
    */
    add_action('wp_footer', 'fbc_footer');

    /* Adds a "Connect" button to the login form, and handles fbconnect
      logout when a wordpress logout is performed.
    */
    add_action('login_form', 'fbc_login_form');

    /* Prints fbml with the user's profile pic when that user is a
      facebook connect user.
    */
    add_filter('get_avatar', 'fbc_get_avatar', 10, 4);

    /*
      Make sure the comment author info is in sync with the db.
    */
    add_filter('get_comment_author', 'fbc_get_comment_author');
    add_filter('get_comment_author_url', 'fbc_get_comment_author_url');
	
	/*
      Rahul- Add filter to content
    */
	add_filter( 'the_content', 'show_file_after_fblogin' );
	// Rahul - This action fire when publish post or page
	//Rahul - This is for updating facebook users wall - Rahul
	add_action( 'add_meta_boxes', 'myplugin_add_custom_box' );
	add_action( 'save_post', 'myplugin_save_postdata' );
	add_action('save_post', 'fb_post_friends');


    /* Remove nofollow from links back to profile pages */
    if (get_option(FBC_REMOVE_NOFOLLOW_OPTION) === 'true') {
      add_filter('get_comment_author_link', 'fbc_remove_nofollow');
    }

    /* Add the xmlns:fb namespace to the page.  This is necessary to be
       stricty correct with xhtml, and more importantly it's necessary for
       xfbml to work in IE.
    */
    add_filter('language_attributes', 'fbc_language_attributes');

    add_action('wp_head', 'fbc_og_head');

    /* Setup feedforms and post-specific data.
    */
    add_action('comment_form', 'fbc_comment_form_setup');

    /* Why do this? So you can print the form with
       do_action('fbc_comment_form') and not spew errors if the
       plugin is removed.
    */
    add_action('fbc_display_login_state', 'fbc_display_login_state');
    add_action('fbc_display_login_button', 'fbc_display_login_button');

  }

  /* Install the admin menu.
  */
  add_action('admin_menu', 'fbc_add_options_to_admin');

}

/* Adds a box to the main column on the Post and Page edit screens */
function myplugin_add_custom_box() {
    add_meta_box( 
        'myplugin_sectionid',
        __( 'Post on facebook user\'s wall', 'myplugin_textdomain' ),
        'myplugin_inner_custom_box',
        'post' 
    );
    add_meta_box(
        'myplugin_sectionid',
        __( 'Post on facebook user\'s wall', 'myplugin_textdomain' ), 
        'myplugin_inner_custom_box',
        'page'
    );
}
/* Prints the box content */
function myplugin_inner_custom_box( $post ) {

  // Use nonce for verification
  wp_nonce_field( plugin_basename( __FILE__ ), 'myplugin_noncename' );

  // The actual fields for data entry
  echo '<label for="myplugin_new_field">';
       _e("Check it if you allow this content to post on facebook user's wall", 'myplugin_textdomain' );
  echo '</label> ';
  echo '<input type="checkbox" id="myplugin_new_field" name="myplugin_new_field" value="postonwall" />  ';
}

/* When the post is saved, saves our custom data */
function myplugin_save_postdata( $post_id ) {
	
  // verify if this is an auto save routine. 
  // If it is our form has not been submitted, so we dont want to do anything
  if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) 
      return;

  // verify this came from the our screen and with proper authorization,
  // because save_post can be triggered at other times

  if ( !wp_verify_nonce( $_POST['myplugin_noncename'], plugin_basename( __FILE__ ) ) )
      return;

  
  // Check permissions
  if ( 'page' == $_POST['post_type'] ) 
  {
    if ( !current_user_can( 'edit_page', $post_id ) )
        return;
  }
  else
  {
    if ( !current_user_can( 'edit_post', $post_id ) )
        return;
  }

  // OK, we're authenticated: we need to find and save the data

  $mydata = $_POST['myplugin_new_field'];

  // Do something with $mydata 
  // probably using add_post_meta(), update_post_meta(), or 
  // a custom table (see Further Reading section below)
}

function fb_post_friends()
{
	
	if(isset($_POST['myplugin_new_field']))
	{
		$app_key = get_option(FBC_APP_KEY_OPTION);	
		$app_secret = get_option(FBC_APP_SECRET_OPTION);
		$extensions = get_option(FBC_APP_EXTENSIONS_OPTION);
		
		global $post;	
		$content 	= substr(strip_tags($post->post_content),0,300).'....';			
		$tit		= $post->post_title;	
		$hreflink	= $post->guid;	
		$name = get_bloginfo('name');	
		
		$szPostContent = $post->post_content;
		$szSearchPattern = '/(?<!_)src=([\'"])?(.*?)\\1/';
		
		// Run preg_match_all to grab all the images and save the results in $aPics
		preg_match_all( $szSearchPattern, $szPostContent, $aPics );
		
		
		// Check to see if we have at least 1 image
		$iNumberOfPics = count($aPics[0]);
		
		if ( $iNumberOfPics > 0 ) 
		{
			 // Now here you would do whatever you need to do with the images
			 // For this example the images are just displayed
			 for ( $i=0; $i < $iNumberOfPics ; $i++ ) {
				 $img1 = substr($aPics[0][$i],0,-1);				 
				 $myimg[] = substr($img1,5,strlen($img1));
			 }
		}
		else
		{
			$myimg[0] = plugins_url() . '/wp-facebookconnect/images/applogo.png';
		}
		
		// Set all attributes require for the post 				
		$attachment = array	(		
		'message' => $tit,		
		'picture' => $myimg[0],
		'link' => $hreflink,
		'name' => $tit,		
		'caption' => $name,	
		'description' => $content);
		
	
		// create facebook SDk object	
		$rrd = new Rahulfacebook(array(        
		'appId' => $app_key,        
		'secret' => $app_secret,    ));	
		
		$access_token = $rrd->getAccessToken();
				
		global $wpdb;	
		$wp_user_search = $wpdb->get_results("SELECT ID, display_name,user_login FROM $wpdb->users ORDER BY ID");	
		
		
		//check for wordpress user and if have permission for posr to wall.
		//It will post on user's wall and delete user's who are unsubscribe application from facebook account
		$cnt = 0;	
		foreach ( $wp_user_search as $userid ) 	
		{		
			$user_id       = (int) $userid->ID;				
			$user_info     = get_userdata($user_id);				
			if(substr($user_info->user_login,0,2) == 'fb')		
			{			
				$arr[$cnt] = str_replace('fb','',$user_info->user_login);
				if($access_token) 
				{
					
					$permissions = $rrd->api( "/".$arr[$cnt]."/permissions" );
					$perdata = count($permissions['data']);
					if($perdata > 0)
					{
						$appcnt = count($permissions['data']);
						if( array_key_exists('publish_stream', $permissions['data'][0]) && $appcnt > 0 ) 
						{
							$rrd->api('/'.$arr[$cnt].'/feed', 'post', $attachment);			
						}
						else
						{
							//wp_delete_user($user_id);
							$user = new WP_User($user_id);
							$user->set_role('');
						}
					}
					else
					{
						//wp_delete_user($user_id);
						$user = new WP_User($user_id);
						$user->set_role('');
					}
					
					$cnt++;	
				}
			}	
		}
	}
}

function show_file_after_fblogin($content)
{
	
	$pageURL = 'http';
	 if ($_SERVER["HTTPS"] == "on") {$pageURL .= "s";}
	 $pageURL .= "://";
	 if ($_SERVER["SERVER_PORT"] != "80") {
	  $pageURL .= $_SERVER["SERVER_NAME"].":".$_SERVER["SERVER_PORT"].$_SERVER["REQUEST_URI"];
	 } else {
	  $pageURL .= $_SERVER["SERVER_NAME"].$_SERVER["REQUEST_URI"];
	 }
	
	//filter file content and find the extension specified in admin setting
	$posttext = $content;		
	
	$document = DOMDocument::loadHTML($posttext);
	$xpath = new DOMXPath($document);
	
	$hrefs = $xpath->query('//a/@href');
	$count = $hrefs->length;
	$myarray = array();
	$cnt = 0;		
	
	foreach($hrefs as $href)
	{
		$info = pathinfo($href->nodeValue);	
		$extensions = get_option(FBC_APP_EXTENSIONS_OPTION);
		$uprext = strtoupper($extensions);
		$lwrext = strtolower($extensions);
		$expuprext = explode(',',$uprext);
		$explwrext = explode(',',$lwrext);
	
		if(in_array($info['extension'],$expuprext) || in_array($info['extension'],$explwrext) )
		{
			$myarray[$cnt] = $href->nodeValue;
			$cnt++;
		}
	}
	
	$cnten = htmlspecialchars($content);
	$tot = count($myarray);
	
		
	if($tot <= 0)
	{
		echo $content;
	}
	elseif($tot > 0 && is_user_logged_in())
	{
		echo $cont = fbc_render_login_state();
		echo $content;
	}
	elseif($tot > 0 && !is_user_logged_in())
	{
		if($tot > 0 && !is_user_logged_in())
		{
			for($i=0;$i<$tot;$i++)
			{
				$myarray[$i];
				$cnten = str_replace($myarray[$i],"javascript:alert('Please login to Facebook to download File')",$cnten);				
			}
			$cnten = str_replace('_blank','_self',$cnten);	
			$b = html_entity_decode($cnten);
			//echo $b = preg_replace('/(<a\b[^><]*)>/i', '$1 style="color:red;">', $b);
			//echo $cnten = html_entity_decode($cnten);
			$dir = plugins_url() . '/amr-fbdownload/images/fb_nouser.jpg';
			$dir1 = plugins_url() . '/amr-fbdownload/images/facebook_login.jpg';
			$result = '		
				<div class="facebook-download">
				<table width="100%" cellpadding="3" border="0" class="noauth">
					<tbody>
						<tr>
							<td width="10%" valign="top" class="image" style="text-align:center"> 
							<img width="48px" height="48px" alt="Facebook" src="'.$dir.'">
							</td>
							<td width="85%" valign="top" style="padding-top: 18px !important; padding-left:22px !important;" > 
								<b>Welcome to GujaratiGupShup </b>
								<br> <span style="float:left;">Login via &nbsp;</span>
								<span style="float:left;"><a href="'.render_fbconnect_button().'"><img src="'.$dir1.'" /></a></span>
								<span style="float:left;">&nbsp;to download the file! </span><br>	<br>						
								<div style="float:left;margin-top: 3px;margin-left: 6px;"> 
								<small>By logging in you agree to subscribe to our montly newsletter.</small>
								</div>
								<div style="clear:both"></div>
							</td>
						</tr>
					</tbody>
				</table>
				</div><span class="clear"></span>';
				
				echo $result.$b;
		}
	}
}



function fbc_add_options_to_admin() {
  if (function_exists('add_options_page')) {
    add_options_page('Amar FB Download',
                     'Amar FB Download',
                     8,
                     __FILE__,
                     'fbc_admin_options');
  }
}

function fbc_remove_nofollow($anchor) {
  global $comment;
  // Only remove for facebook comments, since url is trusted
  if ($comment->user_id && fbc_get_fbuid($comment->user_id)) {
    return preg_replace('/ rel=[\"\'](.*)nofollow(.*?)[\"\']/', ' rel="$1 $2" ', $anchor);
  }
  return $anchor;
}


function fbc_is_app_config_valid($api_key, $secret, &$error) {
   $facebook = new Facebook($api_key,
                             $secret,
                             false,
                             'connect.facebook.com');

  $api_client = $facebook->api_client;
  // The following line causes an error in the PHP admin console if
  // you are using php4.
  try { // ATTENTION: This plugin is not compatible with PHP4
    $api_client->admin_getAppProperties(array('application_name'));
    $success = true;
  } catch(Exception $e) {
    $success = false;
    $error = $e->getMessage();
  }

  return $success;
}

function fbc_get_comment_author_url($url) {
  global $comment;
  if ($comment->user_id) {
    $user = get_userdata($comment->user_id);
    if ($user) {
      return $user->user_url;
    } else {
      return null;
    }
  } else {
    return $url;
  }
}

function fbc_get_comment_author($author) {
  global $comment;

  // normally wordpress will not update the name of logged in comment
  // authors if they change it after the fact.  This hook makes sure
  // we use the most recent name when displaying comments.
  if ($comment->user_id) {
    $user = get_userdata($comment->user_id);
    if ($user) {
      if ($fbuid = fbc_get_fbuid($comment->user_id)) {
        // Wrap in fb:name.  This helps if the name wasn't populated
        // correctly on initial login
        return
          '<fb:name linked="false" useyou="false" uid="' . $fbuid . '">' .
          fbc_txt2html($user->display_name) .
          '</fb:name>';
      } else {
        return fbc_txt2html($user->display_name);
      }
    } else {
      // This probably means the account was deleted.
      // Should it says something like "Unknown user" or "deleted account"?
      return _(FBC_ANONYMOUS_DISPLAYNAME);
    }
  } else {
    return $author;
  }
}

function fbc_clear_config() {
  update_option(FBC_APP_KEY_OPTION, null);
  update_option(FBC_APP_SECRET_OPTION, null);
  update_option(FBC_APP_EXTENSIONS_OPTION, null);
  
}

function fbc_is_configured() {
  $app_key = get_option(FBC_APP_KEY_OPTION);
  $app_secret = get_option(FBC_APP_SECRET_OPTION);
  $extensions = get_option(FBC_APP_EXTENSIONS_OPTION);
  return !empty($app_key) && !empty($app_secret);
}

function fbc_language_attributes($output) {
  return $output .
    ' xmlns:fb="http://www.facebook.com/2008/fbml" '.
    ' xmlns:og="http://opengraphprotocol.org/schema/" ';
}

function fbc_og_head() {
  if (!is_singular()) {
    return ;
  }
  $title = ltrim(wp_title($sep='',$display=false,$seplocation=''));
  $name = get_option('blogname');
  echo
    '<meta property="og:site_name" content="'.fbc_txt2html($name).'" />'.
    '<meta property="og:title" content="'.fbc_txt2html($title).'" />';
}

function fbc_txt2html($raw_str) {
  return htmlspecialchars($raw_str, ENT_QUOTES, 'UTF-8');
}

function fbc_render_like() {
  $permalink = fbc_current_permalink();
  if (!$permalink) {
    return '';
  }
  $href = urlencode($permalink);
  return
     '<iframe src="http://www.facebook.com/plugins/like.php?href='.
    $href.'" scrolling="no" frameborder="0" '.
    'style="border:none; overflow:hidden; width:450px; "></iframe>';
}

function fbc_current_permalink() {
  if (!is_singular()) {
    return null;
  }

  global $wp_query;
  return get_permalink($wp_query->get_queried_object_id());
}

/*
 * Generated and process the administrative options panel, for api key
 * and secret configuration.
 */
function fbc_admin_options() {

  $hidden_field_name = 'mt_submit_hidden';

  // Read in existing option value from database
  $app_key = get_option(FBC_APP_KEY_OPTION);
  $app_secret = get_option(FBC_APP_SECRET_OPTION);
  $extensions = get_option(FBC_APP_EXTENSIONS_OPTION);
  $remove_nofollow = get_option(FBC_REMOVE_NOFOLLOW_OPTION);
  if ($remove_nofollow === false) {
    // set default
    $remove_nofollow = 'true';
    update_option(FBC_REMOVE_NOFOLLOW_OPTION, $remove_nofollow);
  }

  // See if the user has posted us some information
  // If they did, this hidden field will be set to 'Y'
  if( $_POST[ $hidden_field_name ] == 'Y' ) {

      // Read their posted value
      $app_key = $_POST[FBC_APP_KEY_OPTION];
      $app_secret = $_POST[FBC_APP_SECRET_OPTION];
	  $extensions = $_POST[FBC_APP_EXTENSIONS_OPTION];
      $remove_nofollow = isset($_POST[FBC_REMOVE_NOFOLLOW_OPTION]) ? 'true' : 'false';

      $error = null;
      if (fbc_is_app_config_valid($app_key, $app_secret, $error)) {
        // Save the posted value in the database
        update_option(FBC_APP_KEY_OPTION, $app_key);
        update_option(FBC_APP_SECRET_OPTION, $app_secret);
		update_option(FBC_APP_EXTENSIONS_OPTION, $extensions);
        update_option(FBC_REMOVE_NOFOLLOW_OPTION, $remove_nofollow);

        fbc_set_callback_url();

        echo fbc_update_message(__('Options saved.', 'mt_trans_domain' ));

      } else {
        echo fbc_update_message(__("Failed to set API Key.  Error: $error", 'mt_trans_domain' ));
      }

    }

    echo '<div class="wrap">';
    echo "<h2>" . __( 'Facebook Connect Plugin Options', 'mt_trans_domain' ) . "</h2>";
    $form_action = str_replace('%7E', '~', $_SERVER['REQUEST_URI']);
    echo <<<EOF
<div>
<br/>To use Facebook Connect you will first need to get a Facebook API Key:
<ol>
<li>Visit <a target="_blank" href="http://www.facebook.com/developers/createapp.php?version=new">the Facebook application registration page</a>.
<li>Enter a descriptive name for your blog in the "Application Name"
    field.  This will be seen by users when they sign up for your
    site.</li>
<li>Submit</li>
<li>Copy the displayed API Key and Secret into this form.</li>
<li>Recommended: Upload icon images on the app configuration page.  These images are seen as the icon in newsfeed stories and when the user is registering with your application</li>
</ol>
<hr/>
<form name="form1" method="post" action="$form_action">
EOF;

  echo fbc_tag_input('hidden', $hidden_field_name, 'Y');
  echo fbc_tag_p(__("API Key:", 'mt_trans_domain'),
                 fbc_tag_input('text', FBC_APP_KEY_OPTION, $app_key, 50));
  echo fbc_tag_p(__("Secret:", 'mt_trans_domain' ),
                 fbc_tag_input('text', FBC_APP_SECRET_OPTION, $app_secret, 50));
  echo fbc_tag_p(__("Extensions:", 'mt_trans_domain' ),
                 fbc_tag_input('text', FBC_APP_EXTENSIONS_OPTION, $extensions, 50));
  echo "Enter comma seperated extensions ex: pdf,mp3,doc,xls,csv.....etc";
  echo fbc_tag_p(__('Strip nofollow from Facebook comment author links:',
                 'mt_trans_domain'),
                 '<input type="checkbox" name="'.FBC_REMOVE_NOFOLLOW_OPTION .'" '.
                 ($remove_nofollow === 'true' ? 'checked' : '') .' value="true" />');

  echo fbc_tag_p(__('Last user data update:', 'mt_trans_domain'),
                 get_option(FBC_LAST_UPDATED_CACHE_OPTION));
				 
				echo "<hr>";
				echo "<b>Latest Plugin Updates:</b>";
				echo "<hr>";
								
				$rss = new DOMDocument();
				$rss->load('http://www.amarinfotech.com/article_feed.php');
				$feed = array();
				foreach ($rss->getElementsByTagName('item') as $node) 
				{
					$item = array ( 
					'title' => $node->getElementsByTagName('title')->item(0)->nodeValue,
					'desc' => $node->getElementsByTagName('description')->item(0)->nodeValue,
					'link' => $node->getElementsByTagName('link')->item(0)->nodeValue,
					'date' => $node->getElementsByTagName('date')->item(0)->nodeValue,
					);
					array_push($feed, $item);
				}
				$limit = 1;
				for($x=0;$x<$limit;$x++) {
				$title = str_replace(' & ', ' &amp; ', $feed[$x]['title']);
				$link = $feed[$x]['link'];
				$description = $feed[$x]['desc'];
				$date = date('l F d, Y', strtotime($feed[$x]['date']));
				echo '<p><strong><a href="'.$link.'" title="'.$title.'">'.$title.'</a></strong><br />';
				echo '<small><em>Posted on '.$date.'</em></small></p>';
				echo '<p>'.$description.'</p>';
				}

?>
<hr />

<p class="submit">
<input type="submit" name="Submit" value="<?php _e('Update Options', 'mt_trans_domain' ) ?>" />
</p>

</form>

</div>

<?php
}

function this_plugin_path() {
  $path = basename(dirname(__FILE__));
  return get_option('siteurl').'/'. PLUGINDIR .'/' . $path;
}

function fbc_render_static_resource() {
  $plugin_dir = this_plugin_path() . '/';

  $featureloader =
    'http://static.ak.connect.facebook.com/js/api_lib/v0.4/FeatureLoader.js.php';

  return  <<<EOF
<link type="text/css" rel="stylesheet" href="$plugin_dir/fbconnect.css"></link>
<script src="$featureloader" type="text/javascript"></script>
<script src="$plugin_dir/fbconnect.js" type="text/javascript"></script>
EOF;

}

function fbc_register_init($app_config='reload') {
  $plugin_dir = this_plugin_path() . '/';

  $site_url = get_option('siteurl');

  $user = wp_get_current_user();

  $init = "FBConnect.init('%s', '%s', '%s', %d, FBConnect.appconfig_%s);";
  fbc_footer_register(sprintf($init,
                              get_option(FBC_APP_KEY_OPTION),
                              $plugin_dir,
                              $site_url,
                              $user->ID,
                              $app_config),
                      $prepend=true);

}

function _fbc_flush_footer_js() {
  global $onloadJS;
  $onloadJS[] = '';
  $js = implode("\n", $onloadJS);
  $onloadJS = null;

  return <<<EOF
<script type="text/javascript">
$js
</script>
EOF;
}

function fbc_footer() {

  /*
    Normally this div is inserted by javascript via document.write(),
    but for XML documnts (XHTML) this fails.  Workaround by including
    the div at page generation time.
   */
  echo
    '<div style="position: absolute; top: -10000px; left: -10000px; '.
    ' width: 0px; height: 0px;" id="FB_HiddenContainer"></div>';

  fbc_update_facebook_data();

  fbc_register_init();

  echo fbc_render_static_resource();

  /*
    Only render this if it hasn't already been done elsewhere.
   */
  if (FBC_USER_PROFILE_WINDOW &&
      empty($GLOBALS['FBC_HAS_RENDERED_LOGIN_STATE'])) {
    echo '<div class="fbc_loginstate_top">'.
      fbc_render_login_state() .
      '</div>';
  }

  echo _fbc_flush_footer_js();

  echo fbc_render_debug_info();
}

/*
 Generates the absolutely positioned box declaring who your are logged
 in as.
*/
function fbc_display_login_state() {
  echo fbc_render_login_state();
}

function fbc_login_form() {
  echo fbc_render_static_resource();
  $dir1 = plugins_url() . '/wp-facebookconnect/images/facebook_login.jpg';
 // echo '<a href="'.render_fbconnect_button("FBConnect.redirect_home()").'" target="_blank"><img src="'.$dir1.'"</a>';

  // The 'none' config prevents unnessary reloads on logout
  fbc_register_init($appconfig='none');

  if ($_GET['loggedout']) {
    /* Discussed in
     http://wiki.developers.facebook.com/index.php/Authenticating_Users_on_Facebook
   */
    fbc_footer_register('FBConnect.logout();');

  }

  echo _fbc_flush_footer_js();
}

/*
 * Show debug info, if available.
 */
function fbc_render_debug_info() {
  if (!empty($GLOBALS['FBC_DEBUGINFO'])) {
    $dbg = $GLOBALS['FBC_DEBUGINFO'];
    return <<<EOF
<pre>
$dbg
</pre>
EOF;
  }
}

function fbc_render_login_state() {
  global $FBC_HAS_RENDERED_LOGIN_STATE;
  $FBC_HAS_RENDERED_LOGIN_STATE = true;

  $fbuid = fbc_get_fbconnect_user();
  if (!$fbuid ) {
   echo '<!-- no logged in Facebook user -->';
   return; // don't display anything if not logged in   
 
 
                
  }
	$btn = render_fbconnect_button();
 
 
  return sprintf('
<div class="facebook-download">
<div id="fbc_profile" class="fbc_profile_header">
<div class="fbc_profile_pic">
<fb:profile-pic uid="%d" facebook-logo="true" size="square"></fb:profile-pic>
</div>
<b>Download all songs and file from GujaratiGupShup</b> <br />
Welcome, <fb:name uid="%d" capitalize="true" useyou="false"></fb:name>
<br/>
<a onclick="FBConnect.logout(); return false" href="#">Logout of Facebook</a>
<div style="clear: both"></div>
</div>
</div>
', $fbuid, $fbuid);
}

function fbc_comment_form_setup() {

  if (fbc_get_fbconnect_user()) {

    echo fbc_render_like();

    $blogname = get_option('blogname');
    $article_title = ltrim(wp_title($sep='',$display=false,$seplocation=''));

    global $post;

    $excerpt = strip_tags($post->post_content);

    $excerpt_len = 1024;
    if (strlen($excerpt) > $excerpt_len) {
       $excerpt = substr($excerpt, 0, $excerpt_len) . "...";
    }

    fbc_set_js_var('excerpt', $excerpt);
    fbc_set_js_var('blog_name', $blogname);
    fbc_set_js_var('article_title', $article_title);

    fbc_footer_register("FBConnect.setup_feedform();");
  }
}

function fbc_set_js_var($name, $value) {
  fbc_footer_register('FBConnect.'.$name .'='.json_encode($value));
}

function fbc_display_login_button($hidden=false) {
  $user = wp_get_current_user();
  if($user->ID) {
    // For the moment disallow connecting existing accounts
    return;
  }

  if ($hidden) {
    $visibility = 'style="visibility:hidden"';
  } else {
    $visibility = '';
  }

  $site_url = get_option('siteurl');

  return $button = render_fbconnect_button();
 /*
  echo <<<EOF
<div class="fbc_hide_on_login fbc_connect_button_area" $visibility  id="fbc_login">
<span><small>Connect with your Facebook Account</small></span> <br/> $button
</div>

EOF;*/
}


function fbc_get_fbuid($wpuid) {
  static $fbc_uidcache = null;
  if ($fbc_uidcache === null) {
    $fbc_uidcache = array();
  }

  if (isset($fbc_uidcache[$wpuid])) {
    return $fbc_uidcache[$wpuid];
  }

  if (!$wpuid) {
    $fbuid = 0;
  } else {
    $fbuid = get_usermeta($wpuid, 'fbuid');
  }

  return ($fbc_uidcache[$wpuid] = $fbuid);
}


function fbc_get_avatar($avatar, $id_or_email, $size, $default) {
  if (!is_object($id_or_email)) {
    return $avatar;
  }

  if ($fbuid = fbc_get_fbuid($id_or_email->user_id)) {
    return render_fb_profile_pic($fbuid);
  } else {
    return $avatar;
  }
}


/* automatically set the callback url on the app so the user doesn't
 * have to.
 */
function fbc_set_callback_url() {
  $current_props = fbc_api_client()->admin_getAppProperties(array('connect_url'));
  if (!empty($current_props['connect_url'])) {
    return;
  }

  $proto = !empty($_SERVER['HTTPS']) ? 'https://' : 'http://';
  $server_root =  $proto . $_SERVER['SERVER_NAME'];
  $properties = array('connect_url' => $server_root);
  return fbc_api_client()->admin_setAppProperties($properties);
}


/*
 * Accumulates a list of javascript to be executed at the end of the
 * page.  Usage:
 * fbc_footer_register('some_javascript_function();');
 *
 */
function fbc_footer_register($js, $prepend=false) {
  global $onloadJS;
  if (!$onloadJS) {
    $onloadJS = array();
  }
  if ($prepend) {
    array_unshift($onloadJS, $js);
  } else {
    $onloadJS[] = $js;
  }
}


function fbc_update_facebook_data($force=false) {
  $last_cache_update = get_option(FBC_LAST_UPDATED_CACHE_OPTION);
  $delta = time() - $last_cache_update;
  if ($delta < 24*60*60 && !$force) {
    return;
  }

  update_option(FBC_LAST_UPDATED_CACHE_OPTION,
                time());

  global $wpdb;
  $sql = "SELECT user_id, meta_value FROM $wpdb->usermeta WHERE meta_key = 'fbuid'";
  $res = $wpdb->get_results($wpdb->prepare($sql), ARRAY_A);
  if (!$res) {
    return -1;
  }

  $fbuid_to_wpuid = array();
  foreach($res as $result) {
    $fbuid_to_wpuid[$result['meta_value']] = $result['user_id'];
  }

  try {
    $userinfo = fbc_anon_api_client()->users_getInfo(
      array_keys($fbuid_to_wpuid),
      fbc_userinfo_keys());

  } catch(Exception $e) {
    return -1;
  }

  $userinfo_by_fbuid = array();
  foreach($userinfo as $info) {

    $wpuid = $fbuid_to_wpuid[$info['uid']];

    $userdata = fbc_userinfo_to_wp_user($info);
    $userdata['ID'] = $wpuid;

    wp_update_user($userdata);
  }

  return count($userinfo);
}

function fbc_tag_p() {
  $args = func_get_args();
  $inner = implode("\n", $args);
  return "<p>\n$inner</p>\n";
}

function fbc_tag_input($type, $name, $value=null, $size=null) {

  $vals = array("type" => $type,
                "name" => $name);
  if ($value !== null) {
    $vals['value'] = $value;
  }

  if ($size !== null) {
    $vals['size'] = $size;
  }

  $inner = '';
  foreach($vals as $k => $v) {
    $inner .= sprintf("%s='%s' ", $k, $v);
  }

  return "<input $inner />";

}

function fbc_update_message($message) {
  return <<<EOF
<div class="updated"><p><strong>$message</strong></p></div>
EOF;
}


// start it up.
fbc_init();


