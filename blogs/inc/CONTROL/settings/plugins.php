<?php
/**
 * This file implements the UI controller for plugins management.
 *
 * This file is part of the b2evolution/evocms project - {@link http://b2evolution.net/}.
 * See also {@link http://sourceforge.net/projects/evocms/}.
 *
 * @copyright (c)2003-2005 by Francois PLANQUE - {@link http://fplanque.net/}.
 * Parts of this file are copyright (c)2004-2005 by Daniel HAHLER - {@link http://thequod.de/contact}.
 *
 * @license http://b2evolution.net/about/license.html GNU General Public License (GPL)
 *
 * {@internal Open Source relicensing agreement:
 * Daniel HAHLER grants Francois PLANQUE the right to license
 * Daniel HAHLER's contributions to this file and the b2evolution project
 * under any OSI approved OSS license (http://www.opensource.org/licenses/).
 * }}
 *
 * @package admin
 *
 * {@internal Below is a list of authors who have contributed to design/coding of this file: }}
 * @author fplanque: Francois PLANQUE.
 * @author blueyed: Daniel HAHLER
 *
 * @version $Id$
 */
if( !defined('EVO_MAIN_INIT') ) die( 'Please, do not access this page directly.' );


$AdminUI->set_path( 'options', 'plugins' );

$action = $Request->param_action( 'list' );

$UserSettings->param_Request( 'plugins_disp_avail', 'integer' );

// Check permission to display:
$current_User->check_perm( 'options', 'view', true );


$admin_Plugins = new Plugins_admin();


/**
 * Helper function to do the action part of DB schema upgrades for "enable" and "install"
 * actions.
 *
 * @param Plugin
 * @return boolean True, if no changes needed or done; false if we should break out to display "install_db_schema" action payload.
 */
function install_plugin_db_schema_action( & $Plugin )
{
	global $action, $Request, $inc_path, $install_db_deltas, $DB, $Messages;

	$action = 'list';
	// Prepare vars for DB layout changes
	$install_db_deltas_confirm_md5 = $Request->param( 'install_db_deltas_confirm_md5' );

	$db_layout = $Plugin->GetDbLayout();
	$install_db_deltas = array(); // This eventually holds changes to make

	if( ! empty($db_layout) )
	{ // The plugin has a DB layout attached
		require_once $inc_path.'_misc/_upgrade.funcs.php';

		// Get the queries to make:
		foreach( db_delta($db_layout, false) as $table => $queries )
		{
			foreach( $queries as $query_info )
			{
				$install_db_deltas[] = $query_info['query'];
			}
		}

		if( ! empty($install_db_deltas) )
		{ // delta queries to make
			if( empty($install_db_deltas_confirm_md5) )
			{ // delta queries have to be confirmed in payload
				$action = 'install_db_schema';
				return false;
			}
			elseif( $install_db_deltas_confirm_md5 == md5( implode('', $install_db_deltas) ) )
			{ // Confirmed in first step:
				foreach( $install_db_deltas as $query )
				{
					$DB->query( $query );
				}

				$Messages->add( T_('The database has been updated.'), 'success' );
			}
			else
			{ // should not happen
				$Messages->add( T_('The DB schema has been changed since confirmation.'), 'error' );

				// delta queries have to be confirmed (again) in payload
				$action = 'install_db_schema';
				return false;
			}
		}
	}
	return true;
}


/**
 * Helper method for "add_settings_set" and "delete_settings_set" action.
 *
 * Walks the given settings path and either inits the target entry or unsets it ($init_value=NULL).
 *
 * @param string Setting name
 * @param string The settings path, e.g. 'setting[0]foo[1]'. (Is used as array internally for recursion.)
 * @param mixed The initial value of the setting, typically array() - NULL to unset it (action "delete_settings_set" uses it)
 * @param mixed Used internally for recursion (current setting to look at)
 * @param mixed Used internally for recursion (meta info of current setting to look at)
 * @return array|false
 */
function _set_setting_by_path( & $Plugin, $path, $init_value = array(), $setting = NULL, $meta = NULL )
{
	if( ! is_array($path) )
	{ // Init:
		if( ! preg_match( '~(\w+\[\w+\])+~', $path ) )
		{
			debug_die( 'Invalid path param!' );
		}

		$path = preg_split( '~(\[|\]\[?)~', $path, -1, PREG_SPLIT_NO_EMPTY ); // split by "[" and "][", so we get an array with setting name and index alternating
		$set_name = array_shift($path);

		$setting = $Plugin->Settings->get($set_name);

		// meta info for this setting:
		$defaults = $Plugin->GetDefaultSettings();
		if( ! isset($defaults[ $set_name ]) )
		{
			debug_die( 'Invalid setting - no meta data!' );
		}

		$meta = $defaults[ $set_name ];

		$root_instance = true; // to set the new value at the end
	}
	else
	{
		$set_name = array_shift($path);
		$setting = isset($setting[$set_name]) ? $setting[$set_name] : array();
		$meta = $meta['entries'][$set_name];
	}

	if( ! is_array($setting) )
	{ // something broken
		$setting = array();
	}

	$set_index = array_shift($path);

	if( ! count($path) )
	{ // at the end: init this entry

		if( isset($setting[$set_index]) && $init_value != NULL )
		{ // Setting already exists (and we do not want to delete), e.g. page reload!
			return false;
			/*
			while( isset($l_setting[ $path[0] ]) )
			{ // bump the index until not set
				$path[0]++;
			}
			*/
		}

		if( is_null($init_value) )
		{ // NULL is meant to unset it
			unset($setting[$set_index]);
		}
		else
		{
			$setting[$set_index] = $init_value;
			foreach( $meta['entries'] as $k => $v )
			{
				if( isset( $meta['defaultvalue'] ) )
				{ // set to defaultvalue
					$setting[$set_index][$k] = $meta['defaultvalue'];
				}
				else
				{
					if( isset($v['type']) && $v['type'] = 'array' )
					{
						$setting[$set_index][$k] = array();
					}
					else
					{
						$setting[$set_index][$k] = '';
					}
				}
			}
		}
	}
	else
	{ // Recurse:
		$new_set = _set_setting_by_path( $Plugin, $path, $init_value, $setting[$set_index], $meta );

		if( $new_set !== false )
		{
			$setting[$set_index][$path[0]] = $new_set;
		}
	}

	if( isset($root_instance) )
	{ // this is the root call, set the new setting
		$r = $Plugin->Settings->set( $set_name, $setting );
	}

	return $setting;
}


// Actions that delegate to other actions (other than list):
switch( $action )
{
	case 'delete_settings_set':
		param( 'plugin_ID', 'integer', true );
		param( 'set_path' );

		$edit_Plugin = & $admin_Plugins->get_by_ID($plugin_ID);

		_set_setting_by_path( $edit_Plugin, $set_path, NULL );

		$edit_Plugin->Settings->dbupdate();

		$action = 'edit_settings';

		break;

	case 'add_settings_set': // delegates to edit_settings
		// Add a new set to an array type setting:
		param( 'plugin_ID', 'integer', true );
		param( 'set_path', 'string', '' );

		$edit_Plugin = & $admin_Plugins->get_by_ID($plugin_ID);

		_set_setting_by_path( $edit_Plugin, $set_path, array() );

		#$edit_Plugin->Settings->dbupdate();

		$action = 'edit_settings';

		break;

}


switch( $action )
{
	// Disable a plugin, only if it is "enabled"
	case 'disable_plugin':
		$current_User->check_perm( 'options', 'edit', true );

		param( 'plugin_ID', 'integer', true );

		$action = 'list';

		$edit_Plugin = & $admin_Plugins->get_by_ID( $plugin_ID );

		if( empty($edit_Plugin) )
		{
			$Messages->add( sprintf( T_( 'The plugin with ID %d could not get instantiated.' ), $plugin_ID ), 'error' );
			break;
		}
		if( $edit_Plugin->status != 'enabled' )
		{
			$Messages->add( sprintf( T_( 'The plugin with ID %d is already disabled.' ), $plugin_ID ), 'note' );
			break;
		}

		// Check dependencies
		$msgs = $Plugins->validate_dependencies( $edit_Plugin, 'disable' );
		if( ! empty( $msgs['error'] ) )
		{
			$Messages->add( T_( 'The plugin cannot get disabled because of dependency problems:' ).' <ul><li>'.implode('</li><li>', $msgs['error']).'</li></ul>', 'error' );
			break;
		}

		$edit_Plugin->BeforeDisable();

		// we call $Plugins(!) here: the Plugin gets disabled on the current page already and it should not get (un)registered on $Plugins_admin!
		$Plugins->set_Plugin_status( $edit_Plugin, 'disabled' ); // sets $edit_Plugin->status

		$Messages->add( sprintf( T_('Disabled plugin #%d.'), $edit_Plugin->ID ), 'success' );

		break;


	// Try to enable a plugin, only if it is in state "disabled" or "needs_config"
	case 'enable_plugin':
		$current_User->check_perm( 'options', 'edit', true );

		param( 'plugin_ID', 'integer', true );

		$action = 'list';

		$edit_Plugin = & $admin_Plugins->get_by_ID( $plugin_ID );

		if( empty($edit_Plugin) )
		{
			$Messages->add( sprintf( T_( 'The plugin with ID %d could not get instantiated.' ), $plugin_ID ), 'error' );
			break;
		}
		if( $edit_Plugin->status == 'enabled' )
		{
			$Messages->add( sprintf( T_( 'The plugin with ID %d is already enabled.' ), $plugin_ID ), 'note' );
			break;
		}
		if( $edit_Plugin->status == 'broken' )
		{
			$Messages->add( sprintf( T_( 'The plugin status is in a broken state. It cannot get enabled.' ), $plugin_ID ), 'error' );
			break;
		}

		// Check dependencies
		$msgs = $Plugins->validate_dependencies( $edit_Plugin, 'enable' );
		if( ! empty( $msgs['error'] ) )
		{
			$Messages->add( T_( 'The plugin cannot get enabled because of dependency problems:' ).' <ul><li>'.implode('</li><li>', $msgs['error']).'</li></ul>' );
			break;
		}

		if( ! install_plugin_db_schema_action( $edit_Plugin ) )
		{
			$next_action = 'enable_plugin';
			break;
		}

		// Update plugin version in DB:
		$DB->query( '
				UPDATE T_plugins
				   SET plug_version = '.$DB->quote($edit_Plugin->version).'
				 WHERE plug_ID = '.$edit_Plugin->ID );

		// Try to enable plugin:
		$enable_return = $edit_Plugin->BeforeEnable();
		if( $enable_return === true )
		{
			// we call $Plugins(!) here: the Plugin gets active on the current page already and it should not get (un)registered on $Plugins_admin!
			$Plugins->set_Plugin_status( $edit_Plugin, 'enabled' ); // sets $edit_Plugin->status

			$Messages->add( sprintf( T_('Enabled plugin #%d.'), $edit_Plugin->ID ), 'success' );
		}
		else
		{
			$Messages->add( T_('The plugin has not been enabled.').( empty($enable_return) ? '' : '<br />'.$enable_return ), 'error' );
		}

		break;


	case 'reload_plugins':
		// Register new events
		// Unregister obsolete events
		// Detect plugins with no code and try to have at least one plugin with the default code
		// Check permission:
		$current_User->check_perm( 'options', 'edit', true );

		$admin_Plugins->restart();
		$admin_Plugins->load_events();
		$changed = false;
		while( $loop_Plugin = & $admin_Plugins->get_next() )
		{
			// Discover new events:
			if( $admin_Plugins->save_events( $loop_Plugin, array() ) )
			{
				$changed = true;
			}

			// Detect plugins with no code and try to have at least one plugin with the default code:
			if( empty($loop_Plugin->code) )
			{ // Instantiated Plugin has no code
				$default_Plugin = & new $loop_Plugin->classname;

				if( ! empty($default_Plugin->code) // Plugin has default code
				    && ! $admin_Plugins->get_by_code( $default_Plugin->code ) ) // Default code is not in use (anymore)
				{ // Set the Plugin's code to the default one
					if( $admin_Plugins->set_code( $loop_Plugin->ID, $default_Plugin->code ) )
					{
						$changed = true;
					}
				}
			}
		}

		if( $changed )
		{
			$Messages->add( T_('Plugins have been reloaded.'), 'success' );
		}
		else
		{
			$Messages->add( T_('Plugins have not changed.'), 'note' );
		}
		$action = 'list';
		break;


	case 'install': // Install a plugin. This may be a two-step action, when DB changes have to be confirmed {{{
		$action = 'list';
		// Check permission:
		$current_User->check_perm( 'options', 'edit', true );

		// First step:
		param( 'plugin', 'string', true );

		$edit_Plugin = $admin_Plugins->install( $plugin, 'broken' ); // "broken" by default, gets adjusted later

		if( is_string($edit_Plugin) )
		{
			$Messages->add( $edit_Plugin, 'error' );
			break;
		}


	case 'install_db_schema': // we come here from the first step ("install")
		$Request->param( 'plugin_ID', 'integer', 0 );

		if( $plugin_ID )
		{
			$edit_Plugin = & $admin_Plugins->get_by_ID( $plugin_ID );

			if( ! is_a($edit_Plugin, 'Plugin') )
			{
				$Messages->add( sprintf( T_( 'The plugin with ID %d could not get instantiated.' ), $plugin_ID ), 'error' );
				break;
			}
		}
		if( ! install_plugin_db_schema_action($edit_Plugin) )
		{
			$next_action = 'install_db_schema';
			break;
		}

		$Messages->add( sprintf( T_('Installed plugin &laquo;%s&raquo;.'), $edit_Plugin->classname ), 'success' );

		// Install completed:
		$r = $admin_Plugins->call_method( $edit_Plugin->ID, 'AfterInstall', $params = array() );

		// Try to enable plugin:
		$enable_return = $edit_Plugin->BeforeEnable();
		if( $enable_return === true )
		{
			$admin_Plugins->set_Plugin_status( $edit_Plugin, 'enabled' );
		}
		else
		{
			$Messages->add( T_('The plugin has not been enabled.').( empty($enable_return) ? '' : '<br />'.$enable_return ), 'error' );
			$admin_Plugins->set_Plugin_status( $edit_Plugin, 'disabled' ); // also unregisters it
		}

		if( ! empty( $edit_Plugin->install_dep_notes ) )
		{ // Add notes from dependencies
			$Messages->add_messages( array( 'note' => $edit_Plugin->install_dep_notes ) );
		}

		// }}}
		break;


	case 'uninstall':
		// Check permission:
		$current_User->check_perm( 'options', 'edit', true );
		// Uninstall plugin:
		param( 'plugin_ID', 'int', true );

		$action = 'list'; // leave 'uninstall' by default

		$edit_Plugin = & $admin_Plugins->get_by_ID( $plugin_ID );

		if( empty($edit_Plugin) )
		{
			$Messages->add( sprintf( T_( 'The plugin with ID %d could not get instantiated.' ), $plugin_ID ), 'error' );
			break;
		}

		// Check dependencies:
		$msgs = $Plugins->validate_dependencies( $edit_Plugin, 'disable' );
		if( ! empty( $msgs['error'] ) )
		{
			$Messages->add( T_( 'The plugin cannot get uninstalled because of dependency problems:' ).' <ul><li>'.implode('</li><li>', $msgs['error']).'</li></ul>', 'error' );
			break;
		}
		if( ! empty( $msgs['note'] ) )
		{ // just notes:
			$Messages->add_messages( array( 'note' => $msgs['note'] ) );
		}

		// Ask plugin:
		$success = $admin_Plugins->call_method( $plugin_ID, 'BeforeUninstall', $params = array( 'unattended' => false ) );

		if( $success === false )
		{ // failed
			if( empty($params['handles_display']) )
			{ // The plugin does not handle display
				$Messages->add( sprintf( T_('Could not uninstall plugin #%d.'), $plugin_ID ), 'error' );
			}
			break;
		}

		if( $success === true )
		{ // success
			$Plugins->uninstall( $plugin_ID );
			$admin_Plugins->unregister( $edit_Plugin );

			$Messages->add( sprintf( T_('Uninstalled plugin #%d.'), $plugin_ID ), 'success' );
			break;
		}

		// $success === NULL (or other): execute plugin event BeforeUninstallPayload() below
		$action = 'uninstall';

		break;


	case 'update_settings':
		// Update plugin settings:

		// Check permission:
		$current_User->check_perm( 'options', 'edit', true );

		param( 'plugin_ID', 'integer', true );

		// Next default action:
		if( isset($actionArray['update_settings']) && is_array($actionArray['update_settings']) && isset($actionArray['update_settings']['review']) )
		{ // "Save (and review)"
			$action = 'edit_settings';
		}
		else
		{
			$action = 'list';
		}

		$edit_Plugin = & $admin_Plugins->get_by_ID( $plugin_ID );
		if( empty($edit_Plugin) )
		{
			$Messages->add( sprintf( T_( 'The plugin with ID %d could not get instantiated.' ), $plugin_ID ), 'error' );
			$action = 'list';
			break;
		}

		// Params from/for form:
		$Request->param( 'edited_plugin_code' );
		$Request->param( 'edited_plugin_priority' );
		$Request->param( 'edited_plugin_apply_rendering' );

		$updated = $admin_Plugins->set_code( $edit_Plugin->ID, $edited_plugin_code );
		if( is_string( $updated ) )
		{
			$Request->param_error( 'edited_plugin_code', $updated );
			$action = 'edit_settings';
		}
		elseif( $updated === 1 )
		{
			$Messages->add( T_('Plugin code updated.'), 'success' );
		}

		if( $Request->param_check_range( 'edited_plugin_priority', 0, 100, T_('Plugin priority must be numeric (0-100).'), true ) )
		{
			$updated = $admin_Plugins->set_priority( $edit_Plugin->ID, $edited_plugin_priority );
			if( $updated === 1 )
			{
				$Messages->add( T_('Plugin priority updated.'), 'success' );
			}
		}
		else
		{
			$action = 'edit_settings';
		}

		// apply_rendering:
		if( $admin_Plugins->set_apply_rendering( $edit_Plugin->ID, $edited_plugin_apply_rendering ) )
		{
			$Messages->add( T_('Plugin rendering appliance updated.'), 'success' );
		}

		// Settings:
		if( $edit_Plugin->Settings )
		{
			require_once $inc_path.'_misc/_plugin.funcs.php';
			set_Settings_for_Plugin_from_params( $edit_Plugin, $admin_Plugins, 'Settings' );

			// Let the plugin handle custom fields:
			$ok_to_update = $admin_Plugins->call_method( $edit_Plugin->ID, 'PluginSettingsUpdateAction', $tmp_params = array() );

			if( $ok_to_update === false )
			{
				$edit_Plugin->Settings->reset();
			}
			elseif( $edit_Plugin->Settings->dbupdate() )
			{
				$Messages->add( T_('Plugin settings have been updated.'), 'success' );
			}
			else
			{
				$Messages->add( T_('Plugin settings have not changed.'), 'note' );
			}
		}

		// Events:
		param( 'edited_plugin_displayed_events', 'array', array() );
		param( 'edited_plugin_events', 'array', array() );
		$registered_events = $admin_Plugins->get_registered_events( $edit_Plugin );

		$enable_events = array();
		$disable_events = array();
		foreach( $edited_plugin_displayed_events as $l_event )
		{
			if( ! in_array( $l_event, $registered_events ) )
			{ // unsupported event
				continue;
			}
			if( isset($edited_plugin_events[$l_event]) && $edited_plugin_events[$l_event] )
			{
				$enable_events[] = $l_event; // may be already there
			}
			else
			{ // unset:
				$disable_events[] = $l_event;
			}
		}
		if( $admin_Plugins->save_events( $edit_Plugin, $enable_events, $disable_events ) )
		{
			$Messages->add( T_('Plugin events have been updated.'), 'success' );
		}

		break;


	case 'edit_settings':
		// Check permission:
		$current_User->check_perm( 'options', 'view', true );

		// Edit plugin settings:
		param( 'plugin_ID', 'integer', true );

		$edit_Plugin = & $admin_Plugins->get_by_ID( $plugin_ID );

		if( ! $edit_Plugin )
		{
			$Debuglog->add( 'The plugin with ID '.$plugin_ID.' was not found.', array('plugins', 'error') );
			$action = 'list';
			break;
		}

		$admin_Plugins->call_method( $edit_Plugin->ID, 'PluginSettingsEditAction', $tmp_params = array() );

		// Params for form:
		$edited_plugin_code = $edit_Plugin->code;
		$edited_plugin_priority = $edit_Plugin->priority;
		$edited_plugin_apply_rendering = $edit_Plugin->apply_rendering;

		break;


	case 'default_settings': // Restore default settings
		// Check permission:
		$current_User->check_perm( 'options', 'edit', true );

		param( 'plugin_ID', 'integer', true );

		$edit_Plugin = & $admin_Plugins->get_by_ID( $plugin_ID );
		if( !$edit_Plugin )
		{
			$Debuglog->add( 'The plugin with ID '.$plugin_ID.' was not found.', array('plugins', 'error') );
			$action = 'list';
			break;
		}

		$default_Plugin = & new $edit_Plugin->classname; // instantiate it to access default member values

		// Params for/"from" form:
		$edited_plugin_code = $default_Plugin->code;
		$edited_plugin_priority = $default_Plugin->priority;
		$edited_plugin_apply_rendering = $default_Plugin->apply_rendering;

		// Code:
		$updated = $admin_Plugins->set_code( $edit_Plugin->ID, $edited_plugin_code );
		if( is_string( $updated ) )
		{ // error message
			$Request->param_error( 'edited_plugin_code', $updated );
			$action = 'edit_settings';
		}
		elseif( $updated === 1 )
		{
			$Messages->add( T_('Plugin code updated.'), 'success' );
		}

		// Priority:
		if( ! preg_match( '~^1?\d?\d$~', $edited_plugin_priority ) )
		{
			$Request->param_error( 'edited_plugin_priority', T_('Plugin priority must be numeric (0-100).') );
		}
		else
		{
			$updated = $admin_Plugins->set_priority( $edit_Plugin->ID, $edited_plugin_priority );
			if( $updated === 1 )
			{
				$Messages->add( T_('Plugin priority updated.'), 'success' );
			}
		}

		// apply_rendering:
		if( $admin_Plugins->set_apply_rendering( $edit_Plugin->ID, $edited_plugin_apply_rendering ) )
		{
			$Messages->add( T_('Plugin rendering appliance updated.'), 'success' );
		}

		// PluginSettings:
		if( $edit_Plugin->Settings )
		{
			if( $edit_Plugin->Settings->restore_defaults() )
			{
				$Messages->add( T_('Restored default values.'), 'success' );
			}
			else
			{
				$Messages->add( T_('Settings have not changed.'), 'note' );
			}
		}

		// Enable all events:
		if( $admin_Plugins->save_events( $edit_Plugin ) )
		{
			$Messages->add( T_('Plugin events have been updated.'), 'success' );
		}

		// blueyed>> IMHO it's good to see the new settings again. Perhaps we could use $action = 'list' for "Settings have not changed"?
		$action = 'edit_settings';

		break;


	case 'info':
		param( 'plugin_ID', 'integer', true );

		// Discover available plugins:
		$AvailablePlugins = & new Plugins_no_DB(); // do not load registered plugins/events from DB
		$AvailablePlugins->discover();

		if( ! ($edit_Plugin = & $AvailablePlugins->get_by_ID( $plugin_ID )) )
		{
			$edit_Plugin = & $admin_Plugins->get_by_ID($plugin_ID);
		}
		if( ! $edit_Plugin )
		{
			$action = 'list';
		}
		break;


	case 'disp_help_plain': // just the help, without any payload
	case 'disp_help':
		param( 'plugin_ID', 'integer', 0 );
		$edit_Plugin = & $admin_Plugins->get_by_ID($plugin_ID);

		if( ! $edit_Plugin || ! ($help_file = $edit_Plugin->get_help_file( $plugin_ID )) )
		{
			$action = 'list';
		}

		if( $action == 'disp_help' )
		{ // display it later
			break;
		}

		readfile($help_file);
		debug_die();

		break;


}

/*
if( 1 || $Settings->get( 'plugins_disp_log_in_admin' ) )
{
	$Messages->add_messages( $Debuglog->get_messages('plugins') );
}
*/


// Extend titlearea for some actions:
// blueyed>> IMHO it's a good use for the whitespace here (instead of directly above the payload)
switch( $action )
{
	case 'edit_settings':
		$AdminUI->append_to_titlearea( sprintf( T_('Edit plugin &laquo;%s&raquo; (ID %d)'), $edit_Plugin->name, $edit_Plugin->ID ) );
		break;

	case 'disp_help':
		$title = sprintf( T_('Help for plugin &laquo;%s&raquo;'), '<a href="admin.php?ctrl=plugins&amp;action=edit_settings&amp;plugin_ID='.$edit_Plugin->ID.'">'.$edit_Plugin->name.'</a>' );
		if( ! empty($edit_Plugin->help_url) )
		{
			$title .= ' '.action_icon( T_('External help page'), 'www', $edit_Plugin->help_url );
		}
		$AdminUI->append_to_titlearea( $title );
		break;
}

// Display <html><head>...</head> section! (Note: should be done early if actions do not redirect)
$AdminUI->disp_html_head();

// Display title, menu, messages, etc. (Note: messages MUST be displayed AFTER the actions)
$AdminUI->disp_body_top();

// Begin payload block:
$AdminUI->disp_payload_begin();

switch( $action )
{
	case 'disp_help':
		// Display plugin help:
		$help_file_body = implode( '', file($help_file) );

		// Try to extract the BODY part:
		if( preg_match( '~<body.*?>(.*)</body>~is', $help_file_body, $match ) )
		{
			$help_file_body = $match[1];
		}

		echo $help_file_body;
		unset($help_file_body);
		break;


	case 'install_db_schema':
		// Payload for 'install_db_schema' action if DB layout changes have to be confirmed:
		?>

		<div class="panelinfo">

			<?php
			$Form = & new Form( NULL, 'install_db_deltas', 'get' );
			$Form->hidden_ctrl();
			$Form->global_icon( T_('Cancel installation!'), 'close', regenerate_url() );

			$Form->begin_form( 'fform', sprintf( /* %d is ID, %d name */ T_('Setup database for plugin #%d (%s)'), $edit_Plugin->ID, $edit_Plugin->name ) );

			echo '<p>'.T_('The plugin needs the following database changes.').'</p>';

			$Form->hidden( 'action', $next_action );
			$Form->hidden( 'plugin_ID', $edit_Plugin->ID );


			if( ! empty($install_db_deltas) )
			{
				echo '<p>'.T_('The following queries will be executed. If you are not sure what this means, it will probably be alright.').'</p>';
				echo '<ul><li><pre>'.implode( '</pre></li><li><pre>', $install_db_deltas ).'</pre></li></ul>';

				$Form->hidden( 'install_db_deltas_confirm_md5', md5(implode( '', $install_db_deltas )) );
			}

			$Form->submit( array( '', T_('Install!'), 'ActionButton' ) );
			$Form->end_form();
			?>

		</div>

		<?php
		break;


	case 'uninstall':
		$admin_Plugins->call_method( $edit_Plugin->ID, 'BeforeUninstallPayload', $params = array() );
		break;


	case 'edit_settings':
		$AdminUI->disp_view( 'settings/_set_plugins_editsettings.form' );
		// Go on to displaying info - might be handy to not edit a wrong Plugin and provides help links:


	case 'info':
		// Display plugin info:
		$Form = & new Form( $pagenow );
		$Form->begin_form('fform');
		$Form->hidden( 'ctrl', 'plugins' );
		$Form->begin_fieldset('Plugin info', array('class' => 'fieldset clear')); // "clear" to fix Konqueror (http://bugs.kde.org/show_bug.cgi?id=117509)
		$Form->info_field( T_('Name'), $edit_Plugin->name( 'raw', false ) );
		$Form->info_field( T_('Code'),
				( empty($edit_Plugin->code)
					? ' - '
					: $edit_Plugin->code ), array( 'note' => T_('This 32 character code uniquely identifies the functionality of this plugin. It is only necessary to call the plugin by code (SkinTag) or when using it as a Renderer.') ) );
		$Form->info_field( T_('Short desc'), $edit_Plugin->short_desc( 'raw', false ) );
		$Form->info_field( T_('Long desc'), $edit_Plugin->long_desc( 'raw', false ) );
		if( $edit_Plugin->ID > 0 )
		{ // do not display ID for not registered Plugins
			$Form->info_field( T_('ID'), $edit_Plugin->ID );
		}

		// Help icons, if available:
		$help_icons = array();
		if( $help_internal = $edit_Plugin->get_help_icon() )
		{
			$help_icons[] = $help_internal;
		}
		if( $help_external = $edit_Plugin->get_help_icon( NULL, NULL, true ) )
		{
			$help_icons[] = $help_external;
		}
		if( ! empty($help_icons) )
		{
			$Form->info_field( T_('Help'), implode( ' ', $help_icons ) );
		}

		$Form->end_fieldset();
		$Form->end_form();
		$action = '';
		break;

}


if( $action == 'list' )
{
	// Discover available plugins:
	$AvailablePlugins = & new Plugins_no_DB(); // do not load registered plugins/events from DB
	$AvailablePlugins->discover();
	$AvailablePlugins->sort('name');

	// Display VIEW:
	$AdminUI->disp_view( 'settings/_set_plugins.form' );
}

// End payload block:
$AdminUI->disp_payload_end();

// Display body bottom, debug info and close </html>:
$AdminUI->disp_global_footer();
?>