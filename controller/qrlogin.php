<?php
/**
*
* @package phpBB Extension - qrLogin
* @copyright (c) 2017 qrLogin - http://qrlogin.info
* @license http://opensource.org/licenses/gpl-2.0.php GNU General Public License v2
*
*/

namespace qrlogin\qrlogin\controller;

use Symfony\Component\HttpFoundation\Response;

class qrlogin
{
	protected $config;
	protected $auth;
	protected $user;
	protected $request;
	protected $db;
	protected $passwords_manager;
	protected $qrlogin_table;

	public function __construct(
        \phpbb\config\config $config,
        \phpbb\auth\auth $auth,
        \phpbb\user $user,
        \phpbb\request\request $request,
        \phpbb\db\driver\driver_interface $db,
        \phpbb\passwords\manager $passwords_manager,
        $qrlogin_table
    )
	{
		$this->config = $config;
		$this->auth = $auth;
		$this->user = $user;
		$this->request = $request;
		$this->db = $db;
		$this->passwords_manager = $passwords_manager;
		$this->qrlogin_table = $qrlogin_table;
	}

	function get_field_session($field, $sql_where)
	{
		$sql	 = 'SELECT ' . $field . ' FROM ' . $this->qrlogin_table . $sql_where;
		$result	 = $this->db->sql_query($sql);
		$row	 = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);

		return $row[$field];
	}

	function hack_attemp()
	{
		sleep(60);
		return new Response('', 400);
	}

	public function ajax()
	{
		// check request is ajax and 'qrlogin_sid' 
		if (!$this->request->is_ajax() || !$this->request->is_set_post('qrlogin_sid'))
		{
			return $this->hack_attemp();
		}
		// check link_hash and session_id in 'qrlogin_sid' 
		$qa = preg_split( "/=/", $this->request->variable('qrlogin_sid', ''));
		if (!check_link_hash($qa[0], 'qrLogin' . $this->user->session_id) || ($qa[1] != $this->user->session_id))
		{
			return $this->hack_attemp();
		}

		$sid = md5('qrlogin' . $this->request->variable('qrlogin_sid', ''));
		$sql_where = ' WHERE ' . $this->db->sql_build_array('SELECT', array('sid' => $sid));

		// waiting for uid from post - max $poll_lifetime s
		$poll_lifetime = $this->config['qrlogin_poll_lifetime'];
		while (!$uid = $this->get_field_session('uid', $sql_where))
		{
			if (--$poll_lifetime < 0)
			{
				return new Response('', 200);
			}
			sleep(1);
			if (connection_aborted())
			{
				return new Response('', 200);
			}
		}

		// received uid for login - Session creation
		$res = $this->user->session_create($uid, false, false, true);

		// set login status for qrLogin post to 200 or 403 - Forbidden
		$sql = 'UPDATE ' . $this->qrlogin_table . ' SET ' . $this->db->sql_build_array('UPDATE', array('result' => ($res ? 200 : 403))) . $sql_where;
		$this->db->sql_query($sql);

		// answer to ajax with '1' for reload page if OK
		return new Response($res ? '1' : '', 200);
	}

	public function post()
	{
		// check for post from App qrLogin 
		if (substr($this->request->server('HTTP_USER_AGENT'), 0, 7) != 'qrLogin')
		{
			return $this->hack_attemp();
		}

		// get JSON from POST
		$postdata = json_decode(file_get_contents('php://input'), true);

		// if data not correct
		if (($postdata['objectName'] != 'qrLogin') || empty($postdata['sessionid']) || empty($postdata['login']) || empty($postdata['password']))
		{
			return $this->hack_attemp();
		}

		// check link_hash in 'qrlogin_sid'
		$qa = preg_split( "/=/", urldecode($postdata['sessionid']));
		if (!check_link_hash($qa[0], 'qrLogin' . $qa[1]))
		{
			return $this->hack_attemp();
		}
		// check 'qrlogin_sid' - session exist
		$sql = 'SELECT *
			FROM ' . SESSIONS_TABLE . "
			WHERE session_id = '" . $this->db->sql_escape($qa[1]) . "'";
		$result = $this->db->sql_query($sql);
		$row = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);
		if (!$row)
		{
			return $this->hack_attemp();
		}

		// Check user exists and password valid
		$username_clean = utf8_clean_string(urldecode($postdata['login']));
		$sql = 'SELECT *
			FROM ' . USERS_TABLE . "
			WHERE username_clean = '" . $this->db->sql_escape($username_clean) . "'";
		$result = $this->db->sql_query($sql);
		$row = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);
		if (!$row || !$this->passwords_manager->check(urldecode($postdata['password']), $row['user_password'], $row))
		{
			// if error - 403 Forbidden
			return new Response('', 403);
		}
		$uid = $row['user_id'];

		$sid = md5('qrlogin' . urldecode($postdata['sessionid']));
		$sql_where = ' WHERE ' . $this->db->sql_build_array('SELECT', array('sid' => $sid));
		$sql_del = 'DELETE FROM ' . $this->qrlogin_table . $sql_where;
		$sql_ins = 'INSERT INTO ' . $this->qrlogin_table . ' ' . $this->db->sql_build_array('INSERT', array('sid' => $sid, 'uid' => $uid));

		// remove queue from db
		$this->db->sql_query($sql_del);

		// insert queue into db
		$this->db->sql_query($sql_ins);

		// waiting for answer - max qrlogin_post_timeout s
		$post_timeout = $this->config['qrlogin_post_timeout'];
		while ((!$ans = $this->get_field_session('result', $sql_where)) && ($post_timeout-- > 0))
		{
			sleep(1);
		}

		// remove queue from db
		$this->db->sql_query($sql_del);

		// if not exists answer ! 408 Request Timeout
		return new Response('', $ans ? $ans : 408);
	}
}
