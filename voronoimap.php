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
    if ($result ['lat'] > 0 && $result ['lon'] > 0) {
	    array_push($hoods, array(
	        'name' => $result ['name'],
			'type' => $result ['name'],
			'lat' => $result ['lat'],
			'lon' => $result ['lon'],
			'color' => "#F00" 
	    ));
	}
}
?>

<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">

<link href='https://api.tiles.mapbox.com/mapbox.js/v1.6.3/mapbox.css' rel='stylesheet' />
<style>
body {
	margin: 0;
	font-family: Helvetica, Arial, sans-serif;
	font-size: 14px;
}

p {
	margin: 0;
	margin-bottom: 10px;
}

.point-cell {
	fill: none;
	pointer-events: all;
	stroke: #000;
	stroke-opacity: .2;
}

.point-cell:hover, .point-cell.selected {
	fill: none;
	stroke: #000;
	stroke-opacity: .6;
	stroke-width: 2px;
}

.point-cell.selected {
	stroke-opacity: 1;
	stroke-width: 3px;
}

.point circle {
	pointer-events: none;
}

#map {
	position:absolute;
	top:0;
	bottom:0;
	width:100%;
}

#selected,
#selections,
#loading:after,
#about {
	position:absolute;
	background-color: #FFF;
	opacity: 0.8;
	border-radius: 2px;
	padding: 10px 10px 0 10px;
}

#about {
	bottom: 10px;
	right: 10px;
}

#about.visible {
	width: 200px;
}

#about .hide {
	padding-bottom: 0;
	text-align: right;
}

#loading.visible:after {
	top: 50%;
	left: 50%;
	height: 28px;
	width: 80px;
	margin-left: -50px;
	margin-top: -30px;
	content: 'drawing...';
	font-size: 18px;
}

#selections {
	right:10px;
	top:10px;
	width: 190px;
}

#selections label {
	display: block;
	padding-bottom: 8px;
}

#selections input[type=checkbox] {
	position: relative;
	top: -1px;
}

#selections .key {
	display: inline-block;
	width: 12px;
	height: 12px;
	border-radius: 6px;
	margin: 0 5px;
}

#selected {
	bottom: 10px;
	left: 10px;
	height: 28px;
}

#selected h1 {
	font-size: 20px;
	margin: 0px;
	line-height: 20px;
	font-weight: bold;
	white-space: nowrap;
	overflow: hidden;
	text-overflow: ellipsis;
}

.hide,
.show {
	padding-bottom: 10px;
	display: block;
}

.content {
	display: none;
}

@media (min-width: 480px) {
	.selections .content {
		display: block;
	}
	.selections .show {
		display: none;
	}
}

.hidden .content,
.visible .show {
	display: none;
}

.hidden .show,
.visible .content {
	display: block;
}

@media (max-width: 480px) {
	#selected {
		box-sizing: border-box;
		width: 80%;
		height: 32px;
	}

	#selected h1 {
		font-size: 15px;
		line-height: 15px;
		font-weight: bold;
	}
}

.mapbox-control-info {
	display: none !important;
}
</style>
</head>


<body>
	<div id='map'></div>
	<div id='selections' class="selections">
		<a href='#' class="show"></a>
		<div class='content'>
			<a href='#' class="hide">Hide</a>
			<div id="toggles"></div>
		</div>
	</div>
	<div id='loading'></div>
	<div id='selected'>
		<h1>The Freifunk-Franken "hoods" visualized as voronoi charts</h1>
	</div>
	<div id='about'>
		<a href='#' class="show">About</a>
		<p class='content'>
			Explore Freifunk Franken Hoods. Created by <a href="http://wunschik.it">Alexander Wunschik</a> 
			havely based on a example by <a href="http://chriszetter.com">Chris Zetter</a>, 
			maps copyright <a href='https://www.mapbox.com/about/maps/' target='_blank'>Mapbox and OpenStreetMap</a>.
			<a href='#' class="hide">Hide</a>
		</p>
	</div>

	<script src="https://cdnjs.cloudflare.com/ajax/libs/d3/3.4.8/d3.min.js"></script>
	<script src="https://api.tiles.mapbox.com/mapbox.js/v1.6.3/mapbox.js"></script>
	
	<script>
	showHide = function(selector) {
		d3.select(selector).select('.hide').on('click', function() {
			d3.select(selector).classed('visible', false).classed('hidden', true);
		});

		d3.select(selector).select('.show').on('click', function() {
			d3.select(selector).classed('visible', true).classed('hidden', false);
		});
	}

	voronoiMap = function(map, points) {
		var pointTypes = d3.map(), lastSelectedPoint;

		var voronoi = d3.geom.voronoi().x(function(d) {
			return d.x;
		}).y(function(d) {
			return d.y;
		});

		var selectPoint = function() {
			d3.selectAll('.selected').classed('selected', false);

			var cell = d3.select(this), point = cell.datum();

			lastSelectedPoint = point;
			cell.classed('selected', true);

			d3.select('#selected h1').html('').append('a').text(point.name).attr(
					'target', '_blank')
		}

		var drawPointTypeSelection = function() {
			showHide('#selections')
			labels = d3.select('#toggles').selectAll('input').data(
					pointTypes.values()).enter().append("label");

			labels.append("input").attr('type', 'checkbox').property('checked',
					true).attr("value", function(d) {
				return d.type;
			}).on("change", drawWithLoading);

			labels.append("span").attr('class', 'key').style('background-color',
					function(d) {
						return d.color;
					});

			labels.append("span").text(function(d) {
				return d.type;
			});
		}

		var selectedTypes = function() {
			return d3.selectAll('#toggles input[type=checkbox]')[0].filter(
					function(elem) {
						return elem.checked;
					}).map(function(elem) {
				return elem.value;
			})
		}

		var pointsFilteredToSelectedTypes = function() {
			var currentSelectedTypes = d3.set(selectedTypes());
			return points.filter(function(item) {
				return currentSelectedTypes.has(item.type);
			});
		}

		var drawWithLoading = function(e) {
			d3.select('#loading').classed('visible', true);
			if (e && e.type == 'viewreset') {
				d3.select('#overlay').remove();
			}
			setTimeout(function() {
				draw();
				d3.select('#loading').classed('visible', false);
			}, 0);
		}

		var draw = function() {
			d3.select('#overlay').remove();

			var bounds = map.getBounds(), topLeft = map.latLngToLayerPoint(bounds
					.getNorthWest()), bottomRight = map.latLngToLayerPoint(bounds
					.getSouthEast()), existing = d3.set(), drawLimit = bounds
					.pad(0.4);

			filteredPoints = pointsFilteredToSelectedTypes().filter(function(d) {
				var latlng = new L.LatLng(d.lat, d.lon);

				if (!drawLimit.contains(latlng)) {
					return false
				}
				;

				var point = map.latLngToLayerPoint(latlng);

				key = point.toString();
				if (existing.has(key)) {
					return false
				}
				;
				existing.add(key);

				d.x = point.x;
				d.y = point.y;
				return true;
			});

			voronoi(filteredPoints).forEach(function(d) {
				d.point.cell = d;
			});

			var svg = d3.select(map.getPanes().overlayPane).append("svg").attr(
					'id', 'overlay').attr("class", "leaflet-zoom-hide").style(
					"width", map.getSize().x + 'px').style("height",
					map.getSize().y + 'px').style("margin-left", topLeft.x + "px")
					.style("margin-top", topLeft.y + "px");

			var g = svg.append("g").attr("transform",
					"translate(" + (-topLeft.x) + "," + (-topLeft.y) + ")");

			var svgPoints = g.attr("class", "points").selectAll("g").data(
					filteredPoints).enter().append("g").attr("class", "point");

			var buildPathFromPoint = function(point) {
				return "M" + point.cell.join("L") + "Z";
			}

			svgPoints.append("path").attr("class", "point-cell").attr("d",
					buildPathFromPoint).on('click', selectPoint).classed(
					"selected", function(d) {
						return lastSelectedPoint == d
					});

			svgPoints.append("circle").attr("transform", function(d) {
				return "translate(" + d.x + "," + d.y + ")";
			}).style('fill', function(d) {
				return d.color
			}).attr("r", 2);
		}

		var mapLayer = {
			onAdd : function(map) {
				map.on('viewreset moveend', drawWithLoading);
				drawWithLoading();
			}
		};

		showHide('#about');

		map.on('ready', function() {
			points.forEach(function(point) {
				pointTypes.set(point.type, {
					type : point.type,
					color : point.color
				});
			})
			drawPointTypeSelection();
			map.addLayer(mapLayer);
		});
	}
	</script>
	
	<script>
	var map = L.mapbox.map('map', 'examples.map-i86nkdio');
	
	var Burghaslach = new L.LatLng(49.733, 10.6); // Zentrum von Feifunk-franken
	map.setView(Burghaslach, 9);
	
	points = JSON.parse('<?php echo json_encode($hoods) ?>');
	
	voronoiMap(map, points);
	</script>
</body>
</html>
