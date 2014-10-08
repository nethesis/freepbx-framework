<?php
// vim: set ai ts=4 sw=4 ft=php:
/**
 * This is the FreePBX Big Module Object.
 *
 * GPG Class for FreePBX's BMO.
 *
 * This is an interface to GPG, for validating FreePBX Modules.
 * It uses the GPG Web-of-trust to ensure modules are valid
 * and haven't been tampered with.
 *
 * License for all code of this FreePBX module can be found in the license file inside the module directory
 * Copyright 2006-2014 Schmooze Com Inc.
 */
class GPG {

	// Statuses:
	// Valid signature.
	const STATE_GOOD = 1;
	// File has been tampered
	const STATE_TAMPERED = 2;
	// File is signed, but, not by a valid signature
	const STATE_INVALID = 4;
	// File is unsigned.
	const STATE_UNSIGNED = 8;
	// This is in an unsupported state
	const STATE_UNSUPPORTED = 16;
	// Signature has expired
	const STATE_EXPIRED = 32;
	// Signature has been explicitly revoked
	const STATE_REVOKED = 64;
	// Signature is Trusted by GPG
	const STATE_TRUSTED = 128;

	// This is the FreePBX Master Key.
	private $freepbxkey = '2016349F5BC6F49340FCCAF99F9169F4B33B4659';

	// Our path to GPG.
	private $gpg = "/usr/bin/gpg";
	// Default options.
	private $gpgopts = "--no-permission-warning --keyserver-options auto-key-retrieve=true";

	// This is how long we should wait for GPG to run a command.
	// This may need to be tuned on things like the pi.
	public $timeout = 3;


	/**
	 * Validate a file using WoT
	 * @param string $file Filename (explicit or relative path)
	 * @return bool returns true or false
	 */
	public function verifyFile($filename, $retry = true) {
		if (!file_exists($filename)) {
			throw new Exception(sprintf(_("Unable to open file %s"),$filename));
		}

		$out = $this->runGPG("--verify $filename");
		if (strpos($out['status'][1], "[GNUPG:] NO_PUBKEY") === 0) {
			// This should never happen, as we try to auto-download
			// the keys. However, if the keyserver timed out, or,
			// was out of date, we'll try it manually.
			//
			// strlen("[GNUPG:] NO_PUBKEY ") == 19.
			//
			if ($retry && $this->getKey(substr($out['status'][1], 19))) {
				return $this->verifyFile($filename, false);
			} else {
				return false;
			}
		}

		// Now, how does it check out?
		$status = $this->checkStatus($out['status']);
		if ($status['trust']) {
			// It's trusted!  For the interim, we want to make sure that it's signed
			// by the FreePBX Key, or, by a key that's been signed by the FreePBX Key.
			// This is going above-and-beyond the web of trust thing, and we may end up
			// removing it.
			$validline = explode(" ", array_pop($out['status']));
			$thissig = $validline[2];
			$longkey = substr($this->freepbxkey, -16);
			$allsigs = $this->runGPG("--keyid-format long --with-colons --check-sigs $thissig");
			$isvalid = false;
			foreach (explode("\n", $allsigs['stdout']) as $line) {
				$tmparr = explode(":", $line);
				if ($tmparr[4] == $longkey) {
					$isvalid = true;
				}
			}

			return $isvalid;
		} // else
		return false;
	}

	/**
	 * Check the module.sig file against the contents of the
	 * directory
	 *
	 * @param string Module name
	 * @return array (status => GPG::STATE_whatever, details => array (details, details))
	 */
	public function verifyModule($modulename = null) {
		if (!$modulename) {
			throw new Exception(_("No module to check"));
		}

		if (strpos($modulename, "/") !== false) {
			throw new Exception(_("Path given to verifyModule. Only provide a module name"));
		}

		// Get the module.sig file.
		$file = FreePBX::Config()->get('AMPWEBROOT')."/admin/modules/$modulename/module.sig";

		if (!file_exists($file)) {
			// Well. That was easy.
			return array("status" => GPG::STATE_UNSIGNED, "details" => array(_("unsigned")));
		}

		// Check the signature on the module.sig
		$module = $this->checkSig($file);
		if (isset($module['status'])) {
			return array("status" => $module['status'], "details" => array(_("module.sig verification failed")));
		}

		// OK, signature is valid. Let's look at the files we know
		// about, and make sure they haven't been touched.
		$retarr['status'] = GPG::STATE_GOOD | GPG::STATE_TRUSTED;
		$retarr['details'] = array();

		foreach ($module['hashes'] as $file => $hash) {
			$dest = FreePBX::Installer()->getDestination($modulename, $file);
			if ($dest === false) {
				// If the file is explicitly un-checkable, ignore it.
				continue;
			}
			if (!file_exists($dest)) {
				$retarr['details'][] = $dest." missing";
				$retarr['status'] |= GPG::STATE_TAMPERED;
				$retarr['status'] &= ~GPG::STATE_GOOD;
			} elseif (hash_file('sha256', $dest) != $hash) {
				$retarr['details'][] = $dest." altered";
				$retarr['status'] |= GPG::STATE_TAMPERED;
				$retarr['status'] &= ~GPG::STATE_GOOD;
			}
		}
		return $retarr;
	}

	/**
	 * getKey function to download and install a specified key
	 *
	 * If no key is provided, install the FreePBX key.
	 * Throws an exception if unable to find the key requested
	 * @param string $key The key to get?
	 */
	public function getKey($key = null) {
		// Check our permissions
		$this->checkPermissions();

		// If we weren't given one, then load the FreePBX Key
		$key = !empty($key) ? $key : $this->freepbxkey;

		// Lets make sure we don't already have that key.
		$out = $this->runGPG("--list-keys $key");

		if ($out['exitcode'] == 0) {
			// We already have this key
			return true;
		}

		// List of well-known keyservers.
		$keyservers = array("pool.sks-keyservers.net",  // This should almost always work
			"hkp://keyserver.ubuntu.com:80",  // This is in case port 11371 is blocked outbound
			"pgp.mit.edu", "keyserver.pgp.com",  // Other random keyservers
			"pool.sks-keyservers.net"); // Yes. sks is there twice.

		if (strlen($key) > 16) {
			$key = substr($key, -16);
		}

		if (!ctype_xdigit($key)) {
			throw new Exception(sprintf(_("Key provided - %s - is not hex"),$key));
		}

		foreach ($keyservers as $ks) {
			try {
				$retarr = $this->runGPG("--keyserver $ks --recv-keys $key");
			} catch (RuntimeException $e) {
				// Took too long. We'll just try the next one.
				continue;
			}

			if ($retarr['status'][0] == "[GNUPG:] NODATA 1") {
				// not found on this keyserver. Try the next!
				continue;
			}
			// We found it. And loaded it. Yay!
			return true;
		}

		// Do we have this key in a local file?
		$longkey = __DIR__."/${key}.key";
		if (file_exists($longkey)) {
			$out = $this->runGPG("--import $longkey");
			return true;
		}

		// Maybe a shorter version of it?
		$shortkey = __DIR__."/".substr($key, -8).".key";
		if (file_exists($shortkey)) {
			$out = $this->runGPG("--import $shortkey");
			return true;
		}

		// We weren't able to find it.
		throw new Exception(sprintf(_("Unable to download GPG key %s, or find %s or %s"), $key, $longkey, $shortkey));
	}

	/**
	 * trustFreePBX function
	 *
	 * Specifically marks the FreePBX Key as ultimately trusted
	 */
	public function trustFreePBX() {
		// Grab the FreePBX Key, if we don't have it already
		$this->getKey();
		// Ensure the FreePBX Key is trusted.
		$out = $this->runGPG("--export-ownertrust");
		$stdout = explode("\n", $out['stdout']);
		array_pop($stdout); // Remove trailing blank line.
		if (isset($stdout[0]) && strpos($stdout[0], "# List of assigned trustvalues") !== 0) {
			throw new Exception(sprintf(_("gpg --export-ownertrust didn't return sane stuff - %s"), json_encode($out)));
		}

		$trusted = false;
		foreach ($stdout as $line) {
			if (!$line || $line[0] == "#") {
				continue;
			}

			// We now have a trust line that looks like "2016349F5BC6F49340FCCAF99F9169F4B33B4659:6:"
			$trust = explode(':', $line);
			if ($trust[0] === $this->freepbxkey) {
				$trusted = true;
			}
		}

		if (!$trusted) {
			// We need to trust the FreePBX Key
			$stdout[] = $this->freepbxkey.":6:";
			$stdout[] = "# Trailing comment";
			// Create our temporary file.
			$fd = fopen("php://temp", "r+");
			fwrite($fd, join("\n", $stdout));
			fseek($fd, 0);
			$out = $this->runGPG("--import-ownertrust", $fd);
			if ($out['exitcode'] != 0) {
				throw new Exception(sprintf_("Unable to trust the FreePBX Key! -- %s"),json_encode($out));
			}
			fclose($fd);
		}

		// Ensure no permissions have been changed
		$this->checkPermissions();
		return true;
	}

	/**
	 * Strips signature from .gpg file
	 *
	 * This saves the file, minus the .gpg extension, to the same directory
	 * the .gpg file is in. It returns the filename of the output file if
	 * valid, throws an exception if unable to validate
	 * @param string $filename The filename to check
	 */
	public function getFile($filename) {
		// Trust that we have the key?

		if (substr($filename, -4) == ".gpg") {
			$output = substr($filename, 0, -4);
		} else {
			throw new Exception(_("I can only do .gpg files at the moment"));
		}

		$out = $this->runGPG("--batch --yes --out $output --decrypt $filename");
		if ($out['exitcode'] == 0) {
			return $output;
		}
		throw new Exception(sprintf_("Unable to strip signature - result was: %s"),json_encode($out));
	}

	/**
	 * Actually run GPG
	 * @param string Params to pass to gpg
	 * @param fd File Descriptor to feed to stdin of gpg
	 * @return array returns assoc array consisting of (array)status, (string)stdout, (string)stderr and (int)exitcode
	 */
	public function runGPG($params, $stdin = null) {

		$fds = array(
			array("file", "/dev/null", "r"), // stdin
			array("pipe", "w"), // stdout
			array("pipe", "w"), // stderr
			array("pipe", "w"), // Status
		);

		// If we need to send stuff to stdin, then do it!
		if ($stdin) {
			$fds[0] = $stdin;
		}

		$webuser = FreePBX::Freepbx_conf()->get('AMPASTERISKWEBUSER');
		$home = $this->getGpgLocation();

		// We need to ensure that our environment variables are sane.
		// Luckily, we know just the right things to say...
		if (!isset($this->gpgenv)) {
			$this->gpgenv['PATH'] = "/bin:/usr/bin";
			$this->gpgenv['USER'] = $webuser;
			$this->gpgenv['HOME'] = "/tmp";
			$this->gpgenv['SHELL'] = "/bin/bash";
		}

		$homedir = "--homedir $home";

		$cmd = $this->gpg." $homedir ".$this->gpgopts." --status-fd 3 $params";
		$proc = proc_open($cmd, $fds, $pipes, "/tmp", $this->gpgenv);

		if (!is_resource($proc)) { // Unable to start!
			throw new Exception(_("Unable to start GPG"));
		}

		// Wait $timeout seconds for it to finish.
		$tmp = null;
		$r = array($pipes[3]);
		if (!stream_select($r , $tmp, $tmp, $this->timeout)) {
			throw new RuntimeException(sprintf(_("gpg took too long to run the command: %s"),$cmd));
		}
		// We grab stdout and stderr first, as the status fd won't
		// have completed and closed until those FDs are emptied.
		$retarr['stdout'] = stream_get_contents($pipes[1]);
		$retarr['stderr'] = stream_get_contents($pipes[2]);

		$status = explode("\n", stream_get_contents($pipes[3]));
		array_pop($status);  // Remove trailing blank line
		$retarr['status'] = $status;
		$exitcode = proc_close($proc);
		$retarr['exitcode'] = $exitcode;

		return $retarr;
	}

	/**
	 * Return array of all of my private keys
	 */
	public function getMyKeys() {
		$out = $this->runGPG("-K --with-colons");
		$keys = explode("\n", $out['stdout']);
		array_pop($keys);

		$mykeys = array();
		foreach ($keys as $k) {
			$line = explode(":", $k);
			if ($line[0] == "sec") { // This is a key!
				$mykeys[] = $line[4];
			}
		}
		return $mykeys;
	}

	/**
	 * Get list of files in a directory
	 * @param string $dir The directory to get the file list of/from
	 */
	private function getFileList($dir) {
		// When we require PHP5.4, use RecursiveDirectoryIterator.
		// Until then..

		$retarr = array();
		$this->recurseDirectory($dir, $retarr, strlen($dir)+1);
		return $retarr;
	}

	/**
	 * Recursive routine for getFileList
	 * @param string $dir The directory to recurse into
	 * @param array $retarry The returned array
	 * @param string $strip What to strip off of the directory
	 */
	private function recurseDirectory($dir, &$retarr, $strip) {

		$dirarr = scandir($dir);
		foreach ($dirarr as $d) {
			// Always exclude hidden files.
			if ($d[0] == ".") {
				continue;
			}
			$fullpath = "$dir/$d";

			if (is_dir($fullpath)) {
				$this->recurseDirectory($fullpath, $retarr, $strip);
			} else {
				$retarr[] = substr($fullpath, $strip);
			}
		}
	}

	/**
	 * Generate list of hashes to validate
	 * @param string $dir the directory
	 */
	public function getHashes($dir) {
		if (!is_dir($dir)) {
			throw new Exception(sprintf(_("getHashes was given %s which is not a directory!"),$dir));
		}

		$hasharr = array();

		$files = $this->getFileList($dir);
		foreach ($files as $file) {
			$hasharr[$file] = hash_file('sha256', "$dir/$file");
		}

		return $hasharr;
	}

	/**
	 * Check the module.sig file
	 *
	 * If it's valid, return the processed contents of the sig file.
	 * If it's not valid, return false.
	 * @param string $sigfile The signature file we will check against
	 */
	public function checkSig($sigfile) {
		if (!is_file($sigfile)) {
			throw new Exception(sprintf(_("checkSig was given %s, which is not a file"),$sigfile));
		}

		$out = $this->runGPG("--output - $sigfile");

		// Check to see if we don't know about this signature..
		if (isset($out['status'][1]) && preg_match('/ERRSIG (.+) 1 2/', $out['status'][1], $keyarr)) {
			// We don't. Try to grab it.
			$this->getKey($keyarr[1]);
			// And now run the validation again.
			$out = $this->runGPG("--output - $sigfile");
		}

		$status = $this->checkStatus($out['status']);
		if (!$status['trust']) {
			return $status;
		}
		// Silence warnings about '# not a valid comment'.
		// This should be removed after 12beta is finished.
		$modules = @parse_ini_string($out['stdout'], true);
		return $modules;
	}


	/**
	 * Check the return status of GPG to validate
	 * a signature
	 * @param string $status the status to check
	 */
	private function checkStatus($status) {
		if (!is_array($status)) {
			throw new Exception(_("No status was given to checkStatus"));
		}

		$retarr['valid'] = false;
		$retarr['trust'] = false;
		$retarr['trustdetails'] = array();
		$retarr['status'] = 0;

		foreach ($status as $l) {
			if (strpos($l, "[GNUPG:] VALIDSIG") === 0) {
				$retarr['valid'] = true;
				$retarr['status'] |= GPG::STATE_GOOD;
				$tmparr = explode(' ', $l);
				$retarr['signedby'] = $tmparr[2];
				$retarr['timestamp'] = $tmparr[4];
			}
			if (strpos($l, "[GNUPG:] BADSIG") === 0) {
				$retarr['trustdetails'][] = "Bad Signature, Tampered! ($l)";
				$retarr['status'] |= GPG::STATE_TAMPERED;
			}
			if (strpos($l, "[GNUPG:] ERRSIG") === 0) {
				$retarr['trustdetails'][] = "Unknown Signature ($l)";
				$retarr['status'] |= GPG::STATE_INVALID;
			}
			if (strpos($l, "[GNUPG:] REVKEYSIG") === 0) {
				$retarr['trustdetails'][] = "Signed by Revoked Key ($l)";
				$retarr['status'] |= GPG::STATE_REVOKED;
			}
			if (strpos($l, "[GNUPG:] EXPKEYSIG") === 0) {
				$retarr['trustdetails'][] = "Signed by Expired Key ($l)";
				$retarr['status'] |= GPG::STATE_EXPIRED;
			}
			if (strpos($l, "[GNUPG:] TRUST_ULTIMATE") === 0 || strpos($l, "[GNUPG:] TRUST_FULLY") === 0) {
				$retarr['trust'] = true;
				$retarr['status'] |= GPG::STATE_TRUSTED;
			}
		}
		return $retarr;
	}

	public function getGpgLocation() {
		// Re #7429 - Always use the AMPASTERISKWEBUSER homedir for gpg
		$webuser = FreePBX::Freepbx_conf()->get('AMPASTERISKWEBUSER');

		if (!$webuser) {
			throw new Exception(_("I don't know who I should be running GPG as."));
		}

		// We need to ensure that we can actually read the GPG files.
		$web = posix_getpwnam($webuser);
		if (!$web) {
			throw new Exception(sprintf(_("I tried to find out about %s, but the system doesn't think that user exists"),$webuser));
		}
		$home = trim($web['dir']);
		if (!is_dir($home)) {
			// Well, that's handy. It doesn't exist. Let's use ASTSPOOLDIR instead, because
			// that should exist and be writable.
			$home = FreePBX::Freepbx_conf()->get('ASTSPOOLDIR');
			if (!is_dir($home)) {
				// OK, I give up.
				throw new Exception(sprintf(_("Asterisk home dir (%S) doesn't exist, and, ASTSPOOLDIR doesn't exist. Aborting"),$home));
			}
		}

		// If $home doesn't end with /, add it.
		if (substr($home, -1) != "/") {
			$home .= "/";
		}

		// Make sure that home exists
		if (!is_dir($home)) {
			$ret = @mkdir($home);
			if (!$ret) {
				throw new Exception(sprintf(_("Home directory %s doesn't exist, and I can't create it"),$home));
			}
		}

		$dir = $home.".gnupg";

		if (!is_dir($dir)) {
			// That's worrying. Can I make it?
			$ret = @mkdir($dir);
			if (!$ret) {
				throw new Exception(sprintf(_("Directory %s doesn't exist, and I can't make it (getGpgLocation)."),$dir));
			}
		}

		if (is_writable($dir)) {
			return $dir;
		} else {
			throw new Exception(sprintf(_("Don't have permission/can't write to %s"),$dir));
		}
	}

	private function checkPermissions($dir = false) {
		if (!$dir) {
			// No directory specified. Let's use the default.
			$dir = $this->getGpgLocation();
		}

		// If it ends in a slash, remove it, for sanity
		$dir = rtrim($dir, "/");

		if (!is_dir($dir)) {
			// That's worrying. Can I make it?
			$ret = @mkdir($dir);
			if (!$ret) {
				throw new Exception(sprintf(_("Directory %s doesn't exist, and I can't make it. (checkPermissions)"),$dir));
			}
		}

		// Now, who should be running gpg normally?
		$freepbxuser = FreePBX::Freepbx_conf()->get('AMPASTERISKWEBUSER');
		$pwent = posix_getpwnam($freepbxuser);
		$uid = $pwent['uid'];
		$gid = $pwent['gid'];

		// What are the permissions of the GPG home directory?
		$stat = stat($dir);
		if ($uid != $stat['uid'] || $gid != $stat['gid']) {
			// Permissions are wrong on the GPG directory. Hopefully, I'm root, so I can fix them.
			if (!posix_geteuid() === 0) {
				throw new Exception(sprintf(_("Permissions error on %s - please re-run as root to automatically repair"),$home));
			}
			// We're root. Yay.
			chown($dir, $uid);
			chgrp($dir, $gid);
		}

		// Check the permissions of the files inside the .gpg directory
		$allfiles = glob($dir."/*");
		foreach ($allfiles as $file) {
			$stat = stat($file);
			if ($uid != $stat['uid'] || $gid != $stat['gid']) {
				// Permissions are wrong on the file inside the .gnupg directory.
				if (!posix_geteuid() === 0) {
					throw new Exception(sprintf(_("Permissions error on %s - please re-run as root to automatically repair"),$home));
				}
				// We're root. Yay.
				chown($file, $uid);
				chgrp($file, $gid);
			}
		}
	}
}
