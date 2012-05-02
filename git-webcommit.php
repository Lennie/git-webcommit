<pre><?php
	$repos = Array ('/some/dir');

	$dir = $repos [0];

	chdir ($dir);

	$_handles = Array ();
	$_handlecount = 0;

	function start_command ($command, $argarr, $blocking = true) {
		$descriptorspec = array(
			0 => array("pipe", "r"),  // stdin
			1 => array("pipe", "w"),  // stdout
			2 => array("pipe", "r"),  // stderr
		);

		$cwd = '/some/dir';
		$env = array('some_option' => 'aeiou');
		$args = '';

		foreach ($argarr as $v) {
			$args .= ' '. escapeshellarg ($v);
		}

		$command = escapeshellcmd ($command);

		$proc = proc_open($command . ' ' . $args, $descriptorspec, $pipes, $cwd, $env);

		if (is_resource($proc)) {
			if ($blocking === false) {
				stream_set_blocking ($pipes [0], 0);
				stream_set_blocking ($pipes [1], 0);
				stream_set_blocking ($pipes [2], 0);
			}

			global $_handles, $_handlecount;

			$h = ++$_handlecount;

//		echo "start_command:\n";
//		var_dump ($h);

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
		@fclose ($arr[0]);
		@fclose ($arr[1]);
		@fclose ($arr[2]);

//		echo "end_command:\n";
//		var_dump ($h);
//		var_dump ($_handles [$h]);

		if ($_handles [$h]['running'] !== false) {

//				$status = proc_get_status ($_handles[$h] ['proc']);
//print_r ($status);
//		if ($status ['running'] !== false) {
			$rv2 = proc_close ($_handles [$h] ['proc']);
//				$status = proc_get_status ($_handles[$h] ['proc']);
//print_r ($status);
//			if ($rv === false)
				$rv = $rv2;
		} else
//			$rv = $_handles [$h]['proc']['exitcode'];
			$rv = $_handles [$h]['rv'];
/*
if ($rv !== 0) {
	echo "<b>not 0 ?</b>\n";
	var_dump ($rv);
	var_dump ($rv2);
	print_r ($_handles [$h]);
}
*/
//var_dump ($rv);

		$_handles [$h]['running'] = false;

//		echo "was still running !!\n";
		return $rv;
	}

	function is_done ($h) {
		global $_handles;

		if (!isset ($_handles [$h]))
			return true; // closest thing to an error

		if ($_handles [$h]['done'] === true)
			return true;

		return false;
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

		/* Prepare the read array */
		$read   = array($_handles[$h][1], $_handles[$h][2]);
		$write  = NULL;
		$except = NULL;
		$wait = 120;

		$rv = Array ('', '');

		if (false === ($num_changed_streams = stream_select($read, $write, $except, $wait))) {
			/* Error handling */
			echo "error occured\n";

			$_handles [$h]['done'] = true; // ?

			return false;
		} elseif ($num_changed_streams > 0) {
			/* At least on one of the streams something interesting happened */
			$alleof = true;

			$newout = fgets ($_handles[$h][1], 8192);
			$newerr = fgets ($_handles[$h][2], 8192);

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

//		echo "_get_data:\n";
//		var_dump ($h);

//print_r ($status);

				if ($status ['running'] === false) {
					$return_value = $status ['exitcode'];
				} else {
					$return_value = end_command ($h);
				}
//var_dump ($return_value);

				$_handles [$h]['rv'] = $return_value;
				$_handles [$h]['running'] = false;
			}
		} else
			echo "hier";

		return $rv;
	}

	function get_all_data ($h, $type = 'stdout') {
		global $_handles;

		if (!isset ($_handles [$h]))
			return false;

		if ($type == 'stdout')
			$rv = stream_get_contents ($_handles[$h][1]); // if $rv == false we return false at the end

		$status = proc_get_status ($_handles[$h]['proc']);

//echo "get_all_data\n";

print_r ($status);

		if ($status ['running'] === false)
			$return_value = $status ['exitcode'];
		else
			$return_value = end_command ($h);

		$_handles [$h]['rv'] = $return_value;
		$_handles [$h]['running'] = false;

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

		if ($_handles [$h]['done'] !== true)
			end_command ($h);

		unset ($_handles [$h]);
	}

	function error ($str = '') {
		echo 'ERROR: '.$str;
		return false;
	}

	function get_status () {
		$result = Array ();

		clearstatcache ();

		$h = start_command ('git', Array ('status', '--porcelain'), false);
		if ($h === false)
			return error ('command failed to start');
		else {
			$err = '';
			$out = '';

			while (!is_done ($h)) {
				$line = get_stdout_line ($h);
				if ($line != '') {
//					var_dump ($line);
					$parsed = parse_line ($line);
					interpret ($parsed);
				}
			}

			$exit = get_exit_code ($h);
			if ($exit !== 0)
				return error ('command failed, it returned exitcode: '.$exit);

			
		}

		return $result;
	}

	function get_file_hash ($file) {
		if (file_exists ($file))
			return sha1_file ($file);

		return false;
	}

	function parse_line ($str) {
		$str = rtrim ($str);
		$file = substr ($str, 3);

		if (file_exists ($file) ) {
			$res = Array ('staged' => $str [0], 'modified' => $str [1]);
			$type = filetype ($file);
			if ($type === 'file') {
				$res ['hash'] = get_file_hash ($file);
				$res ['file'] = $file;
			} elseif ($type == 'dir') {
				$res ['dir'] = $file;
			} else
				$res = Array ();

			return $res;
		}

		return Array ();
	}

	function interpret ($parsed) {
//echo "\nnewout: ";
//var_dump ($newout);

		if (isset ($parsed ['file'])) {
			print_r ($parsed);
			if ($parsed ['staged'] == '?') {
				echo "new file !\n";
				$h = start_command ('diff', Array ('-u', '/dev/null', $parsed ['file']));
				echo get_all_data ($h);
				clean_up ($h);
			} elseif ($parsed ['modified'] == 'M') {
				$h = start_command ('git', Array ('diff', $parsed ['file']));
				echo get_all_data ($h);
				clean_up ($h);
			} elseif ($parsed ['modified'] == 'D?') {
				print_r ($parsed);
			} else {
				echo "euh... not new file, not modified, not deleted: ".$parsed ['file']."\n";
				print_r ($parsed);
			}
		} elseif (isset ($parsed ['dir']))
			print_r ($parsed);
		else {
			print_r ($parsed);

			echo "euh... not file nor dir: ".$parsed ['file']."\n";
		}
	}

	var_dump (get_status ());
?>
