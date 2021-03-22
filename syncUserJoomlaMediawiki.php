<?php
#
# Transfer Joomla user accounts into mediawiki. This script takes all
# accounts and password from a Joomla installation and creates the
# identical users (and passwords) on the mediawiki side.  This can be
# used to allow Joomla users to use a mediawiki with identical
# credentials.
# 
# (Copyright (C) 2022, Dr. Tilmann Bubeck, tilmann@bubecks.de
#
#
#    This program is free software: you can redistribute it and/or modify
#    it under the terms of the GNU General Public License as published by
#    the Free Software Foundation, either version 3 of the License, or
#    (at your option) any later version.
#
#    This program is distributed in the hope that it will be useful,
#    but WITHOUT ANY WARRANTY; without even the implied warranty of
#    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
#    GNU General Public License for more details.
#
#    You should have received a copy of the GNU General Public License
#    along with this program.  If not, see <https://www.gnu.org/licenses/>.


## Transfer joomla password into mediawiki password.
#
# Joomla always uses bcrypt hash function inside PHP's password_hash
# to generate the hashed password.  Mediawiki is able to handle
# multiple hash functions and therefore needs to know, which hash
# function is used for the given hash value. This is done with the
# ":algo:" prefix. By using ":bcrypt:" mediawiki will use also use
# bcrypt to hash the value. This means, that we can transfer the hash
# value without knowing the real password.
#
# Another (small) difference is, that Joomla concatenates salt and
# hash without a delimiter and mediawiki uses "$" as a delimiter
# between salt and hash.
#
# @param joomla password hash
#
# @return mediawiki password hash
#
function transfer_j_pw_to_mw($j_password) {
    if (substr($j_password, 0, 4) == '$2y$') {
        list($nothing, $algo, $iter, $salt_and_pw) = explode("$", $j_password);
        $salt = substr($salt_and_pw, 0, 22);
        $hash = substr($salt_and_pw, 22);
        $mw_password = ":bcrypt:" . $iter . "$" . $salt . "$" . $hash;
    } else {
        print "Unknown password hash found: $j_password\n";
        exit(1);
    }
    return $mw_password;
}    

## Create a new user in mediawiki with the given username and password.
#
# @param username the name of the user in mediawiki database
# @param password the password to set
#
function create_mw_user($username, $password) {
    global $dry_run;
    
    verbose("Creating mediawiki user $username and password $password");
    $cmd = "php " . MEDIAWIKI_PATH . "/maintenance/createAndPromote.php --conf " . MEDIAWIKI_PATH . "/LocalSettings.php $username $password";
    verbose($cmd);
    if ( ! $dry_run ) {
        system($cmd, $ret);
        ($ret == 0) or die("returned an error $ret: $cmd");
    }
}    

## Update user in mediawiki with the given username and password.
#
# @param username the user to update the password
# @param password the new password hash to setAttribute#
function update_mw_password($username, $password) {
    global $conn;
    global $dry_run;
    
    verbose("Updating mediawiki user $username to password $password");
    if ( ! $dry_run ) {
        $stmt = $conn->prepare("UPDATE user SET user_password=? WHERE user_name=?");
        $stmt->execute([$password, $username]);
    }
}    

function verbose($string) {
    global $options;
    
    if (array_key_exists("v", $options)) {
        print $string . "\n";
    }
}

$options = getopt("vj:m:x:hk");

if (array_key_exists("h", $options)) {
    print <<<EOT
Transfer user accounts and passwords from Joomla CMS to mediawiki installation.
This can be used to keep accounts and passwords in sync between Joomla and mediawiki.

  -j joomla base directory (containing configuration.php)
  -m mediawiki base directory (containing LocalSettings.php)
  -v verbose 
  -h help
  -x exclude-user
  -k dry-run
  
The option "-x" can be given multiple times to specify Joomla user names, which should not be transferred. This is e.g. typically admin.

EOT;
    exit(0);
}
        
$dry_run = array_key_exists("k", $options);

$skip_users_joomla = array();
if (array_key_exists("x", $options)) {
    if (is_array($options["x"])) {
        $skip_users_joomla = $options["x"];
    } else {
        array_push($skip_users_joomla, $options["x"]);
    }
}

if (array_key_exists("j", $options)) {
    define("JOOMLA_PATH", $options["j"]);
    if (!file_exists(JOOMLA_PATH . '/configuration.php')) {
        print "Unable to find Joomla configuration.php under " . JOOMLA_PATH . "\n";
        exit(1);
    }
} else {
  print "Please use option -j to give directory of joomla containing configuration.php\n";
  exit(1);
}

if (array_key_exists("m", $options)) {
    define("MEDIAWIKI_PATH", $options["m"]);
    if (!file_exists(MEDIAWIKI_PATH . '/LocalSettings.php')) {
        print "Unable to find mediawiki LocalSettings.php under " . MEDIAWIKI_PATH . "\n";
        exit(1);
    }
} else {
  print "Please use option -m to give directory of mediawiki containing LocalSettings.php\n";
  exit(1);
}

require_once JOOMLA_PATH . '/configuration.php';

# These defines are necessary to correctly read LocalSettings.php
define("MEDIAWIKI", 1);
define("CACHE_DB", "cache_db");
define("CACHE_NONE", "cache_none");

require_once MEDIAWIKI_PATH . '/LocalSettings.php';

$conf = new JConfig;   # Joomla configuration

# [1] Connect to Joomla database
switch($conf->dbtype) {
    case "mysqli":
        $conn = new PDO("mysql:host=$conf->host;dbname=$conf->db", $conf->user, $conf->password);
        break;
    default:
        print "Unknown database type $conf->dbtype\n";
        exit(1);
}

$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

# [2] get all user names and passwords from Joomla
$stmt = $conn->prepare("SELECT username, password FROM {$conf->dbprefix}users");
$stmt->execute();
$result = $stmt->fetchAll(PDO::FETCH_ASSOC);

# [3] Remove unwanted user accounts from command line option "x"
$joomla_users = array();
foreach ($result as $user) {
	if ( !in_array($user["username"], $skip_users_joomla) ) {
	     array_push ($joomla_users, $user); 
	} else {
        verbose("Skipping Joomla user {$user["username"]}");
	}
}
$conn = null;

# [4] Connect to mediawiki database
switch($wgDBtype) {
case "sqlite":
    $conn = new PDO("$wgDBtype:$wgSQLiteDataDir/$wgDBname.sqlite");
    break;
case "mysql":
    $conn = new PDO("$wgDBtype:host=$wgDBserver;dbname=$wgDBname", $wgDBuser, $wgDBpassword);
    break;
default:
    print "Unknown database type $wgDBtype\n";
    exit(1);
}

$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

# [5] get all user names and passwords from mediawiki
$stmt = $conn->prepare("SELECT user_name, user_password FROM user");
$stmt->execute();
$mw_users = $stmt->fetchAll(PDO::FETCH_ASSOC);

# [6] create all missing users in mediawiki
foreach ($joomla_users as $j_user) {
    $user_found = 0;
    foreach ($mw_users as $mw_user) {
        if (strcasecmp($j_user["username"],$mw_user["user_name"]) == 0) {
            $user_found = 1;
            break;
        }
    }
    if ($user_found == 0) {
        $pwd = bin2hex(openssl_random_pseudo_bytes(5));    # initial random password 5*2 chars long
        create_mw_user($j_user["username"], $pwd);
        $mw_user["user_name"] = $j_user["username"];
        $mw_user["password"] = "will-be-synced";
        array_push($mw_users, $mw_user);
    }
}

# [7] synchronize joomla password to mediawiki password
foreach ($joomla_users as $j_user) {
    $user_found = 0;
    foreach ($mw_users as $mw_user) {
        if (strcasecmp($j_user["username"],$mw_user["user_name"]) == 0) {
            $mw_pw = transfer_j_pw_to_mw($j_user["password"]);
            if ($mw_pw != $mw_user["user_password"]) {
                verbose("Joomla user {$j_user["username"]} password changed to         {$j_user["password"]}");
                $mw_user["user_password"] = $mw_pw;
                update_mw_password($mw_user["user_name"], $mw_user["user_password"]);
            } else {
                verbose("Joomla user {$j_user["username"]} and mediawiki user {$mw_user["user_name"]} in sync.");
            }
        }
    }
}

function wfLoadSkin($name) {
  # This function must be defined for requiring LocalSettings.php to work
  # do nothing
}

function wfLoadExtension($name) {
  # This function must be defined for requiring LocalSettings.php to work
  # do nothing
}

?>
