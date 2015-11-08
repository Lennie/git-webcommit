<?php
	error_reporting (E_ALL);

	/// settings ///

	// configure the list of repositories, only one support at the moment
	$repos = Array ('/tmp/git-webcommit');

	// set the default repository, you probably want to keep it set to 0
	$defaultrepo = 0;

	// configure the authentication method:
	$authmethod = 'httpbasic';
//	$authmethod = 'htpasswd';
//	$authmethod = 'none';

	// when using htpasswd, the 'pass' entry isn't needed
	// 'name' and 'email' are required.
	$auth = Array (
		'testuser2' => Array ('pass' => 'a94a8fe5ccb19ba61c4c0873d391e987982fbbd3', 'name' => 'Test User', 'email' => 'test@somewhere')
	);

	// passwords are sha1 hashes, uncomment next line to create the password hash:
//	exit (sha1 ('my pass'));

	// author for when you don't use authentication
	$author = $auth ['testuser2']['name'] . '<' . $auth ['testuser2']['email'] . '>'; // 'firstname lastname <email-address>'
//	$author = '';

	$title = '';

	$enable_stats = false; // not available yet

	$debug = false;
//	$debug = true;

	$gitpath = 'git';
	$diffpath = 'diff';

	if (file_exists (dirname (__FILE__) . '/config-git-webcommit.php'))
		include (dirname (__FILE__) . '/config-git-webcommit.php');

	/// main ///

	if ($authmethod === 'httpbasic')
		$author = handle_basic_auth ();
	elseif ($authmethod === 'htpasswd')
		$author = handle_htpasswd_auth ();

	@ob_end_clean ();
	flush ();

	$dir = $repos [$defaultrepo];

	if (!chdir ($dir))
		exit ('directory not found: '.$dir);

	$_handles = Array ();
	$_handlecount = 0;

	$md5_empty_string = 'd41d8cd98f00b204e9800998ecf8427e';
	$sha1_empty_string = 'da39a3ee5e6b4b0d3255bfef95601890afd80709';

	$somethingstaged = false;

	echo html_header ();

	if ($_SERVER ['REQUEST_METHOD'] == 'POST') {
		if (isset ($_POST ['commit_message']))
			$commit_message = $_POST ['commit_message'];
		else
			$commit_message = '';

		debug ($_POST);

		if (isset ($_POST ['change_staged']) && $_POST ['change_staged'] && isset ($_POST ['statushash']) && $_POST ['statushash'])
			handle_change_staged_req ();
		elseif (isset ($_POST ['commit']) && $_POST ['commit'] && isset ($_POST ['statushash']) && $_POST ['statushash'] && isset ($_POST ['commit_message']) && $_POST ['commit_message'] != '')
			handle_commit_req ();
		elseif (isset ($_POST ['refresh']) && $_POST ['refresh'])
			handle_refresh_req ();
		elseif (isset ($_POST ['pull']) && $_POST ['pull'])
			handle_pull_req ();
		elseif (isset ($_POST ['push']) && $_POST ['push'])
			handle_push_req ();
		else {
			error ('POST failure');
			exit ();
		}
	} else {
		echo html_header_message ('&nbsp;');
		echo html_form_start ();
		view_result ();
	}

	/// functions ///

	function view_result ($status = '') {
		global $somethingstaged, $enable_stats;

		if ($status == '')
			$status = get_status ();

		debug ($status);

		if ($status ['disable_commit'] !== true)
			$something_to_commit = $somethingstaged;

		echo html_form_end ($something_to_commit, $status ['hash']);

		echo html_footer ();
	}

	function handle_refresh_req () {
		echo html_header_message ('refreshing...');
		echo html_form_start ();

		view_result ();
		echo html_header_message_update ('refreshing... done');
	}

	function handle_pull_req () {
		echo html_header_message ('pulling...');
		echo html_form_start ();
		do_git_action('pull');
		view_result ();
		echo html_header_message_update ('pulling... done');
	}

	function handle_push_req () {
		echo html_header_message ('pushing...');
		echo html_form_start ();
		do_git_action('push');
		view_result ();
		echo html_header_message_update ('pushing... done');
	}

	function handle_change_staged_req () {
		global $enable_stats;

		echo html_header_message ('checking, before handling staging...');
		echo html_form_start ();

		$status = get_status (true, false, false);

		if ($status ['hash'] != $_POST ['statushash'])
			error ('something changed in the directory and/or repository, not doing any changes ! Sorry');
		else {
			echo html_header_message_update ('doing staging/unstaging...');

			$num1 = 0;
			$num2 = 0;

			$arr = Array ();

			foreach ($status ['lines'] as $v)
				$arr [] = $v ['file'];

			$poststaged = Array ();

			if (isset ($_POST ['stagecheckbox']))
				foreach ($_POST ['stagecheckbox'] as $v) {
					$key = array_search ($v, $_POST ['hash']);
					if ($key !== false)
						$poststaged [$key] = 'Y';
				}

			$max = count ($_POST ['filename']);
			if ($max !== count ($arr))
				staged_change_checker_error ();

			for ($i = 0; $i < $max; $i++) {
				if ($_POST ['filename'] [$i] !== $arr [$i]) 
					staged_change_checker_error ();

				if ($status ['lines'][$i]['staged'] == 'N' && isset ($poststaged [$i]) && $poststaged [$i] == 'Y')
					stage_file ($arr [$i], $status ['lines'][$i]);

				if ($status ['lines'][$i]['staged'] == 'Y' && !isset ($poststaged [$i]))
					unstage_file ($arr [$i], $status ['lines'][$i]);

				echo html_js_remove_container ($status ['lines'][$i]['prefix']);
			}

			$status = get_status (false, true, $enable_stats);
		}

		view_result ($status);
		echo html_header_message_update ('doing staging/unstaging... done');
	}

	function staged_change_checker_error () {
		error ('something went wrong when comparing the POST and current status of the files on disk, eventhough the previously calculated hashes were OK. Processing stopped. Sorry.');
		exit ();
	}

	function handle_commit_req () {
		global $enable_stats, $author;

		echo html_header_message ('checking, before doing commit...');
		echo html_form_start ();

		$status = get_status (true, false, false);

		if ($status ['hash'] != $_POST ['statushash'])
			error ('something changed in the directory and/or repository, not doing any changes ! Sorry');
		else {
			do_commit ($_POST ['commit_message'], $author);

			$max = count ($_POST ['filename']);
			for ($i = 0; $i < $max; $i++)
				echo html_js_remove_container ($status ['lines'][$i]['prefix']);

			$status = get_status ();
		}

		view_result ($status);
	}

///////////////////////////////

	function stage_file ($file, $status) {
		global $gitpath;

		echo html_header_message_update ("staging file $file");

		if ($status ['state'] == 'Deleted')
			$args = Array ('rm', $file);
		else
			$args = Array ('add', $file);

		debug ('git ' . implode (' ', $args));
		$h = start_command ($gitpath, $args);
		list ($stdout, $stderr) = get_all_data ($h, Array ('stdout', 'stderr'));
		debug ("stdout: $stdout");
		debug ("stderr: $stderr");
		$exit = get_exit_code ($h);
		debug ($exit);
		clean_up ($h);

		if ($exit === 0) 
			echo html_header_message_update ("staging file $file: OK");
		else {
			echo html_header_message_update ("staging file $file: ".'<span class="error">FAILED</a>', true);
			if (trim ($stderr) != '')
				error ("$stderr");
			echo html_form_end ();
			exit ();
		}
	}

	function unstage_file ($file, $status) {
		global $gitpath;

		echo html_header_message_update ("unstaging file: $file");

		debug ($status);

		$args = Array ('reset', 'HEAD', $file);
		debug ('git ' . implode (' ', $args));
		$h = start_command ($gitpath, $args);
		list ($stdout, $stderr) = get_all_data ($h, Array ('stdout', 'stderr'));
		debug ("stdout: $stdout");
		debug ("stderr: $stderr");
		$exit = get_exit_code ($h);
		debug ($exit);
		clean_up ($h);

		if ($exit == 0 || $exit == 1) // 0 is nothing staged, 1 still something staged
			echo html_header_message_update ("unstaging file: $file: OK");
		else {
			echo html_header_message_update ("unstaging file: $file: ".'<span class="error">FAILED</a>', true);
			if (trim ($stderr) != '')
				error ("$stderr");
			echo html_form_end ();
			exit ();
		}
	}

	function do_commit ($msg = false, $author = '') {
		global $commit_message, $gitpath;

		$tmp = tempnam ('/tmp', 'git-commit');
		$fp = fopen ($tmp, 'w+');
		fwrite ($fp, $msg);
		fclose ($fp);

		echo html_header_message_update ("commiting changed files...");
		$args = Array ('commit', '--no-status', '-F', $tmp);
		if ($author != '') {
			$args [] = '--author';
			$args [] = '"'.$author.'"';
		}
		debug ('git ' . implode (' ', $args));
		$h = start_command ($gitpath, $args);
		list ($stdout, $stderr) = get_all_data ($h, Array ('stdout', 'stderr'));
		debug ("stdout: $stdout");
		debug ("stderr: $stderr");
		$exit = get_exit_code ($h);
		debug ($exit);
		clean_up ($h);
		unlink ($tmp);

		if ($exit === 0) {
			echo html_header_message_update ("commiting changed files... OK");
			$commit_message = '';
		} else {
			echo html_header_message_update ('commiting changed files...: <span class="error">FAILED</a>', true);
			if (trim ($stderr) != '')
				error ("$stderr");
			echo html_form_end ();
			exit ();
		}
	}

	function do_git_action ($action) {
		global $commit_message, $gitpath;


		echo html_header_message_update ($action."ing...");
		$args = Array ($action);
		
		debug ('git ' . implode (' ', $args));
		$h = start_command ($gitpath, $args);
		list ($stdout, $stderr) = get_all_data ($h, Array ('stdout', 'stderr'));
		debug ("stdout: $stdout");
		debug ("stderr: $stderr");
		$exit = get_exit_code ($h);
		debug ($exit);
		clean_up ($h);
		if ($exit === 0) {
			echo html_header_message_update ($action."ing... OK");
		} else {
			echo html_header_message_update ($action.'ing...: <span class="error">FAILED</a>', true);
			if (trim ($stderr) != '')
				error ("$stderr");
			echo html_form_end ();
			exit ();
		}
	}

///////////////////////////////

	function error ($str = '') {
		echo '<p>ERROR: '.$str . "</p>\n";
		return false;
	}

	function debug ($input = '', $force = false) {
		global $debug;

		if ($force === false && $debug === false)
			return true;

		if (is_string ($input))
			echo "<pre>$input</pre>";
		elseif (is_array ($input)) {
			echo "<pre>";
			print_r ($input);
			echo "</pre>";
		} else {
			echo "<pre>";
			var_dump ($input);
			echo "</pre>";
		}	
	}

///////////////////////////////

	function make_one_hash (&$rs) {
		$str = '';
	
		if (isset ($rs ['lines']) && is_array ($rs ['lines']))
			foreach ($rs ['lines'] as $v)
				$str .= $v ['hash'];

		$str .= $rs ['outputhash'];
		
		$rs ['hash'] = sha1 ($str);
	}

	function get_status ($disabled = false, $makediff = true, $stats = false) {
		global $somethingstaged, $gitpath;

		static $firstrun;

		if (!isset ($firstrun))
			$firstrun = true;
		else
			$firstrun = false;

		if (!$firstrun)
			echo close_and_add_filelist_parent ();

		$result = Array ('lines' => Array ());

		$somethingstaged = false;

		clearstatcache ();

		$h = start_command ($gitpath, Array ('status', '--porcelain'), false);
		if ($h === false)
			return error ('command failed to start');
		else {
			close_stdin ($h);

			$err = '';
			$out = '';

			while (!is_done ($h)) {
				$line = get_stdout_line ($h);
				if ($line != '') {
					debug ($line);
					$parsed = parse_line ($line);
					$int = interpret ($parsed, $disabled, $makediff, $stats);
					if ($int !== false) {
						if (isset ($int ['dir'])) {
							$list = Array ();
							$list = add_directory_listing ($parsed ['dir'], $disabled, $makediff, $stats, $list);
							$result ['lines'] = array_merge ($result ['lines'], $list);
						} else
							$result ['lines'][] = $int;
					}

					flush ();
				}
			}

			$exit = get_exit_code ($h);

			if ($exit !== 0) {
 				$errors = get_all_data ($h, Array ('stdout', 'stderr'));
 				if (!is_array ($errors))
 					$errors = Array ();
 				return error ("command failed with exitcode ".$exit.":\n".implode (' ', $errors));
			}

			$result ['output'] = get_all_data ($h);

			$result ['outputhash'] = sha1 ($result['output']);

			$result ['disable_commit'] = false;

			make_one_hash ($result);
		}

		clean_up ($h);

		return $result;
	}

	function get_file_hash ($file) {
		if (file_exists ($file))
			return sha1 ($file . sha1_file ($file));

		return false;
	}

	function parse_line ($str) {
		global $sha1_empty_string;

		$str = rtrim ($str);
		$file = substr ($str, 3);

		if (file_exists ($file) ) {
			$res = Array ('strstaged' => $str [0], 'strmodified' => $str [1], 'str' => $str);
			$type = filetype ($file);
			if ($type === 'file') {
				$res ['hash'] = get_file_hash ($file);
				$res ['file'] = $file;
			} elseif ($type == 'dir') {
				$res ['dir'] = $file;
			} else
				$res = Array ('str' => $str);

			return $res;
		} elseif ($str [0] == ' ' && $str [1] == 'D') {
			$res = Array ('strstaged' => ' ', 'strmodified' => 'D', 'str' => $str, 'file' => $file, 'hash' => sha1 ($file . $sha1_empty_string));
		} elseif ($str [0] == 'D' && $str [1] == ' ') {
			$res = Array ('strstaged' => 'D', 'strmodified' => ' ', 'str' => $str, 'file' => $file, 'hash' => sha1 ($file . $sha1_empty_string));
		} elseif ($str [0] == 'R') {
			$sub = substr ($str, 4);
			$arr = explode (' -> ', $sub);
			$res =  Array ('strstaged' => 'R', 'strmodified' => ' ', 'str' => $str, 'oldfile' => $arr [0], 'newfile' => $arr [1], 'hash' => get_file_hash ($arr[1]), 'file' => $arr[1]);
		} elseif ($str [0] == 'C') {
			preg_match ('/(.*) -> (.*)/', $file, $filenames);
			$res = Array ('strstaged' => $str [0], 'strmodified' => ' ', 'oldfile' => $filenames [1], 'newfile' => $filenames [2], 'str' => $str, 'hash' => get_file_hash ($filenames [2]), 'file' => $filenames [2]);
		} else
			$res = Array ('str' => $str);

		return $res;
	}

	function interpret ($parsed, $disabled = false, $makediff = true, $stats = false) {
		global $gitpath;

		if (isset ($parsed ['file'])) {
			if ($parsed ['strstaged'] == '?' || $parsed ['strstaged'] == 'A') {
				if ($makediff) {
					$command = 'diff';
					$args = Array ('-u', '/dev/null', $parsed ['file']);
					if (isset ($parsed ['staged']) && $parsed ['staged'] == 'A') {
						$args = Array ('diff', '--cached', $parsed ['file']);
						$command = $gitpath;
					}
					$h = start_command ($command, $args);
					close_stdin ($h);
					$diff = htmlentities (get_all_data ($h));
					clean_up ($h);
				} else
					$diff = false;

				$parsed ['state'] = set_state ($parsed ['strmodified'], $parsed ['strstaged']);
				$parsed ['staged'] = set_staged ($parsed ['strmodified'], $parsed ['strstaged']);

				list ($str, $prefix) = html_file ($parsed ['file'], $parsed ['state'], $parsed ['staged'], $parsed ['hash'], $diff, $disabled);
				echo $str;
				$parsed ['prefix'] = $prefix;
			} elseif (( $parsed ['strmodified'] == 'M') || ($parsed ['strstaged'] == 'M' && $parsed ['strmodified'] == ' ')) {
				if ($makediff) {
					$args = Array ('diff', $parsed ['file']);

					if ($parsed ['strstaged'] == 'M' && $parsed ['strmodified'] == ' ')
						$args = Array ('diff', '--cached', $parsed ['file']);

					$h = start_command ($gitpath, $args);
					close_stdin ($h);
					$str = get_all_data ($h);
					$diff = htmlentities ($str);
					$exit = get_exit_code ($h);
					clean_up ($h);
				} else
					$diff = false;

				$parsed ['state'] = set_state ($parsed ['strmodified'], $parsed ['strstaged']);
				$parsed ['staged'] = set_staged ($parsed ['strmodified'], $parsed ['strstaged']);

				list ($str, $prefix) = html_file ($parsed ['file'], $parsed ['state'], $parsed ['staged'], $parsed ['hash'], $diff, $disabled);
				echo $str;
				$parsed ['prefix'] = $prefix;
			} elseif ($parsed ['strmodified'] == 'D') {
				if ($makediff) {
					$args = Array ('diff', '--', $parsed ['file']);
					$h = start_command ($gitpath, $args);
					close_stdin ($h);
					$str = get_all_data ($h);
					$diff = htmlentities ($str);
					$exit = get_exit_code ($h);
					clean_up ($h);
				} else
					$diff = false;

				$parsed ['state'] = set_state ($parsed ['strmodified'], $parsed ['strstaged']);
				$parsed ['staged'] = set_staged ($parsed ['strmodified'], $parsed ['strstaged']);

				list ($str, $prefix) = html_file ($parsed ['file'], $parsed ['state'], $parsed ['staged'], $parsed ['hash'], $diff, $disabled);
				echo $str;
				$parsed ['prefix'] = $prefix;
			} elseif ($parsed ['strstaged'] == 'D') {
				if ($makediff) {
					$args = Array ('diff', '--cached', '--', $parsed ['file']);
					$h = start_command ($gitpath, $args);
					close_stdin ($h);
					$str = get_all_data ($h);
					$diff = htmlentities ($str);
					$exit = get_exit_code ($h);
					clean_up ($h);
				} else
					$diff = false;

				$parsed ['state'] = set_state ($parsed ['strmodified'], $parsed ['strstaged']);
				$parsed ['staged'] = set_staged ($parsed ['strmodified'], $parsed ['strstaged']);

				list ($str, $prefix) = html_file ($parsed ['file'], $parsed ['state'], $parsed ['staged'], $parsed ['hash'], $diff, $disabled);
				echo $str;
				$parsed ['prefix'] = $prefix;
			} elseif ($parsed ['strstaged'] == 'R') {
				if ($makediff) {
					$args = Array ('diff', '--cached', '--', $parsed ['file']);
					$h = start_command ($gitpath, $args);
					close_stdin ($h);
					$str = get_all_data ($h);
					$diff = htmlentities ($str);
					$exit = get_exit_code ($h);
					clean_up ($h);
				} else
					$diff = false;

				$parsed ['state'] = set_state ($parsed ['strmodified'], $parsed ['strstaged']);
				$parsed ['staged'] = set_staged ($parsed ['strmodified'], $parsed ['strstaged']);

				list ($str, $prefix) = html_file ($parsed ['file'], $parsed ['state'], $parsed ['staged'], $parsed ['hash'], $diff, $disabled);
				echo $str;
				$parsed ['prefix'] = $prefix;
			} elseif ($parsed ['strstaged'] == 'C') {
				$info = htmlentities ('file ' . $parsed ['newfile'] . ' is a copy of ' . $parsed ['oldfile']);

				$parsed ['state'] = set_state ($parsed ['strmodified'], $parsed ['strstaged']);
				$parsed ['staged'] = set_staged ($parsed ['strmodified'], $parsed ['strstaged']);

				list ($str, $prefix) = html_file ($parsed ['file'], $parsed ['state'], $parsed ['staged'], $parsed ['hash'], $info, $disabled);
				echo $str;
				$parsed ['prefix'] = $prefix;
			} else
				interpret_not_supported ($parsed, __FILE__, __LINE__);
		} else {
			if (isset ($parsed ['dir']) && $parsed ['strmodified'] == '?' && $parsed ['strstaged'] == '?') {
				// is a dir, handled outside this function
			} else
				interpret_not_supported ($parsed, __FILE__, __LINE__);
		}

		return $parsed;
	}

	function add_directory_listing ($dir, $disabled, $makediff, $stats, &$list) {
		global $diffpath;

		$handle = opendir ($dir);
		while (($entry = readdir ($handle)) !== false)
			if ($entry != '.' && $entry != '..') {
				$type = filetype ($dir . $entry);
				if ($type == 'file') {
					$file = $dir . $entry;
					$hash = get_file_hash ($file);
					$parsed = Array ('file' => $file, 'hash' => $hash);
					$parsed ['state'] = 'New';
					$parsed ['staged'] = 'N';
					if ($makediff) {
						$command = $diffpath;
						$args = Array ('-u', '/dev/null', $parsed ['file']);
						$h = start_command ($command, $args);
						close_stdin ($h);
						$diff = htmlentities (get_all_data ($h));
						clean_up ($h);
					} else
						$diff = false;
					list ($str, $prefix) = html_file ($file, $parsed ['state'], $parsed ['staged'], $parsed ['hash'], $diff, $disabled);
					echo $str;
					$parsed ['prefix'] = $prefix;
					$list [] = $parsed;
				} elseif ($type == 'dir') {
					add_directory_listing ($dir . $entry . '/', $disabled, $makediff, $stats, $list);
				} else
					interpret_not_supported ($dir . $entry, __FILE__, __LINE__);
			}

		return $list;
	}

	function interpret_not_supported ($debug, $file = false, $line = false) {
		if ($file === false)
			$file = '';
		else
			$file = $file . ': ';

		if ($line === false)
			$line = '';
		else
			$line = $line . ': ';

		error ($file.$line.'Not implemented: Only changed, added, deleted files is supported right now. Found something else in the output of git status, debug output is below. Sorry.');

		debug ($debug, true);

		exit ();
	}

	function set_state ($modified, $staged) {
		$rv = $modified; // last resort ?

		if ($modified == '?' || $staged == 'A')
			$rv = 'New';

		if ($modified == 'D' || $staged == 'D')
			$rv = 'Deleted';

		if ($modified == 'M' || $staged == 'M')
			$rv = 'Modified';

		if ($staged == 'R')
			$rv = 'Renamed';

		if ($staged == 'C')
			$rv = 'Copied';

		return $rv;
	}

	function set_staged ($modified, $staged) {
		$rv = $staged; // last resort ?

		if ($staged == ' ' || $staged == '?')
			$rv = 'N';

		if ($staged == 'Y' || $staged == 'M' || $staged == 'A' || $staged == 'D' || $staged == 'R' || $staged == 'C')
			$rv = 'Y';

		return $rv;
	}
///////////////////////////////

	function start_command ($command, $argarr, $blocking = true) {
		$descriptorspec = array(
			0 => Array ('pipe', 'r'),  // stdin
			1 => Array ('pipe', 'w'),  // stdout
			2 => Array ('pipe', 'w'),  // stderr
		);

		$pipes = Array ();

		$args = '';

		foreach ($argarr as $v)
			$args .= ' '. escapeshellarg ($v);

		$command = escapeshellcmd ($command);

		$proc = proc_open($command . ' ' . $args, $descriptorspec, $pipes);

		if (is_resource($proc)) {
			if ($blocking === false) {
				stream_set_blocking ($pipes [0], 0);
				stream_set_blocking ($pipes [1], 0);
				stream_set_blocking ($pipes [2], 0);
			} else {
				// should already be the default
				stream_set_blocking ($pipes [0], 1);
				stream_set_blocking ($pipes [1], 1);
				stream_set_blocking ($pipes [2], 1);
			}

			global $_handles, $_handlecount;

			$h = ++$_handlecount;

			$_handles [$h] = Array ('proc' => $proc, 0 => $pipes [0], 1 => $pipes [1], 2 => $pipes [2]);

			return $h;
		} else
			return false;
	}

	function end_command ($h) {
		global $_handles;

		if (!isset ($_handles [$h]))
			return false;

		// It is important that you close any pipes before calling
		// proc_close in order to avoid a deadlock
		@fclose ($_handles [$h][0]);
		@fclose ($_handles [$h][1]);
		@fclose ($_handles [$h][2]);

		if ((!isset ($_handles [$h]['running'])) || ($_handles [$h]['running'] !== false))
			$rv = proc_close ($_handles [$h] ['proc']);
		else
			$rv = $_handles [$h]['rv'];

		$_handles [$h]['running'] = false;

		return $rv;
	}

	function is_done ($h) {
		global $_handles;

		if (!isset ($_handles [$h]))
			return true; // closest thing to an error

		if (isset ($_handles [$h]['done']) && $_handles [$h]['done'] === true)
			return true;

		return false;
	}

	function put_in_stdin ($h, $str) {
		global $_handles;

		if (!isset ($_handles [$h]))
			return false;

		return fwrite ($_handles [$h][0]);
	}

	function close_stdin ($h) {
		global $_handles;

		if (!isset ($_handles [$h]))
			return false;

		return @fclose ($_handles [$h][0]);
	}

	function get_stdout_line ($h) {
		return _get_line ($h, 0);
	}

	function get_stderr_line ($h) {
		return _get_line ($h, 1);
	}

	function _get_line ($h, $num) {
		global $_handles;

		if (!isset ($_handles [$h]))
			return ""; // closest thing to an error

		$rv = _get_data ($h);
		if ($rv === false)
			return ""; // closest thing to an error

		return $rv [$num];
	}

	function _get_data ($h) {
		global $_handles;

		if (!isset ($_handles [$h]))
			return false;

		$read   = array($_handles[$h][1], $_handles[$h][2]);
		$write  = NULL;
		$except = NULL;
		$wait = 120;

		$rv = Array ('', '');

		if (false === ($num_changed_streams = stream_select($read, $write, $except, $wait))) {
			/* Error handling */

			$_handles [$h]['done'] = true; // ?

			return error ("error occured");
		} elseif ($num_changed_streams > 0) {
			/* At least on one of the streams something interesting happened */
			$alleof = true;

			$newout = fgets ($_handles[$h][1], 8192);
			$newerr = fgets ($_handles[$h][2], 8192);

			if (!isset ($_handles[$h]['stdout']))
				$_handles[$h]['stdout'] = '';

			if (!isset ($_handles[$h]['stderr']))
				$_handles[$h]['stderr'] = '';

			if ($newout !== false)
				$_handles[$h]['stdout'] .= $newout;
			if ($newerr !== false)
				$_handles[$h]['stderr'] .= $newerr;

			if (!feof ($_handles[$h][1]) && $newout != '')
				$alleof = false;
			if (!feof ($_handles[$h][2]) && $newerr != '')
				$alleof = false;

			if ($newout != '')
				$rv [0] = $newout;

			if ($newerr != '')
				$rv [1] = $newerr;

			if ($alleof) {
				$_handles [$h]['done'] = true;

				$status = proc_get_status ($_handles[$h] ['proc']);

				if ($status ['running'] === false)
					$return_value = $status ['exitcode'];
				else
					$return_value = end_command ($h);

				$_handles [$h]['rv'] = $return_value;
				$_handles [$h]['running'] = false;
			}
		} else
			debug ( "hier" );

		return $rv;
	}

	function get_all_data ($h, $intype = 'stdout') {
		global $_handles;

		if (!isset ($_handles [$h]))
			return false;

		if (!is_array ($intype))
			$types = Array ($intype);
		else
			$types = $intype;

		$rv = Array ();

		if (isset ($_handles [$h]['done']) && $_handles [$h]['done']) {
			foreach ($types as $k => $type)
				$rv [$k] = $_handles [$h][$type];

			if (!is_array ($intype))
				return $rv [0];

			return $rv;
		}

// XXX BUG: possibly a second call to function will fail, if done was false the first time and done = true second time.

		foreach ($types as $k => $type) {
			// if $rv == false we return false at the end
			if ($type == 'stdout')
				$rv [$k] = stream_get_contents ($_handles[$h][1]);
			elseif ($type == 'stderr')
				$rv [$k] = stream_get_contents ($_handles[$h][2]);
		}

		$status = proc_get_status ($_handles[$h]['proc']);

		if ($status ['running'] === false)
			$return_value = $status ['exitcode'];
		else
			$return_value = end_command ($h);

		$_handles [$h]['rv'] = $return_value;
		$_handles [$h]['running'] = false;

		if (!is_array ($intype))
			return $rv [0];

		return $rv;
	}

	function get_exit_code ($h) {
		global $_handles;

		if (!isset ($_handles [$h]))
			return false;

		if (!isset ($_handles [$h]['rv']))
			return false;

		return $_handles [$h]['rv'];
	}

	function clean_up ($h) {
		global $_handles;

		if (!isset ($_handles [$h]))
			return false;

		if (isset ($_handles [$h]['done']) && $_handles [$h]['done'] !== true)
			end_command ($h);

		unset ($_handles [$h]);
	}

///////////////////////////////

	function handle_basic_auth ($realm = 'private area') {
		global $auth;

		if (isset ($_SERVER['PHP_AUTH_USER'])
			&& isset ($_SERVER['PHP_AUTH_PW'])
			&& isset ($auth [$_SERVER['PHP_AUTH_USER']])
			&& $auth [$_SERVER['PHP_AUTH_USER']]['pass'] === sha1 ($_SERVER['PHP_AUTH_PW'])
		) {
			return $auth [$_SERVER['PHP_AUTH_USER']]['name'] . ' <'.$auth [$_SERVER['PHP_AUTH_USER']]['email'].'>';
		}

		header('WWW-Authenticate: Basic realm="'.$realm.'"');
		header('HTTP/1.0 401 Unauthorized');

		exit ('You did not supply any or the wrong username/password combination');
	}

	function handle_htpasswd_auth () {
		global $auth;

		if (!isset ($_SERVER['REMOTE_USER']))
			exit ('htpasswd not setup correctly');

		if (isset ($_SERVER['REMOTE_USER']) && isset ($auth [$_SERVER ['REMOTE_USER']]))
			return $auth [$_SERVER ['REMOTE_USER']]['name'] . ' <'.$auth [$_SERVER ['REMOTE_USER']]['email'].'>';

		exit ('htpasswd user unknown to git-webcommit');
	}

///////////////////////////////

	function html_js_remove_container ($prefix) {
return <<<HERE
		<script>( function () {
			var el = document.getElementById ('${prefix}_div');
			if (el)
				el.parentNode.removeChild (el);
		}) ();</script>
HERE;
	}

	function html_header_message ($str = '') {
		return <<<HERE
		<p id="headermessage">$str</p>
HERE;
	}

	function html_header_message_update ($str = '', $no_encode = false) {
		if ($no_encode === false)
			$str = htmlentities ($str);
		return <<<HERE
		<script>
			(function () {
				var el = document.getElementById ('headermessage');
				el.innerHTML = '$str';
			}) ();
		</script>
HERE;
	}

	function html_form_start () {
		global $title;

		$strtitle = '';

		if (isset ($title) && $title != '')
			$strtitle = $title . ' ';

return <<<HERE
		<form method="POST">
		<h1>${strtitle}version control system</h1>
		<div class="filename_div filelist_header"><span class="checkbox_span">&nbsp;</span><span class="staged_span">Staged</span><span class="state_span">State</span><span class="filename_span">Filename</span></div>
		<article>
HERE;
	}

	function close_and_add_filelist_parent () {
		return <<<HERE
</article><article>
HERE;
	}

	function html_form_end ($something_to_commit = false, $hash = '') {
		global $commit_message;

		$something_to_commit = $something_to_commit ? 'true' : 'false'; 

		if (!isset ($commit_message))
			$commit_message = '';
		else
			$commit_message = htmlentities ($commit_message);

return <<<HERE
		</article>
		<input id="pull" type="submit" name="pull" value="pull">
		<input id="push" type="submit" name="push" value="push">
		<input id="change_staged" type="submit" name="change_staged" value="change staged">
		<input id="submit_commit" type="submit" name="commit" value="commit">
		<input id="submit_refresh" type="submit" name="refresh" value="refresh">
		<input type="hidden" name="statushash" value="$hash">
		<textarea id="commit_message" name="commit_message">$commit_message</textarea>
		</form>
		<script>something_to_commit = $something_to_commit; enable_disable_buttons (); handle_commit_textarea ();</script>
		
HERE;
	}

	function html_header () {
		global $title;

		$strtitle = '';

		if ($title != '')
			$strtitle = ': '.$title;

		$css = html_css ();

		$js = html_js ();

return <<<HERE
<!DOCTYPE HTML>

<html>
	<head><title>git webcommit$strtitle</title><style>$css</style><script>$js</script></head>
	<body>
HERE;
	}

	function html_footer () {
return <<<HERE
</body></html>
HERE;
	}

	function html_css () {

	$padding = '10px';
	$margin = '6px';
	$totalwidth = '1000px';

return <<<HERE
	PRE { color: purple; } /* debug */
	BODY { background-color: #fff; font-family: arial; font-size: 13px; text-align: center; margin: 0; pading: 0; }
	FORM { width: $totalwidth; margin: 0 auto; text-align: left; }
	TEXTAREA { display: none; width: 100%; height: 300px; border: 1px solid black; font-size: 11px; }
	ARTICLE DIV:nth-child(even) { background-color: #efefef }
	.filename_div { cursor: pointer; border: 1px solid black; width: $totalwidth; padding-top: 3px; padding-bottom: 3px; margin-top: $margin; margin-bottom: $margin; overflow: hidden; background-color: inherit}
	.state_span, .staged_span, .checkbox_span { float: left; padding-left: $padding; padding-right: $padding; }
	.staged_span { width: 60px; }
	.state_span { width: 100px; }
	.checkbox_span { width: 14px; }
	.filelist_header { font-weight: bold; cursor: default; }
	.checkbox { border: 1px solid black; }
	#commit_message { margin-top: $margin; }
	.error { font-weight: bold; color: red; }
	H1 { font-size: 16px; margin: 0; padding: 0; }
HERE;
	}

	function html_js () {
		return <<<HERE

var staged_checked_changed = 0;
var something_to_commit = false;

function enable_disable_buttons () {
	var button1 = document.getElementById ('change_staged');
	var button2 = document.getElementById ('submit_commit');
	var msg = document.getElementById ('commit_message');

	if (staged_checked_changed > 0) {
		if (button1)
			button1.disabled = false;
		if (button2)
			button2.disabled = true;
		if (msg)
			msg.style.display = 'none';
	} else {
		if (button1)
			button1.disabled = true;

		if (something_to_commit) {
			if (msg) {
				msg.style.display = 'block';
				if (msg.value != '' && button2)
					button2.disabled = false;
				else
					button2.disabled = true;
			} else
				if (button2)
					button2.disabled = true;
		} else {
			if (button2)
				button2.disabled = true;

			if (msg)
				msg.style.display = 'none';
		}
	}
}

function handle_commit_textarea () {
	var msg = document.getElementById ('commit_message');
	var button2 = document.getElementById ('submit_commit');
	if (msg && button2) {
		var handler = function () {
			if (something_to_commit && staged_checked_changed == 0 && msg.value.length > 0) {
				if (button2)
					button2.disabled = false;
			} else {
				if (button2)
					button2.disabled = true;
			}
		}

		msg.addEventListener ('keydown', handler);
		msg.addEventListener ('keyup', handler);
		msg.addEventListener ('change', handler);
	}
}

function handle_fileline (prefix, check, disable) {
	if (prefix == '')
		return false;

	var el = document.getElementById (prefix+'_checkbox');
	if (el) {
		el.checked = check;
		el.addEventListener ('click', function (e) {
			var el = document.getElementById (prefix+'_checkbox');

			if (el) {
				if (check === el.checked)
					staged_checked_changed--;
				else
					staged_checked_changed++;

				enable_disable_buttons ();
			}
		});
	}

	if (!disable) {
		var el = document.getElementById (prefix+'_div');
		if (el)
			el.addEventListener ('click', function (e) {

			var classname = '';

			if (e.target && e.target.className)
				classname = ' '+e.target.className+' ';

				if (classname.indexOf (' filename_span ') >= 0 || classname.indexOf (' filename_div ') >= 0 || classname.indexOf (' staged_span ') >= 0 || classname.indexOf (' state_span ') >= 0) {
					var el2 = document.getElementById (prefix+'_textarea');
					if (el2) {
						if (el2.style.display == 'block')
							el2.style.display = 'none'
						else
							el2.style.display = 'block';
					}
				}
			});
	}
}

HERE;
	}

	function html_file_js ($prefix = '', $check = false, $disable = false) {
		$check = $check ? 'true' : 'false';
		$disable = $disable ? 'true' : 'false';

		return <<<HERE
handle_fileline ('$prefix', $check, $disable);
HERE;
	}

	function html_file ($filename, $state, $staged, $hash, $diff, $disabled = false) {
		global $somethingstaged;

		static $ts;

		if (!isset ($ts))
			$ts = microtime (true);
		else
			$ts++;

		$basename = basename ($filename);
		$prefix = $basename . '_' . $ts;

		$checked = false;

		if ($staged == 'Y') {
			$checked = true;
			$somethingstaged = true;
		}

		$js = html_file_js ($prefix, $checked, $disabled);
		if ($checked)
			$checked = 'checked ';

		if ($diff === false)
			$diff = '';

		if ($disabled === true)
			$disabled = 'disabled ';
		else
			$disabled = '';

$str = <<<HERE
<div title="$filename" id="${prefix}_div" class="filename_div"><span class="checkbox_span" id="${prefix}_checkbox_span"><input class="checkbox" ${checked}type="checkbox" id="${prefix}_checkbox" ${disabled} name="stagecheckbox[]" value="$hash"></span><span class="staged_span">$staged</span><span class="state_span">$state</span><span class="filename_span">$filename</span>
<input id="${prefix}_filename" type="hidden" name="filename[]" value="$filename">
<input id="${prefix}_hash" type="hidden" name="hash[]" value="$hash">
<input id="${prefix}_prefix" type="hidden" name="prefix[]" value="$prefix">
<textarea id="${prefix}_textarea">$diff</textarea>
<script>$js</script></div>
HERE;

		return Array ($str, $prefix);
	}

/*

notes:
_______________

per file stats:

added removed

$ git diff --cached --numstat -- kjdfkjshda.txt
0       2       kjdfkjshda.txt

$ git diff --cached --numstat readme.txt
1       3       readme.txt

$ git diff --numstat test.txt
3       0       test.txt

$ wc -l asdfasdf.txt 
1 asdfasdf.txt

*/
