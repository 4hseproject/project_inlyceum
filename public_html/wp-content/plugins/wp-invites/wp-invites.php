<?php
/*
Plugin Name: WP-invites
Author URI: http://jehy.ru/articles/
Plugin URI: http://jehy.ru/articles/2009/02/09/wordpress-plugins/
Description: Invites system for WordPress, (WordPress MU and BuddyPress)!
To set up, visit <a href="options-general.php?page=wp-invites/wp-invites.php">configuration panel</a>.
Author: Jehy, themaster507
Version: 2.52
*/
if (!function_exists('str_split')) {
    function str_split($str, $l = 1)
    {
        $str_array = explode("\r\n", chunk_split($str, $l));
        return $str_array;
    }
}


class wp_invites
{
    var $debug_log, $options;

    function output_debug()
    {
        echo "\n<!--wp-invites debug:\n" . implode("\n\n", $this->debug_log) . "\n-->";
    }

    function wp_invites()
    {
        $this->debug_log = array();
        $this->init();
        #add_action('init', array($this, 'init'), 99);
    }

    function debug_info($info, $return = 0)
    {
        if ($this->options['debug']) {
            $t = "\n<!--wp-invites debug:\n" . $info . "\n-->";
            $this->debug_log[] = $info;
            if ($return)
                return $t;
        }
    }

    function init_lang()
    {
        $plugin_dir = basename(dirname(__FILE__));
        load_plugin_textdomain('wp-invites', false, $plugin_dir . '/lang');
    }


    function init()
    {
        $this->invites_get_options();
        @session_start();
        $this->init_lang();


        if ($this->options['IS_WPMU']) {
            #for WPMU and buddypress

            #validate signup for wpmu
            add_filter('wpmu_validate_user_signup', array($this, 'invites_validate_signup_fields'), 99, 1);

            #activate - add meta for wpmu
            add_filter('wpmu_activate_user', array($this, 'invites_on_activate_user'), 1, 3);

            #add registration field in wpmu
            add_action('signup_extra_fields', array($this, 'invites_add_signup_fields'));
        }
        if ($this->options['IS_BUDDYPRESS']) {
            #validate fields
            add_action('bp_signup_validate', array($this, 'invites_validate_signup_fields'), 99, 1);

            #add registration field in buddy
            add_action('bp_before_account_details_fields', array($this, 'invites_add_signup_fields'), 99);

            #output code in buddy profile
            add_action('bp_after_profile_header_content', array($this, 'bp_output_invites'), 99);

            #set meta for buddy
            add_filter('bp_signup_usermeta', array($this, 'wpmu_invites_add_signup_meta'), 1, 1);

            #if blog is selected, it is necessary...
            add_filter('bp_core_account_activated', array($this, 'bp_invites_on_activate_user'), 1, 2);
        }

        if ($this->options['IS_WPMU'] && !$this->options['IS_BUDDYPRESS'])#for MU only
        {
            #set meta for wpmu
            add_filter('add_signup_meta', array($this, 'wpmu_invites_add_signup_meta'), 1, 1);
        }
        if (!$this->options['IS_WPMU'])#for simple WordPress
        {
            add_action('register_form', array($this, 'invites_add_signup_fields'));
            add_filter('registration_errors', array($this, 'invites_validate_signup_fields'), 99, 1);
            add_action('user_register', array($this, 'wp_invites_on_activate_user'));
        }
        add_action('admin_menu', array($this, 'invites_add_admin_menu'));

        #output in wp user panel
        add_action('show_user_profile', array($this, 'wp_output_invites'), 99, 1);
        add_action('edit_user_profile', array($this, 'wp_output_invites'), 99, 1);
        if($this->options['debug'])
            add_action('wp_footer',array($this,'output_debug'),99);
        $this->debug_info('Options: '.var_export($this->options,1));
    }


    function invites_ifreal($val)#check if it's a real invite code
    {
        global $wpdb;
        $i = addslashes($this->invites_unbeautify($val));
        $this->debug_info('checking for real invite "'.$i.'"');
        $sql='SELECT 1 FROM ' . $this->options['INVITES_PREFIX'] . 'invites WHERE `value`= %s LIMIT 1';
        $this->debug_info($sql);
        $wpdb->query($wpdb->prepare($sql,$i));
        $res = $wpdb->num_rows;//$wpdb->get_var($wpdb->prepare($sql,$i));
        if (is_null($res)) {
			if($this->options['debug']){
				echo $wpdb->last_error;
				$this->debug_info('Got null');
				$this->output_debug();
			}
            return FALSE;
        }
        if (!$res) {
            if($this->options['debug']){
				$this->debug_info('Got false');
				$this->output_debug();
			}
            return FALSE;
        }
		if($this->options['debug']){
			$this->debug_info('Got true, '.var_export($res,1));
			$this->output_debug();
		}
        return TRUE;
    }

    function invites_unbeautify($str)
    {
        return addslashes(str_replace($this->options['SEPARATOR'], '', trim($str)));
    }

    function invites_beautify($str)
    {
        return implode($this->options['SEPARATOR'], str_split($str, $this->options['INVITE_SPLIT']));
    }

    /* Functions for handling the admin area tabs for administrators */

    function invites_add($val)#add invite... ))
    {
        global $wpdb;
        $sql = 'INSERT INTO ' . $this->options['INVITES_PREFIX'] . 'invites (`value`,`datetime`) VALUES(%s,NOW())';
        if($this->options['debug']){
			$this->debug_info($sql);
		}
        $wpdb->query($wpdb->prepare($sql, $this->invites_unbeautify($val)));
    }

    function invites_make()#make new code
    {
        $str = '';
        $chars = $this->options['CHARS'];
        for ($i = 0; $i < $this->options['INVITE_LENGTH']; $i++)
            $str .= $chars[rand(0, strlen($chars) - 1)];

        #paranoid check
        if ($this->invites_ifreal($str))#if such code already exists in base, generate new
            $str = $this->invites_make();
        return $str;
    }

    function invites_get_options()
    {
        global $wpdb;
        #initialize with defaults
        $this->options = array('INVITE_LENGTH' => 12,#invite code length
            'INVITE_SPLIT' => 4,#visual split, number of characters
            'CHARS' => '1234567890qwertyuiopasdfghjklzxcvbnm',#symbols used in code
            'REMOVE_INTERVAL' => '30',#time after which we remove invite code from base
            'SEPARATOR' => '-',
            'debug' => 0
        );

        #get the options from the database
        if(is_multisite())
            $options = get_site_option('wp-invites'); // get the options from the database
        else
            $options = get_option('wp-invites');
        if (sizeof($options) && $options)
            foreach ($options as $key => $val)
                $this->options[$key] = $val;


        if (is_multisite())
            $this->options['IS_WPMU'] = 1;
        else
            $this->options['IS_WPMU'] = 0;

        if (defined('BP_PLUGIN_DIR'))
            $this->options['IS_BUDDYPRESS'] = 1;
        else
            $this->options['IS_BUDDYPRESS'] = 0;

        if ($this->options['IS_WPMU'])
            $this->options['INVITES_PREFIX'] = $wpdb->base_prefix;
        else
            $this->options['INVITES_PREFIX'] = $wpdb->prefix;
    }

    function invites_menu()
    {
        echo '
<ul style="font-size:14px;"><li><a href="?page=wp-invites/wp-invites.php&action=view">' . __('View created codes', 'wp-invites') . '</a></li>
<li><a href="?page=wp-invites/wp-invites.php&action=options">' . __('Configure plugin', 'wp-invites') . '</a></li>
<li><a href="?page=wp-invites/wp-invites.php">' . __('Generate new codes', 'wp-invites') . '</a></li>
<li><a href="?page=wp-invites/wp-invites.php&action=add">' . __('Add codes manually', 'wp-invites') . '</a></li>
<li><a href="https://www.paypal.com/cgi-bin/webscr?cmd=_s-xclick&hosted_button_id=EE8RM4N7BSNZ6">' . __('Donate', 'wp-invites') . '</a></li></ul>
';
    }

    function invites_admin()
    {
        global $wpdb;
        if (($wpdb->get_var('show tables like "' . $this->options['INVITES_PREFIX'] . 'invites"') == null)) {
            echo '<br>' . __('No MySQL table found. Installing...', 'wp-invites');
            $this->invites_install();
        }
        $this->invites_menu();
        if (isset($_REQUEST['action']) && $_REQUEST['action'] == 'options') {
            if (isset($_REQUEST['wp_invites']) && isset($_REQUEST['step']) && $_REQUEST['step'] == '2') {
                if ($this->options['IS_WPMU'])
                    update_site_option('wp-invites', $_REQUEST['wp_invites']);
                else
                    update_option('wp-invites', $_REQUEST['wp_invites']);
                echo '<div class="updated">' . __('Options updated!', 'wp-invites') . '</div>';
            }
            $this->invites_get_options();
            ?>
            <h2><?php _e('WP-Invites options', 'wp-invites'); ?></h2>
            <form method="post" action="">
                <table>
                    <tr>
                        <td><?php _e('Code length', 'wp-invites'); ?></td>
                        <td><input type="text" name="wp_invites[INVITE_LENGTH]"
                                   value="<?php echo $this->options['INVITE_LENGTH']; ?>"></td>
                    </tr>
                    <tr>
                        <td><?php _e('visual split of characters', 'wp-invites'); ?></td>
                        <td><input type="text" name="wp_invites[INVITE_SPLIT]"
                                   value="<?php echo $this->options['INVITE_SPLIT']; ?>"></td>
                    </tr>
                    <tr>
                        <td><?php _e('chars, used for code generation', 'wp-invites'); ?></td>
                        <td><input type="text" name="wp_invites[CHARS]" value="<?php echo $this->options['CHARS']; ?>">
                        </td>
                    </tr>
                    <tr>
                        <td><?php _e('remove interval, in days. Set to 3650 (10 years), if you need infinite', 'wp-invites'); ?>
                            :)
                        </td>
                        <td><input type="text" name="wp_invites[REMOVE_INTERVAL]"
                                   value="<?php echo $this->options['REMOVE_INTERVAL']; ?>"></td>
                    </tr>
                    <tr>
                        <td><?php _e('Separator for output', 'wp-invites'); ?></td>
                        <td><input type="text" name="wp_invites[SEPARATOR]"
                                   value="<?php echo $this->options['SEPARATOR']; ?>"></td>
                    </tr>
                    <tr>
                        <td><?php echo __('Enable debug output', 'wp-invites').' ('.
                                __('Debug log will appear in html comments in the bottom of the page code', 'wp-invites').')'; ?></td>
                        <td><input type="checkbox" name="wp_invites[debug]"
                                   value="1" <?php if ($this->options['debug']) echo 'checked' ?>></td>
                    </tr>
                </table>
                <input type="hidden" name="action" value="options">
                <input type="hidden" name="step" value="2">
                <input type="submit" value="<?php _e('Save', 'wp-invites') ?>" class="button button-primary"></form>
        <?php

        } elseif (isset($_REQUEST['action']) && $_REQUEST['action'] == 'add') {
            if (isset($_REQUEST['codes']) && isset($_REQUEST['step']) && $_REQUEST['step'] == '2') {
                $codes = explode("\n", $_REQUEST['codes']);
                echo '<div class="updated">';
                for ($i = 0; $i < sizeof($codes); $i++) {
                    $invite = trim($codes[$i]);
                    $invite = $this->invites_unbeautify($invite);
                    if ($invite) {
                        $this->invites_add($invite);
                        echo '<br>' . __('Code added:', 'wp-invites') . ' ' . $this->invites_beautify($invite);
                    }
                }
                echo '</div>';
            }
            ?>
            <form method="post"
                  action=""><?php _e('Please add codes, one for each line. Default expiration date will be used for them. You can add them with or without separators.', 'wp-invites') ?>
                <br>
                <br><textarea rows="20" name="codes" class="large-text code" style="width:300px;"></textarea>
                <input type="hidden" name="action" value="add">
                <input type="hidden" name="page" value="wp-invites/wp-invites.php">
                <input type="hidden" name="step" value="2">
                <input type="submit" value="<?php _e('Add', 'wp-invites') ?>" class="button button-primary"
                       style="width:80px;"></form>
        <?php
        } elseif (isset($_REQUEST['action']) && $_REQUEST['action'] == 'view') {
            $sql = 'SELECT value,`datetime`,(`datetime`+ INTERVAL ' . $this->options['REMOVE_INTERVAL'] . ' DAY) as `remove`
            FROM ' . $this->options['INVITES_PREFIX'] . 'invites order by `datetime`';
            $res = $wpdb->get_results($sql, ARRAY_A);
            if (is_null($res)) {
                echo $wpdb->last_error;
            } elseif (is_array($res)) {
                echo '<h2>' . __('Generated codes:', 'wp-invites') . '</h2><table width="100%"><tr><td>' . __('Code', 'wp-invites') .
                    '</td><td>' . __('Generated on', 'wp-invites') . '</td><td>' . __('Valid till', 'wp-invites') . '</td></tr>';
                foreach ($res as $row)
                    echo '<tr><td>' . $this->invites_beautify($row['value']) . '</td><td>' . $row['datetime'] . '</td><td>' . $row['remove'] . '</td></tr>';
                ?></table><?php
            }
        } else {
            if (isset($_REQUEST['invites_num']) && isset($_REQUEST['step']) && $_REQUEST['step'] == '2') {
                ?>
                <div class="updated"><H2><?php _e('Generated invitation codes:', 'wp-invites') ?></h2>

                <p><?php
                    for ($i = 0; $i < $_REQUEST['invites_num']; $i++) {
                        $invite = $this->invites_make();
                        $this->invites_add($invite);
                        echo '<br>' . $this->invites_beautify($invite);
                    }?></p></div><?php
            }
            ?><h2><?php _e('Generate codes', 'wp-invites'); ?></h2>
            <p><?php _e('Please, choose, how many invitation codes you are going to generate. Later, codes will be either assigned to registered users, or disappear after a period of time. Code has a length of', 'wp-invites') ?>
                <?php echo $this->options['INVITE_LENGTH'] ?> <?php _e(' chars, and is combined from', 'wp-invites') ?>
                <?php echo strlen($this->options['CHARS']) ?> <?php _e(' different chars, and, if not activated, is being removed after', 'wp-invites') ?>
                <?php echo $this->options['REMOVE_INTERVAL']; ?> <?php _e(' days.', 'wp-invites') ?></p>
            <p><?php _e('You can always change code generation parameters on options page.', 'wp-invites') ?></p>
            <form method="post" action="">
                <input type="text" name="invites_num" value="50" class="regular-text ltr" style="width:50px;">
                <input type="hidden" name="action" value="generate">
                <input type="hidden" name="step" value="2">
                <input type="submit" value="<?php _e('Generate', 'wp-invites') ?>" class="button button-primary">
            </form></div>
        <?php
        }
    }

    function invites_install()
    {
        global $bp, $wpdb;

        if (!empty($wpdb->charset))
            $charset_collate = ' DEFAULT CHARACTER SET ' . $wpdb->charset;

        $sql1 = 'CREATE TABLE ' . $this->options['INVITES_PREFIX'] . 'invites (
			 `id` int(11) unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY,
			 `value` varchar(255) NOT NULL,
			 `datetime` datetime default NULL
	)';
        $sql = $sql1 . $charset_collate . ';';
        $result = $wpdb->query($sql);
        if ($result === FALSE)#possibly, mysql 3 or 4, does not support encoding parameter
        {
            $sql = $sql1 . ';';
            $result = $wpdb->query($sql);
            if ($result === FALSE) {
                echo '<div class="error">' . __('WP invites table could not be installed! Please check database permissions.', 'wp-invites') .
                    ' <br><b>' . __('Query:', 'wp-invites') . '</b><br> ' . $sql . '<br><b>' . __('Error:', 'wp-invites') . '</b>';
                $wpdb->print_error();
                echo '</div>';
            }
        }
    }


    function invites_add_signup_fields($errors_mu)
    {
        global $errors, $bp;
        //print_R($errors);die;
        if ($this->options['IS_BUDDYPRESS'])
            $error = $bp->signup->errors['wp_invites_error'];
        elseif ($this->options['IS_WPMU'])
            $error = $errors_mu->get_error_message('wp_invites_error');
        else
            $error = $errors->get_error_message('wp_invites_error');
        ?>
        <div style="width:100%;">
            <hr style="clear: both; margin-bottom: 1.5em; border: 0; border-top: 1px solid #999; height: 1px;"/>
        </div><?php

        if ($error)
            echo '<p class="error">' . $error . '</p>';
        ?>
        <p>
            <label for="wp-invites"><?php _e('Invite code', 'wp-invites') ?></label><br/>
            <?php _e('Please, input here invitation code, received from the blog owner', 'wp-invites') ?><br>
            <input type="text" name="invite_code" value="<?php echo (isset($_REQUEST['invite_code'])) ? $_REQUEST['invite_code'] : ''; ?>"
                   class="regular-text ltr">
        </p>
    <?php
    }

    function invites_validate_signup_fields($result)
    {
        global $wpdb, $bp;
        $sql = 'DELETE FROM ' . $this->options['INVITES_PREFIX'] . 'invites WHERE `datetime` < NOW() - INTERVAL ' . $this->options['REMOVE_INTERVAL'] . ' DAY';//remove old codes
        if($this->options['debug']){
			$this->debug_info($sql);
		}
        $wpdb->query($sql);
        if (isset($_REQUEST['invite_code']) && $_REQUEST['invite_code']){
            $_SESSION['invite_code'] = $_REQUEST['invite_code'];
		} else {
			$_SESSION['invite_code'] = false;
		}
        if (!$this->invites_ifreal($this->invites_unbeautify($_SESSION['invite_code']))) {
            if ($this->options['IS_BUDDYPRESS'])
                $bp->signup->errors['wp_invites_error'] = '<b>' . __('Error:', 'wp-invites') . '</b>' . __('Wrong invite code', 'wp-invites');
            elseif ($this->options['IS_WPMU'])
                $result['errors']->add('wp_invites_error', '<b>' . __('Error:', 'wp-invites') . '</b>' . __('Wrong invite code', 'wp-invites'));
            else
                $result->add('wp_invites_error', '<b>' . __('Error:', 'wp-invites') . '</b>' . __('Wrong invite code', 'wp-invites'));
        }
        return $result;
    }


    function invites_on_activate_user($user_id, $password = '', $meta = '')
    {
        update_user_meta($user_id, 'invite_code', $meta['invite_code']);
    }

    function wp_invites_on_activate_user($user_id)
    {
        global $wpdb;
        $sql = 'DELETE FROM ' . $this->options['INVITES_PREFIX'] . 'invites WHERE `value`=%s';
        if($this->options['debug']){
			$this->debug_info($sql);
		}
        $res = $wpdb->query($wpdb->prepare($sql, $this->invites_unbeautify($_SESSION['invite_code'])));
        if ($res === FALSE)
            echo $wpdb->last_error;
        update_user_meta($user_id, 'invite_code', $_SESSION['invite_code']);
    }

    function bp_invites_on_activate_user($meta = '', $key = '')
    {
        update_user_meta($meta['user_id'], 'invite_code', $meta['meta']['invite_code']);
    }

    function invites_add_admin_menu()
    {
        /*global $wpdb, $bp;
        if(constant('IS_BUDDYPRESS'))
        {
           add_submenu_page( 'bp-general-settings', 'WP-invites', 'WP-invites', 8, "wp-invites", "invites_admin" );
        }
        if(constant('IS_WPMU'))
        {
            if ( is_site_admin() )
                add_submenu_page( 'wpmu-admin.php', 'WP-invites', 'WP-invites', 8, "wp-invites", "invites_admin" );
        }
        else #same for BuddyPress and simple WordPress
            //add_submenu_page('plugins.php','WP-invites','WP-invites',8,"wp-invites",'invites_admin');
          {  */
        add_options_page(
            'WP-invites',
            'WP-invites',
            'manage_options',
            __FILE__,
            array($this, 'invites_admin')
        );
        //}
    }


    function wp_output_invites($user)
    {
        $code = $this->invites_beautify(get_user_meta($user->ID, 'invite_code',true));
        if (!$code)
            $code = __('No code assigned', 'wp-invites');
        if (
            !function_exists('is_site_admin') ||
            (
                is_site_admin() ||
                (get_current_user_id() == $user->ID)
            )
        ) {
            ?>
            <table class="form-table">
            <tr>
                <th>
                    <label for="invite code"><?php _e('Invite code', 'wp-invites') ?></label></th>
                <td><input type="text" disabled="disabled" class="regular-text ltr" value="<?php echo $code; ?>"></td>
            </tr></table><?php
        }
    }


    function bp_output_invites($id)
    {
        global $bp;
        if ($bp->current_component != 'profile')
            return;
        $code = $this->invites_beautify(get_user_meta($bp->displayed_user->id, 'invite_code',true));
        if (!$code)
            $code = __('No code assigned', 'wp-invites');
        if (bp_is_home() || is_site_admin()) {
            ?>
            <div class="bp-widget">
                <h4><?php _e('Invitation code', 'wp-invites') ?></h4>
                <table class="profile-fields">
                    <tr class="field_1">
                        <td class="label"><?php _e('Code', 'wp-invites') ?></td>
                        <td class="data"><p><?php echo $code; ?></p></td>
                    </tr>
                </table>
            </div>
        <?php
        }
    }

    function wpmu_invites_add_signup_meta($meta)
    {
        global $wpdb;

        $sql = 'DELETE FROM ' . $this->options['INVITES_PREFIX'] . 'invites WHERE `value`=%s';
        if($this->options['debug']){
			$this->debug_info($sql);
		}
        $res = $wpdb->query($wpdb->prepare($sql, $this->invites_unbeautify($_SESSION['invite_code'])));
        if ($res === FALSE)
            echo $wpdb->last_error;

        $add_meta = array('invite_code' => $this->invites_unbeautify($_SESSION['invite_code']));
        $meta = array_merge($add_meta, $meta);
        return $meta;
    }

}

new wp_invites();
?>