<?php

	/// settings ///

	$debug = false;
//	$debug = true;

	$enable_stats = false; // not available yet

	$repos = Array ('/some/dir');

	$defaultrepo = 0;

	$auth = Array (); // not available yet

	/// main ///

	ob_end_clean ();
	flush ();

	$dir = $repos [$defaultrepo];

	chdir ($dir);

	$_handles = Array ();
	$_handlecount = 0;

	$md5_empty_string = 'd41d8cd98f00b204e9800998ecf8427e';
	$sha1_empty_string = 'da39a3ee5e6b4b0d3255bfef95601890afd80709';

	$somethingstaged = false;

	echo html_header ();

	if ($_SERVER ['REQUEST_METHOD'] == 'POST') {
		$commit_message = $_POST ['commit_message'];

		if ($_POST ['change_staged'])
			handle_change_staged ();
		elseif ($_POST ['commit'])
			handle_commit ();
		else {
			error ('POST failure');
			exit ();
		}
	} else
		view_result ();

	/// functions ///

	function view_result ($status = '') {
		global $somethingstaged, $enable_stats;

		echo html_form_start ();

		if ($status == '') {
			$status = get_status ($enable_stats);
			debug ($status);
		}

		if ($status ['disable_commit'] !== true)
			$something_to_commit = $somethingstaged;

		echo html_form_end ($something_to_commit, $status ['hash']);

		echo html_footer ();
	}

	function handle_change_staged () {
		global $enable_stats;

		$status = get_status ($enable_stats);

		view_result ($status);
	}

	function handle_commit () {
		global $enable_stats;

		$status = get_status ($enable_stats);

		view_result ($status);
	}

	function html_form_start () {
return <<<HERE
		<form method="POST">
		<div class="filename_div filelist_header"><span class="checkbox_span">&nbsp;</span><span class="staged_span">Staged</span><span class="state_span">State</span><span class="filename_span">Filename</span></div>
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
		<input id="change_staged" type="submit" name="change_staged" value="change staged">
		<input id="submit_commit" type="submit" name="commit" value="commit">
		<input type="hidden" name="hash" value="$hash">
		<textarea id="commit_message" name="commit_message">$commit_message</textarea>
		</form>
		<script>something_to_commit = $something_to_commit; enable_disable_buttons (); handle_commit_textarea ();</script>
HERE;
	}

	function html_header ($title = '') {	
		if ($title != '')
			$title = ': '.$title;

		$css = html_css ();

		$js = html_js ();

return <<<HERE
<!DOCTYPE HTML>

<html>
	<head><title>git webcommit$title</title><style>$css</style><script>$js</script></head>
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
	$totalwidth = '800px';

return <<<HERE
	PRE { color: purple; } /* debug */
	BODY { background-color: white; }
	TEXTAREA { display: none; width: $totalwidth; height: 300px; border: 1px solid black; }
	.filename_div { border: 1px solid black; width: $totalwidth; padding-top: 3px; padding-bottom: 3px; margin-top: $margin; margin-bottom: $margin; }
	.state_span, .staged_span, .checkbox_span { float: left; padding-left: $padding; padding-right: $padding; }
	.staged_span { width: 60px; }
	.state_span { width: 100px; }
	.checkbox_span { width: 14px; }
	.filelist_header { font-weight: bold; }
	.checkbox { border: 1px solid black; }
	#commit_message { margin-top: $margin; }
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
				msg.disabled = false;
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

			if (msg) {
				msg.style.display = 'none';
				msg.disabled = true;
			}
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

function handle_fileline (prefix, check) {
	if (prefix == '')
		return false;

	var el = document.getElementById (prefix+'checkbox');
	if (el) {
		el.checked = check;
		el.addEventListener ('click', function (e) {
			if (el) {
				if (check === el.checked)
					staged_checked_changed--;
				else
					staged_checked_changed++;

				enable_disable_buttons ();

				e.stopPropagation ();
			}
		});
	}

	var el = document.getElementById (prefix+'div');
	if (el)
		el.addEventListener ('click', function () {
			var el2 = document.getElementById (prefix+'textarea');
			console.log (el.id);
			if (el2) {
				if (el2.style.display == 'block')
					el2.style.display = 'none'
				else
					el2.style.display = 'block';
			}
		});
}

HERE;
	}

	function html_file_js ($prefix = '', $check = false) {
		$check = $check ? 'true' : 'false';

		return <<<HERE
handle_fileline ('$prefix', $check);
HERE;
	}

	function html_file ($filename, $state, $staged, $hash, $diff) {
		global $somethingstaged;

		$basename = basename ($filename);
		static $ts;

		if (!isset ($ts))
			$ts = mktime ();
		else
			$ts++;

		$prefix = $basename . '_' . $ts . '_';

		$checked = false;

		if ($state == '?' || $staged == 'A')
			$state = 'New';

		if ($state == 'D' || $staged == 'D')
			$state = 'Deleted';

		if ($state == 'M' || $staged == 'M')
			$state = 'Modified';

		if ($staged == ' ' || $staged == '?')
			$staged = 'N';

		if ($staged == ' ')
			$staged = 'N';

		if ($staged == '?')
			$staged = 'N';

		if ($staged == 'Y' || $staged == 'M' || $staged == 'A' || $staged == 'D') {
			$staged = 'Y';
			$checked = true;
			$somethingstaged = true;
		}

		$js = html_file_js ($prefix, $checked);
		if ($checked)
			$checked = 'checked ';

return <<<HERE
<div id="${prefix}div" class="filename_div"><span class="checkbox_span" id="${prefix}checkbox_span"><input class="checkbox" ${checked}type="checkbox" id="${prefix}checkbox"></span><span class="staged_span">$staged</span><span class="state_span">$state</span><span class="filename_span">$filename</span></div>
<input id="${prefix}filename" type="hidden" name="filename" value="$filename">
<input id="${prefix}hash" type="hidden" name="hash" value="$hash">
<textarea id="${prefix}textarea">$diff</textarea>
<script>$js</script>
HERE;
	}

	function start_command ($command, $argarr, $blocking = true) {
		$descriptorspec = array(
			0 => array("pipe", "r"),  // stdin
			1 => array("pipe", "w"),  // stdout
			2 => array("pipe", "r"),  // stderr
		);

		$args = '';

		foreach ($argarr as $v) {
			$args .= ' '. escapeshellarg ($v);
		}

		$command = escapeshellcmd ($command);

		$proc = proc_open($command . ' ' . $args, $descriptorspec, $pipes);

		if (is_resource($proc)) {
			if ($blocking === false) {
				stream_set_blocking ($pipes [0], 0);
				stream_set_blocking ($pipes [1], 0);
				stream_set_blocking ($pipes [2], 0);
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
		@fclose ($arr[0]);
		@fclose ($arr[1]);
		@fclose ($arr[2]);

		if ($_handles [$h]['running'] !== false)
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

			$_handles [$h]['done'] = true; // ?

			return error ("error occured");
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

	function get_all_data ($h, $type = 'stdout') {
		global $_handles;

		if (!isset ($_handles [$h]))
			return false;

		if ($_handles [$h]['done'])
			return $_handles [$h]['stdout'];

		if ($type == 'stdout')
			$rv = stream_get_contents ($_handles[$h][1]); // if $rv == false we return false at the end

		$status = proc_get_status ($_handles[$h]['proc']);

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
		echo 'ERROR: '.$str . "\n";
		return false;
	}

	function debug ($input = '') {
		global $debug;

		if ($debug === false)
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

	function make_one_hash ($rs) {
		$str = '';
	
		foreach ($rs ['lines'] as $v)
			$str .= $v ['hash'];

		$str .= $rs ['outputhash'];
		
		$rs ['hash'] = sha1 ($str);
	}

	function get_status ($stats = false) {
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
//debug ('<hr>');

					$parsed = parse_line ($line);
					$int = interpret ($parsed, $stats);
					if ($int !== false) {
						$result ['lines'][] = $int;
					}

					flush ();
				}
			}

			$exit = get_exit_code ($h);
			if ($exit !== 0)
				return error ('command failed, it returned exitcode: '.$exit);

			$result ['output'] = get_all_data ($h);

			$result ['outputhash'] = sha1 ($result['str']);

			$result ['disable_commit'] = false;

			make_one_hash (&$result);
		}

//debug ('<hr>');

//debug ($GLOBALS ['_handles'][$h]['stdout']);

clean_up ($h);

//debug ('<hr>');
		return $result;
	}

	function get_file_hash ($file) {
		if (file_exists ($file))
			return sha1_file ($file);

		return false;
	}

	function parse_line ($str, $stats = false) {
		global $sha1_empty_string;
	
		$str = rtrim ($str);
		$file = substr ($str, 3);

		if (file_exists ($file) ) {
			$res = Array ('staged' => $str [0], 'modified' => $str [1], 'str' => $str);
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
			$res = Array ('staged' => ' ', 'modified' => 'D', 'str' => $str, 'file' => $file, 'hash' => $sha1_empty_string);
		} elseif ($str [0] == 'D' && $str [1] == ' ') {
			$res = Array ('staged' => 'D', 'modified' => ' ', 'str' => $str, 'file' => $file, 'hash' => $sha1_empty_string);
		} else {
			$res = Array ('str' => $str);
		}

		return $res;
	}

	function interpret ($parsed) {
		if (isset ($parsed ['file'])) {
//			print_r ($parsed);
			if ($parsed ['staged'] == '?' || $parsed ['staged'] == 'A') {
//				debug ("new file !");
				$command = 'diff';
				$args = Array ('-u', '/dev/null', $parsed ['file']);
				if ($parsed ['staged'] == 'A') {
					$args = Array ('diff', '--cached', $parsed ['file']);
					$command = 'git';
				}
				$h = start_command ($command, $args);
				$diff = htmlentities (get_all_data ($h));
//				debug ($diff);
				echo html_file ($parsed ['file'], $parsed ['modified'], $parsed ['staged'], $parsed ['hash'], $diff);
//var_dump (get_exit_code ($h));
				clean_up ($h);
			} elseif (( $parsed ['modified'] == 'M') || ($parsed ['staged'] == 'M' && $parsed ['modified'] == ' ')) {
				$args = Array ('diff', $parsed ['file']);

				if ($parsed ['staged'] == 'M' && $parsed ['modified'] == ' ')
					$args = Array ('diff', '--cached', $parsed ['file']);

				$h = start_command ('git', $args);
				$str = get_all_data ($h);
//				debug ($str);
				$diff = htmlentities ($str);
				$exit = get_exit_code ($h);
//				var_dump ($exit);
//				debug ($diff);
				echo html_file ($parsed ['file'], $parsed ['modified'], $parsed ['staged'], $parsed ['hash'], $diff);
//var_dump (get_exit_code ($h));
				clean_up ($h);
			} elseif ($parsed ['modified'] == 'D') {
//				debug ($parsed);
				$args = Array ('diff', '--', $parsed ['file']);
				$h = start_command ('git', $args);
				$str = get_all_data ($h);
//				debug ($str);
				$diff = htmlentities ($str);
				$exit = get_exit_code ($h);
//				var_dump ($exit);
//				debug ($diff);
				echo html_file ($parsed ['file'], $parsed ['modified'], $parsed ['staged'], $parsed ['hash'], $diff);
				clean_up ($h);
			} elseif ($parsed ['staged'] == 'D') {
//				debug ($parsed);
				$args = Array ('diff', '--cached', '--', $parsed ['file']);
				$h = start_command ('git', $args);
				$str = get_all_data ($h);
//				debug ($str);
				$diff = htmlentities ($str);
				$exit = get_exit_code ($h);
//				var_dump ($exit);
//				debug ($diff);
				echo html_file ($parsed ['file'], $parsed ['modified'], $parsed ['staged'], $parsed ['hash'], $diff);
				clean_up ($h);
			} else {
				debug ("euh... not new file, not modified, not deleted: ".$parsed ['file']);
				debug ($parsed);
			}
		} elseif (isset ($parsed ['dir']))
			debug ($parsed); // list of filenames ?
		else {
			debug ($parsed);

			debug ("euh... not file nor dir: ".$parsed ['file']);
		}
		return $parsed;
	}

/*

	hash1 = hash (status output)
	hash2 = hash (file1)
	hash3 = hash (file2)

	hash (hash1,hash2,hash3) = result ?

*/

/*

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

?>
