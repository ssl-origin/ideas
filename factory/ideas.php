<?php
/**
 *
 * This file is part of the phpBB Forum Software package.
 *
 * @author Callum Macrae (callumacrae) <callum@lynxphp.com>
 * @copyright (c) phpBB Limited <https://www.phpbb.com>
 * @license GNU General Public License, version 2 (GPL-2.0)
 *
 * For full copyright and license information, please see
 * the docs/CREDITS.txt file.
 *
 */

namespace phpbb\ideas\factory;

class Ideas
{
	/* @var \phpbb\db\driver\factory */
	protected $db;

	/* @var \phpbb\user */
	protected $user;

	/** @var string */
	protected $table_ideas;

	/** @var string */
	protected $table_duplicates;

	/** @var string */
	protected $table_rfcs;

	/** @var string */
	protected $table_statuses;

	/** @var string */
	protected $table_tickets;

	/** @var string */
	protected $table_votes;

	/**
	 * @param \phpbb\db\driver\factory $db
	 * @param \phpbb\user              $user
	 * @param string                   $table_ideas
	 * @param string                   $table_duplicates
	 * @param string                   $table_rfcs
	 * @param string                   $table_statuses
	 * @param string                   $table_tickets
	 * @param string                   $table_votes
	 */
	public function __construct(\phpbb\db\driver\factory $db, \phpbb\user $user, $table_ideas, $table_duplicates, $table_rfcs, $table_statuses, $table_tickets, $table_votes) {
		$this->db = $db;
		$this->user = $user;

		$this->table_ideas = $table_ideas;
		$this->table_duplicates = $table_duplicates;
		$this->table_rfcs = $table_rfcs;
		$this->table_statuses = $table_statuses;
		$this->table_tickets = $table_tickets;
		$this->table_votes = $table_votes;
	}


	/**
	 * Returns an array of ideas. Defaults to ten ideas ordered by date
	 * excluding duplicate or rejected ideas.
	 *
	 * @param int $number The number of ideas to return.
	 * @param string $sort Thing to sort by.
	 * @param string $sort_direction ASC / DESC.
	 * @param string $where SQL WHERE query.
	 * @return array Array of row data
	 */
	public function get_ideas($number = 10, $sort = 'date', $sort_direction = 'DESC', $where = 'idea_status != 4 AND idea_status != 3 AND idea_status != 5')
	{
		switch (strtolower($sort))
		{
			case 'author':
				$sortby = 'idea_author ' . $sort_direction;
				break;

			case 'date':
				$sortby = 'idea_date ' . $sort_direction;
				break;

			case 'id':
				$sortby = 'idea_id ' . $sort_direction;
				break;

			case 'score':
				$sortby = 'idea_votes_up - idea_votes_down ' . $sort_direction;
				break;

			case 'title':
				$sortby = 'idea_title ' . $sort_direction;
				break;

			case 'votes':
				$sortby = 'idea_votes_up + idea_votes_down ' . $sort_direction;
				break;

			case 'top':
				// Special case!
				$sortby = 'TOP';
				break;

			default:
				// Special case!
				$sortby = 'ALL';
				break;
		}

		if ($sortby !== 'TOP' && $sortby !== 'ALL')
		{
			$sql = 'SELECT *
				FROM ' . $this->table_ideas . "
				WHERE $where
				ORDER BY $sortby";
		}
		else
		{
			if ($sortby === 'TOP')
			{
				$where .= ' AND idea_votes_up > idea_votes_down';
			}

			// YEEEEEEEEAAAAAAAAAAAAAHHHHHHH
			// From http://evanmiller.org/how-not-to-sort-by-average-rating.html
			$sql = 'SELECT *,
					((idea_votes_up + 1.9208) / (idea_votes_up + idea_votes_down) -
	                1.96 * SQRT((idea_votes_up * idea_votes_down) / (idea_votes_up + idea_votes_down) + 0.9604) /
	                (idea_votes_up + idea_votes_down)) / (1 + 3.8416 / (idea_votes_up + idea_votes_down))
	                AS ci_lower_bound
       			FROM ' . $this->table_ideas . "
       			WHERE $where
       			ORDER BY ci_lower_bound " . $sort_direction;
		}

		$result = $this->db->sql_query_limit($sql, $number);
		$rows = $this->db->sql_fetchrowset($result);
		$this->db->sql_freeresult($result);

		if (count($rows))
		{
			$topic_ids = array();
			foreach ($rows as $row)
			{
				$topic_ids[] = $row['topic_id'];
			}
			$topic_tracking_info = get_complete_topic_tracking(IDEAS_FORUM_ID, $topic_ids);

			$last_times = array();
			$sql = 'SELECT topic_id, topic_last_post_time
				FROM ' . TOPICS_TABLE . '
				WHERE ' . $this->db->sql_in_set('topic_id', $topic_ids);
			$result = $this->db->sql_query($sql);
			while (($last_time = $this->db->sql_fetchrow($result)))
			{
				$last_times[$last_time['topic_id']] = $last_time['topic_last_post_time'];
			}
			$this->db->sql_freeresult($result);

			foreach ($rows as &$row)
			{
				$topic_id = $row['topic_id'];
				$row['read'] = !(isset($topic_tracking_info[$topic_id]) && $last_times[$topic_id] > $topic_tracking_info[$topic_id]);
			}
		}

		return $rows;
	}

	/**
	 * Returns the specified idea.
	 *
	 * @param int $id The ID of the idea to return.
	 *
	 * @return array The idea.
	 */
	public function get_idea($id)
	{
		$sql = 'SELECT *
			FROM ' . $this->table_ideas . "
			WHERE idea_id = $id";
		$result = $this->db->sql_query_limit($sql, 1);
		$row = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);

		if ($row === false) {
			return null;
		}

		$sql = 'SELECT duplicate_id
			FROM ' . $this->table_duplicates . "
			WHERE idea_id = $id";
		$this->db->sql_query_limit($sql, 1);
		$row['duplicate_id'] = $this->db->sql_fetchfield('duplicate_id');

		$sql = 'SELECT ticket_id
			FROM ' . $this->table_tickets . "
			WHERE idea_id = $id";
		$this->db->sql_query_limit($sql, 1);
		$row['ticket_id'] = $this->db->sql_fetchfield('ticket_id');

		$sql = 'SELECT rfc_link
			FROM ' . $this->table_rfcs . "
			WHERE idea_id = $id";
		$this->db->sql_query_limit($sql, 1);
		$row['rfc_link'] = $this->db->sql_fetchfield('rfc_link');

		return $row;
	}

	/**
	 * Returns the status name from the status ID specified.
	 *
	 * @param int $id ID of the status.
	 * @return string The status name.
	 */
	public function get_status_from_id($id)
	{
		$sql = 'SELECT status_name
			FROM ' . $this->table_statuses . "
			WHERE status_id = $id";
		$result = $this->db->sql_query_limit($sql, 1);
		$row = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);

		return $row['status_name'];
	}

	/**
	 * Returns all statuses.
	 *
	 * @return Array of statuses.
	 */
	public function get_statuses()
	{
		$sql = 'SELECT * FROM ' . $this->table_statuses;
		$result = $this->db->sql_query($sql);
		$rows = $this->db->sql_fetchrowset($result);
		$this->db->sql_freeresult($result);

		return $rows;
	}

	/**
	 * Updates the status of an idea.
	 *
	 * @param int $idea_id The ID of the idea.
	 * @param int $status The ID of the status.
	 */
	public function change_status($idea_id, $status)
	{
		$sql = 'UPDATE ' . $this->table_ideas . '
			SET idea_status = ' . (int) $status . '
			WHERE idea_id = ' . (int) $idea_id;
		$this->db->sql_query($sql);
	}

	/**
	 * Sets the ID of the duplicate for an idea.
	 *
	 * @param int $idea_id ID of the idea to be updated.
	 * @param string $duplicate Idea ID of duplicate.
	 */
	public function set_duplicate($idea_id, $duplicate)
	{
		if ($duplicate && !is_numeric($duplicate))
		{
			return; // Don't bother informing user, probably an attempted hacker
		}

		$sql = 'DELETE FROM ' . $this->table_duplicates . '
			WHERE idea_id = ' . (int) $idea_id;
		$this->db->sql_query($sql);

		$sql = 'INSERT INTO ' . $this->table_duplicates . ' (idea_id, duplicate_id)
			VALUES (' . (int) $idea_id . ', ' . (int) $duplicate . ')';
		$this->db->sql_query($sql);
	}

	/**
	 * Sets the RFC link of an idea.
	 *
	 * @param int $idea_id ID of the idea to be updated.
	 * @param string $rfc Link to the RFC.
	 */
	public function set_rfc($idea_id, $rfc)
	{
		$match = '/^https?:\/\/area51\.phpbb\.com\/phpBB\/viewtopic\.php/';
		if ($rfc && !preg_match($match, $rfc))
		{
			return; // Don't bother informing user, probably an attempted hacker
		}

		$sql = 'DELETE FROM ' . $this->table_rfcs . '
			WHERE idea_id = ' . (int) $idea_id;
		$this->db->sql_query($sql);

		$sql = 'INSERT INTO ' . $this->table_rfcs . ' (idea_id, rfc_link)
			VALUES (' . (int) $idea_id . ', \'' . $this->db->sql_escape($rfc) . '\')';
		$this->db->sql_query($sql);
	}

	/**
	 * Sets the ticket ID of an idea.
	 *
	 * @param int $idea_id ID of the idea to be updated.
	 * @param string $ticket Ticket ID.
	 */
	public function set_ticket($idea_id, $ticket)
	{
		if ($ticket && !is_numeric($ticket))
		{
			return; // Don't bother informing user, probably an attempted hacker
		}

		$sql = 'DELETE FROM ' . $this->table_tickets . '
			WHERE idea_id = ' . (int) $idea_id;
		$this->db->sql_query($sql);

		$sql = 'INSERT INTO ' . $this->table_tickets . ' (idea_id, ticket_id)
			VALUES (' . (int) $idea_id . ', ' . (int) $ticket . ')';
		$this->db->sql_query($sql);
	}

	/**
	 * Sets the title of an idea.
	 *
	 * @param int $idea_id ID of the idea to be updated.
	 * @param string $title New title.
	 *
	 * @return boolean False if invalid length.
	 */
	public function set_title($idea_id, $title)
	{
		if (strlen($title) < 6 || strlen($title) > 64)
		{
			return false;
		}

		$sql_ary = array(
			'idea_title'    => $title
		);

		$sql = 'UPDATE ' . $this->table_ideas . '
			SET ' . $this->db->sql_build_array('UPDATE', $sql_ary) . '
			WHERE idea_id = ' . $idea_id;
		$this->db->sql_query($sql);

		add_log('mod', 0, 0, 'LOG_IDEA_TITLE_EDITED', 'Idea #' . $idea_id);

		return true;
	}

	/**
	 * Submits a vote on an idea.
	 *
	 * @param array $idea The idea returned by get_idea().
	 * @param int $user_id The ID of the user voting.
	 * @param boolean $value Up or down?
	 *
	 * @return array Array of information.
	 */
	public function vote(&$idea, $user_id, $value)
	{
		// Validate $vote - must be 0 or 1
		if ($value !== 0 && $value !== 1)
		{
			return 'INVALID_VOTE';
		}

		// Check whether user has already voted - update if they have
		$sql = 'SELECT idea_id, vote_value
			FROM ' . $this->table_votes . "
			WHERE idea_id = {$idea['idea_id']}
				AND user_id = $user_id";
		$this->db->sql_query_limit($sql, 1);
		if ($this->db->sql_fetchrow())
		{
			$sql = 'SELECT vote_value
				FROM ' . $this->table_votes . '
				WHERE user_id = ' . (int) $user_id . '
					AND idea_id = ' . (int) $idea['idea_id'];
			$this->db->sql_query($sql);
			$old_value = $this->db->sql_fetchfield('vote_value');

			if ($old_value != $value)
			{
				$sql = 'UPDATE ' . $this->table_votes . '
					SET vote_value = ' . $value . '
					WHERE user_id = ' . (int) $user_id . '
						AND idea_id = ' . (int) $idea['idea_id'];
				$this->db->sql_query($sql);

				if ($value == 1)
				{
					// Change to upvote
					$idea['idea_votes_up']++;
					$idea['idea_votes_down']--;
				}
				else
				{
					// Change to downvote
					$idea['idea_votes_up']--;
					$idea['idea_votes_down']++;
				}

				$sql = 'UPDATE ' . $this->table_ideas . '
					SET idea_votes_up = ' . $idea['idea_votes_up'] . ',
						idea_votes_down = ' . $idea['idea_votes_down'] . '
					WHERE idea_id = ' . $idea['idea_id'];
				$this->db->sql_query($sql);
			}

			return array(
				'message'	    => $this->user->lang('UPDATED_VOTE'),
				'votes_up'	    => $idea['idea_votes_up'],
				'votes_down'	=> $idea['idea_votes_down'],
				'points'        => $idea['idea_votes_up'] - $idea['idea_votes_down']
			);
		}

		// Insert vote into votes table.
		$sql_ary = array(
			'idea_id'		=> $idea['idea_id'],
			'user_id'		=> $user_id,
			'vote_value'	=> $value,
		);

		$sql = 'INSERT INTO ' . $this->table_votes . ' ' .
			$this->db->sql_build_array('INSERT', $sql_ary);
		$this->db->sql_query($sql);


		// Update number of votes in ideas table
		$idea['idea_votes_' . ($value ? 'up' : 'down')]++;

		$sql_ary = array(
			'idea_votes_up'	    => $idea['idea_votes_up'],
			'idea_votes_down'	=> $idea['idea_votes_down'],
		);

		$sql = 'UPDATE ' . $this->table_ideas . '
			SET ' . $this->db->sql_build_array('UPDATE', $sql_ary) . '
			WHERE idea_id = ' . $idea['idea_id'];
		$this->db->sql_query($sql);

		return array(
			'message'	    => $this->user->lang('VOTE_SUCCESS'),
			'votes_up'	    => $idea['idea_votes_up'],
			'votes_down'	=> $idea['idea_votes_down'],
			'points'        => $idea['idea_votes_up'] - $idea['idea_votes_down']
		);
	}

	public function remove_vote(&$idea, $user_id)
	{
		// Only change something if user has already voted
		$sql = 'SELECT idea_id, vote_value
			FROM ' . $this->table_votes . "
			WHERE idea_id = {$idea['idea_id']}
				AND user_id = $user_id";
		$this->db->sql_query_limit($sql, 1);
		if ($result = $this->db->sql_fetchrow())
		{
			$sql = 'DELETE FROM ' . $this->table_votes . '
				WHERE idea_id = ' . (int) $idea['idea_id'] . '
					AND user_id = ' . (int) $user_id;
			$this->db->sql_query($sql);

			$idea['idea_votes_' . ($result['vote_value'] == 1 ? 'up' : 'down')]--;

			$sql = 'UPDATE ' . $this->table_ideas . '
					SET idea_votes_up = ' . $idea['idea_votes_up'] . ',
						idea_votes_down = ' . $idea['idea_votes_down'] . '
					WHERE idea_id = ' . $idea['idea_id'];
			$this->db->sql_query($sql);
		}

		return array(
			'message'	    => $this->user->lang('UPDATED_VOTE'),
			'votes_up'	    => $idea['idea_votes_up'],
			'votes_down'	=> $idea['idea_votes_down'],
			'points'        => $idea['idea_votes_up'] - $idea['idea_votes_down']
		);
	}

	/**
	 * Returns voter info on an idea.
	 *
	 * @param int $id ID of the idea.
	 * @return array Array of row data
	 */
	public function get_voters($id)
	{
		$sql = 'SELECT iv.user_id, iv.vote_value, u.username, u.user_colour
			FROM ' . $this->table_votes . ' as iv,
				' . USERS_TABLE . ' as u
			WHERE iv.idea_id = ' . (int) $id . '
				AND iv.user_id = u.user_id
			ORDER BY u.username DESC';
		$result = $this->db->sql_query($sql);
		$rows = $this->db->sql_fetchrowset($result);
		$this->db->sql_freeresult($result);

		return $rows;
	}

	/**
	 * Submits a new idea.
	 *
	 * @param string $title The title of the idea.
	 * @param string $desc The description of the idea.
	 * @param int $user_id The ID of the author.
	 *
	 * @return array|int Either an array of errors, or the ID of the new idea.
	 */
	public function submit($title, $desc, $user_id)
	{
		$error = array();
		if (strlen($title) < 6)
		{
			$error[] = $this->user->lang['TITLE_TOO_SHORT'];
		}
		if (strlen($desc) < 5)
		{
			$error[] = $this->user->lang['DESC_TOO_SHORT'];
		}
		if (strlen($title) > 64)
		{
			$error[] = $this->user->lang['TITLE_TOO_LONG'];
		}
		if (strlen($desc) > 9900)
		{
			$error[] = $this->user->lang['DESC_TOO_LONG'];
		}

		if (count($error))
		{
			return $error;
		}

		// Submit idea
		$sql_ary = array(
			'idea_title'		=> $title,
			'idea_author'		=> $user_id,
			'idea_date'			=> time(),
			'topic_id'			=> 0
		);

		$sql = 'INSERT INTO ' . $this->table_ideas . ' ' .
			$this->db->sql_build_array('INSERT', $sql_ary);
		$this->db->sql_query($sql);
		$idea_id = $this->db->sql_nextid();

		$sql = 'SELECT username
			FROM ' . USERS_TABLE . '
			WHERE user_id = ' . $user_id;
		$result = $this->db->sql_query_limit($sql, 1);
		$username = $this->db->sql_fetchfield('username');
		$this->db->sql_freeresult($result);

		// Initial vote
		$idea = $this->get_idea($idea_id);
		$this->vote($idea, $this->user->data['user_id'], 1);

		// Submit topic
		$bbcode = "[idea={$idea_id}]{$title}[/idea]";
		$desc .= "\n\n----------\n\n" . $this->user->lang('VIEW_IDEA_AT', $bbcode);
		$bbcode = "[user={$user_id}]{$username}[/user]";
		$desc .= "\n\n" . $this->user->lang('IDEA_POSTER', $bbcode);

		$uid = $bitfield = $options = '';
		generate_text_for_storage($desc, $uid, $bitfield, $options, true, true, true);

		$data = array(
			'forum_id'			=> IDEAS_FORUM_ID,
			'topic_id'			=> 0,
			'icon_id'			=> false,
			'poster_id'			=> IDEAS_POSTER_ID,

			'enable_bbcode'		=> true,
			'enable_smilies'	=> true,
			'enable_urls'		=> true,
			'enable_sig'		=> true,

			'message'			=> $desc,
			'message_md5'		=> md5($desc),

			'bbcode_bitfield'	=> $bitfield,
			'bbcode_uid'		=> $uid,

			'post_edit_locked'	=> 0,
			'topic_title'		=> $title,

			'notify_set'		=> false,
			'notify'			=> false,
			'post_time'			=> 0,
			'forum_name'		=> 'Ideas forum',

			'enable_indexing'	=> true,

			'force_approved_state'	=> true,
		);

		// Get Ideas Bot info
		$sql = 'SELECT *
			FROM ' . USERS_TABLE . '
			WHERE user_id = ' . IDEAS_POSTER_ID;
		$result = $this->db->sql_query_limit($sql, 1);
		$poster_bot = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);

		$poster_bot['is_registered'] = true;

		$tmpdata = $this->user->data;
		$this->user->data = $poster_bot;

		$poll = array();
		submit_post('post', $title, $this->user->data['username'], POST_NORMAL, $poll, $data);

		$this->user->data = $tmpdata;

		// Edit topic ID into idea; both should link to each other
		$sql = 'UPDATE ' . $this->table_ideas . '
			SET topic_id = ' . $data['topic_id'] . '
			WHERE idea_id = ' . $idea_id;
		$this->db->sql_query($sql);

		return $idea_id;
	}

	/**
	 * Deletes an idea and the topic to go with it.
	 *
	 * @param int $id The ID of the idea to be deleted.
	 * @param int $topic_id The ID of the idea topic. Optional, but preferred.
	 *
	 * @return boolean Whether the idea was deleted or not.
	 */
	public function delete($id, $topic_id = 0)
	{
		if (!$topic_id)
		{
			$idea = $this->get_idea($id);
			$topic_id = $idea['topic_id'];
		}

		// Delete topic
		delete_posts('topic_id', $topic_id);

		// Delete idea
		$sql = 'DELETE FROM ' . $this->table_ideas . '
			WHERE idea_id = ' . (int) $id;
		$this->db->sql_query($sql);
		$deleted = (bool) $this->db->sql_affectedrows();

		// Delete votes
		$sql = 'DELETE FROM ' . $this->table_votes . '
			WHERE idea_id = ' . (int) $id;
		$this->db->sql_query($sql);

		// Delete RFCS
		$sql = 'DELETE FROM ' . $this->table_rfcs . '
			WHERE idea_id = ' . (int) $id;
		$this->db->sql_query($sql);

		// Delete tickets
		$sql = 'DELETE FROM ' . $this->table_tickets . '
			WHERE idea_id = ' . (int) $id;
		$this->db->sql_query($sql);

		return $deleted;
	}
}
