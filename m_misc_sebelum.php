<?php
Class M_Misc extends CI_Model
{
	// variable to hold user session data
	private $_usr_sess = array('id' => '0', 'username' => 'system', 'role' => '', 'app' => array('app_ver' => '1.0'));
	private $_usr_addr = array('server_addr' => '127.0.0.1', 'client_addr' => '127.0.0.1', 'client_agent' => 'PHP CLI');
	
	function __construct()
	{
		parent::__construct();
		
		if(array_key_exists('SERVER_ADDR', $_SERVER)) $this->_usr_addr['server_addr'] = $_SERVER['SERVER_ADDR'];
		if($this->input->ip_address()) $this->_usr_addr['client_addr'] = $this->input->ip_address();
		if($this->input->user_agent()) $this->_usr_addr['client_agent'] = $this->input->user_agent();
		
		// db connection selector
		if($this->session->userdata('statisys_db'))
		{
			$this->load->database($this->session->userdata('statisys_db'), FALSE, TRUE); 
		}
		
		// app data selector
		if($this->session->userdata('statisys_usr_session'))
		{
			// get app data from session
			$this->_usr_sess = $this->session->userdata('statisys_usr_session');
		}
		else
		{
			// user not yet login. get app data from m_login
			$this->load->model('site/m_login', '', TRUE);
			
			$app_data = $this->m_login->get_app_settings();
			$sess_array = array(
				'username' => 'system',
				'app' => $app_data
			);
			
			$this->_usr_sess = $sess_array;
		}
	}
	
	// function to save file import/upload status
	function file_iu_status($type = '', $class = '', $file = '', $size = '', $row = '', $status = FALSE, $status_msg = '', $start_time = 'CURRENT_TIMESTAMP', $end_time = 'CURRENT_TIMESTAMP', $elapsed_time = '', $memory_usage = '')
	{
		try
		{
			// start transaction
			$this->db->trans_begin();
			
			// start query
			$data = array(
						'type' 				=> $type,
						'class' 			=> $class,
						'file' 				=> $file,
						'size' 				=> $size,
						'row' 				=> $row,
						'status' 			=> $status,
						'message' 			=> $status_msg,
						'start_time'		=> $start_time,
						'end_time'			=> $end_time,
						'elapsed_time'		=> $elapsed_time,
						'memory_usage'		=> $memory_usage,
						"changed_by"		=> $this->_usr_sess['username'],
						"server_ip_addr"	=> $this->_usr_addr['server_addr'],
						"client_ip_addr"	=> $this->_usr_addr['client_addr'],
						"ci_ver"			=> CI_VERSION,
						"app_ver"			=> $this->_usr_sess['app']['app_ver']
					);
					
			$this->db->insert('tlog_import_upload',$data);

			// execute query, rollback on error
			if ($this->db->trans_status() === FALSE)
			{
				// rollback transaction
				$this->db->trans_rollback();
				
				// event log
				trigger_error("Failed insert to table 'tlog_import_upload' - Database error.");
			}
			else
			{
				// commit transaction
				$this->db->trans_commit();
			}
			
			// prepare web notification
			$title = '';
			
			if($type == 'I') $title = 'Import ';
			else if($type == 'U') $title = 'Upload ';
			
			$title .= $class;
			$title .= ' - ';
			
			if($status == TRUE) $title .= 'Success';
			else $title .= 'Failed';
			
			$message = 'Filename: ' . $file . '<br>Filesize: ' . $size . '&ensp;|&ensp;Total Data: ' . number_format($row);
			if($type == 'R') $message = 'Total Rollup Data: ' . number_format($row);
			
			// add notification
			$this->set_notify($title, $message);
		}
		catch(Exception $ex)
		{
			// exception log
			throw new Exception($ex->getMessage(), E_CORE_ERROR);
		}
	}
	
	// function to convert number to time format
	function convert_to_time($size)
	{
		try
		{
			// default return value
			$result = 0;
			
			// if time > 1 hour
			if ($size > 3600)
			{
				$res = $size / 3600;
				$res2 = intval($res);
				$res3 = intval(($size / 60) % 60);
				$res4 = intval($size % 60);
				$result = $res2." Hour ".$res3." Min ".$res4." Sec";
			}
			else {
				// if time > 1 minutes
				if ($size > 60)
				{
					$res = $size / 60;
					$res2 = intval(($size / 60) % 60);
					$res3 = intval($size % 60);
					$result = $res2." Min ".$res3." Sec";
				}
				// if time < 1 minutes
				else
				{
					$result = $size." Sec";
				}
			}
			
			// return value
			return $result;
		}
		catch(Exception $ex)
		{
			// exception log
			throw new Exception($ex->getMessage(), E_CORE_ERROR);
			
			return NULL;
		}
	}
		
	// function to convert number to memory format
	function convert_to_memory($size)
	{
		try
		{
			$unit = array('', 'B', 'KB', 'MB', 'GB', 'TB', 'PB');
			
			if($size > 0) return @round($size/pow(1024, ($i=floor(log($size,1024)))), 2).' '.$unit[$i];
			else return 0;
		}
		catch(Exception $ex)
		{
			// exception log
			throw new Exception($ex->getMessage(), E_CORE_ERROR);
			
			return NULL;
		}
	}
	
	// function to send mail
	function send_mail($to, $mail_type, $subject, $data, $attachment = null, $pic = null)
	{
		try
		{
			$emailpic='';
			$this->db->select('user_mail');
			$this->db->from('tmas_user');
			$this->db->where_in('pic', $pic);
			$result_mail= $this->db->get();
			foreach($result_mail->result() as $rowemail){
				$emailpic=$emailpic.$rowemail->user_mail.';';
				//log_message('error',$emailpic);
			}
			// set initial mail status
			$mail['status'] = FALSE;
			$mail['message'] = '';

			// get group mailto based on mail_type
			$mail_group = $this->get_mail_group($mail_type);
			$mail_counter = intval(date('Ymd') . '50');
			
			if($mail_counter > intval($mail_group['mail_counter']))
			{
				// set mail config
				$config = Array(    
					'protocol'		=> $this->_usr_sess['app']['mail_protocol'],
					'smtp_host'		=> $this->_usr_sess['app']['mail_smtp_host'],
					'smtp_port'		=> $this->_usr_sess['app']['mail_smtp_port'],
					'smtp_user'		=> $this->_usr_sess['app']['mail_smtp_user'],
					'smtp_pass'		=> $this->_usr_sess['app']['mail_smtp_pass'],
					'smtp_timeout'	=> $this->_usr_sess['app']['mail_smtp_timeout'],
					'mailtype'		=> $this->_usr_sess['app']['mail_mailtype'],
					'charset'		=> $this->_usr_sess['app']['mail_charset'],
					'priority'		=> $this->_usr_sess['app']['mail_priority'],
					'crlf'			=> '\r\n',
					'newline'		=> '\r\n'
				);
				trigger_error(implode(', ', $config));
				/*
				$config = Array(
					'protocol' => 'smtp',
					'smtp_host' => '172.17.8.24',
					'smtp_port' => 25,
					'smtp_user' => 'prosystmp',
					'smtp_pass' => 'syspro14',
					'mailtype' => 'html',
					'charset' => 'utf-8',
					'wordwrap' => TRUE
				);
				*/
				// initialize mail config
				$this->load->library('email');
				$this->email->initialize($config);
				$this->email->set_newline("\r\n");
				
				// set mail to
				if($mail_group['mail_to'] != '')
				{
					$to = $to . ', ' . $mail_group['mail_to'];
				}
				
				// set mail header
				$this->email->from($this->_usr_sess['app']['mail_smtp_user'], $this->_usr_sess['app']['app_fullname']);
				$this->email->to($to); 
				$this->email->cc($mail_group['mail_cc']. ';');
				if($attachment) $this->email->attach($attachment);
				
				// set mail content
				$this->email->subject($subject);
				$data['mail_subject'] = $subject;
				$data['mail_title'] = $this->_usr_sess['app']['app_name'];
				$data['mail_header'] = $this->_usr_sess['app']['app_name'] . ' - ' . $this->_usr_sess['app']['app_fullname'];
				$data['mail_footer'] = $this->_usr_sess['app']['app_owner'];
				
				$body = $this->load->view($mail_group['mail_view'], $data, TRUE);
				$this->email->message($body);	

				// send mail
				if(!$this->email->send()) trigger_error("Failed send mail notification for '" . $mail_type . "' - Mail error. " . $this->email->print_debugger());
				else
				{
					$this->set_mail_log($to, $mail_type, $subject, $data);
					$this->set_mail_max($mail_type, intval($mail_group['mail_counter']));
					
					if($attachment) unlink($attachment);
				}
			}
			else trigger_error('PHP Send Mail Error - Limit 50 mail per day for mail group ' . $mail_type);
		}
		catch(Exception $ex)
		{
			// exception log
			throw new Exception($ex->getMessage(), E_CORE_ERROR);
		}
		
		// always return true whatever the result --> to prevent process blocking
		return TRUE;
	}
	
	// function to get group mailto
	function get_mail_group($mail_group = NULL)
	{
		try
		{
			// start transaction
			$this->db->trans_begin();
			
			// select mail group
			$this->db->from('tmas_mail_group');
			$this->db->where('mail_group = ' . "'" . $mail_group . "'"); 
			$this->db->limit(1);
			
			// execute query, rollback on error
			if ($this->db->trans_status() === FALSE)
			{
				// rollback transaction
				$this->db->trans_rollback();					
				
				// event log
				trigger_error("Failed get data from table 'tmas_mail_group' for mail group '" . $mail_group . "' - Database error.");
			
				// return empty data
				return '';
			}
			else
			{
				// commit transaction
				$this->db->trans_commit();
				
				// set up user session_data
				$session_data = $this->db->get();
				
				// return value
				return $session_data->row_array();
			}
		}
		catch(Exception $ex)
		{
			// exception log
			throw new Exception($ex->getMessage(), E_CORE_ERROR);
			
			// return empty data
			return '';
		}
	}
	
	// function to get group mailto
	function set_mail_max($mail_group = NULL, $mail_max = 0)
	{
		try
		{
			$mail_limit = $this->_usr_sess['app']['app_session_timeout'];
			if(is_numeric($mail_limit)) $mail_limit = intval($this->_usr_sess['app']['app_session_timeout']);
			else $mail_limit = 50;
			
			if($mail_max == 0)
			{
				$mail_max = intval(date('Ymd') . '01');
			}
			else
			{
				$mail_counter = intval(date('Ymd') . '' . $mail_limit);
				
				if(($mail_counter > $mail_max) && ($mail_counter - $mail_max) < $mail_limit) $mail_max = $mail_max + 1;
				else $mail_max = intval(date('Ymd') . '01');
			}
			
			// start transaction
			$this->db->trans_begin();
			
			// update data
			$update_data = array(
				"mail_counter"	=> $mail_max
			);
			
			// start query
			$this->db->where('mail_group = ' . "'" . $mail_group . "'"); 
			$this->db->update('tmas_mail_group', $update_data);
			
			// execute query, rollback on error
			if ($this->db->trans_status() === FALSE)
			{
				// rollback transaction
				$this->db->trans_rollback();					
				
				// event log
				trigger_error("Failed set data from table 'tmas_mail_group' for mail group '" . $mail_group . "' - Database error.");
			}
			else
			{
				// commit transaction
				$this->db->trans_commit();
			}
		}
		catch(Exception $ex)
		{
			// exception log
			throw new Exception($ex->getMessage(), E_CORE_ERROR);
			
			// return empty data
			return '';
		}
	}
	
	// function to set user notification
	function set_mail_log($to, $mail_type, $subject, $mail_content)
	{
		try
		{
			// remove unnecessary content element
			unset($mail_content['mail_subject']);
			unset($mail_content['mail_title']);
			unset($mail_content['mail_header']);
			unset($mail_content['mail_footer']);
			unset($mail_content['sys']);
			
			// start transaction
			$this->db->trans_begin();
			
			// start query
			$data = array(
						'mail_to'		=> $to,
						'mail_type'		=> $mail_type,
						'mail_subject'	=> $subject,
						'mail_content'	=> json_encode($mail_content)
					);
					
			$this->db->insert('this_notification_mail',$data);

			// execute query, rollback on error
			if ($this->db->trans_status() === FALSE)
			{
				// rollback transaction
				$this->db->trans_rollback();
				
				// event log
				trigger_error("Failed insert to table 'this_notification_user' - Database error.");
			}
			else
			{
				// commit transaction
				$this->db->trans_commit();
			}
		}
		catch(Exception $ex)
		{
			// exception log
			throw new Exception($ex->getMessage(), E_CORE_ERROR);
		}
	}
	
	// function to get user notification
	function get_notify($last_id = '0')
	{
		try
		{
			// start transaction
			$this->db->trans_begin();
			
			// select mail group
			$this->db->select("tnu.id_this_notification_user, tnu.message_header, tnu.message_content, CASE WHEN DATEDIFF(NOW(),tnu.message_timestamp) > 0 THEN CONCAT('',DATEDIFF(NOW(),tnu.message_timestamp),' days ago') WHEN MOD(HOUR(TIMEDIFF(NOW(),tnu.message_timestamp)),24) > 0 AND MINUTE(TIMEDIFF(NOW(),tnu.message_timestamp)) > 0 THEN CONCAT('',MOD(HOUR(TIMEDIFF(NOW(),tnu.message_timestamp)),24),' hours ', MINUTE(TIMEDIFF(NOW(),tnu.message_timestamp)), ' minutes ago') WHEN MOD(HOUR(TIMEDIFF(NOW(),tnu.message_timestamp)),24)> 0 AND MINUTE(TIMEDIFF(NOW(),tnu.message_timestamp)) = 0 THEN CONCAT('',MOD(HOUR(TIMEDIFF(NOW(),tnu.message_timestamp)),24),' hours ago') WHEN MOD(HOUR(TIMEDIFF(NOW(),tnu.message_timestamp)),24) = 0 AND MINUTE(TIMEDIFF(NOW(),tnu.message_timestamp)) > 0 THEN CONCAT('',MINUTE(TIMEDIFF(NOW(),tnu.message_timestamp)),' minutes ago') WHEN MOD(HOUR(TIMEDIFF(NOW(),tnu.message_timestamp)),24) = 0 AND MINUTE(TIMEDIFF(NOW(),tnu.message_timestamp)) = 0 THEN CONCAT('',SECOND(TIMEDIFF(NOW(),tnu.message_timestamp)),' seconds ago') END as 'message_timelapse', tnu.message_flag");
			$this->db->from('this_notification_user tnu');
			$this->db->where('tnu.message_to not like ' . "'%[" . $this->_usr_sess['id'] . "]%'"); 
		//	$this->db->where("tnu.message_flag = '0' OR UNIX_TIMESTAMP(tnu.message_timestamp) > " . $last_notify);
			$this->db->where("tnu.id_this_notification_user > " . $last_id);
			$this->db->order_by('tnu.message_timestamp', 'desc');
			$this->db->limit(10);
			
			// execute query, rollback on error
			if ($this->db->trans_status() === FALSE)
			{
				// rollback transaction
				$this->db->trans_rollback();					
				
				// event log
				trigger_error("Failed get data from table 'this_notification_user' for user '" . $this->_usr_sess['username'] . "' - Database error.");
			
				// return empty data
				return '';
			}
			else
			{
				// commit transaction
				$this->db->trans_commit();
				
				// set up user notify_data
				$notify_data = $this->db->get();
				
				// return value
				return $notify_data->result();
			}
		}
		catch(Exception $ex)
		{
			// exception log
			throw new Exception($ex->getMessage(), E_CORE_ERROR);
			
			// return empty data
			return '';
		}
	}
	
	// function to set user notification
	function set_notify($title = NULL, $message = NULL)
	{
		try
		{
			// start transaction
			$this->db->trans_begin();
			
			// start query
			$data = array(
						'message_header'	=> $title,
						'message_content'	=> $message
					);
					
			$this->db->insert('this_notification_user',$data);

			// execute query, rollback on error
			if ($this->db->trans_status() === FALSE)
			{
				// rollback transaction
				$this->db->trans_rollback();
				
				// event log
				trigger_error("Failed insert to table 'this_notification_user' - Database error.");
			}
			else
			{
				// commit transaction
				$this->db->trans_commit();
			}
		}
		catch(Exception $ex)
		{
			// exception log
			throw new Exception($ex->getMessage(), E_CORE_ERROR);
		}
	}
/*
// =================================================== NOT USED - TO BE DELETED ====================================================
	// function to insert audit log
	function audit_log($log_message = null)
	{
		$section_type = 'M'; 			// M = Model
		$class_name = 'Audit';			// current class name
		$function_name = 'audit_log';	// current function name
		
		try {
			// start transaction
			$this->db->trans_begin();
			
			// start query
			$data = array(
						'source_ip' => $this->input->ip_address(),
						'id_tmas_user' => $user_id,
						'log_message' => $log_message
					);
			$this->db->insert('this_audit_log',$data);
			
			// execute query, rollback on error
			if ($this->db->trans_status() === FALSE) {
				$this->db->trans_rollback();
			}
			else {
				$this->db->trans_commit();
			}
		}
		catch(Exception $ex) {
			// exception log
			$this->exception_log($section_type, $class_name, $function_name, $ex->getMessage());
		}	
	}
	
	// function to add database log
	function db_exception_log($log_message = null)
	{
		$section_type = 'M'; 					// M = Model
		$class_name = 'Audit';					// current class name
		$function_name = 'db_exception_log';	// current function name
		
		try {
			// start transaction
			$this->db->trans_begin();
			
			// start query
			$data = array(
						'log_ip' => $this->input->ip_address(),
						'log_message' => $log_message
					);
			$this->db->insert('this_db_exception_log',$data);
			
			// execute query, rollback on error
			if ($this->db->trans_status() === FALSE) {
				$this->db->trans_rollback();
			}
			else {
				$this->db->trans_commit();
			}
		}
		catch(Exception $ex) {
			// exception log
			$this->exception_log($section_type, $class_name, $function_name, $ex->getMessage());
		}
	}
	
	// function to add exception log
	function exception_log($log_type = 'M', $log_page = null, $log_function = null, $log_message = null)
	{
		$section_type = 'M'; 				// M = Model
		$class_name = 'Audit';				// current class name
		$function_name = 'exception_log';	// current function name
		
		try {
			// start transaction
			$this->db->trans_begin();
			
			// start query
			$data = array (
						'log_ip' => $this->input->ip_address(),
						'log_type' => $log_type,
						'log_page' => $log_page,
						'log_function' => $log_function,
						'log_message' => $log_message
					);
			$this->db->insert('this_exception_log',$data);
			
			// execute query, rollback on error
			if ($this->db->trans_status() === FALSE) {
				$this->db->trans_rollback();
			}
			else {
				$this->db->trans_commit();
			}
		}
		catch(Exception $ex) {
			// exception log (no log to avoid loophole)
		}
	}
	
	// function to insert audit trail log
	function trail_log($table_name, $function_code, $table_value = array(), $instance_key = '')
	{
		$section_type = 'M'; 			// M = Model
		$class_name = 'Audit';			// current class name
		$function_name = 'trail_log';	// current function name
		
		try {
			// check for $function_code validity (only accept 'S' for Select, 'I' for Insert, 'U' for Update, 'D' for Delete)
			if($function_code == 'S' || $function_code == 'I' || $function_code == 'U' || $function_code == 'D') {
				// start transaction
				$this->db->trans_begin();
				
				// start query
				$data = array(
							'source_ip' => $this->input->ip_address(),
							'id_tmas_user' => $this->_usr_sess['id'],
							'function_code' => $function_code,
							'table_name' => $table_name,
							'table_value' => json_encode($table_value),
							'instance_key' => $instance_key
						);
				$this->db->insert('this_audit_trail',$data);
				
				// execute query, rollback on error
				if ($this->db->trans_status() === FALSE) {
					$this->db->trans_rollback();
					
					// db_error_log
					$this->audit->db_exception_log('this_audit_trail', $this->db->log_message(), $user_id);
				}
				else {
					$this->db->trans_commit();
				}
			}	
			else {
				$this->exception_log($section_type, $class_name, $function_name, 'Function Code is not \'S\', \'I\', \'U\', or \'D\'');
			}
		}
		catch(Exception $ex) {
			// exception log
			$this->exception_log($section_type, $class_name, $function_name, $ex->getMessage());
		}
	}
*/
	function user_log_old($iduser,$action,$description)
	{	
		try
		{
			// start transaction
			$this->db->trans_begin();
			
			// start query
			$data = array(
				'id_tmas_user'	=> $iduser,
				'action'		=> strtoupper($action),
				'description'	=> $description
			);
			
			$this->db->insert('tlog_user_activity',$data);

			// execute query, rollback on error
			if ($this->db->trans_status() === FALSE)
			{
				// rollback transaction
				$this->db->trans_rollback();
				
				// event log
				trigger_error("Failed insert to table 'tlog_user_activity' - Database error.");
			}
			else
			{
				// commit transaction
				$this->db->trans_commit();
			}
		}
		catch(Exception $ex)
		{
			// exception log
			throw new Exception($ex->getMessage(), E_CORE_ERROR);
		}
	}
	
	function user_log($action, $description, $user = "SESSION_DATA")
	{	
		try
		{
			if($user == 'SESSION_DATA') $user = $this->_usr_sess['id'];
			else
			{
				$this->db->select("id_tmas_user");
				$this->db->from('tmas_user');
				$this->db->where('user_name', $user);
				
				$usr = $this->db->get();
				foreach($usr->result() as $data)
				{
					$user = $data->id_tmas_user;
				}
			}
			
			// accepted $action
			$action = strtoupper($action);
			// I: Input	|| U: Update || D: Delete || C: Copy  || R: Roll Up || M: Mapping || P: Import || X: Export || O: DOWNLOAD || L: Upload || S: Site
			if($action == 'I') $action = 'INPUT';
			else if($action == 'U') $action = 'UPDATE';
			else if($action == 'D') $action = 'DELETE';
			else if($action == 'C') $action = 'COPY';
			else if($action == 'R') $action = 'ROLL UP';
			else if($action == 'M') $action = 'MAPPING';
			else if($action == 'P') $action = 'IMPORT';
			else if($action == 'X') $action = 'EXPORT';
			else if($action == 'O') $action = 'DOWNLOAD';
			else if($action == 'L') $action = 'UPLOAD';
			else if($action == 'S') $action = 'APPS';
			else $action = 'ERROR';
			
			IF($action != 'ERROR')
			{
				// start transaction
				$this->db->trans_begin();
				
				// start query
				$data = array(
					'id_tmas_user'	=> $user,
					'action'		=> $action,
					'description'	=> strtoupper($description)
				);
				
				$this->db->insert('tlog_user_activity',$data);

				// execute query, rollback on error
				if ($this->db->trans_status() === FALSE)
				{
					// rollback transaction
					$this->db->trans_rollback();
					
					// event log
					trigger_error("Failed insert to table 'tlog_user_activity' - Database error.");
				}
				else
				{
					// commit transaction
					$this->db->trans_commit();
				}
			}
		}
		catch(Exception $ex)
		{
			// exception log
			throw new Exception($ex->getMessage(), E_CORE_ERROR);
		}
	}
	
	// function to check menu status
	// return value: 0 -> Off || 1 -> On || 2 -> Read Only
	function get_menu_status($menu_uri)
	{
		try
		{
			// get user role from session
			$role = $this->_usr_sess['role'];
			$stat = 0;
			
			if($role)
			{
				$this->db->select("rolemenu_status");
				$this->db->from('tmas_rolemenu');
				$this->db->join('tmas_menu', 'tmas_menu.id_tmas_menu = tmas_rolemenu.id_tmas_menu');
				$this->db->where('tmas_rolemenu.id_tmas_role', $role);
				$this->db->where('upper(tmas_menu.menu_page)', strtoupper($menu_uri));
				
				$usr = $this->db->get();
				foreach($usr->result() as $data)
				{
					$stat = $data->rolemenu_status;
				}
			}
			
			return $stat;
		}
		catch(Exception $ex)
		{
			// exception log
			throw new Exception($ex->getMessage(), E_CORE_ERROR);
		}
	}
	
	// =================================================== CUSTOM SESSION HANDLER ====================================================
	function check_session()
	{
		try
		{
			$sess_status = $this->validate_session('idle');
			
			if($sess_status == 'session_destroy')
			{
				redirect('home/logout', 'refresh');
			}
		}
		catch(Exception $ex)
		{
			// exception log
			throw new Exception($ex->getMessage(), E_CORE_ERROR);
		}
	}
	
	// function to check active session
	function validate_session($stat = 'idle')
	{
		$ip_address = $this->input->ip_address();
		$curr_date = new DateTime();
		$curr_time = $curr_date->getTimestamp();
		$return_value = 'session_destroy';
		
		// check session last timestamp
		$this->db->select("timestamp");
		$this->db->from('tlog_session');
		$this->db->where('ip_address', $ip_address);
		
		$usr = $this->db->get();
		
		$timestamp = 0;
		foreach($usr->result() as $data)
		{
			$timestamp = $data->timestamp;
		}
		
		// if no session found, create one
		if($timestamp == 0)
		{
			/*
			// start transaction
			$this->db->trans_begin();
			
			// start query
			$data = array(
				'ip_address'	=> $ip_address,
				'timestamp'		=> $curr_time,
				'data'			=> $this->_usr_sess['username']
			);
			
			$this->db->replace('tlog_session',$data);

			// execute query, rollback on error
			if ($this->db->trans_status() === FALSE)
			{
				// rollback transaction
				$this->db->trans_rollback();
				
				// event log
				trigger_error("Failed insert to table 'tlog_session' - Database error.");
			}
			else
			{
				// commit transaction
				$this->db->trans_commit();
				
				$return_value = 'session_create';
			}
			*/
			// $return_value = 'session_destroy';
			$return_value = '';
		}
		// else, validate existing timestamp
		else
		{
			$diff_time = ($curr_time - $timestamp) / 60;
			
			// if timestamp <= 30 minutes, update timestamp
			if($diff_time <= 30 && $stat == 'load')
			{
				// start transaction
				$this->db->trans_begin();
				
				// start query
				$data = array(
					'ip_address'	=> $ip_address,
					'timestamp'		=> $curr_time,
					'data'			=> $this->_usr_sess['username']
				);
				
				$this->db->replace('tlog_session',$data);

				// execute query, rollback on error
				if ($this->db->trans_status() === FALSE)
				{
					// rollback transaction
					$this->db->trans_rollback();
					
					// event log
					trigger_error("Failed insert to table 'tlog_session' - Database error.");
				}
				else
				{
					// commit transaction
					$this->db->trans_commit();
					
					// $return_value = 'session_ok';
					$return_value = '';
				}
			}
			
			// if timestamp between 25 - 29 minutes, set notify for user
			else if($diff_time >=25 && $diff_time < 30 && $stat == 'idle') $return_value = '' . (30 - round($diff_time)) . '';
			
			// destroy session if exceeding 30 minutes
			// else $return_value = 'session_destroy';
			// else if($diff_time == 30) $return_value = 'less than 1';
			// else if($diff_time > 30) $return_value = 'session_destroy';
			else if($diff_time == 30) $return_value = '';
			else if($diff_time > 30) $return_value = '';
			else $return_value = '';
		}
		
		// session royale
		// destroy all session exceeding 30 (30 * 60 = 1800) minutes deadline
		$time_deadline = $curr_time - 1800;
		
		// start transaction
		$this->db->trans_begin();
		
		// start query
		$this->db->where('timestamp < ' . $time_deadline);
		$this->db->delete('tlog_session');
		
		// execute query, rollback on error
		if ($this->db->trans_status() === FALSE)
		{
			// rollback transaction
			$this->db->trans_rollback();
			
			// event log
			trigger_error("Failed to delete data on table 'tlog_session' database status - Database error.", E_COMPILE_ERROR);
		}
		else
		{
			$this->db->trans_commit();
		}
		
		return $return_value;
	}
	
	function get_mail_log_error()
	{
		try
		{
			 $hasil=array();
			// get user role from session
				$log=$this->db->query("SELECT * FROM `tlog_error` where changed_on <= now()  and changed_on >= (now() - INTERVAL '59' MINUTE) and type='Warning' ORDER BY changed_on desc");
				if($log->num_rows() > 0){
					foreach($log->result() as $data){
						$hasil[] = $data;
					}
					
				}
			
			return $hasil;
		}
		catch(Exception $ex)
		{
			// exception log
			throw new Exception($ex->getMessage(), E_CORE_ERROR);
		}
	}
}
?>