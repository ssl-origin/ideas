<?php
/**
 *
 * Ideas extension for the phpBB Forum Software package.
 *
 * @copyright (c) phpBB Limited <https://www.phpbb.com>
 * @license GNU General Public License, version 2 (GPL-2.0)
 *
 */

namespace phpbb\ideas\acp;

class ideas_module
{
	/** @var \phpbb\config\config */
	protected $config;

	/** @var \phpbb\db\driver\driver_interface */
	protected $db;

	/** @var \phpbb\language\language */
	protected $language;

	/** @var \phpbb\log\log */
	protected $log;

	/** @var \phpbb\request\request */
	protected $request;

	/** @var \phpbb\template\template */
	protected $template;

	/** @var \phpbb\user */
	protected $user;

	/** @var array */
	protected $new_config;

	/** @var string */
	protected $phpbb_root_path;

	/** @var string */
	protected $php_ext;

	/** @var string */
	public $u_action;

	/**
	 * Constructor
	 *
	 * @access public
	 */
	public function __construct()
	{
		global $config, $db, $phpbb_container, $phpbb_log, $request, $template, $user, $phpbb_root_path, $phpEx;

		$this->config = $config;
		$this->db = $db;
		$this->language = $phpbb_container->get('language');
		$this->log = $phpbb_log;
		$this->request = $request;
		$this->template = $template;
		$this->user = $user;
		$this->phpbb_root_path = $phpbb_root_path;
		$this->php_ext = $phpEx;

		// Add the phpBB Ideas ACP lang file
		$this->language->add_lang('phpbb_ideas_acp', 'phpbb/ideas');

		// Load a template from adm/style for our ACP page
		$this->tpl_name = 'acp_phpbb_ideas';

		// Set the page title for our ACP page
		$this->page_title = 'ACP_PHPBB_IDEAS_SETTINGS';
	}

	/**
	* Main ACP module
	*
	* @param int $id
	* @param string $mode
	* @access public
	*/
	public function main($id, $mode)
	{
		// Define the name of the form for use as a form key
		$form_name = 'acp_phpbb_ideas_settings';
		add_form_key($form_name);

		// Set an empty errors array
		$errors = array();

		$display_vars = array(
			'legend1'	=> 'ACP_PHPBB_IDEAS_SETTINGS',
				'ideas_forum_id'	=> array('lang' => 'ACP_IDEAS_FORUM_ID',	'validate' => 'string',	'type' => 'custom', 'method' => 'select_ideas_forum', 'explain' => true),
				'ideas_poster_id'	=> array('lang' => 'ACP_IDEAS_POSTER_ID',	'validate' => 'string',	'type' => 'custom', 'method' => 'select_ideas_topics_poster', 'explain' => true),
				'ideas_base_url'	=> array('lang' => 'ACP_IDEAS_BASE_URL',	'validate' => 'string',	'type' => 'text:45:255', 'explain' => true),
		);

		// Display forum setup utility button only if the forum is set
		if (!empty($this->config['ideas_forum_id']))
		{
			$display_vars = array_merge($display_vars, array(
				'legend2'	=> 'ACP_IDEAS_UTILITIES',
					'ideas_forum_setup'	=> array('lang' => 'ACP_IDEAS_FORUM_SETUP',	'validate' => 'bool',	'type' => 'custom', 'method' => 'set_ideas_forum_permissions', 'explain' => true),
			));
		}

		$this->new_config = $this->config;
		$cfg_array = ($this->request->is_set('config')) ? $this->request->variable('config', array('' => ''), true) : $this->new_config;
		$submit = $this->request->is_set_post('submit');
		$submit_forum_setup = $this->request->is_set_post('ideas_forum_setup');

		// We validate the complete config if wished
		validate_config_vars($display_vars, $cfg_array, $errors);

		if ($submit)
		{
			if (!check_form_key($form_name))
			{
				$errors[] = $this->language->lang('FORM_INVALID');
			}
		}

		// Check if selected user exists
		if ($submit)
		{
			$sql = 'SELECT user_id
				FROM ' . USERS_TABLE . "
				WHERE username_clean = '" . $this->db->sql_escape(utf8_clean_string($cfg_array['ideas_poster_id'])) . "'";
			$result = $this->db->sql_query($sql);
			$user_id = (int) $this->db->sql_fetchfield('user_id');
			$this->db->sql_freeresult($result);

			if (!$user_id)
			{
				$errors[] = $this->language->lang('NO_USER');
			}
			else
			{
				// If selected user does exist, reassign the config value to its ID
				$cfg_array['ideas_poster_id'] = $user_id;
			}
		}

		// Check if Ideas forum is selected and apply relevant settings if it is
		// But display the confirm box first
		if ($submit_forum_setup && !confirm_box(true))
		{
			confirm_box(false, $this->language->lang('ACP_IDEAS_FORUM_SETUP_CONFIRM'), build_hidden_fields(array(
				'i'			=> $id,
				'mode'		=> $mode,
				'ideas_forum_setup'	=> $submit_forum_setup,
			)));
		}
		else if ($submit_forum_setup)
		{
			if (empty($this->config['ideas_forum_id']))
			{
				trigger_error($this->language->lang('ACP_IDEAS_NO_FORUM') . '.' . adm_back_link($this->u_action));
			}
			else
			{
				if (!class_exists('auth_admin'))
				{
					include($this->phpbb_root_path . 'includes/acp/auth.' . $this->php_ext);
				}
				$auth_admin = new \auth_admin();

				$forum_id = (int) $this->config['ideas_forum_id'];

				// Get the REGISTERED usergroup ID
				$sql = 'SELECT group_id
					FROM ' . GROUPS_TABLE . "
					WHERE group_name = '" . $this->db->sql_escape('REGISTERED') . "'";
				$this->db->sql_query($sql);
				$group_id = (int) $this->db->sql_fetchfield('group_id');

				// Get 'f_' local REGISTERED users group permissions array for the ideas forum
				// Default undefined permissions to ACL_NO
				$hold_ary = $auth_admin->get_mask('set', false, $group_id, $forum_id, 'f_', 'local', ACL_NO);
				$auth_settings = $hold_ary[$group_id][$forum_id];

				// Set 'Can start new topics' permissions to 'Never' for the ideas forum
				$auth_settings['f_post'] = ACL_NEVER;

				// Update the registered usergroup  permissions for selected Ideas forum...
				$auth_admin->acl_set('group', $forum_id, $group_id, $auth_settings);

				// Disable auto-pruning for ideas forum
				$sql = 'UPDATE ' . FORUMS_TABLE . '
					SET ' . $this->db->sql_build_array('UPDATE', array('enable_prune' => false)) . '
					WHERE forum_id = ' . $forum_id;
				$this->db->sql_query($sql);
			}
		}

		// Do not write values if there are errors
		if (sizeof($errors))
		{
			$submit = $submit_forum_setup = false;
		}

		// We go through the display_vars to make sure no one is trying to set variables he/she is not allowed to
		foreach ($display_vars as $config_name => $null)
		{
			if (!isset($cfg_array[$config_name]) || strpos($config_name, 'legend') !== false)
			{
				continue;
			}

			$this->new_config[$config_name] = $config_value = $cfg_array[$config_name];

			if ($submit)
			{
				$this->config->set($config_name, $config_value);
			}
		}

		// Submit relevant log entries and output success message
		if ($submit || $submit_forum_setup)
		{
			$message = ($submit_forum_setup) ? 'FORUM_SETUP' : 'SETTINGS';

			$this->log->add('admin', $this->user->data['user_id'], $this->user->ip, "ACP_PHPBB_IDEAS_{$message}_LOG");

			trigger_error($this->language->lang("ACP_IDEAS_{$message}_UPDATED") . adm_back_link($this->u_action));
		}

		// Output relevant page
		foreach ($display_vars as $config_key => $vars)
		{
			if (!is_array($vars) && strpos($config_key, 'legend') === false)
			{
				continue;
			}

			if (strpos($config_key, 'legend') !== false)
			{
				$this->template->assign_block_vars('options', array(
					'S_LEGEND'		=> true,
					'LEGEND'		=> $this->language->lang($vars))
				);
				continue;
			}

			$type = explode(':', $vars['type']);

			$content = build_cfg_template($type, $config_key, $this->new_config, $config_key, $vars);

			if (empty($content))
			{
				continue;
			}

			$this->template->assign_block_vars('options', array(
				'KEY'			=> $config_key,
				'TITLE'			=> $this->language->lang($vars['lang']),
				'S_EXPLAIN'		=> $vars['explain'],
				'TITLE_EXPLAIN'	=> ($vars['explain']) ? $this->language->lang($vars['lang'] . '_EXPLAIN') : '',
				'CONTENT'		=> $content,
			));
		}

		$this->template->assign_vars(array(
			'S_ERROR'	=> (bool) sizeof($errors),
			'ERROR_MSG'	=> (sizeof($errors)) ? implode('<br />', $errors) : '',

			'U_ACTION'	=> $this->u_action,
			'U_FIND_USERNAME'	=> append_sid("{$this->phpbb_root_path}memberlist.$this->php_ext", 'mode=searchuser&amp;form=acp_phpbb_ideas_settings&amp;field=ideas_poster_id&amp;select_single=true'),
		));
	}

	/**
	 * Generate ideas forum select options
	 *
	 * @param mixed $value The method value
	 * @param mixed $key   The method key
	 * @return string Select menu HTML code
	 * @access public
	 */
	public function select_ideas_forum($value, $key)
	{
		$ideas_forum_id = (int) $this->config['ideas_forum_id'];
		$s_forums_list = '<select id="' . $key . '" name="config[' . $key . ']">';
		$s_forums_list .= '<option value="0"' . ((!$ideas_forum_id) ? ' selected="selected"' : '') . '>' . $this->language->lang('ACP_IDEAS_NO_FORUM') . '</option>';
		$forum_list = make_forum_select($ideas_forum_id, false, true, true);
		$s_forums_list .= $forum_list . '</select>';

		return $s_forums_list;
	}

	/**
	 * Generate ideas user input field
	 *
	 * @param mixed $value The method value
	 * @param mixed $key   The method key
	 * @return string Input field HTML code
	 * @access public
	 */
	public function select_ideas_topics_poster($value, $key)
	{
		$ideas_poster_id = (int) $this->config['ideas_poster_id'];
		$sql = 'SELECT username FROM ' . USERS_TABLE . '
			WHERE user_id = ' . $ideas_poster_id;
		$this->db->sql_query($sql);
		$username = $this->db->sql_fetchfield('username');
		$username = ($username !== false) ? $username : '';

		$tpl = '<input id="' . $key . '" type="text" size="45" maxlength="255" name="config[' . $key . ']" value="' . $username . '" />';

		return $tpl;
	}

	/**
	 * Generate ideas forum setup submit button
	 *
	 * @param mixed $value The method value
	 * @param mixed $key   The method key
	 * @return string Input field HTML code
	 * @access public
	 */
	public function set_ideas_forum_permissions($value, $key)
	{
		return '
			<form id="acp_phpbb_ideas_forum_setup" method="post" action="' . $this->u_action . '" data-ajax="true">
				<input class="button2" type="submit" id="' . $key . '" name="' . $key . '" value="' . $this->language->lang('RUN') . '" />
			</form>';
	}
}
