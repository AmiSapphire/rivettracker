<?php

header("Content-type: text/plain");
header("Pragma: no-cache");

ignore_user_abort(1);

$GLOBALS["peer_id"] = "";
$summaryupdate = array();

require_once("config.php");
require_once("funcsv2.php");


// Prep database
if ($GLOBALS["persist"])
	$db = @mysql_pconnect($dbhost, $dbuser, $dbpass) or showError("Tracker error: can't connect to database. Contact the webmaster.");
else
	$db = @mysql_connect($dbhost, $dbuser, $dbpass) or showError("Tracker error: can't connect to database. Contact the webmaster.");
@mysql_select_db($database) or showError("Tracker error: can't open database. Contact the webmaster");


if (isset ($_SERVER["PATH_INFO"]) )
{
	// Scrape interface

// Error: no web browsers allowed
	if (!isset($_GET["info_hash"]))
	{
		header("HTTP/1.0 400 Bad Request");
		die("This file is for BitTorrent clients.\n");
	}

// Deny access made with a browser...
$agent = mysql_real_escape_string($_SERVER["HTTP_USER_AGENT"]);

if (preg_match("/^Mozilla|^Opera|^Links|^Lynx/i", $agent))
{
    header("HTTP/1.0 400 Bad Request");
    die("This file is for BitTorrent clients.\n");
}

	if (substr($_SERVER["PATH_INFO"],-7) == '/scrape')
	{
		if ($scrape == true)
		{
			$usehash = false;
			if (isset($_GET["info_hash"]))
			{
				if (get_magic_quotes_gpc())
					$info_hash = stripslashes(trim(strip_tags($_GET["info_hash"])));
				else
					$info_hash = trim(strip_tags($_GET["info_hash"]));
				if (strlen($info_hash) == 20)
					$info_hash = filterChar(bin2hex($info_hash));
				else if (strlen($info_hash) == 40)
					filterInt(verifyHash($info_hash)) or showError("Invalid info hash value.");
				else
					showError("Invalid info hash value.");
				$usehash = true;
			}
			if ($usehash)
				$query = mysql_query("SELECT info_hash, filename FROM ".$prefix."namemap WHERE info_hash='$info_hash'");
			else
				$query = mysql_query("SELECT info_hash, filename FROM ".$prefix."namemap");
			$namemap = array();
			while ($row = mysql_fetch_row($query))
				$namemap[$row[0]] = $row[1];
	
			if ($usehash)
				$query = mysql_query("SELECT info_hash, seeds, leechers, finished FROM ".$prefix."summary WHERE info_hash='$info_hash'") or showError("Database error. Cannot complete request.");
			else
				$query = mysql_query("SELECT info_hash, seeds, leechers, finished FROM ".$prefix."summary ORDER BY info_hash") or showError("Database error. Cannot complete request.");

			echo "d5:filesd";

			while ($row = mysql_fetch_row($query))
			{
				$hash = hex2bin($row[0]);
				echo "20:".$hash."d";
				echo "8:completei".$row[1]."e";
				echo "10:downloadedi".$row[3]."e";
				echo "10:incompletei".$row[2]."e";
				if (isset($namemap[$row[0]]))
					echo "4:name".strlen($namemap[$row[0]]).":".$namemap[$row[0]];
				echo "e";
			}

			echo "ee";
			exit();
		}
		else
			//client tried scraping but scraping has been disabled by the tracker
			showError("Scraping has been disabled by this tracker.");
	}
}


///////////////////////////////////////////////////////////////////
// Handling of parameters from the URL and other setup


// Error: no web browsers allowed
if (!isset($_GET["info_hash"]) || !isset($_GET["peer_id"]))
{
	header("HTTP/1.0 400 Bad Request");
	die("This file is for BitTorrent clients.\n");
}
$agent = mysql_real_escape_string($_SERVER["HTTP_USER_AGENT"]);
// Deny access made with a browser...

if (preg_match("/^Mozilla|^Opera|^Links|^Lynx/i", $agent))
{
    header("HTTP/1.0 400 Bad Request");
    die("This file is for BitTorrent clients.\n");
}


$info_hash = bin2hex(clean($_GET["info_hash"]));
$peer_id = filterChar(bin2hex($_GET["peer_id"]));


if (!isset($_GET["port"]) || !isset($_GET["downloaded"]) || !isset($_GET["uploaded"]) || !isset($_GET["left"])) {
	showError("Invalid information received from BitTorrent client");
}

$port = filterInt($_GET["port"]);
$ip = filterFloat(str_replace("::ffff:", "", $_SERVER["REMOTE_ADDR"]));
$downloaded = filterFloat($_GET["downloaded"]);
$uploaded = filterFloat($_GET["uploaded"]);
$left = filterFloat($_GET["left"]);


if (isset($_GET["event"]))
	$event = filterData($_GET["event"]);
else
	$event = "";

if (!isset($GLOBALS["ip_override"]))
	$GLOBALS["ip_override"] = true;

if (isset($_GET["numwant"]))
	if ($_GET["numwant"] < $GLOBALS["maxpeers"] && $_GET["numwant"] >= 0)
		$GLOBALS["maxpeers"] = filterFloat($_GET["numwant"]);

if (isset($_GET["trackerid"]))
{	
	if (is_numeric($_GET["trackerid"]))
		$GLOBALS["trackerid"] = filterInt($_GET["trackerid"]);
}
if (!is_numeric($port) || !is_numeric($downloaded) || !is_numeric($uploaded) || !is_numeric($left))
	showError("Invalid numerical field(s) from client");



/////////////////////////////////////////////////////
// Any section of code might need to make a new peer, so this is a function here.
// I don't want to put it into funcsv2, even though it should, just for consistency's sake.

function start($info_hash, $ip, $port, $peer_id, $left)
{
	require("config.php"); //need prefix value...
	if (isset($_SERVER["HTTP_X_FORWARDED_FOR"]))
	{
      foreach(explode(",",$_SERVER["HTTP_X_FORWARDED_FOR"]) as $address)
      {
		$addr = ip2long(trim($address));
		if ($addr != -1)
		{
			if ($addr >= -1062731776 && $addr <= -1062666241)
			{
				// 192.168.x.x
			}
			else if ($addr >= -1442971648 && $addr <= -1442906113)
			{
				// 169.254.x.x
			}
			else if ($addr >= 167772160 && $addr <= 184549375)
			{
				// 10.x.x.x
			}
			else if ($addr >= 2130706432 && $addr <= 2147483647)
			{
				// 127.0.0.1
			}
			else if ($addr >= -1408237568 && $addr <= -1407188993)
			{
				// 172.[16-31].x.x
			}
			else
			{
				// Finally, we can accept it as a "real" ip address.
				$ip = mysql_real_escape_string(trim($address));
				break;
			}
		}
	  }
	}

	if (isset($_GET["ip"]) && $GLOBALS["ip_override"])
	{
		// compact check: valid IP address:
		if (ip2long($_GET["ip"]) == -1)
			showError("Invalid IP address. Must be standard dotted decimal (hostnames not allowed)");
		$ip = filterFloat($_GET["ip"]);
	}

	if ($left == 0)
		$status = "seeder";
	else
		$status = "leecher";
	if (@isFireWalled($info_hash, $peer_id, $ip, $port))
		$nat = "'Y'";
	else
		$nat = "'N'";
	
	$results = @mysql_query("INSERT INTO ".$prefix."x$info_hash SET peer_id='$peer_id', port='$port', ip='$ip', lastupdate=UNIX_TIMESTAMP(), bytes='$left', status='$status', natuser=$nat");

	// Special case: duplicated peer_id. 
	if (!$results)
	{
		$error = mysql_error();
		if (stristr($error, "key"))
		{
			// Duplicate peer_id! Check IP address
			$peer = getPeerInfo($peer_id, $info_hash);
			if ($ip == $peer["ip"])
			{
				// Same IP address. Tolerate this error.
				return "WHERE natuser='N'";
			}
			//showError("Duplicated peer_id or changed IP address. Please restart BitTorrent.");
			// Different IP address. Assume they were disconnected, and alter the IP address.
			quickQuery("UPDATE ".$prefix."x$info_hash SET ip='$ip' WHERE peer_id='$peer_id'");
			return "WHERE natuser='N'";
		}
		error_log("RivetTracker: start: ".$error);
		showError("Tracker/database error. The details are in the error log.");
	}
	$GLOBALS["trackerid"] = mysql_insert_id();

	$compact = mysql_real_escape_string(pack('Nn', ip2long($ip), $port));
	$peerid = mysql_real_escape_string('2:ip' . strlen($ip) . ':' . $ip . '7:peer id20:' . hex2bin($peer_id) . "4:porti{$port}e");
	$no_peerid = mysql_real_escape_string('2:ip' . strlen($ip) . ':' . $ip . "4:porti{$port}e");
	@mysql_query("INSERT INTO ".$prefix."y$info_hash SET sequence='{$GLOBALS["trackerid"]}', compact='$compact', with_peerid='$peerid', without_peerid='$no_peerid'");

	if ($left == 0)
	{
		summaryAdd("seeds", 1);
		return "WHERE status='leecher' AND natuser='N'";
	}
	else
	{
		summaryAdd("leechers", 1);
		return "WHERE natuser='N'";
	}
}

// End of function start



////////////////////////////////////////////////////////////////////////////////////////
// Actual work. Depends on value of $event. (Missing event is mapped to '' above)

if ($event == '')
{
	verifyTorrent($info_hash) or evilReject($ip, $peer_id,$port);
	$peer_exists = getPeerInfo($peer_id, $info_hash);
	$where = "WHERE natuser='N'";

	if (!is_array($peer_exists))
		$where = start($info_hash, $ip, $port, $peer_id, $left);

	if ($peer_exists["bytes"] != 0 && $left == 0)
	{

		quickQuery("UPDATE ".$prefix."x$info_hash SET bytes=0, status='seeder' WHERE sequence='${GLOBALS["trackerid"]}'");
		if (mysql_affected_rows() == 1)
		{
			summaryAdd("leechers", -1);
			summaryAdd("seeds", 1);
			summaryAdd("finished", 1);
		}
	}
	collectBytes($peer_exists, $info_hash, $left);
	sendRandomPeers($info_hash);
}
else if ($event == "started")
{
	verifyTorrent($info_hash) or evilReject($ip, $peer_id,$port);

	$start = start($info_hash, $ip, $port, $peer_id, $left);
	
	// Don't send the tracker id for newly started clients. Send it next time. Make sure
	// they get a good random list of peers to begin with.
	sendRandomPeers($info_hash);
}
else if ($event == "stopped")
{
	verifyTorrent($info_hash) or evilReject($ip, $peer_id,$port);
	killPeer($peer_id, $info_hash, $left);	

	// I don't know why, but the real tracker returns peers on event=stopped
	// but I'll just send an empty list. On the other hand, 
	// TheSHADOW asked for this.
	if (isset($_GET["tracker"]))
		$peers = getRandomPeers($info_hash);
	else
		$peers = array("size" => 0);

	sendPeerList($peers);
}
else if ($event == "completed") // now the same as an empty string
{
	verifyTorrent($info_hash) or evilReject($ip, $peer_id,$port);
	$peer_exists = getPeerInfo($peer_id, $info_hash);

	if (!is_array($peer_exists))
		start($info_hash, $ip, $port, $peer_id, $left);
	else
	{
		quickQuery("UPDATE ".$prefix."x$info_hash SET bytes=0, status='seeder' WHERE sequence='${GLOBALS["trackerid"]}'");

		// Race check
		if (mysql_affected_rows() == 1)
		{
			summaryAdd("leechers", -1);
			summaryAdd("seeds", 1);
			summaryAdd("finished", 1);
		}
	}
	collectBytes($peer_exists, $info_hash, $left);
	$peers=getRandomPeers($info_hash);

	sendPeerList($peers);

}
else
	showError("Invalid event= from client.");


if ($GLOBALS["countbytes"])
{
	// Once every minute or so, we run the speed update checker.
	// This is still not very accurate... :/
	//@ symbol suppresses errors
	$query = @mysql_query("SELECT UNIX_TIMESTAMP() - lastSpeedCycle FROM ".$prefix."summary WHERE info_hash='$info_hash'");
	$results = mysql_fetch_row($query);
	if ($results[0] >= 60 || $event == "completed")
	{
		if (Lock("SPEED:$info_hash"))
		{
			@runSpeed($info_hash, $results[0]);
			Unlock("SPEED:$info_hash");
		}
	}
}



/* 
 * Under heavy loads, this will lighten the load slightly... very slightly...
 */
//if (mt_rand(1,10) == 4)
  trashCollector($info_hash, $report_interval);



// Finally, it's time to do stuff to the summary table.
if (!empty($summaryupdate))
{
	$stuff = "";
	foreach ($summaryupdate as $column => $value)
	{
		$stuff .= ', '.$column. ($value[1] ? "=" : "=$column+") . $value[0];
	}
	mysql_query("UPDATE ".$prefix."summary SET ".substr($stuff, 1)." WHERE info_hash='$info_hash'");
}

?>