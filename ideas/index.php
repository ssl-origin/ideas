<?php

/**
*
* @package phpBB3 Ideas
* @author Callum Macrae (callumacrae) <callum@lynxphp.com>
* @author Mark Barnes (MarkTheDaemon)
* @license http://opensource.org/licenses/gpl-license.php GNU Public License
*
*/

/**
* @ignore
*/
define('IN_IDEAS', true);
$ideas_root_path = (defined('IDEAS_ROOT_PATH')) ? IDEAS_ROOT_PATH : __DIR__ . '/';
include($ideas_root_path . 'common.php');

page_header($user->lang['IDEAS_HOME'], false);

$rows = $ideas->get_ideas();
foreach ($rows as $row)
{
	$template->assign_block_vars('latest_ideas', array(
		'ID'				=> $row['idea_id'],
		'LINK'			=> append_sid('./idea.php?id=' . $row['idea_id']),
		'TITLE'			=> $row['idea_title'],
		'AUTHOR'		=> get_user_link($row['idea_author']),
		'DATE'			=> $user->format_date($row['idea_date']),
		'RATING'		=> $row['idea_rating'],
		'VOTES'			=> $row['idea_votes']
	));
}

$rows = $ideas->get_ideas(10, 'idea_rating DESC, idea_votes DESC');
foreach ($rows as $row)
{
	$template->assign_block_vars('top_ideas', array(
		'ID'				=> $row['idea_id'],
		'LINK'			=> append_sid('./idea.php?id=' . $row['idea_id']),
		'TITLE'			=> $row['idea_title'],
		'AUTHOR'		=> get_user_link($row['idea_author']),
		'DATE'			=> $user->format_date($row['idea_date']),
		'RATING'		=> $row['idea_rating'],
		'VOTES'			=> $row['idea_votes']
	));
}

$template->assign_vars(array(
	'U_VIEW_TOP'		=> append_sid('./list.php'),
	'U_VIEW_LATEST'	=> append_sid('./list.php?sort=date'),
));

$template->set_filenames(array(
    'body' => 'index_body.html'
));

$template->display('body');
