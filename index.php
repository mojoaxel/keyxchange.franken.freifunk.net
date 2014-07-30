<?

$mysql_db = "example";
$mysql_user = "example";
$mysql_pass = "example";

$delete_time = 60*60*24*30;

class db
{
	private static $instance = NULL;

	private function __construct() {
	}

	public static function getInstance() {
		if (!self::$instance) {
            global $mysql_db;
            global $mysql_user;
            global $mysql_pass;
			self::$instance = new PDO("mysql:host=localhost;dbname=$mysql_db", $mysql_user, $mysql_pass);;
			self::$instance->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
		}
		return self::$instance;
	}

	private function __clone() {
	}
}

//if (isset($_SERVER['REMOTE_ADDR']) && $_SERVER['REMOTE_ADDR'])
//$ip = $_SERVER['REMOTE_ADDR'];
if (isset($_SERVER['HTTP_X_FORWARDED_FOR']) && $_SERVER['HTTP_X_FORWARDED_FOR'])
    $ip = $_SERVER['HTTP_X_FORWARDED_FOR'];
if (isset($_GET['mac']) && $_GET['mac'])
    $mac = $_GET['mac'];
else
    $mac = '000000000000';
if (isset($_GET['name']) && $_GET['name'])
    $name = $_GET['name'];
if (isset($_GET['key']) && $_GET['key'])
    $key = $_GET['key'];
if (isset($_GET['port']) && $_GET['port'])
    $port = $_GET['port'];
else
	$port = 10000;

$hood = 1; // default hood
$gateway = false;

if ($ip && $name && $key) {
    if (!preg_match('/^([a-zA-Z0-9]|[a-zA-Z0-9][a-zA-Z0-9\-]{0,61}'.
        '[a-zA-Z0-9])(\.([a-zA-Z0-9]|[a-zA-Z0-9][a-zA-Z0-9\-]{0,61}'.
        '[a-zA-Z0-9]))*$/', $name))
        exit("invalid name");

    if ($mac != '000000000000')
        $sql = 'SELECT * FROM nodes WHERE mac = :mac;';
    else
        $sql = 'SELECT * FROM nodes WHERE mac = \'000000000000\' AND name = :name;';

    try {
        $rs = db::getInstance()->prepare($sql);
        if ($mac != '000000000000')
            $rs->bindParam(':mac', $mac);
        else
            $rs->bindParam(':name', $name);
        $rs->execute();
    } catch (PDOException $e) {
        exit($e);
    }

    if ($rs->rowCount() > 1)
        exit("To much nodes with mac=$mac, name=$name");

    if ($rs->rowCount() == 1) {
        $result = $rs->fetch(PDO::FETCH_ASSOC);
        $hood = $result['hood_ID'];
        $gateway = $result['isgateway'];
        if (!$result['readonly']) {
            $sql = 'UPDATE nodes SET ip = :ip, mac = :mac, name = :name, `key` = :key,'
               .' port = :port, timestamp = CURRENT_TIMESTAMP WHERE ID = :id';
            try {
                $rs = db::getInstance()->prepare($sql);
                $rs->bindParam(':id', $result['ID'], PDO::PARAM_INT);
                $rs->bindParam(':ip', $ip);
                $rs->bindParam(':mac', $mac);
                $rs->bindParam(':name', $name);
                $rs->bindParam(':key', $key);
                $rs->bindParam(':port', $port);
                $rs->execute();
            } catch (PDOException $e) {
                exit($e);
            }
        }
    } else {
        $sql = 'INSERT INTO nodes (ip, mac, name, `key`, port, readonly, isgateway, hood_ID)'
            .' VALUES (:ip, :mac, :name, :key, :port, 0, 0, :hood);';
        try {
            $rs = db::getInstance()->prepare($sql);
            $rs->bindParam(':ip', $ip);
            $rs->bindParam(':mac', $mac);
            $rs->bindParam(':name', $name);
            $rs->bindParam(':key', $key);
            $rs->bindParam(':port', $port);
            $rs->bindParam(':hood', $hood);
            $rs->execute();
        } catch (PDOException $e) {
            exit($e);
        }
    }
}

if ($gateway)
    $sql = 'SELECT * FROM nodes WHERE hood_ID = :hood;';
else
    $sql = 'SELECT * FROM nodes WHERE hood_ID = :hood AND isgateway = \'1\';';

try {
    $rs = db::getInstance()->prepare($sql);
    $rs->bindParam(':hood', $hood);
    $rs->execute();
} catch (PDOException $e) {
    exit($e);
}

if ($rs->rowCount() > 0) {
    while($result = $rs->fetch(PDO::FETCH_ASSOC))
    {
        $filename = $result['mac'];
        if ($filename == '000000000000')
            $filename = $result['name'];

        echo "####".$filename.".conf\n";
        echo "#name \"".$result['name']."\";\n";
        echo "key \"".$result['key']."\";\n";
        if (preg_match("/[0-9]+\.[0-9]+\.[0-9]+\.[0-9]+/", $result['ip']))
            echo "remote ipv4 \"".$result['ip']."\" port ".$result['port']." float;\n";
        else
            echo "remote ipv6 \"".$result['ip']."\" port ".$result['port']." float;\n";
        echo "\n";
    }
    echo "###\n";
}

/*
 * DEFEKT
$sql = 'DELETE FROM nodes WHERE readonly = 0 AND `timestamp`+'.$delete_time.' < CURRENT_TIMESTAMP;';
try {
    $rs = db::getInstance()->prepare($sql);
    $rs->bindParam(':hood', $hood);
    $rs->execute();
} catch (PDOException $e) {
    exit($e);
}*/

?>
