<?php
try {
    require ("config.inc.php");
    $db = new PDO("mysql:host=$mysql_server;dbname=$mysql_db;charset=utf8mb4", $mysql_user, $mysql_pass);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $rs = $db->prepare ( "SELECT * FROM `hoods`" );
    $rs->execute ();
} catch ( PDOException $e ) {
    exit($e);
}

$hoods = array();
while ( $result = $rs->fetch ( PDO::FETCH_ASSOC ) ) {
    array_push($hoods, array(
        'name' => $result ['name'],
        'lat' => $result ['lat'],
        'lon' => $result ['lon'],
        'radius' => $result ['radius']
    ));
}
?>
<html>
<head>
<title>Hoods</title>

<link rel="stylesheet" href="http://cdn.leafletjs.com/leaflet-0.7.3/leaflet.css" />
<script src="http://cdn.leafletjs.com/leaflet-0.7.3/leaflet.js"></script>

<script language="javascript">
function init() {
    var map = new L.Map('map');
    
    L.tileLayer('http://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
       attribution: '© <a href="http://openstreetmap.org">OpenStreetMap</a> contributors',
       maxZoom: 18
    }).addTo(map);
    map.attributionControl.setPrefix(''); // Don't show the 'Powered by Leaflet' text.
    
    // Location to centre the map
    var Heilsbronn = new L.LatLng(49.33861, 10.79083); //Zentrum von Franken
    var Burghaslach = new L.LatLng(49.733, 10.6); // Zentrum von Feifunk-franken

    var Fuerth = new L.LatLng(49.47833, 10.99027); //Zentrum von meiner Welt

    map.setView(Burghaslach, 9);
 
    var hoods = JSON.parse('<?php echo json_encode($hoods) ?>');

    var circleOptions = {
            stroke: false,
            fillColor: '#dc0067', 
            fillOpacity: 0.5
        };

    for (var h = 0; h<=hoods.length-1; h++) { 
        var hood = hoods[h];

        var circleLocation = new L.LatLng(hood.lat, hood.lon);
        var circle = new L.Circle(circleLocation, hood.radius*1000, circleOptions);
        
        map.addLayer(circle);
    }
}
</script>

<style type="text/css">
#map {
    width: 100%; 
    height: 100%;
}
</style>

</head>
<body onLoad="javascript:init();">
	<center><div id="map" style=""></div></center>
</body>
</html>
