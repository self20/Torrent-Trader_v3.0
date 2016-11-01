<?php
/**
 * @file
 * @author TorrentTrader <http://www.TorrentTrader.org>
 *
 * Main functions file.
 * @section Modified
 * - $LastChangedDate: 2012-07-16 23:30:33 +0100 (Mon, 16 Jul 2012) $
 * - $LastChangedBy: torrenttrader $

 */

error_reporting(E_ALL ^ E_NOTICE);

// Prefer unescaped. Data will be escaped as needed.
if (ini_get("magic_quotes_gpc")) {
    $_POST = array_map_recursive("unesc", $_POST);
    $_GET = array_map_recursive("unesc", $_GET);
    $_REQUEST = array_map_recursive("unesc", $_REQUEST);
    $_COOKIE = array_map_recursive("unesc", $_COOKIE);
}

if (function_exists("date_default_timezone_set"))
    date_default_timezone_set("Europe/London"); // Do NOT change this. All times are converted to user's chosen timezone.

define("BASEPATH", str_replace("backend", "", dirname(__FILE__)));
$BASEPATH = BASEPATH;
define("BACKEND", dirname(__FILE__));
$BACKEND = BACKEND;

require_once(BACKEND."/mysql.php"); //Get MYSQL Connection Info
require_once(BACKEND."/config.php");  //Get Site Settings and Vars ($site_config)
require(BACKEND."/tzs.php"); // Get Timezones
require_once(BACKEND."/cache.php"); // Caching
require_once(BACKEND."/mail.php"); // Mail functions
require_once(BACKEND."/mysql.class.php");
require_once(BACKEND."/languages.php");

$GLOBALS['tstart'] = array_sum(explode(" ", microtime()));


/**
 * Connect to the database and load user details
 *
 * @param $autoclean
 *   (optional) boolean - Check whether or not to run cleanup (default: false)
 */
function dbconn($autoclean = false) {
    global $mysql_host, $mysql_user, $mysql_pass, $mysql_db, $THEME, $LANGUAGE, $LANG, $site_config;
    $THEME = $LANGUAGE = null;

    if (!ob_get_level()) {
        if (extension_loaded('zlib') && !ini_get('zlib.output_compression'))
            ob_start('ob_gzhandler');
        else
            ob_start();
    }

    //header("Content-Type: text/html;charset=$site_config[CHARSET]");

    function_exists("mysqli_connect") or die("MySQL support not available.");

    @($GLOBALS["___mysqli_ston"] = mysqli_connect($mysql_host,  $mysql_user,  $mysql_pass)) or die('DATABASE: mysql_connect: ' . ((is_object($GLOBALS["___mysqli_ston"])) ? mysqli_error($GLOBALS["___mysqli_ston"]) : (($___mysqli_res = mysqli_connect_error()) ? $___mysqli_res : false)));
    @((bool)mysqli_query($GLOBALS["___mysqli_ston"], "USE " . $mysql_db)) or die('DATABASE: mysql_select_db: ' . ((is_object($GLOBALS["___mysqli_ston"])) ? mysqli_error($GLOBALS["___mysqli_ston"]) : (($___mysqli_res = mysqli_connect_error()) ? $___mysqli_res : false)));

    unset($mysql_pass); //security

    userlogin(); //Get user info

    //Get language and theme
    $CURUSER = $GLOBALS["CURUSER"];

    $ss_a = mysqli_fetch_assoc(SQL_Query_exec("select uri from stylesheets where id='" . ( $CURUSER ? $CURUSER['stylesheet'] : $site_config['default_theme'] )  . "'"));
    $THEME = $ss_a["uri"];

    $lng_a = mysqli_fetch_assoc(SQL_Query_exec("select uri from languages where id='" .  ( $CURUSER ? $CURUSER['language'] : $site_config['default_language'] )  . "'"));
    $LANGUAGE = $lng_a["uri"];
	
    require_once("languages/$LANGUAGE");

    if ($autoclean)
        autoclean();
}

// Main Cleanup
function autoclean() {
    global $site_config;
    require_once("cleanup.php");

    $now = gmtime();

    $res = SQL_Query_exec("SELECT last_time FROM tasks WHERE task='cleanup'");
    $row = mysqli_fetch_row($res);
    if (!$row) {
        SQL_Query_exec("INSERT INTO tasks (task, last_time) VALUES ('cleanup',$now)");
        return;
    }
    $ts = $row[0];
    if ($ts + $site_config["autoclean_interval"] > $now)
        return;
    SQL_Query_exec("UPDATE tasks SET last_time=$now WHERE task='cleanup' AND last_time = $ts");
    if (!mysqli_affected_rows($GLOBALS["___mysqli_ston"]))
        return;

    do_cleanup();
}

// IP Validation
function validip($ip) {
    if (extension_loaded("filter")) {
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
    }

    if (preg_match('![:a-f0-9]!i', $ip))
        return true;

    if (!empty($ip) && $ip == long2ip(ip2long($ip))) {
        $reserved_ips = array (
                array('0.0.0.0','2.255.255.255'),
                array('10.0.0.0','10.255.255.255'),
                array('127.0.0.0','127.255.255.255'),
                array('169.254.0.0','169.254.255.255'),
                array('172.16.0.0','172.31.255.255'),
                array('192.0.2.0','192.0.2.255'),
                array('192.168.0.0','192.168.255.255'),
                array('255.255.255.0','255.255.255.255')
        );

        foreach ($reserved_ips as $r) {
                $min = ip2long($r[0]);
                $max = ip2long($r[1]);
                if ((ip2long($ip) >= $min) && (ip2long($ip) <= $max)) return false;
        }
        return true;
    }
        return false;
}

function getip() {
      $ipaddress = '';
      if (getenv('HTTP_CLIENT_IP'))
          $ipaddress = getenv('HTTP_CLIENT_IP');
      else if(getenv('HTTP_X_FORWARDED_FOR'))
          $ipaddress = getenv('HTTP_X_FORWARDED_FOR');
      else if(getenv('HTTP_X_FORWARDED'))
          $ipaddress = getenv('HTTP_X_FORWARDED');
      else if(getenv('HTTP_FORWARDED_FOR'))
          $ipaddress = getenv('HTTP_FORWARDED_FOR');
      else if(getenv('HTTP_FORWARDED'))
          $ipaddress = getenv('HTTP_FORWARDED');
      else if(getenv('REMOTE_ADDR'))
          $ipaddress = getenv('REMOTE_ADDR');
      else
          $ipaddress = 'UNKNOWN';

      return $ipaddress;
}

function userlogin() {
    $ip = getip();
    // If there's no IP a script is being ran from CLI. Any checks here will fail, skip all.
    if ($ip == "") return;

    GLOBAL $CURUSER;
    unset($GLOBALS["CURUSER"]);

    //Check IP bans
    if (is_ipv6($ip))
        $nip = ip2long6($ip);
    else
        $nip = ip2long($ip);
    $res = SQL_Query_exec("SELECT * FROM bans");
    while ($row = mysqli_fetch_assoc($res)) {
        $banned = false;
        if (is_ipv6($row["first"]) && is_ipv6($row["last"]) && is_ipv6($ip)) {
            $row["first"] = ip2long6($row["first"]);
            $row["last"] = ip2long6($row["last"]);
            $banned = bccomp($row["first"], $nip) != -1 && bccomp($row["last"], $nip) != -1;
        } else {
            $row["first"] = ip2long($row["first"]);
            $row["last"] = ip2long($row["last"]);
            $banned = $nip >= $row["first"] && $nip <= $row["last"];
        }
        if ($banned) {
            header("HTTP/1.0 403 Forbidden");
            echo "<html><head><title>Forbidden</title></head><body><h1>Forbidden</h1>Unauthorized IP address.<br />".
            "Reason for banning: $row[comment]</body></html>";
            die;
        }
    }

    //Check The Cookie and get CURUSER details
    if (strlen($_COOKIE["pass"]) != 40 || !is_numeric($_COOKIE["uid"])) {
        logoutcookie();
        return;
    }

    //Get User Details And Permissions
    $res = SQL_Query_exec("SELECT * FROM users INNER JOIN groups ON users.class=groups.group_id WHERE id=$_COOKIE[uid] AND users.enabled='yes' AND users.status = 'confirmed'");
    $row = mysqli_fetch_assoc($res);

    if (!$row || sha1($row["id"].$row["secret"].$row["password"].$ip.$row["secret"]) != $_COOKIE["pass"]) {
        logoutcookie();
        return;
    }

    $where = where ($_SERVER["SCRIPT_FILENAME"], $row["id"], 0);
    SQL_Query_exec("UPDATE users SET last_access='" . get_date_time() . "', ip=".sqlesc($ip).", page=".sqlesc($where)." WHERE id=" . $row["id"]);


    $GLOBALS["CURUSER"] = $row;
    unset($row);
}

function logincookie($id, $password, $secret, $updatedb = 1, $expires = 0x7fffffff) {
    $hash = sha1($id.$secret.$password.getip().$secret);
    setcookie("pass", $hash, $expires, "/");
    setcookie("uid", $id, $expires, "/");

    if ($updatedb)
        SQL_Query_exec("UPDATE users SET last_login = '".get_date_time()."' WHERE id = $id");
}

function logoutcookie() {
    setcookie("pass", null, time(), "/");
    setcookie("uid", null, time(), "/");
}

function stdhead($title = "") {
    global $site_config, $CURUSER, $THEME, $LANGUAGE;  //Define globals
 
    //site online check
    if (!$site_config["SITE_ONLINE"]){
        if ($CURUSER["control_panel"] != "yes") {
            echo '<br /><br /><br /><center>'. stripslashes($site_config["OFFLINEMSG"]) .'</center><br /><br />';
            die;
        }else{
            echo '<br /><br /><br /><center><b><font color="#ff0000">SITE OFFLINE, STAFF ONLY VIEWING! DO NOT LOGOUT</font></b><br />If you logout please edit backend/config.php and set SITE_ONLINE to true </center><br /><br />';
        }
    }
    //end check

    if (!$CURUSER)
        guestadd();

    if ($title == ""){
        $title = $site_config['SITENAME'];
        $page = "Home";
    }else{
        $title = $site_config['SITENAME']. " : ". htmlspecialchars($title);
        $page = htmlspecialchars($title);
    }
    require_once("themes/" . $THEME . "/block.php");
    require_once("themes/" . $THEME . "/header.php");
}

function stdfoot() {
    global $site_config, $CURUSER, $THEME, $LANGUAGE;
    require_once("themes/" . $THEME . "/footer.php");
    ((is_null($___mysqli_res = mysqli_close($GLOBALS["___mysqli_ston"]))) ? false : $___mysqli_res);
}

function leftblocks() {
    global $site_config, $CURUSER, $THEME, $LANGUAGE, $TTCache, $blockfilename;  //Define globals

    //if (($blocks=$TTCache->get("blocks_left", 900)) === false) {
        $res = SQL_Query_exec("SELECT * FROM blocks WHERE position='left' AND enabled=1 ORDER BY sort");
        $blocks = array();
        while ($result = mysqli_fetch_assoc($res)) {
                $blocks[] = $result["name"];
        }
        //$TTCache->Set("blocks_left", $blocks, 900);
    //}

    foreach ($blocks as $blockfilename){
        include("blocks/".$blockfilename."_block.php");
    }
}

function rightblocks() {
    global $site_config, $CURUSER, $THEME, $LANGUAGE, $TTCache, $blockfilename;  //Define globals

    //if (($blocks=$TTv3Cache->get("blocks_right", 900)) === false) {
        $res = SQL_Query_exec("SELECT * FROM blocks WHERE position='right' AND enabled=1 ORDER BY sort");
        $blocks = array();
        while ($result = mysqli_fetch_assoc($res)) {
                $blocks[] = $result["name"];
        }
        //$TTCache->Set("blocks_right", $blocks, 900);
    //}

    foreach ($blocks as $blockfilename){
        include("blocks/".$blockfilename."_block.php");
    }
}

function middleblocks() {
    global $site_config, $CURUSER, $THEME, $LANGUAGE, $TTv3Cache;  //Define globals
    
    //if (($blocks=$TTCache->get("blocks_middle", 900)) === false) {
        $res = SQL_Query_exec("SELECT * FROM blocks WHERE position='middle' AND enabled=1 ORDER BY sort");
        $blocks = array();
        while ($result = mysqli_fetch_assoc($res)) {
                $blocks[] = $result["name"];
        }
        //$TTCache->Set("blocks_middle", $blocks, 900);
    //}

    foreach ($blocks as $blockfilename){
        include("blocks/".$blockfilename."_block.php");
    }
}

function show_error_msg($title, $message, $wrapper = "1") {
    if ($wrapper) {
        ob_start();
        ob_clean();
        stdhead($title);
    }
	
    echo '<p class="error">'.$message.'</p>';

    if ($wrapper){
        stdfoot();
        die();
    }
}

function ajax_show_error_msg($title, $message, $wrapper = "1") {
    if ($wrapper) {
        ob_start();
        ob_clean();
    }
	
    echo '<div id="overlay" style="position: fixed; z-index: 10000; top: 30px; left: 605px; display: block;">
        <div class="overlay_header">
            <div class="close"></div>
            '.$title.'
        </div>
        <div class="overlay_text">
            <div class="error" header="'.$title.'">
                '.$message.'
            </div>
        </div>
        <div class="overlay_footer"></div>
    </div>';

    if ($wrapper){
        die();
    }
}

function ajax_show_infos_msg($title, $message, $wrapper = "1") {
    if ($wrapper) {
        ob_start();
        ob_clean();
    }

    echo '<div id="overlay" style="position: fixed; z-index: 10000; top: 30px; left: 605px; display: block;">
        <div class="overlay_header">
            <div class="close"></div>
            '.$title.'
        </div>
        <div class="overlay_text">
            <div class="done" header="'.$title.'">
                '.$message.'
            </div>
        </div>
        <div class="overlay_footer"></div>
    </div>';

    if ($wrapper){
        die();
    }
}

function health($leechers, $seeders) {
    if (($leechers == 0 && $seeders == 0) || ($leechers > 0 && $seeders == 0))
        return 0;
    elseif ($seeders > $leechers)
        return 10;

    $ratio = $seeders / $leechers * 100;
    if ($ratio > 0 && $ratio < 15)
        return 1;
    elseif ($ratio >= 15 && $ratio < 25)
        return 2;
    elseif ($ratio >= 25 && $ratio < 35)
        return 3;
    elseif ($ratio >= 35 && $ratio < 45)
        return 4;
    elseif ($ratio >= 45 && $ratio < 55)
        return 5;
    elseif ($ratio >= 55 && $ratio < 65)
        return 6;
    elseif ($ratio >= 65 && $ratio < 75)
        return 7;
    elseif ($ratio >= 75 && $ratio < 85)
        return 8;
    elseif ($ratio >= 85 && $ratio < 95)
        return 9;
    else
        return 10;
}


// MySQL escaping
function sqlesc($x) {
   if (!is_numeric($x)) {
       $x = "'".((isset($GLOBALS["___mysqli_ston"]) && is_object($GLOBALS["___mysqli_ston"])) ? mysqli_real_escape_string($GLOBALS["___mysqli_ston"], $x) : ((trigger_error("[MySQLConverterToo] Fix the mysql_escape_string() call! This code does not work.", E_USER_ERROR)) ? "" : ""))."'";
   }
   return $x;
}


function unesc($x) {
    if (get_magic_quotes_gpc())
        return stripslashes($x);
    return $x;
}

/**
 * Convert bytes to readable format
 *
 * @param $s
 *   integer: bytes
 * @param $precision
 *   (optional) integer: decimal precision (default: 2)
 * @return
 *   string: formatted size
 */
function mksize ($s, $precision = 2) {
    $suf = array("B", "kB", "MB", "GB", "TB", "PB", "EB", "ZB", "YB");

    for ($i = 1, $x = 0; $i <= count($suf); $i++, $x++) {
        if ($s < pow(1024, $i) || $i == count($suf)) // Change 1024 to 1000 if you want 0.98GB instead of 1,0000MB
            return number_format($s/pow(1024, $x), $precision)." ".$suf[$x];
    }
}

function escape_url($url) {
    $ret = '';
    for($i = 0; $i < strlen($url); $i+=2)
    $ret .= '%'.$url[$i].$url[$i + 1];
    return $ret;
}

/**
 * Scrape torrent and return stats
 *
 * @param $scrape
 *   string: Scrape URL
 * @param $hash
 *   string: SHA1 hash (info_hash) of torrent
 * @return
 *   array:
 *     All -1 if failed
 *     - seeds: integer - number of seeders
 *     - leechers: integer - number of leechers
 *     - downloaded: integer - number of complete downloads
 *
 */
function torrent_scrape_url($scrape, $hash) {
    if (function_exists("curl_exec")) {
        $ch = curl_init();
        $timeout = 5;
        curl_setopt ($ch, CURLOPT_URL, $scrape.'?info_hash='.escape_url($hash));
        curl_setopt ($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt ($ch, CURLOPT_CONNECTTIMEOUT, $timeout);
        curl_setopt ($ch, CURLOPT_HEADER, false);
        $fp = curl_exec($ch);
        curl_close($ch);
    } else {
        ini_set('default_socket_timeout',10); 
        $fp = @file_get_contents($scrape.'?info_hash='.escape_url($hash));
    }
    $ret = array();
    if ($fp) {
        $stats = BDecode($fp);
        $binhash = pack("H*", $hash);
        $binhash = addslashes($binhash);
        $seeds = $stats['files'][$binhash]['complete'];
        $peers = $stats['files'][$binhash]['incomplete'];
        $downloaded = $stats['files'][$binhash]['downloaded'];
        $ret['seeds'] = $seeds;
        $ret['peers'] = $peers;
        $ret['downloaded'] = $downloaded;
    }

    if ($ret['seeds'] === null) {
        $ret['seeds'] = -1;
        $ret['peers'] = -1;
        $ret['downloaded'] = -1;
    }

    return $ret;
}

function mkprettytime($s) {
    if ($s < 0)
        $s = 0;

    $t = array();
    $t["day"] = floor($s / 86400);
    $s -= $t["day"] * 86400;

    $t["hour"] = floor($s / 3600);
    $s -= $t["hour"] * 3600;

    $t["min"] = floor($s / 60);
    $s -= $t["min"] * 60;

    $t["sec"] = $s;

    if ($t["day"])
        return $t["day"] . "d " . sprintf("%02d:%02d:%02d", $t["hour"], $t["min"], $t["sec"]);
    if ($t["hour"])
        return sprintf("%d:%02d:%02d", $t["hour"], $t["min"], $t["sec"]);
        return sprintf("%d:%02d", $t["min"], $t["sec"]);
}

function gmtime() {
    return sql_timestamp_to_unix_timestamp(get_date_time());
}

function loggedinonly() {
    global $CURUSER;
    if (!$CURUSER) {
        header("Refresh: 0; url=account-login.php?returnto=" . urlencode($_SERVER["REQUEST_URI"]));
        exit();
    }
}

function validfilename($name) {
    return preg_match('/^[^\0-\x1f:\\\\\/?*\xff#<>|]+$/si', $name);
}

function validemail($email) {
    if (function_exists("filter_var"))
        return filter_var($email, FILTER_VALIDATE_EMAIL);
    return preg_match('/^([a-z0-9._-](\+[a-z0-9])*)+@[a-z0-9.-]+\.[a-z]{2,6}$/i', $email);
}

function mksecret($len = 20) {
    $chars = array_merge(range(0, 9), range("A", "Z"), range("a", "z"));
    shuffle($chars);
    $x = count($chars) - 1;
    for ($i = 1; $i <= $len; $i++)
        $str .= $chars[mt_rand(0, $x)];
    return $str;
}

function deletetorrent($id) {
    global $site_config;

    $row = @mysqli_fetch_assoc(@SQL_Query_exec("SELECT image1,image2 FROM torrents WHERE id=$id"));

    foreach(explode(".","peers.comments.ratings.files.announce") as $x)
        SQL_Query_exec("DELETE FROM $x WHERE torrent = $id");

    SQL_Query_exec("DELETE FROM completed WHERE torrentid = $id");
    SQL_Query_exec("DELETE FROM bookmarks WHERE torrentid = $id"); // DELETE FROM bookmarks - mobman
    
    if (file_exists($site_config["torrent_dir"] . "/$id.torrent"))
        unlink($site_config["torrent_dir"] . "/$id.torrent");
    
    if ($row["image1"]) {                             
        unlink($site_config['torrent_dir'] . "/images/" . $row["image1"]);
    }
    
    if ($row["image2"]) {
        unlink($site_config['torrent_dir'] . "/images/" . $row["image2"]);
    }

    @unlink($site_config["nfo_dir"]."/$id.nfo");

    SQL_Query_exec("DELETE FROM torrents WHERE id = $id");
        SQL_Query_exec("DELETE FROM reports WHERE votedfor = $id AND type = 'torrent'");
}

// Added DELETE FROM bookmarks and friends by mobman
function deleteaccount($userid) 
{
    SQL_Query_exec("DELETE FROM users WHERE id = $userid");
    SQL_Query_exec("DELETE FROM warnings WHERE userid = $userid");
    SQL_Query_exec("DELETE FROM ratings WHERE user = $userid");
    SQL_Query_exec("DELETE FROM peers WHERE userid = $userid");
    SQL_Query_exec("DELETE FROM completed WHERE userid = $userid"); 
    SQL_Query_exec("DELETE FROM reports WHERE addedby = $userid");
    SQL_Query_exec("DELETE FROM reports WHERE votedfor = $userid AND type = 'user'");
    SQL_Query_exec("DELETE FROM forum_readposts WHERE userid = $userid");
    SQL_Query_exec("DELETE FROM pollanswers WHERE userid = $userid");
    SQL_Query_exec("DELETE FROM `friends` WHERE `userid` = $userid");
    SQL_Query_exec("DELETE FROM `friends` WHERE `friendid` = $userid");
    SQL_Query_exec("DELETE FROM bookmarks WHERE userid = $userid"); 
}

function genrelist() {
    $ret = array();
    $res = SQL_Query_exec("SELECT id, name, parent_cat FROM categories ORDER BY parent_cat ASC, sort_index ASC");
    while ($row = mysqli_fetch_assoc($res))
        $ret[] = $row;
    return $ret;
}

function langlist() {
    $ret = array();
    $res = SQL_Query_exec("SELECT id, name, image FROM torrentlang ORDER BY sort_index, id");
    while ($row = mysqli_fetch_assoc($res))
        $ret[] = $row;
    return $ret;
}

function is_valid_id($id){
    return is_numeric($id) && ($id > 0) && (floor($id) == $id);
}

function is_valid_int($id){
    return is_numeric($id) && (floor($id) == $id);
}

function sql_timestamp_to_unix_timestamp($s){
    return mktime(substr($s, 11, 2), substr($s, 14, 2), substr($s, 17, 2), substr($s, 5, 2), substr($s, 8, 2), substr($s, 0, 4));
}

function write_log($text){
    $text = sqlesc($text);
    $added = sqlesc(get_date_time());
    SQL_Query_exec("INSERT INTO log (added, txt) VALUES($added, $text)");
}

function get_elapsed_time($ts){
  $mins = floor((gmtime() - $ts) / 60);
  $hours = floor($mins / 60);
  $mins -= $hours * 60;
  $days = floor($hours / 24);
  $hours -= $days * 24;
  $weeks = floor($days / 7);
  $days -= $weeks * 7;
  $t = "";
  if ($weeks > 0)
    return "$weeks wk" . ($weeks > 1 ? "s" : "");
  if ($days > 0)
    return "$days day" . ($days > 1 ? "s" : "");
  if ($hours > 0)
    return "$hours hr" . ($hours > 1 ? "s" : "");
  if ($mins > 0)
    return "$mins min" . ($mins > 1 ? "s" : "");
  return "< 1 min";
}

function guestadd() {
    $ip = $_SERVER['REMOTE_ADDR'];
    $time = gmtime();
    SQL_Query_exec("INSERT INTO `guests` (`ip`, `time`) VALUES ('$ip', '$time') ON DUPLICATE KEY UPDATE `time` = '$time'");
}
                                
function getguests() {
    $past = (gmtime() - 2400);
    SQL_Query_exec("DELETE FROM `guests` WHERE `time` < $past");
    return get_row_count("guests");
}

function time_ago($addtime) {
   $addtime = get_elapsed_time(sql_timestamp_to_unix_timestamp($addtime));
   return $addtime;
}

function CutName ($vTxt, $Car) {
    if (strlen($vTxt) > $Car) {
        return substr($vTxt, 0, $Car) . "...";
    }
    return $vTxt;
}

function searchfield($s) {
    return preg_replace(array('/[^a-z0-9]/si', '/^\s*/s', '/\s*$/s', '/\s+/s'), array(" ", "", "", " "), $s);
}

function get_row_count($table, $suffix = "") {
    $res = SQL_Query_exec("SELECT COUNT(*) FROM $table $suffix");
    $row = mysqli_fetch_row($res);
    return $row[0];
}

function get_date_time($timestamp = 0){
    if ($timestamp)
        return date("Y-m-d H:i:s", $timestamp);
    else
        return gmdate("Y-m-d H:i:s");
}

// Convert UTC to user's timezone
function utc_to_tz ($timestamp=0) {
    GLOBAL $CURUSER, $tzs;

    if (method_exists("DateTime", "setTimezone")) {
        if (!$timestamp)
            $timestamp = get_date_time();
        $date = new DateTime($timestamp, new DateTimeZone("UTC"));

        $date->setTimezone(new DateTimeZone($CURUSER ? $tzs[$CURUSER["tzoffset"]][1] : "Europe/London"));
        return $date->format('Y-m-d H:i:s');
    }
    if (!is_numeric($timestamp))
        $timestamp = sql_timestamp_to_unix_timestamp($timestamp);
    if ($timestamp == 0)
        $timestamp = gmtime();

    $timestamp = $timestamp + ($CURUSER['tzoffset']*60);
    if (date("I")) $timestamp += 3600; // DST Fix
    return date("Y-m-d H:i:s", $timestamp);
}

function utc_to_tz_time ($timestamp=0) {
    GLOBAL $CURUSER, $tzs;

    if (method_exists("DateTime", "setTimezone")) {
        if (!$timestamp)
            $timestamp = get_date_time();
        $date = new DateTime($timestamp, new DateTimeZone("UTC"));
        $date->setTimezone(new DateTimeZone($CURUSER ? $tzs[$CURUSER["tzoffset"]][1] : "Europe/London"));
        return sql_timestamp_to_unix_timestamp($date->format('Y-m-d H:i:s'));
    }

    if (!is_numeric($timestamp))
        $timestamp = sql_timestamp_to_unix_timestamp($timestamp);
    if ($timestamp == 0)
        $timestamp = gmtime();

    $timestamp = $timestamp + ($CURUSER['tzoffset']*60);
    if (date("I")) $timestamp += 3600; // DST Fix

    return $timestamp;
}

function encodehtml($s, $linebreaks = true) {
      $s = str_replace("<", "&lt;", str_replace("&", "&amp;", $s));
      if ($linebreaks)
        $s = nl2br($s);
      return $s;
}


function format_urls($s){
    return preg_replace(
    "/(\A|[^=\]'\"a-zA-Z0-9])((http|ftp|https|ftps|irc):\/\/[^<>\s]+)/i",
    "\\1<a href='https://anonym.to/?\\2' target='_blank'>\\2</a>", $s);
}

function format_comment($text) {
    global $site_config, $smilies;

    $s = $text;

    $s = htmlspecialchars($s);
    $s = format_urls($s);

    // [*]
    $s = preg_replace("/\[\*\]/", "<li>", $s);

    // [b]Bold[/b]
    $s = preg_replace("/\[b\]((\s|.)+?)\[\/b\]/", "<b>\\1</b>", $s);

    // [i]Italic[/i]
    $s = preg_replace("/\[i\]((\s|.)+?)\[\/i\]/", "<i>\\1</i>", $s);

    // [u]Underline[/u]
    $s = preg_replace("/\[u\]((\s|.)+?)\[\/u\]/", "<u>\\1</u>", $s);

    // [u]Underline[/u]
    $s = preg_replace("/\[u\]((\s|.)+?)\[\/u\]/i", "<u>\\1</u>", $s);

    // [color=blue]Text[/color]
    $s = preg_replace("/\[color=([a-zA-Z]+)\]((\s|.)+?)\[\/color\]/i", "<font color='\\1'>\\2</font>", $s);

    // [color=#ffcc99]Text[/color]
    $s = preg_replace("/\[color=(#[a-f0-9][a-f0-9][a-f0-9][a-f0-9][a-f0-9][a-f0-9])\]((\s|.)+?)\[\/color\]/i", "<font color='\\1'>\\2</font>", $s);

    // [align=?]Text[/align]
    $s = preg_replace("/\[align=(left|right|center)\](.*?)\[\/align\]/i", "<div align='\\1'>\\2</div>", $s);

    // [size=4]Text[/size]
    $s = preg_replace("/\[size=([1-7])\]((\s|.)+?)\[\/size\]/i", "<font size='\\1'>\\2</font>", $s);

    // [s]strikethrough[/s]
    $s = preg_replace("/\[s\]((\s|.)+?)\[\/s\]/i", "<s>\\1</s>", $s);

    // [font=Arial]Text[/font]
    $s = preg_replace("/\[font=([a-zA-Z ,]+)\]((\s|.)+?)\[\/font\]/i", "<font face=\"\\1\">\\2</font>", $s);

    // [img]http://www/image.gif[/img]
    $s = preg_replace("/\[img\]((http|https):\/\/[^\s'\"<>]+(\.gif|\.jpg|\.png|\.bmp|\.jpeg))\[\/img\]/i", "<img border='0' src=\"\\1\" alt='' />", $s);

    // [img=http://www/image.gif]
    $s = preg_replace("/\[img=((http|https):\/\/[^\s'\"<>]+(\.gif|\.jpg|\.png|\.bmp|\.jpeg))\]/i", "<img border='0' src=\"\\1\" alt='' />", $s);

    // [imgp]http://www/image.gif[/imgp]
    $s = preg_replace("/\[imgp](http:\/\/[^\s'\"<>]+(\.(jpg|jpeg|gif|png)))\[\/imgp\]/i","<a class=\"light\" target=\"_self\" href=\"\\1 \"><img width=\"800\" src=\"\\1\" alt=\"\" / border=\"0\"></a><br /><font size=\"1\" color=\"#FF2000\" style=\"margin-left:5px\">Cliquez sur l'image pour la voir en taille réelle!</font>", $s);

    // [color=blue]Text[/color]
    $s = preg_replace(
        "/\[color=([a-zA-Z]+)\]((\s|.)+?)\[\/color\]/i",
        "<font color='\\1'>\\2</font>", $s);

    // [color=#ffcc99]Text[/color]
    $s = preg_replace(
        "/\[color=(#[a-f0-9][a-f0-9][a-f0-9][a-f0-9][a-f0-9][a-f0-9])\]((\s|.)+?)\[\/color\]/i",
        "<font color='\\1'>\\2</font>", $s);

    // [url=http://www.example.com]Text[/url]
    $s = preg_replace(
        "/\[url=((http|ftp|https|ftps|irc):\/\/[^<>\s]+?)\]((\s|.)+?)\[\/url\]/i",
        "<a href='\\1' target='_self'>\\3</a>", $s);

    // [url]http://www.example.com[/url]
    $s = preg_replace(
        "/\[url\]((http|ftp|https|ftps|irc):\/\/[^<>\s]+?)\[\/url\]/i",
        "<a href='\\1' target='_self'>\\1</a>", $s);

    // [size=4]Text[/size]
    $s = preg_replace(
        "/\[size=([1-7])\]((\s|.)+?)\[\/size\]/i",
        "<font size='\\1'>\\2</font>", $s);

    // [font=Arial]Text[/font]
    $s = preg_replace(
        "/\[font=([a-zA-Z ,]+)\]((\s|.)+?)\[\/font\]/i",
        "<font face=\"\\1\">\\2</font>", $s);

    //[code]Text[/code ]
    $s = preg_replace("/\[code\]\s*((\s|.)+?)\s*\[\/code\]\s*/i",
        "<p class='sub'><b>Code:</b></p><table class='main' border='1' cellspacing='0' cellpadding='10'><tr><td style='border: 1px black dotted'>\\1</td></tr></table><br />", $s);

    // [df]defilement[/df]
    $s = preg_replace("/\[df\]((\s|.)+?)\[\/df\]/", "<marquee>\\1</marquee>", $s);

    // [blink]clignotement[/blink]
    $s = preg_replace("/\[blink\]((\s|.)+?)\[\/blink\]/", "<blink>\\1</blink>", $s);

    //[align=(center|left|right|justify)]text[/align]
    $s = preg_replace("/\[align=([a-zA-Z]+)\]((\s|.)+?)\[\/align\]/i","<div style=\"text-align:\\1\">\\2</div>", $s);

    // [left]Left[/left]
    $s = preg_replace("/\[left\]((\s|.)+?)\[\/left\]/", "<div align=left>\\1</div>", $s);
    $s = preg_replace("/\[LEFT\]((\s|.)+?)\[\/LEFT\]/", "<div align=left>\\1</div>", $s);

    // [right]Right[/right]
    $s = preg_replace("/\[right\]((\s|.)+?)\[\/right\]/", "<div align=right>\\1</div>", $s);
    $s = preg_replace("/\[RIGHT\]((\s|.)+?)\[\/RIGHT\]/", "<div align=right>\\1</div>", $s);

    // [center]Center[/center]
    $s = preg_replace("/\[center\]((\s|.)+?)\[\/center\]/", "<center>\\1</center>", $s);
    $s = preg_replace("/\[CENTER\]((\s|.)+?)\[\/CENTER\]/", "<center>\\1</center>", $s);

    // [imgw]http://www/image.gif[/imgw]
    $s = preg_replace("/\[imgw](http:\/\/[^\s'\"<>]+(\.(jpg|jpeg|gif|png)))\[\/imgw\]/i","<a class=\"light\" target=\"_self\" href=\"\\1 \"><img width=\"500\" src=\"\\1\" alt=\"\" / border=\"0\"></a><br /><font size=\"1\" color=\"#FF2000\" style=\"margin-left:5px\">Cliquez sur l'image pour la voir en taille réelle!</font>", $s);

    // [imgbitcoins]http://www/image.gif[/imgbitcoins]
    $s = preg_replace("/\[imgbitcoins](http:\/\/[^\s'\"<>]+(\.(jpg|jpeg|gif|png)))\[\/imgbitcoins\]/i","<a class=\"light\" target=\"_self\" href=\"\\1 \"><img width=\"100\" height=\"100\" src=\"\\1\" alt=\"\" / border=\"0\"></a>", $s);

    // [imgw=http://www/image.gif]
    $s = preg_replace("/\[imgw=(http:\/\/[^\s'\"<>]+(\.(jpg|jpeg|gif|png)))\]/i","<a class=\"light\" target=\"_self\" href=\"\\1 \"><img width=\"500\" height=\"150\" src=\"\\1\" alt=\"\" / border=\"0\"></a><br /><font size=\"1\" color=\"#FF2000\" style=\"margin-left:5px\">Click on image to see it in full size!</font>", $s);

    // [imgor=http://www/image.gif]
    $s = preg_replace("/\[imgw=(http:\/\/[^\s'\"<>]+(\.(jpg|jpeg|gif|png)))\]/i","<a class=\"light\" target=\"_self\" href=\"\\1 \"><img width=\"1100\" src=\"\\1\" alt=\"\" / border=\"0\"></a><br /><font size=\"1\" color=\"#FF2000\" style=\"margin-left:5px\">Click on image to see it in full size!</font>", $s);

    // [textarea]Textarea[/textarea]
    $s = preg_replace("/\[textarea](.+)\[\/textarea\]/i", "<textarea rows=(10) cols=(40)\>\\1</textarea>", $s);

    // [highlight=red]Surbrillance[/highlight]
    $s = preg_replace("#\[highlight](.*?)\[/highlight]#si", "<span class=\"highlight\">\\1</span>", $s);
    $s = preg_replace("#\[HIGHLIGHT](.*?)\[/HIGHLIGHT]#si", "<span class=\"highlight\">\\1</span>", $s);

    // [multi]lecteur de fichier multimedia[/multi]
    $s = preg_replace("/\[multi\]((\s|.)+?)\[\/multi\]/", "<embed width=\"680\" height=\"440\" src=\"\\1\">", $s);
    $s = preg_replace("/\[MULTI\]((\s|.)+?)\[\/MULTI\]/", "<embed width=\"680\" height=\"440\" src=\"\\1\">", $s);

    //[Allocine]
    $s = preg_replace("/\[allocine\]([0-9]+)\[\/allocine\]/i",'<embed type="application/x-shockwave-flash" width="440" height="320" src="http://www.allocine.fr/blogvision/\\1"></embed>', $s);

    //[mail]mail[/mail]
    $s = preg_replace("/\[mail\]((\s|.)+?)\[\/mail\]/i","<a href=\"mailto:\\1\" targe=\"_blank\">\\1</a>", $s);

    //[hr]
    $s = preg_replace("/\[hr\]/i", "<hr />", $s);

    //[hr=#ffffff] [hr=red]
    $s = preg_replace("/\[hr=((#[a-f0-9][a-f0-9][a-f0-9][a-f0-9][a-f0-9][a-f0-9])|([a-zA-z]+))\]/i", "<hr color='\\1 />", $s);

    //[swf]http://somesite.com/test.swf[/swf]
    $s = preg_replace("/\[swf\]((www.|http:\/\/|https:\/\/)[^\s]+(\.swf))\[\/swf\]/i",
        "<param name='movie' value='\\1'/><embed width='640' height='480' src='\\1'></embed>", $s);

    //[swf=http://somesite.com/test.swf]
    $s = preg_replace("/\[swf=((www.|http:\/\/|https:\/\/)[^\s]+(\.swf))\]/i",
        "<param name='movie' value='\\1'/><embed width='640' height='480' src='\\1'></embed>", $s);

    //[swf width=XXX heigth=XXX]swf[/swf]
    $s = preg_replace("/\[swf (.*?),(.*?)\]((www.|http:\/\/|https:\/\/)[^\s]+(\.swf))\[\/swf\]/i",
        "<param name='movie' value='\\3'/><embed width='\\1' height='\\2' src='\\3'></embed>", $s);

    //[video]YouTube Video URL[/video]
    $s = preg_replace("/\[video\][^\s'\"<>]*youtube.com.*v=([^\s'\"<>]+)\[\/video\]/ims",
        "<object width='640' height='480'>
<param name='movie' value='http://www.youtube.com/v/\\1'></param>
<param name='allowscriptaccess' value='never'></param>
<param name='allowFullScreen' value='true'></param>
<param name='allowNetworking' value='internal'></param>
<embed src='http://www.youtube.com/v/\\1' type='application/x-shockwave-flash' allowscriptaccess='never' allowfullscreen='true' allowNetworking='internal' width='640' height='480'></embed>
</object>", $s);

    //[video]Dailymotion Video URL[/video]
    $s = preg_replace("/\[video\][^\s'\"<>]*dailymotion.com\/video\/([^\s'\"<>]+)\[\/video\]/ims",
        "<object width='640' height='480'>
<param name='movie' value='http://www.dailymotion.com/swf/\\1'></param>
<param name='allowscriptaccess' value='never'></param>
<param name='allowFullScreen' value='true'></param>
<param name='allowNetworking' value='internal'>
<embed src='http://www.dailymotion.com/swf/\\1' type='application/x-shockwave-flash' allowscriptaccess='never' allowfullscreen='true' allowNetworking='internal' width='640' height='480'></embed>
</object>", $s);

    // YouTube Video [video=URL]
    $s = preg_replace("/\[video=[^\s'\"<>]*youtube.com.*v=([^\s'\"<>]+)\]/ims",
        "<object width='640' height='480'>
<param name='movie' value='http://www.youtube.com/v/\\1'></param>
<param name='allowscriptaccess' value='never'></param>
<param name='allowFullScreen' value='true'></param>
<param name='allowNetworking' value='internal'></param>
<embed src='http://www.youtube.com/v/\\1' type='application/x-shockwave-flash' allowscriptaccess='never' allowfullscreen='true' allowNetworking='internal' width='640' height='480'></embed>
</object>", $s);

    // Dailymotion Video [video=URL]
    $s = preg_replace("/\[video\=[^\s'\"<>]*dailymotion.com\/video\/([^\s'\"<>]+)\]/ims",
        "<object width='640' height='480'>
<param name='movie' value='http://www.dailymotion.com/swf/\\1'></param>
<param name='allowscriptaccess' value='never'></param>
<param name='allowFullScreen' value='true'></param>
<param name='allowNetworking' value='internal'></param>
<embed src='http://www.dailymotion.com/swf/\\1' type='application/x-shockwave-flash' allowscriptaccess='never' allowfullscreen='true' allowNetworking='internal' width='640' height='480'></embed>
</object>", $s);

    //[video]YouTube Small Video[/video]
    $s = preg_replace("/\[videow\][^\s'\"<>]*youtube.com.*v=([^\s'\"<>]+)\[\/videow\]/ims",
        "<object width='320' height='240'>
<param name='movie' value='http://www.youtube.com/v/\\1'></param>
<param name='allowscriptaccess' value='never'></param>
<param name='allowFullScreen' value='true'></param>
<param name='allowNetworking' value='internal'></param>
<embed src='http://www.youtube.com/v/\\1' type='application/x-shockwave-flash' allowscriptaccess='never' allowfullscreen='true' allowNetworking='internal' width='320' height='240'></embed>
</object>", $s);

    //[video]Dailymotion Small Video[/video]
    $s = preg_replace("/\[videow\][^\s'\"<>]*dailymotion.com\/video\/([^\s'\"<>]+)\[\/videow\]/ims",
        "<object width='320' height='240'>
<param name='movie' value='http://www.dailymotion.com/swf/\\1'></param>
<param name='allowscriptaccess' value='never'></param>
<param name='allowFullScreen' value='true'></param>
<param name='allowNetworking' value='internal'></param>
<embed src='http://www.dailymotion.com/swf/\\1' type='application/x-shockwave-flash' allowscriptaccess='never' allowfullscreen='true' allowNetworking='internal' width='320' height='240'></embed>
</object>", $s);

    // YouTube Small Video [videow=URL]
    $s = preg_replace("/\[videow=[^\s'\"<>]*youtube.com.*v=([^\s'\"<>]+)\]/ims",
        "<object width='320' height='240'>
<param name='movie' value='http://www.youtube.com/v/\\1'></param>
<param name='allowscriptaccess' value='never'></param>
<param name='allowFullScreen' value='true'></param>
<param name='allowNetworking' value='internal'></param>
<embed src='http://www.youtube.com/v/\\1' type='application/x-shockwave-flash' allowscriptaccess='never' allowfullscreen='true' allowNetworking='internal' width='320' height='240'></embed>
</object>", $s);

    // Dailymotiion Small Video [videow=URL]
    $s = preg_replace("/\[videow\=[^\s'\"<>]*dailymotion.com\/video\/([^\s'\"<>]+)\]/ims",
        "<object width='320' height='240'>
<param name='movie' value='http://www.dailymotion.com/swf/\\1'></param>
<param name='allowscriptaccess' value='never'></param>
<param name='allowFullScreen' value='true'></param>
<param name='allowNetworking' value='internal'></param>
<embed src='http://www.dailymotion.com/swf/\\1' type='application/x-shockwave-flash' allowscriptaccess='never' allowfullscreen='true' allowNetworking='internal' width='320' height='240'></embed>
</object>", $s);

    //[audio]URL[/audio]
    $s = preg_replace("/\[audio\]((www.|http:\/\/|https:\/\/)[^\s]+(\.mp3|wav|wma|aac|bwf|ogg|ac3|flac|alac))\[\/audio\]/i",
        "<embed autostart='false' loop='false' controller='true' width='220' height='18' src='\\1'></embed>", $s);

    //[audio=URL]
    $s = preg_replace("/\[audio\=((www.|http:\/\/|https:\/\/)[^\s]+(\.mp3|wav|wma|aac|bwf|ogg|ac3|flac|alac))\]/i",
        "<embed autostart='false' loop='false' controller='true' width='220' height='18' src='\\1'></embed>", $s);

    //[quote]Text[/quote]
    while (preg_match("/\[quote\]\s*((\s|.)+?)\s*\[\/quote\]\s*/i", $s))
        $s = preg_replace(
            "/\[quote\]\s*((\s|.)+?)\s*\[\/quote\]\s*/i",
            "<table border='0' cellspacing='10' cellpadding='10'><tr><td class='table_col1' cellspacing='10' cellpadding='10' style='border: 0px violet dotted'>\\1</td></tr></table><br />", $s);

    //[quote=Author]Text[/quote]
    while (preg_match("/\[quote=(.+?)\]\s*((\s|.)+?)\s*\[\/quote\]\s*/i", $s))
        $s = preg_replace(
            "/\[quote=(.+?)\]\s*((\s|.)+?)\s*\[\/quote\]\s*/i",
            "\\1<table class='main' border='0' cellspacing='0' cellpadding='10'><tr><td class='table_col1' style='border: 0px violet dotted'>\\2</td></tr></table><br />", $s);

    // [spoiler]Text[/spoiler]
    $r = substr(md5($text), 0, 4);
    $i = 0;
    while (preg_match("/\[spoiler\]\s*((\s|.)+?)\s*\[\/spoiler\]\s*/i", $s)) {
        $s = preg_replace("/\[spoiler\]\s*((\s|.)+?)\s*\[\/spoiler\]\s*/i",
            "<br /><img src='images/plus.gif' id='pic$r$i' title='Spoiler' onclick='klappe_torrent(\"$r$i\")' alt='' /><div id='k$r$i' style='display: none;'>\\1<br /></div>", $s);
        $i++;
    }

    // [spoiler=Heading]Text[/spoiler]
    while (preg_match("/\[spoiler=(.+?)\]\s*((\s|.)+?)\s*\[\/spoiler\]\s*/i", $s)) {
        $s = preg_replace("/\[spoiler=(.+?)\]\s*((\s|.)+?)\s*\[\/spoiler\]\s*/i",
            "<br /><img src='images/plus.gif' id='pic$r$i' title='Spoiler' onclick='klappe_torrent(\"$r$i\")' alt='' /><b>\\1</b><div id='k$r$i' style='display: none;'>\\2<br /></div>", $s);
        $i++;
    }

    //[hr]
    $s = preg_replace("/\[hr\]/i", "<hr />", $s);

    //[hr=#ffffff] [hr=red]
    $s = preg_replace("/\[hr=((#[a-f0-9][a-f0-9][a-f0-9][a-f0-9][a-f0-9][a-f0-9])|([a-zA-z]+))\]/i", "<hr color=\"\\1\"/>", $s);

    //[swf]http://somesite.com/test.swf[/swf]
    $s = preg_replace("/\[swf\]((www.|http:\/\/|https:\/\/)[^\s]+(\.swf))\[\/swf\]/i",
        "<param name='movie' value='\\1'/><embed width='470' height='310' src='\\1'></embed>", $s);

    //[swf=http://somesite.com/test.swf]
    $s = preg_replace("/\[swf=((www.|http:\/\/|https:\/\/)[^\s]+(\.swf))\]/i",
        "<param name='movie' value='\\1'/><embed width='470' height='310' src='\\1'></embed>", $s);

    // Linebreaks
    $s = nl2br($s);

    // Maintain spacing
    $s = str_replace("  ", " &nbsp;", $s);

    // Smilies
    require_once("smilies.php");
    reset($smilies);
    while (list($code, $url) = each($smilies))
        $s = str_replace($code, '<img border="0" src="'.$site_config["SITEURL"].'/images/smilies/'.$url.'" alt="'.$code.'" title="'.$code.'" />', $s);

    if($site_config["OLD_CENSOR"])
    {
    $r = SQL_Query_exec("SELECT * FROM censor");
    while($rr=mysqli_fetch_row($r))
    $s = preg_replace("/".preg_quote($rr[0])."/i", $rr[1], $s);
    }
    else
    {
    
    $f = @fopen("censor.txt","r");
    
    if ($f && filesize("censor.txt") != 0) {
    
       $bw = fread($f, filesize("censor.txt"));
       $badwords = explode("\n",$bw);
       
       for ($i=0; $i<count($badwords); ++$i)
           $badwords[$i] = trim($badwords[$i]);
       $s = str_replace($badwords, "<img src='images/censored.png' border='0' alt='Censored' title='Censored' />", $s);
    }
    @fclose($f);
    }

    return $s;
}

function torrenttable($res) {
    global $site_config, $CURUSER, $THEME, $LANGUAGE;  //Define globals

    if ($site_config["MEMBERSONLY_WAIT"] && $site_config["MEMBERSONLY"] && in_array($CURUSER["class"], explode(",",$site_config["WAIT_CLASS"]))) {
        $gigs = $CURUSER["uploaded"] / (1024*1024*1024);
        $ratio = (($CURUSER["downloaded"] > 0) ? ($CURUSER["uploaded"] / $CURUSER["downloaded"]) : 0);
        if ($ratio < 0 || $gigs < 0) $wait = $site_config["WAITA"];
        elseif ($ratio < $site_config["RATIOA"] || $gigs < $site_config["GIGSA"]) $wait = $site_config["WAITA"];
        elseif ($ratio < $site_config["RATIOB"] || $gigs < $site_config["GIGSB"]) $wait = $site_config["WAITB"];
        elseif ($ratio < $site_config["RATIOC"] || $gigs < $site_config["GIGSC"]) $wait = $site_config["WAITC"];
        elseif ($ratio < $site_config["RATIOD"] || $gigs < $site_config["GIGSD"]) $wait = $site_config["WAITD"];
        else $wait = 0;
    }

    // Columns
    $cols = explode(",", $site_config["torrenttable_columns"]);
    $cols = array_map("strtolower", $cols);
    $cols = array_map("trim", $cols);
    $colspan = count($cols);
    // End
    
    // Expanding Area
    $expandrows = array();
    if (!empty($site_config["torrenttable_expand"])) {
        $expandrows = explode(",", $site_config["torrenttable_expand"]);
        $expandrows = array_map("strtolower", $expandrows);
        $expandrows = array_map("trim", $expandrows);
    }
    // End
    
    echo '<table align="center" class="ttable_headinner" width="100%"><thead><tr class="ttable_head">';

    foreach ($cols as $col) {
        switch ($col) {
            case 'category':
                echo "<th>".T_("CATEGORY")."</th>";
            break;
            case 'name':
                echo "<th>".T_("NAME")."</th>";
            break;
            case 'dl':
                echo "<th>".T_("DOWNLOAD")."</th>";
            break;
            case 'uploader':
                echo "<th>".T_("UPLOADER")."</th>";
            break;
            case 'comments':
                echo "<th>".T_("COMMENTS")."</th>";
            break;
            case 'nfo':
                echo "<th>".T_("NFO")."</th>";
            break;
            case 'size':
                echo "<th>".T_("SIZE")."</th>";
            break;
            case 'completed':
                echo "<th>".T_("C")."</th>";
            break;
            case 'seeders':
                echo "<th>".T_("SEEDER")."</th>";
            break;
            case 'leechers':
                echo "<th>".T_("LEECHER")."</th>";
            break;
            case 'health':
                echo "<th>".T_("HEALTH")."</th>";
            break;
            case 'external':
                if ($site_config["ALLOWEXTERNAL"])
                    echo "<th>".T_("L/E")."</th>";
            break;
            case 'added':
                echo "<th>".T_("ADDED")."</th>";
            break;
            case 'speed':
                echo "<th>".T_("SPEED")."</th>";
            break;
            case 'wait':
                if ($wait)
                    echo "<th>".T_("WAIT")."</th>";
            break;
            case 'rating':
                echo "<th>".T_("RATINGS")."</th>";
            break;
        }
    }
    if ($wait && !in_array("wait", $cols))
        echo "<th>".T_("WAIT")."</th>";
    
    echo "</tr></thead>";

    while ($row = mysqli_fetch_assoc($res)) {
        $id = $row["id"];

        print("<tr class='t-row'>\n");

    $x = 1;

    foreach ($cols as $col) {
        switch ($col) {
            case 'category':
                print("<td class='ttable_col$x' align='center' valign='middle'>");
                if (!empty($row["cat_name"])) {
                    print("<a href=\"torrents.php?cat=" . $row["category"] . "\">");
                    if (!empty($row["cat_pic"]) && $row["cat_pic"] != "")
                        print("<img border=\"0\"src=\"" . $site_config['SITEURL'] . "/images/categories/" . $row["cat_pic"] . "\" alt=\"" . $row["cat_name"] . "\" />");
                    else
                        print($row["cat_parent"].": ".$row["cat_name"]);
                    print("</a>");
                } else
                    print("-");
                print("</td>\n");
            break;
            case 'name':
            $char1 = 100; //cut name length 
            $smallname = htmlspecialchars(CutName($row["name"], $char1));
            $dispname = "<b>" . $smallname . "</b>";
            $descrs = $row["description"];
            $last_access = $CURUSER["last_browse"];
            $time_now = gmtime();
            if ($last_access > $time_now || !is_numeric($last_access))
                $last_access = $time_now;
            if (sql_timestamp_to_unix_timestamp($row["added"]) >= $last_access)
                $dispname .= "<b><font color='#ff0000'> - (" . T_("NEW") . "!)</font></b>";
            if ($row["freeleech"] == 1)
                $dispname .= " <img src='images/free.gif' border='0' alt='' />";
            if ($row["team"] != 0)
                $dispname .= " <img src='" . $row["teamimage"] . "' border='0' alt = '" . $row["teamname"] . "'>";
            if ($CURUSER) {
                $bookt = SQL_Query_exec("SELECT torrentid FROM bookmarks WHERE torrentid = $id AND userid = $CURUSER[id]");
                if (mysqli_num_rows($bookt) > 0) {
                    $bookmark = "<a href='/bookmark.php'><img src='../images/booked.png' width='25' height='25' border='0' title='Bookmarked'></a>";
                } else {
                    $bookmark = "<a href='/bookmarks.php?torrent=$id'><img src='../images/book.png' width='25' height='25' border='0' title='Bookmark this Torrent'></a>";
                }
                print("<td class='ttable_col$x' nowrap='nowrap'>" . (count($expandrows) ? "<a href=\"javascript: klappe_torrent('t" . $row['id'] . "')\"><img border=\"0\" src=\"" . $site_config["SITEURL"] . "/images/plus.gif\" id=\"pict" . $row['id'] . "\" alt=\"Show/Hide\" class=\"showthecross\" /></a>" : "") . "&nbsp;$bookmark <a title=\"" . $row["name"] . "\" href=\"/torrents-details.php?id=$id&amp;hit=1\">$dispname</a> $freeleech $new</td>");
            } else {
                print("<td class='ttable_col$x' nowrap='nowrap'>" . (count($expandrows) ? "<a href=\"javascript: klappe_torrent('t" . $row['id'] . "')\"><img border=\"0\" src=\"" . $site_config["SITEURL"] . "/images/plus.gif\" id=\"pict" . $row['id'] . "\" alt=\"Show/Hide\" class=\"showthecross\" /></a>" : "") . "&nbsp;<a title=\"" . $row["name"] . "\" href=\"/torrents-details.php?id=$id&amp;hit=1\">$dispname</a><br>$descrs</td>");
            }
            break;

            case 'dl':
                print("<td class='ttable_col$x' align='center'><a href=\"download.php?id=$id&amp;name=" . rawurlencode($row["filename"]) . "\"><img src='../images/torrent.png' width='25' height='25' border='0' title='Torrent'></a></td>");
            break;
            case 'uploader':
                echo "<td class='ttable_col$x' align='center'>";
                if (($row["anon"] == "yes" || $row["privacy"] == "strong") && $CURUSER["id"] != $row["owner"] && $CURUSER["edit_torrents"] != "yes")
                    echo "Anonymous";
                elseif ($row["username"])
                    echo "<a href='account-details.php?id=$row[owner]'>$row[username]</a>";
                else
                    echo "Unknown";
                echo "</td>";
            break;
            case 'comments':
                print("<td class='ttable_col$x' align='center'> <a href=/comments.php?type=torrent&amp;id=$id'><img src='" . $site_config['SITEURL'] . "/images/comment.png' width='25' height='25'/><font color='#A30000'><b>" . number_format($row["comments"]) . "</b></a></font></td>\n");
            break;
            case 'nfo':
                if ($row["nfo"] == "yes")
                    print("<td class='ttable_col$x' align='center'><a href='nfo-view.php?id=$row[id]'><img src='" . $site_config['SITEURL'] . "/images/icon_nfo.gif' border='0' alt='View NFO' /></a></td>");
                else
                    print("<td class='ttable_col$x' align='center'>-</td>");
            break;
            case 'size':
                print("<td class='ttable_col$x' align='center'>".mksize($row["size"])."</td>\n");
            break;
            case 'completed':    
                print("<td class='ttable_col$x' align='center'><font color='orange'><b>".number_format($row["times_completed"])."</b></font></td>");
            break;
            case 'seeders':
                print("<td class='ttable_col$x' align='center'><font color='green'><b>".number_format($row["seeders"])."</b></font></td>\n");
            break;
            case 'leechers':
                print("<td class='ttable_col$x' align='center'><font color='#ff0000'><b>" . number_format($row["leechers"]) . "</b></font></td>\n");
            break;
            case 'health':
                print("<td class='ttable_col$x' align='center'><img src='".$site_config["SITEURL"]."/images/health/health_".health($row["leechers"], $row["seeders"]).".gif' alt='' /></td>\n");
            break;
            case 'external':
                if ($site_config["ALLOWEXTERNAL"]){
                    if ($row["external"]=='yes')
                        print("<td class='ttable_col$x' align='center'>".T_("E")."</td>\n");
                    else
                        print("<td class='ttable_col$x' align='center'>".T_("L")."</td>\n");
                }
            break;
            case 'added':
                print("<td class='ttable_col$x' align='center'>".date("d-m-Y H:i:s", utc_to_tz_time($row['added']))."</td>");
            break;
            case 'speed':
                if ($row["external"] != "yes" && $row["leechers"] >= 1){
                    $speedQ = SQL_Query_exec("SELECT (SUM(downloaded)) / (UNIX_TIMESTAMP('".get_date_time()."') - UNIX_TIMESTAMP(started)) AS totalspeed FROM peers WHERE seeder = 'no' AND torrent = '$id' ORDER BY started ASC");
                    $a = mysqli_fetch_assoc($speedQ);
                    $totalspeed = mksize($a["totalspeed"]) . "/s";
                } else
                    $totalspeed = "--";
            print("<td class='ttable_col$x' align='center'>$totalspeed</td>");
            break;
            case 'wait':
                if ($wait){
                    $elapsed = floor((gmtime() - strtotime($row["added"])) / 3600);
                    if ($elapsed < $wait && $row["external"] != "yes") {
                        $color = dechex(floor(127*($wait - $elapsed)/48 + 128)*65536);
                        print("<td class='ttable_col$x' align='center'><a href=\"faq.php#section46\"><font color=\"$color\">" . number_format($wait - $elapsed) . " h</font></a></td>\n");
                    } else
                        print("<td class='ttable_col$x' align='center'>--</td>\n");
                }
            break;
            case 'rating':
                if (!$row["rating"])
                    $rating = "--";
                else
                    $rating = "<a title='$row[rating]/5'>".ratingpic($row["rating"])."</a>";
                    //$rating = ratingpic($row["rating"]);
                     //$srating .= "$rpic (" . $row["rating"] . " out of 5) " . $row["numratings"] . " users have rated this torrent";
                print("<td class='ttable_col$x' align='center'>$rating</td>");
            break;
        }
        if ($x == 2)
            $x--;
        else
            $x++;
    }

    
        //Wait Time Check
        if ($wait && !in_array("wait", $cols)) {
            $elapsed = floor((gmtime() - strtotime($row["added"])) / 3600);
            if ($elapsed < $wait && $row["external"] != "yes") {
                $color = dechex(floor(127*($wait - $elapsed)/48 + 128)*65536);
                print("<td class='ttable_col$x' align='center'><a href=\"faq.php\"><font color=\"$color\">" . number_format($wait - $elapsed) . " h</font></a></td>\n");
            } else
                print("<td class='ttable_col$x' align='center'>--</td>\n");
            $colspan++;
            if ($x == 2)
                $x--;
            else
                $x++;
        }
        
        print("</tr>\n");

        //Expanding area
        if (count($expandrows)) {
            print("<tr class='t-row'><td class='ttable_col$x' colspan='$colspan'><div id=\"kt".$row['id']."\" style=\"margin-left: 2px; display: none;\">");
            print("<table width='100%' border='0' cellspacing='0' cellpadding='0'>");
            foreach ($expandrows as $expandrow) {
                switch ($expandrow) {
                    case 'size':
                        print("<tr><td><b>".T_("SIZE")."</b>: ".mksize($row['size'])."</td></tr>");
                    break;
                    case 'speed':
                        if ($row["external"] != "yes" && $row["leechers"] >= 1){
                            $speedQ = SQL_Query_exec("SELECT (SUM(downloaded)) / (UNIX_TIMESTAMP('".get_date_time()."') - UNIX_TIMESTAMP(started)) AS totalspeed FROM peers WHERE seeder = 'no' AND torrent = '$id' ORDER BY started ASC");
                            $a = mysqli_fetch_assoc($speedQ);
                            $totalspeed = mksize($a["totalspeed"]) . "/s";
                            print("<tr><td><b>".T_("SPEED").":</b> $totalspeed</td></tr>");
                        }
                    break;
                    case 'added':
                        print("<tr><td><b>".T_("ADDED").":</b> ".date("d-m-Y \\a\\t H:i:s", utc_to_tz_time($row['added']))."</td></tr>");
                    break;
                    case 'tracker':
                    if ($row["external"] == "yes")
                        print("<tr><td><b>".T_("TRACKER").":</b> ".htmlspecialchars($row["announce"])."</td></tr>");
                    break;
                    case 'completed':
                        print("<tr><td><b>".T_("COMPLETED")."</b>: ".number_format($row['times_completed'])."</td></tr>");
                    break;
                }
            }
                print("</table></div></td></tr>\n");
        }
        //End Expanding Area


    }

    print("</table><br />\n");

}

function pager($rpp, $count, $href, $opts = array()) {
    $pages = ceil($count / $rpp);

    if (!$opts["lastpagedefault"])
        $pagedefault = 0;
    else {
        $pagedefault = floor(($count - 1) / $rpp);
        if ($pagedefault < 0)
            $pagedefault = 0;
    }

    if (isset($_GET["page"])) {
        $page = (int) $_GET["page"];
        if ($page < 0)
            $page = $pagedefault;
    }
    else
        $page = $pagedefault;

    $pager = "";

    $mp = $pages - 1;
    $as = "<b>&lt;&lt;&nbsp;".T_("PREVIOUS")."</b>";
    if ($page >= 1) {
        $pager .= "<a href=\"{$href}page=" . ($page - 1) . "\">";
        $pager .= $as;
        $pager .= "</a>";
    }
    else
        $pager .= $as;
    $pager .= "&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;";
    $as = "<b>".T_("NEXT")."&nbsp;&gt;&gt;</b>";
    if ($page < $mp && $mp >= 0) {
        $pager .= "<a href=\"{$href}page=" . ($page + 1) . "\">";
        $pager .= $as;
        $pager .= "</a>";
    }
    else
        $pager .= $as;

    if ($count) {
        $pagerarr = array();
        $dotted = 0;
        $dotspace = 3;
        $dotend = $pages - $dotspace;
        $curdotend = $page - $dotspace;
        $curdotstart = $page + $dotspace;
        for ($i = 0; $i < $pages; $i++) {
            if (($i >= $dotspace && $i <= $curdotend) || ($i >= $curdotstart && $i < $dotend)) {
                if (!$dotted)
                    $pagerarr[] = "...";
                $dotted = 1;
                continue;
            }
            $dotted = 0;
            $start = $i * $rpp + 1;
            $end = $start + $rpp - 1;
            if ($end > $count)
                $end = $count;
            $text = "$start&nbsp;-&nbsp;$end";
            if ($i != $page)
                $pagerarr[] = "<a href=\"{$href}page=$i\"><b>$text</b></a>";
            else
                $pagerarr[] = "<b>$text</b>";
        }
        $pagerstr = join(" | ", $pagerarr);
        $pagertop = "<p align=\"center\">$pager<br />$pagerstr</p>\n";
        $pagerbottom = "<p align=\"center\">$pagerstr<br />$pager</p>\n";
    }
    else {
        $pagertop = "<p align=\"center\">$pager</p>\n";
        $pagerbottom = $pagertop;
    }

    $start = $page * $rpp;

    return array($pagertop, $pagerbottom, "LIMIT $start,$rpp");
}

function commenttable($res, $type = null) {
    global $site_config, $CURUSER, $THEME, $LANGUAGE;  //Define globals

    while ($row = mysqli_fetch_assoc($res)) {

        $postername = htmlspecialchars($row["username"]);
        if ($postername == "") {
            $postername = T_("DELUSER");
            $avatar = "";
            $userid = "";
            $color = "#000";
        }else {
            $avatar = htmlspecialchars($row["avatar"]);
            $ires = SQL_Query_exec("SELECT id, username FROM users WHERE username='$row[username]'");
            $iarr = mysqli_fetch_assoc($ires);
            $userid = $iarr["id"];
            $cres = SQL_Query_exec("SELECT Color FROM groups WHERE group_id='$row[class]'");
            $carr = mysqli_fetch_assoc($cres);
            $color = $carr["Color"];
        }

        if ($row["gender"] == "m")
            $gender = "Male";
        elseif ($user["gender"] == "f")
            $gender = "Female";
        else
            $gender = "Unspecified";

        if (!$avatar) {
            if ($gender == "Male")
                $avatar = "/themes/default/avatars/avatar_m_m.png";
            elseif ($gender == "Female")
                $avatar = "/themes/default/avatars/avatar_f_m.png";
            else
                $avatar = "/themes/default/avatars/avatar_m.png";
        }

        $commenttext = format_comment($row["text"]);
   
        $edit = null;
        if ($type == "torrent" && $CURUSER["edit_torrents"] == "yes" || $type == "news" && $CURUSER["edit_news"] == "yes" || $CURUSER['id'] == $row['user']) 
            $edit = '[<a href="comments.php?id='.$row["id"].'&amp;type='.$type.'&amp;edit=1">Edit</a>]&nbsp;';
       
        $delete = null;
        if ($type == "torrent" && $CURUSER["delete_torrents"] == "yes" || $type == "news" && $CURUSER["delete_news"] == "yes")
            $delete = '[<a href="comments.php?id='.$row["id"].'&amp;type='.$type.'&amp;delete=1">Delete</a>]&nbsp;';

        echo '<div id="Commentsbox_2" class="comments">
	        <div class="cAvatar"><img src="'.$avatar.'" alt="" title="" class="clickable avatar" id="member_info" memberid="'.$userid.'" width="48" height="48" /></div>
	
        	<div class="commentHolder">
		        <div id="posted_message_'.$row["id"].'" class="cMessage">'.$commenttext.'</div>
		
        		<div class="commentDate">
		        	<div class="cLinks">
				        <span id="LikeLink_'.$row["id"].'_profile_comments"><span class="clickable small" id="like" content_id="2" content_memberid="'.$userid.'" content_type="profile_comments" extra="1"><img src="http://templateshares-ue.net/tsue/styles/default/buttons/button_like.png" alt="Like" title="Like" border="0" class="button_images" /></span></span>
				        <span id="reply_comment" rel="'.$row["id"].'" content_type="profile_comments" content_id="1"><img src="http://templateshares-ue.net/tsue/styles/default/buttons/reply_add.png" alt="Reply" title="Reply" border="0" class="button_images clickable" /></span>
				        <span class="clickable" id="report_content" content_type="profile_comments" content_id="'.$row["id"].'"><img src="http://templateshares-ue.net/tsue/styles/default/buttons/report.png" alt="Report" title="Report" class="button_images" /></span>
			        </div>
			        <span id="member_info" memberid="'.$userid.'" class="clickable"><span style="color: '.$color.'">'.$postername.'</span></span> '.date("d-m-Y H:i:s", utc_to_tz_time($row["added"])).'
		        </div>

        		<div class="clear"></div>
		        <div id="like_list_holder_'.$row["id"].'_profile_comments" class="small">
				
			    </div>
		
        		<div id="posted_reply_message_holder_2"></div>
        	</div>

        	<div class="clear"></div>

        </div>

        <div class="clear"></div>

        <div id="reply_message_holder_2"></div>';

        //' . $edit . $delete . '[<a href="report.php?comment='.$row["id"].'">Report</a>] Posted: '.date("d-m-Y \\a\\t H:i:s", utc_to_tz_time($row["added"])).'<a id="comment'.$row["id"].'"></a></th>');
    }
}

function where ($scriptname = "index", $userid, $update=1){
    if (!is_valid_id($userid))
        die;
    if (preg_match("/torrents-details/i", $scriptname))
        $where = "Browsing Torrents Details (ID: $_GET[id])...";
    elseif (preg_match("/torrents.php/i", $scriptname))
        $where = "Browsing Torrents...";
    elseif (preg_match("/account-details/i", $scriptname))
        $where = "Browsing Account Details (ID: $_GET[id])...";
    elseif (preg_match("/torrents-upload/i", $scriptname))
        $where = "Uploading Torrent..";
    elseif (preg_match("/account/i", $scriptname))
        $where = "Browsing User Control Panel...";
    elseif (preg_match("/torrents-search/i", $scriptname))
        $where = "Searching Torrents...";
    elseif (preg_match("/forums/i", $scriptname))
        $where = "Browsing Forums...";
    elseif (preg_match("/index/i", $scriptname))
        $where = "Browsing Homepage...";
    else
        $where = "Unknown Location...";

    if ($update) {
        $query = sprintf("UPDATE users SET page=".sqlesc($where)." WHERE id ='%s'", ((isset($GLOBALS["___mysqli_ston"]) && is_object($GLOBALS["___mysqli_ston"])) ? mysqli_real_escape_string($GLOBALS["___mysqli_ston"], $userid) : ((trigger_error("[MySQLConverterToo] Fix the mysql_escape_string() call! This code does not work.", E_USER_ERROR)) ? "" : "")));
        $result = SQL_Query_exec($query);
    }
        return $where;
}

function get_user_class_name($i){
    GLOBAL $CURUSER;
    if ($i == $CURUSER["class"])
        return $CURUSER["level"];

    $res=SQL_Query_exec("SELECT level FROM groups WHERE group_id=".$i."");
    $row=mysqli_fetch_row($res);
    return $row[0];
}

function get_user_class(){
    return $GLOBALS["CURUSER"]["class"];
}

function get_ratio_color($ratio) {
    if ($ratio < 0.1) return "#ff0000";
    if ($ratio < 0.2) return "#ee0000";
    if ($ratio < 0.3) return "#dd0000";
    if ($ratio < 0.4) return "#cc0000";
    if ($ratio < 0.5) return "#bb0000";
    if ($ratio < 0.6) return "#aa0000";
    if ($ratio < 0.7) return "#990000";
    if ($ratio < 0.8) return "#880000";
    if ($ratio < 0.9) return "#770000";
    if ($ratio < 1) return "#660000";
    return "#000000";
}

function ratingpic($num) {
    GLOBAL $site_config;
    $r = round($num * 2) / 2;
    if ($r != $num) {
        $n = $num-$r;
        if ($n < .25)
            $n = 0;
        elseif ($n >= .25 && $n < .75)
            $n = .5;
        $r += $n;
    }
    if ($r < 1 || $r > 5)
        return;

    return "<img src=\"".$site_config["SITEURL"]."/images/rating/$r.png\" border=\"0\" alt=\"rating: $num/5\" title=\"rating: $num/5\" />";
}

function DateDiff ($start, $end) {
    if (!is_numeric($start))
        $start = sql_timestamp_to_unix_timestamp($start);
    if (!is_numeric($end))
        $end = sql_timestamp_to_unix_timestamp($end);
    return ($end - $start);
}

function classlist() {
    $ret = array();
    $res = SQL_Query_exec("SELECT * FROM groups ORDER BY group_id ASC");
    while ($row = mysqli_fetch_assoc($res))
        $ret[] = $row;
    return $ret;
}

function array_map_recursive ($callback, $array) {
    $ret = array();

    if (!is_array($array))
        return $callback($array);

    foreach ($array as $key => $val) {
        $ret[$key] = array_map_recursive($callback, $val);
    }
    return $ret;
}

function strtobytes ($str) {
    $str = trim($str);
    if (!preg_match('!^([\d\.]+)\s*(\w\w)?$!', $str, $matches))
        return 0;

    $num = $matches[1];
    $suffix = strtolower($matches[2]);
    switch ($suffix) {
        case "tb": // TeraByte
            return $num * 1099511627776;
        case "gb": // GigaByte
            return $num * 1073741824;
        case "mb": // MegaByte
            return $num * 1048576;
        case "kb": // KiloByte
            return $num * 1024;
        case "b": // Byte
        default:
            return $num;
    }
}

function cleanstr ($s) {
    if (function_exists("filter_var")) {
        return filter_var($s, FILTER_SANITIZE_STRING, FILTER_FLAG_STRIP_LOW);
    } else {
        return preg_replace('/[\x00-\x1F]/', "", $s);
    }
}

function is_ipv6 ($s) {
    return is_int(strpos($s, ":"));
}

// Taken from php.net comments
function ip2long6($ipv6) {
  $ip_n = inet_pton($ipv6);
  $bits = 15; // 16 x 8 bit = 128bit
  while ($bits >= 0) {
    $bin = sprintf("%08b",(ord($ip_n[$bits])));
    $ipv6long = $bin.$ipv6long;
    $bits--;
  }
  return gmp_strval(gmp_init($ipv6long,2),10);
}

function long2ip6($ipv6long) {

  $bin = gmp_strval(gmp_init($ipv6long,10),2);
  if (strlen($bin) < 128) {
    $pad = 128 - strlen($bin);
    for ($i = 1; $i <= $pad; $i++) {
    $bin = "0".$bin;
    }
  }
  $bits = 0;
  while ($bits <= 7) {
    $bin_part = substr($bin,($bits*16),16);
    $ipv6 .= dechex(bindec($bin_part)).":";
    $bits++;
  }
  // compress

  return inet_ntop(inet_pton(substr($ipv6,0,-1)));
} 

function passhash ($text) {
    GLOBAL $site_config;

    switch (strtolower($site_config["passhash_method"])) {
        case "sha1":
            return sha1($text);
        break;
        case "md5":
            return md5($text);
        break;
        case "hmac":
            $ret = hash_hmac($site_config["passhash_algorithm"], $text, $site_config["passhash_salt"]);
            return $ret ? $ret : die("Config Error: Unknown algorithm '$site_config[passhash_algorithm]'");
        break;
        default:
            die("Unrecognised passhash_method. Check your config.");
    }
}

  function autolink($al_url, $al_msg) {
      stdhead();
      begin_frame("");
      echo "\n<meta http-equiv=\"refresh\" content=\"3; url=$al_url\">\n";
      echo "<b>$al_msg</b>\n";
      echo "\n<b>Redirecting ...</b>\n";
      echo "\n[ <a href='$al_url'>link</a> ]\n";
      end_frame();
      stdfoot();
      exit;
  }
 // Max Slots
function avail_slots($userid, $class)
{
    $queries = SQL_Query_exec("SELECT `maxslots` FROM `groups` WHERE `group_id` = $class");
    $row = mysqli_fetch_array($queries);
    $res_user = SQL_Query_exec("SELECT id, warned FROM users WHERE id = $userid");
    $row_user = mysqli_fetch_array($res_user);
    if ($row_user['warned'] == 'yes') {
        $maxslot = 1;
    } else {
        $maxslot = $row[0];
    }
    return $maxslot;
}
///////////

function class_user($name)
{
  global $site_config;
  $a = "SELECT u.class, u.donated, u.warned, g.Color, g.level, u.uploaded, u.downloaded FROM `users` `u` INNER JOIN `groups` `g` ON g.group_id=u.class WHERE username ='" . $name . "'";
  //  echo "Query a".$a."<br />";
  $res = SQL_Query_exec($a);
  $classy = @mysqli_fetch_array($res);
  $gcolor = $classy['Color'];
  if ($classy['donated'] > 0) {
  $star = "<img src='" . $site_config[SITEURL] . "/images/icons/star.gif' alt='donated' border='0'>";
  } else {
  $star = "";
  }
  if ($classy['warned'] == "yes") {
  $warn = "<img src='" . $site_config[SITEURL] . "/images/icons/warn.gif' alt='warned' border='0'>";
  } else {
  $warn = "";
  }
  $rang = auto_rank_icon($classy['uploaded'], $classy['downloaded'], $classy['level']);
  return unesc("<font color='" . $gcolor . "'>" . $name . "" . $star . "" . $warn . "" . $rang . "</font>");
}

/////////////////////////////////// Auto Uploader rank function (Author: ArcticWolf)///////////////////////////////////////////
function auto_rank_icon($uploaded, $downloaded, $level)
{
  $tb = 1024 * 1024 * 1024 * 1024;
  // Config
  $minup0 = .5 * $tb;
  $minup1 = 1 * $tb;
  $minup2 = 2 * $tb;
  $minup3 = 3.5 * $tb;
  $minup4 = 5.5 * $tb;
  $minup5 = 8 * $tb;
  $minup6 = 15 * $tb;
  // Config End
  $icon1 = "legend_stjarna_brons.png";
  $icon2 = "legend_stjarna_silver.png";
  $icon3 = "legend_stjarna_guld.png";
  $icon4 = "krona_brons.png";
  $icon5 = "krona_silver.png";
  $icon6 = "krona_guld.png";
  $icon7 = "diamant.png";

  if ($uploaded >= $minup6 && $uploaded >= $downloaded) {
  $uprankr = "<img src=\"images/icons/" . $icon7 . "\" alt='$icon7' border=\"0\"/>";
  return $uprankr;
  } elseif ($uploaded >= $minup5 && $uploaded < $minup6 && $uploaded >= $downloaded) {
  $uprankr = "<img src=\"images/icons/" . $icon6 . "\" alt='$icon6' border=\"0\"/>";
  return $uprankr;
  } elseif ($uploaded >= $minup4 && $uploaded < $minup5 && $uploaded >= $downloaded) {
  $uprankr = "<img src=\"images/icons/" . $icon5 . "\" alt='$icon5' border=\"0\"/>";
  return $uprankr;
  } elseif ($uploaded >= $minup3 && $uploaded < $minup4 && $uploaded >= $downloaded) {
  $uprankr = "<img src=\"images/icons/" . $icon4 . "\" alt='$icon4' border=\"0\"/>";
  return $uprankr;
  } elseif ($uploaded >= $minup2 && $uploaded < $minup3 && $uploaded >= $downloaded) {
  $uprankr = "<img src=\"images/icons/" . $icon3 . "\" alt='$icon3' border=\"0\"/>";
  return $uprankr;
  } elseif ($uploaded >= $minup1 && $uploaded < $minup2 && $uploaded >= $downloaded) {
  $uprankr = "<img src=\"images/icons/" . $icon2 . "\" alt='$icon2' border=\"0\"/>";
  return $uprankr;
  } elseif ($uploaded >= $minup0 && $uploaded >= $downloaded) {
  $uprankr = "<img src=\"images/icons/" . $icon1 . "\" alt='$icon1' border=\"0\"/>";
  return $uprankr;
  }
  return "";
}

// http://stackoverflow.com/questions/2089590/mysqli-equivalent-of-mysql-result
// MySQLi RESULT
function mysqli_result($result,$row,$field=0) {
    if ($result===false) return false;
    if ($row>=mysqli_num_rows($result)) return false;
    if (is_string($field) && !(strpos($field,".")===false)) {
        $t_field=explode(".",$field);
        $field=-1;
        $t_fields=mysqli_fetch_fields($result);
        for ($id=0;$id<mysqli_num_fields($result);$id++) {
            if ($t_fields[$id]->table==$t_field[0] && $t_fields[$id]->name==$t_field[1]) {
                $field=$id;
                break;
            }
        }
        if ($field==-1) return false;
    }
    mysqli_data_seek($result,$row);
    $line=mysqli_fetch_array($result);
    return isset($line[$field])?$line[$field]:false;
}

 function __autoload( $f )
 {
     include("library/$f.php");
 }

function retour($url, $msg, $temps)
{
    stdhead(Redirection);
    begin_frame(Redirection);
    echo "\n<meta http-equiv=\"refresh\" content=\"$temps; url=$url\">\n";
    echo "<center><font color='red'>$msg</font></b><br/>\n";
    echo "\n<b>Redirection ...</b>\n";
    echo "\n[ <a href='$url'>cliquez ici si vous ne voulez pas attendre</a> ]\n</center>";
    end_frame();
    stdfoot();
    exit;
}

function oui_non($s){
    $s = preg_replace("/yes/","<font color='#2E8B57'>Oui</font>", $s);
    $s = preg_replace("/no/","<font color='#ff0000'>Non</font>", $s);
    return $s;
}

// Return age from date 1970/01/01
function Age($date){
    $dna = strtotime($date);
    $now = time();

    $age = date('Y',$now)-date('Y',$dna);
    if(strcmp(date('md', $dna),date('md', $now))>0) $age--;

    return $age;
}

function verifyatbf($username, $password){
    GLOBAL $site_config;
    $try = $site_config['atbf_trials'];
    $dayslock = $site_config['atbf_days_lock'];
    $monthslock = $site_config['atbf_months_lock'];
    $yearslock = $site_config['atbf_years_lock'];
    $password = passhash($password);
    $res2 = SQL_Query_exec("SELECT password, atbf_date, atbf_date_locked, atbf_trials, atbf_islocked FROM users WHERE username = $username");
    $row2 = mysqli_fetch_assoc($res2);
    $takedate = $row2['atbf_date'];
    $takepass = $row2['password'];
    $taketry = $row2['atbf_trials'];
    $date = date("Y-m-d"); // today datetime
    $d1 = new DateTime($date);// Hash date to accepted format
    $d2 = new DateTime($takedate); // Hash date to accepted format
    if ($takedate != "0000-00-00") { // If db date is different than this
        if($d1 >= $d2){ // If actual date is bigger or equal to db date (Actual date is defined by your php.ini file => DEFAULT.TIMEZONE )
            if($takepass != $password){ // If db pass is different from post pass
                if($try == 1) { //If Trials config = 1 trial
                    SQL_Query_exec("UPDATE users SET atbf_trials = '1', atbf_date_locked = NOW(), atbf_date = NOW() + INTERVAL $dayslock DAY + INTERVAL $monthslock MONTH + INTERVAL $yearslock YEAR, atbf_islocked = '1'  WHERE username = $username");
                    $message = T_("Account locked now for ".$dayslock." day(s)".$monthslock." month(s)".$yearslock." year(s)");
                }else{
                    SQL_Query_exec("UPDATE users SET atbf_date = '0000-00-00', atbf_date_locked = '0000-00-00', atbf_trials = '1', atbf_islocked = '0' WHERE username = $username");// Update DB
                    $message = T_("LOGIN_INCORRECT");//Display warning
                }
            } else {
                SQL_Query_exec("UPDATE users SET atbf_date = '0000-00-00', atbf_date_locked = '0000-00-00', atbf_trials = '0', atbf_islocked = '0' WHERE username = $username"); // Reset data
                $message = ''; // And Quit
            }
        }else{
            $message = T_("Your account is locked until ".$takedate.""); // Display warning message
        }
        return $message;
    }
}

function addatbf($username){
    GLOBAL $site_config;
    $try = $site_config['atbf_trials'] - 1;
    $dayslock = $site_config['atbf_days_lock'];
    $monthslock = $site_config['atbf_months_lock'];
    $yearslock = $site_config['atbf_years_lock'];
    $res1 = SQL_Query_exec("SELECT atbf_date, atbf_date_locked, atbf_trials, atbf_islocked FROM users WHERE username = $username");
    $row1 = mysqli_fetch_assoc($res1);
    $updatetry = $row1['atbf_trials'] + 1;
    $taketry = $row1['atbf_trials'];
    $takedate = $row1['atbf_date'];
    $takelockeddate = $row1['atbf_date_locked'];
    $islocked = $row1['atbf_islocked'];
    if ($islocked == 0){
        if ($taketry >= $try){
            if ($takedate == "0000-00-00") {
                SQL_Query_exec("UPDATE users SET atbf_trials = $updatetry, atbf_date_locked = NOW(), atbf_date = NOW() + INTERVAL $dayslock DAY + INTERVAL $monthslock MONTH + INTERVAL $yearslock YEAR, atbf_islocked = '1'  WHERE username = $username");
                $message = T_("Account locked now for ".$dayslock." day(s)".$monthslock." month(s)".$yearslock." year(s)");
            }else{
                $message = T_("Your account is locked until ".$takedate."");
            }
        }else{
            SQL_Query_exec("UPDATE users SET atbf_trials = $updatetry WHERE username = $username");
            $message = T_("LOGIN_INCORRECT");
        }
        return $message;
    }
}

function resetantibruteforce($userid) { //Admin reset
    SQL_Query_exec("UPDATE users SET atbf_date = '0000-00-00', atbf_date_locked = '0000-00-00', atbf_trials = '0', atbf_islocked = '0' WHERE id = $userid"); // Reset data
}
?> 
