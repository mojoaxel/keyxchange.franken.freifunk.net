<?php
/**
 * Handle VPN-Key-Exchange request for Freifunk-Franken.
 *
 * @author RedDog <reddog@mastersword.de>
 * @author delphiN <freifunk@wunschik.net>
 *
 * @license https://www.gnu.org/licenses/agpl-3.0.txt AGPL-3.0
 */

$DEFAULT_HOOD_ID = 1;
$INVALID_MAC = '000000000000';

class db {
	private static $instance = NULL;
	private function __construct() {
	}
	public static function getInstance() {
		if (! self::$instance) {
			require ("config.inc.php");
			self::$instance = new PDO("mysql:host=$mysql_server;dbname=$mysql_db", $mysql_user, $mysql_pass);
			self::$instance->setAttribute ( PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION );
		}
		return self::$instance;
	}
	private function __clone() {
	}
}

/**
 * returns details error msg (as json)
 *
 * @param integer $code
 *        	HTTP error 400, 500 or 503
 * @param string $msg
 *        	Error message text
 */
function showError($code, $msg) {
	if ($code == 400) {
		header ( "HTTP/1.0 400 Bad Request" );
	} else if ($code == 500) {
		header ( "HTTP/1.0 500 Internal Server Error" );
	} else if ($code == 503) {
		header ( "HTTP/1.0 503 Service Unavailable" );
	}
	header ( "Content-Type: application/json" );

	$errorObject = array (
			'error' => array (
					'msg' => $msg,
					'url' => "http://$_SERVER[HTTP_HOST]$_SERVER[REQUEST_URI]"
			)
	);
	print_r ( json_encode ( $errorObject ) );
}

if (isset ( $_SERVER ['HTTP_X_FORWARDED_FOR'] ) && $_SERVER ['HTTP_X_FORWARDED_FOR'])
	$ip = $_SERVER ['HTTP_X_FORWARDED_FOR'];
if (isset ( $_GET ['mac'] ) && $_GET ['mac'])
	$mac = $_GET ['mac'];
else
	$mac = $INVALID_MAC;
if (isset ( $_GET ['name'] ) && $_GET ['name'])
	$name = $_GET ['name'];
if (isset ( $_GET ['key'] ) && $_GET ['key'])
	$key = $_GET ['key'];
if (isset ( $_GET ['port'] ) && $_GET ['port'])
	$port = $_GET ['port'];
else
	$port = 10000;

$hood = $DEFAULT_HOOD_ID;
$gateway = false;

if ($ip && $name && $key) {
	if (! preg_match ( '/^([a-zA-Z0-9]|[a-zA-Z0-9][a-zA-Z0-9\-]{0,61}' . '[a-zA-Z0-9])(\.([a-zA-Z0-9]|[a-zA-Z0-9][a-zA-Z0-9\-]{0,61}' . '[a-zA-Z0-9]))*$/', $name ))
		exit ( showError ( 400, "invalid name" ) );
	
	if ($mac != $INVALID_MAC)
		$sql = 'SELECT * FROM nodes WHERE mac = :mac;';
	else
		$sql = 'SELECT * FROM nodes WHERE mac = \'000000000000\' AND name = :name;';
	
	try {
		$rs = db::getInstance ()->prepare ( $sql );
		if ($mac != $INVALID_MAC)
			$rs->bindParam ( ':mac', $mac );
		else
			$rs->bindParam ( ':name', $name );
		$rs->execute ();
	} catch ( PDOException $e ) {
		exit ( showError ( 500, $e ) );
	}
	
	if ($rs->rowCount () > 1)
		exit ( showError ( 500, "To much nodes with mac=$mac, name=$name" ) );
	
	if ($rs->rowCount () == 1) {
		$result = $rs->fetch ( PDO::FETCH_ASSOC );
		$hood = $result ['hood_ID'];
		$gateway = $result ['isgateway'];
		if (! $result ['readonly']) {
			$sql = 'UPDATE nodes SET ip = :ip, mac = :mac, name = :name, `key` = :key,' . ' port = :port, timestamp = CURRENT_TIMESTAMP WHERE ID = :id';
			try {
				$rs = db::getInstance ()->prepare ( $sql );
				$rs->bindParam ( ':id', $result ['ID'], PDO::PARAM_INT );
				$rs->bindParam ( ':ip', $ip );
				$rs->bindParam ( ':mac', $mac );
				$rs->bindParam ( ':name', $name );
				$rs->bindParam ( ':key', $key );
				$rs->bindParam ( ':port', $port );
				$rs->execute ();
			} catch ( PDOException $e ) {
				exit ( showError ( 500, $e ) );
			}
		}
	} else {
		$sql = 'INSERT INTO nodes (ip, mac, name, `key`, port, readonly, isgateway, hood_ID)' . ' VALUES (:ip, :mac, :name, :key, :port, 0, 0, :hood);';
		try {
			$rs = db::getInstance ()->prepare ( $sql );
			$rs->bindParam ( ':ip', $ip );
			$rs->bindParam ( ':mac', $mac );
			$rs->bindParam ( ':name', $name );
			$rs->bindParam ( ':key', $key );
			$rs->bindParam ( ':port', $port );
			$rs->bindParam ( ':hood', $hood );
			$rs->execute ();
		} catch ( PDOException $e ) {
			exit ( showError ( 500, $e ) );
		}
	}
}

if ($gateway)
	$sql = 'SELECT * FROM nodes WHERE hood_ID = :hood;';
else
	$sql = 'SELECT * FROM nodes WHERE hood_ID = :hood AND isgateway = \'1\';';

try {
	$rs = db::getInstance ()->prepare ( $sql );
	$rs->bindParam ( ':hood', $hood );
	$rs->execute ();
} catch ( PDOException $e ) {
	exit ( showError ( 500, $e ) );
}

if ($rs->rowCount () > 0) {
	while ( $result = $rs->fetch ( PDO::FETCH_ASSOC ) ) {
		$filename = $result ['mac'];
		if ($filename == $INVALID_MAC)
			$filename = $result ['name'];
		
		echo "####" . $filename . ".conf\n";
		echo "#name \"" . $result ['name'] . "\";\n";
		echo "key \"" . $result ['key'] . "\";\n";
		if (preg_match ( "/[0-9]+\.[0-9]+\.[0-9]+\.[0-9]+/", $result ['ip'] ))
			echo "remote ipv4 \"" . $result ['ip'] . "\" port " . $result ['port'] . " float;\n";
		else
			echo "remote ipv6 \"" . $result ['ip'] . "\" port " . $result ['port'] . " float;\n";
		echo "\n";
	}
	echo "###\n";
}

?>
