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
$debug = false;

/**
 * Singelton DB instance
 */
class db {
	private static $instance = NULL;
	private function __construct() {
	}
	public static function getInstance() {
		if (! self::$instance) {
			require ("config.inc.php");
			self::$instance = new PDO ( "mysql:host=$mysql_server;dbname=$mysql_db", $mysql_user, $mysql_pass );
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

/**
 * Haversine distance function in km
 * https://en.wikipedia.org/wiki/Haversine_formula
 *
 * @param double $lat1
 *        	latitude point 1
 * @param double $lon1
 *        	longitude point 1
 * @param double $lat2
 *        	latitude point 2
 * @param double $lon2
 *        	longitude point 2
 * @return integer distance between the points in km
 */
function distance_haversine($lat1, $lon1, $lat2, $lon2) {
	$earth_radius = 6371;
	$delta_lat = $lat1 - $lat2;
	$delta_lon = $lon1 - $lon2;
	$alpha = $delta_lat / 2;
	$beta = $delta_lon / 2;
	$a = sin ( deg2rad ( $alpha ) ) * sin ( deg2rad ( $alpha ) ) + cos ( deg2rad ( $lat1 ) ) * cos ( deg2rad ( $lat2 ) ) * sin ( deg2rad ( $beta ) ) * sin ( deg2rad ( $beta ) );
	$c = asin ( min ( 1, sqrt ( $a ) ) );
	$distance = 2 * $earth_radius * $c;
	$distance = round ( $distance, 3 );
	return $distance;
}

/**
 * Try to read the geo coodinates from netmon and
 * return them as an array [lat, lon].
 * In case of error return empty array.
 *
 * @param unknown $mac
 *        	search for the router by the given mac adress
 * @param unknown $name
 *        	search for the router by the given hostname
 * @return array [lat, lon] or []
 */
function getLocationByMacOrName($mac, $name) {
	global $debug;
	
	if ($mac) {
		$url = "https://netmon.freifunk-franken.de/api/rest/router/" . $mac;
	} elseif ($name) {
		$url = "https://netmon.freifunk-franken.de/api/rest/router/" . $name;
	} else {
		if ($debug)
			print_r ( "ERROR: MAC and NAME invalid: mac: " . $mac . ", name: " . $name . "\n" );
		return [ ];
	}
	
	if (! $netmon_response = simplexml_load_file ( $url ))
		exit ( 'Failed to open ' . $url );
	
	if ($netmon_response->request->error_code > 0) {
		if ($debug)
			print_r ( "WARN: " . $netmon_response->request->error_message . "\n" );
		return [ ];
	}
	
	// get geo-location
	$nodeLat = floatval ( $netmon_response->router->latitude );
	$nodeLon = floatval ( $netmon_response->router->longitude );
	if ($nodeLat == 0 || $nodeLon == 0) {
		if ($debug)
			print_r ( "WARN nodeLat: " . $nodeLat . ", nodeLon: " . $nodeLon . "\n" );
		return [ ];
	}
	
	if ($debug)
		print_r ( "nodeLat: $nodeLat, \nnodeLon: $nodeLon \n\n" );
	return array (
			$nodeLat,
			$nodeLon 
	);
}

/**
 * Check is the given geo coordinates are within one of the hoods.
 *
 * @param double $lat
 *        	latitude point 1
 * @param double $lon
 *        	longitude point 1
 * @return integer hood-id
 */
function getHoodByGeo($lat, $lon) {
	global $debug;
	global $DEFAULT_HOOD_ID;
	
	// load hoods from DB
	try {
		$rs = db::getInstance ()->prepare ( "SELECT * FROM `hoods`$sql" );
		$rs->execute ();
	} catch ( PDOException $e ) {
		exit ( showError ( 500, $e ) );
	}
	
	// check for every hood if node is within the given radius
	while ( $result = $rs->fetch ( PDO::FETCH_ASSOC ) ) {
		if ($debug)
			print_r ( "\n\nhood: " . $result ['name'] . "\n" );
		
		$hoodCenterLat = $result ['lat'];
		$hoodCenterLon = $result ['lon'];
		$hoodRadius = $result ['radius'];
		$hoodID = $result ['ID'];
		
		if ($hoodCenterLat <= 0 || $hoodCenterLon <= 0 || $hoodRadius <= 0) {
			continue;
		}
		
		if ($debug)
			print_r ( "hoodCenterLat: $hoodCenterLat, \nhoodCenterLon: $hoodCenterLon, \nhoodRadius: $hoodRadius, \nhoodID: $hoodID \n" );
		
		$distance = distance_haversine ( $hoodCenterLat, $hoodCenterLon, $lat, $lon );
		if ($debug)
			print_r ( "distance: $distance, \nhoodRadius: $hoodRadius \n\n" );
		
		if ($distance <= $hoodRadius) {
			if ($debug)
				print_r ( "Node belongs to Hood " . $hoodID . " (" . $result ['name'] . ")" . "\n\n" );
			return $hoodID;
		}
	}
	
	if ($debug)
		print_r ( "No Hood found. This means default-hood.\n" );
	return $DEFAULT_HOOD_ID;
}

// ----------------------------------------------------------------------------

// get parameters and initialice settings
if (isset ( $_SERVER ['HTTP_X_FORWARDED_FOR'] ) && $_SERVER ['HTTP_X_FORWARDED_FOR']) {
	$ip = $_SERVER ['HTTP_X_FORWARDED_FOR'];
}
if (isset ( $_GET ['mac'] ) && $_GET ['mac']) {
	$mac = $_GET ['mac'];
} else {
	$mac = $INVALID_MAC;
}
if (isset ( $_GET ['name'] ) && $_GET ['name']) {
	$name = $_GET ['name'];
}
if (isset ( $_GET ['key'] ) && $_GET ['key']) {
	$key = $_GET ['key'];
}
if (isset ( $_GET ['port'] ) && $_GET ['port']) {
	$port = $_GET ['port'];
} else {
	$port = 10000;
}
$hood = $DEFAULT_HOOD_ID;
$gateway = false;

// discover the best hood-id from netmons geo-location
$location = getLocationByMacOrName ( $mac, $name );
if ($location && $location [0] && $location [1]) {
	$hood = getHoodByGeo ( $location [0], $location [1] );
}

// insert or update the current node in the database
if ($ip && $name && $key) {
	if (! preg_match ( '/^([a-zA-Z0-9]|[a-zA-Z0-9][a-zA-Z0-9\-]{0,61}[a-zA-Z0-9])(\.([a-zA-Z0-9]|[a-zA-Z0-9][a-zA-Z0-9\-]{0,61}[a-zA-Z0-9]))*$/', $name )) {
		exit ( showError ( 400, "invalid name" ) );
	}
	
	if ($mac != $INVALID_MAC) {
		$sql = '
		SELECT * FROM nodes 
		WHERE mac = :mac;';
	} else {
		$sql = '
		SELECT * FROM nodes 
		WHERE mac = \'' . $INVALID_MAC . '\' AND name = :name;';
	}
	
	try {
		$rs = db::getInstance ()->prepare ( $sql );
		if ($mac != $INVALID_MAC) {
			$rs->bindParam ( ':mac', $mac );
		} else {
			$rs->bindParam ( ':name', $name );
		}
		$rs->execute ();
	} catch ( PDOException $e ) {
		exit ( showError ( 500, $e ) );
	}
	
	if ($rs->rowCount () > 1) {
		exit ( showError ( 500, "To much nodes with mac=$mac, name=$name" ) );
	}
	
	if ($rs->rowCount () == 1) {
		$result = $rs->fetch ( PDO::FETCH_ASSOC );
		$gateway = $result ['isgateway'];
		
		if (! $result ['readonly']) {
			$sql = '
			UPDATE nodes 
			SET 
				ip = :ip, mac = :mac, name = :name, `key` = :key, port = :port, timestamp = CURRENT_TIMESTAMP 
			WHERE 
				ID = :id';
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
		$sql = '
		INSERT INTO nodes 
			(ip, mac, name, `key`, port, readonly, isgateway, hood_ID) 
		VALUES 
			(:ip, :mac, :name, :key, :port, 0, 0, :hood);';
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

// return either all nodes (if gateway) or all gateways (if node) from the hood
if ($gateway) {
	$sql = '
	SELECT * FROM nodes 
	WHERE 
		hood_ID = :hood;';
} else {
	$sql = '
	SELECT * FROM nodes 
	WHERE 
		hood_ID = :hood AND isgateway = \'1\';';
}
try {
	$rs = db::getInstance ()->prepare ( $sql );
	$rs->bindParam ( ':hood', $hood );
	$rs->execute ();
} catch ( PDOException $e ) {
	exit ( showError ( 500, $e ) );
}

// return results in a easy parsable way
if ($rs->rowCount () > 0) {
	while ( $result = $rs->fetch ( PDO::FETCH_ASSOC ) ) {
		
		$filename = $result ['mac'];
		if ($filename == $INVALID_MAC) {
			$filename = $result ['name'];
		}
		
		echo "####" . $filename . ".conf\n";
		echo "#name \"" . $result ['name'] . "\";\n";
		echo "key \"" . $result ['key'] . "\";\n";
		if (preg_match ( "/[0-9]+\.[0-9]+\.[0-9]+\.[0-9]+/", $result ['ip'] )) {
			echo "remote ipv4 \"" . $result ['ip'] . "\" port " . $result ['port'] . " float;\n";
		} else {
			echo "remote ipv6 \"" . $result ['ip'] . "\" port " . $result ['port'] . " float;\n";
		}
		echo "\n";
	}
	echo "###\n";
}

?>
