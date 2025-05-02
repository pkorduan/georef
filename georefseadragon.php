<?php
$js_path = CUSTOM_PATH . 'layouts/snippets/georef/js/';
$img_path = CUSTOM_PATH . 'layouts/snippets/georef/img/';
$data_path = SHAPEPATH . 'hro_hist_raka/';
$script_url = 'index.php?go=show_snippet&snippet=georef/georef&csrf_token=' . $_SESSION['csrf_token'];

function exec_cmd($url) {
  // echo '<br>url: ' . $url;
  $ch = curl_init();
  #$url = curl_escape($ch, $url);
  curl_setopt($ch, CURLOPT_URL, $url);
  curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 300);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
  $output = curl_exec($ch);
  curl_close($ch);
  $success = strpos($output, 'ERROR') === false;
  $output = json_decode($output);
  return array(
    'success' => $success,
    'output' => $output
  );
}

function get_next_tiff_file($data_path) {
  foreach(glob($data_path . 'source/*.tiff') AS $file) {
    $path_parts = pathinfo($file);
    if (!file_exists($data_path . 'georef/' . $path_parts['filename'] . '.tif')) {
      return $path_parts['basename'];
    }
  };
  return 'not found';
}

function get_coords($file_name) {
  $west = 308800;
  $south = 6006300;
  $east = 309300;
  $north = 6006800;
  return array(
    'ulrw' => $west,
    'ulhw' => $north,
    'urrw' => $east,
    'urhw' => $north,
    'lrrw' => $east,
    'lrhw' => $south,
    'llrw' => $west,
    'llhw' => $south
  );
}

switch ($this->formvars['action']) {
  case 'get_file' : {
    $file = SHAPEPATH . 'hro_hist_raka/source/' . $this->formvars['file'];
    if (file_exists($file)) {
      readfile($file);
    }
    else {
      echo 'Datei ' . $this->formvars['file'] . ' nicht gefunden!';
    }
    exit;
  } break;
  case 'georef' : {
    $this->sanitize([
      'file_name' => 'text',
      'ulrw' => 'numeric',
      'ulhw' => 'numeric',
      'urrw' => 'numeric',
      'urhw' => 'numeric',
      'lrrw' => 'numeric',
      'lrhw' => 'numeric',
      'llrw' => 'numeric',
      'llhw' => 'numeric',
      'uldrw' => 'numeric',
      'uldhw' => 'numeric',
      'urdrw' => 'numeric',
      'urdhw' => 'numeric',
      'lrdrw' => 'numeric',
      'lrdhw' => 'numeric',
      'lldrw' => 'numeric',
      'lldhw' => 'numeric'
    ]);
    $c = $this->formvars;
    echo '<br>ul: ' . $c['ulx'] . ',' . $c['uly'];
    echo '<br>ur: ' . $c['urx'] . ',' . $c['ury'];
    echo '<br>lr: ' . $c['lrx'] . ',' . $c['lry'];
    echo '<br>ll: ' . $c['llx'] . ',' . $c['lly'];
    echo '<br>Kante oben: ' . ($c['urx'] - $c['ulx']);
    echo '<br>Kante rechts: ' . ($c['lry'] - $c['ury']);
    echo '<br>Kante unten: ' . ($c['lrx'] - $c['llx']);
    echo '<br>Kante links: ' . ($c['lly'] -  $c['uly']);
    echo '<br>uld: ' . $c['uldrw'] . ',' . $c['uldhw'];
    echo '<br>urd: ' . $c['urdrw'] . ',' . $c['urdhw'];
    echo '<br>lrd: ' . $c['lrdrw'] . ',' . $c['lrdhw'];
    echo '<br>lld: ' . $c['lldrw'] . ',' . $c['lldhw'];
    $filename = pathinfo($this->formvars['file_name'], PATHINFO_FILENAME);
    // Translate image to georeferenced Tiff
    $translate_file = $data_path . 'tmp/' . $filename . '_translate.tiff';

    // gdal_translate \
    // -of GTiff \
    // -gcp 192 124 308800 6006800 \
    // -gcp 1605 124 309300 6006800 \
    // -gcp 1603 1554 309300 6006300 \
    // -gcp 190 1559 308800 6006300 \
    // source/Typ1_ak1223.tiff tmp/Typ1_ak1223_translate.tiff

    $gdal_container_connect = 'gdalcmdserver:8080/t/?tool=';
    $tool = 'gdal_translate';
    $param = '-of GTiff'
      . ' -gcp ' . $c['ulx'] . ' ' . $c['uly'] . ' ' . $c['ulrw'] . ' ' . $c['ulhw']
      . ' -gcp ' . $c['urx'] . ' ' . $c['ury'] . ' ' . $c['urrw'] . ' ' . $c['urhw']
      . ' -gcp ' . $c['lrx'] . ' ' . $c['lry'] . ' ' . $c['lrrw'] . ' ' . $c['lrhw']
      . ' -gcp ' . $c['llx'] . ' ' . $c['lly'] . ' ' . $c['llrw'] . ' ' . $c['llhw']
      . ' ' . $data_path . 'source/' . $filename . '.tiff ' . $translate_file;
    echo '<br>transform: ' . $tool . ' ' . $param;
    $url = $gdal_container_connect . $tool . '&param=' . urlencode($param);
    $result = exec_cmd($url);
		if (!$result['success']) {
      echo '<p>Fehler beim Befehl: ' . $url . ' result: ' . print_r($result['output'], true);
      exit;
		}

//     // Cut georeferenced file to frame
//     $geojson_text = '{
//   "type": "FeatureCollection",
//   "name": "Typ1_ak1223_cutline",
//   "crs": { "type": "name", "properties": { "name": "urn:ogc:def:crs:EPSG::25833" } },
//   "features": [
//     { "type": "Feature", "properties": { "id": 1223 }, "geometry": { "type": "MultiPolygon", "coordinates": [ [ [ '
//       . '[' . $c['ulrw'] . ', ' . $c['ulhw'] . '],'
//       . '[' . $c['urrw'] . ', ' . $c['urhw'] . '],'
//       . '[' . $c['lrrw'] . ', ' . $c['lrhw'] . '],'
//       . '[' . $c['llrw'] . ', ' . $c['llhw'] . '],'
//       . '[' . $c['ulrw'] . ', ' . $c['ulhw'] . ']'
//       . '] ] ] } }
//   ]
// }';
//   $geojson_file = $data_path . 'tmp/' . $filename . '_cutline.geojson';
//   echo '<br>Schreibe Datei ' . $geojson_file;
//   file_put_contents($geojson_file, $geojson_text);

  // gdalwarp \
  // -r bilinear \
  // -tps \
  // -overwrite \
  // -te 308800 6006300 309300 6006800 \
  // -t_srs EPSG:25833 \
  // tmp/Typ1_ak1223_translate.tiff tmp/Typ1_ak1223_warp.tiff

  $warp_file =  $data_path . 'georef/' . $filename . '.tif';
  $tool = 'gdalwarp';
  // $param = '-co "TFW=YES" -s_srs EPSG:25833 -t_srs EPSG:25833 -cutline ' . $geojson_file . ' -crop_to_cutline -dstalpha -setci ' . $translate_file . ' ' . $warp_file;
  $param = '-co "TFW=YES" -r bilinear -tps -overwrite -te ' . $c['llrw'] . ' ' . $c['llhw'] . ' ' . $c['urrw'] . ' ' . $c['urhw'] . ' -t_srs EPSG:25833 -dstalpha -setci ' . $translate_file . ' ' . $warp_file;

  echo '<br>warp: ' . $tool . ' ' . $param;
  $url = $gdal_container_connect . $tool . '&param=' . urlencode($param);
  $result = exec_cmd($url);
  if (!$result['success']) {
    echo '<p>Fehler beim Befehl: ' . $url . ' result: ' . print_r($result['output'], true);
    exit;
  }

  $shp_file = $data_path . 'tileindex.shp';
  $tool = 'gdaltindex';
  $param = $shp_file . ' ' . $data_path . 'georef/' . $filename . '.tif';
  echo '<br>gdaltindex: ' . $tool . ' ' . $param;
  $url = $gdal_container_connect . $tool . '&param=' . urlencode($param);
  $result = exec_cmd($url);
  if (!$result['success']) {
    echo '<p>Fehler beim Befehl: ' . $url . ' result: ' . print_r($result['output'], true);
    exit;
  }

} break;
  default : {
    // Load App
  }
}

$file_name = get_next_tiff_file($data_path);
if ($file_name === 'not found') {
  echo '<div style="margin-top: 50px">Keine tiff-Datei in Verzeichnis:<br>' . $data_path . 'source/<br>gefunden, die im Verzeichnis:<br>' . $data_path . 'georef/<br>noch keine .tif und .tfw hat!</div>';
  exit;
}
$coords = get_coords($file_name); ?>
<!-- <link rel="stylesheet" href="https://unpkg.com/leaflet@1.7.1/dist/leaflet.css" /> -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/openseadragon/4.1.0/openseadragon.min.js"></script>
<!-- <script src="https://unpkg.com/leaflet@1.7.1/dist/leaflet.js"></script> -->
<!-- <script src="<? echo $js_path; ?>geotiff.js"></script> -->
<script src="<? echo $js_path; ?>helmert2D.js"></script>
<style>
  /* #map {
    width: 100%;
    height: 500px;
    cursor: url('<? echo $img_path; ?>crosshair-pnt64.png') 16 16, auto;
  } */

  #viewer {
    width: 100%;
    height: 500px;
    background: black;
    cursor: url('<? echo $img_path; ?>crosshair-pnt64.png') 16 16, auto;
  }

  .residues-box {
    display: none
  }

  .value-box {
    width: 200px;
    float: left;
  }

  .submit-box {
    float: left;
    margin: 20px;
    width: 100%;
  }

  .submit-box input {
    width: 80px;
    display: none;
  }

  .file-box{
    float: left;
    margin-bottom: 5px;
  }

  .headtop{
    text-align: center;
    font-weight: bold;
    width: 90px;
    float: left;
    margin-bottom: 5px;
  }

  .headleft {
    text-align: left;
    font-weight: bold;
    width: 90px;
    float: left;
  }

  .cell {
    text-align: left;
    width: 90px;
    float: left
  }

  .cell input {
    width: 88px
  }

  .coord-box {
    display: flex;
    border: 2px solid white;
    padding-left: 2px;
  }

  .focus {
    border: 2px solid lightcoral;
  }

  .left {
    width: 150px;
    float: left;
    padding: 2px
  }

  .right {
    width: 300px;
    float: right;
    /* text-align: right; */
    padding: 2px
  }

  .clear {
    clear: both;
  }
</style>

<div id="viewer"></div>
<img src="<?php echo URL . APPLVERSION . $script_url . '&only_main=1&action=get_file&file=' . $file_name; ?>"/><br>
<?php echo URL . APPLVERSION . $script_url . '&only_main=1&action=get_file&file=' . $file_name; ?>

<!-- <div id="map"></div> -->
  <input type="hidden" name="go" value="show_snippet"/>
  <input type="hidden" name="snippet" value="georef/georef"/>
  <input type="hidden" name="action" value="georef"/>
  <div class="headleft">Datei:</div>
  <div class="file-box"><input id="file_name" name="file_name"></div>
  <div class="clear"></div>

  <div class="coord-box">
    <div class="headleft headtop">Passpunkte</div>
    <div class="headtop">RW [m]</div>
    <div class="headtop">HW [m]</div>
    <div class="headtop">X [px]</div>
    <div class="headtop">Y [px]</div>
    <div class="headtop">dRW [m]</div>
    <div class="headtop">dHW [m]</div>
    <div class="headtop">dX [px]</div>
    <div class="headtop">dY [px]</div>
  </div>
  <div class="clear"></div>

  <div id="ul_box" class="coord-box focus">
    <div class="headleft">Oben links:</div>
    <div class="cell"><input id="ulrw" name="ulrw" value="<?php echo $coords['ulrw']; ?>"></div>
    <div class="cell"><input id="ulhw" name="ulhw" value="<?php echo $coords['ulhw']; ?>"></div>
    <div class="cell"><input id="ulx"  name="ulx"></div>
    <div class="cell"><input id="uly"  name="uly"></div>
    <div class="cell"><input id="uldrw" name="uldrw"></div>
    <div class="cell"><input id="uldhw" name="uldhw"></div>
    <div class="cell"><input id="uldx"  name="uldx"></div>
    <div class="cell"><input id="uldy"  name="uldy"></div>
  </div>
  <div class="clear"></div>

  <div id="ur_box" class="coord-box">
    <div class="headleft">Oben rechts:</div>
    <div class="cell"><input id="urrw" name="urrw" value="<?php echo $coords['urrw']; ?>"></div>
    <div class="cell"><input id="urhw" name="urhw" value="<?php echo $coords['urhw']; ?>"></div>
    <div class="cell"><input id="urx"  name="urx"></div>
    <div class="cell"><input id="ury"  name="ury"></div>
    <div class="cell"><input id="urdrw" name="urdrw"></div>
    <div class="cell"><input id="urdhw" name="urdhw"></div>
    <div class="cell"><input id="urdx"  name="urdx"></div>
    <div class="cell"><input id="urdy"  name="urdy"></div>
  </div>
  <div class="clear"></div>

  <div id="lr_box" class="coord-box">
    <div class="headleft">Unten rechts:</div>
    <div class="cell"><input id="lrrw" name="lrrw" value="<?php echo $coords['lrrw']; ?>"></div>
    <div class="cell"><input id="lrhw" name="lrhw" value="<?php echo $coords['lrhw']; ?>"></div>
    <div class="cell"><input id="lrx"  name="lrx"></div>
    <div class="cell"><input id="lry"  name="lry"></div>
    <div class="cell"><input id="lrdrw" name="rldrw"></div>
    <div class="cell"><input id="lrdhw" name="rldhw"></div>
    <div class="cell"><input id="lrdx"  name="rldx"></div>
    <div class="cell"><input id="lrdy"  name="rldy"></div>
  </div>
  <div class="clear"></div>

  <div id="ll_box" class="coord-box">
    <div class="headleft">Unten links:</div>
    <div class="cell"><input id="llrw" name="llrw" value="<?php echo $coords['llrw']; ?>"></div>
    <div class="cell"><input id="llhw" name="llhw" value="<?php echo $coords['llhw']; ?>"></div>
    <div class="cell"><input id="llx"  name="llx"></div>
    <div class="cell"><input id="lly"  name="lly"></div>
    <div class="cell"><input id="lldrw" name="lldrw"></div>
    <div class="cell"><input id="lldhw" name="lldhw"></div>
    <div class="cell"><input id="lldx"  name="lldx"></div>
    <div class="cell"><input id="lldy"  name="lldy"></div>
  </div>
  <div class="clear"></div>

  <div class="submit-box">
    <input id="georef_button" type="button" name="go" value="Georeferenzieren" onclick="georef();">
  </div>
  <div class="clear"></div>
<script>
  let views = {
    ul: {
      key: 'ul',
      pan: [18.5, 1.9],
      next: 'ur'
    },
    ur:
    {
      key: 'ur',
      pan: [18.5, 16.0],
      next: 'lr'
    },
    lr:
    {
      key: 'lr',
      pan: [3.9, 16.0],
      next: 'll'
    },
    ll:
    {
      key: 'll',
      pan: [3.9, 1.9],
      next: 'ul'
    }
  };
  let current_view = views.ul;
  let zoom = 9;
  let view_keys = Object.keys(views);

  const viewer = OpenSeadragon({
    id: "viewer",
    prefixUrl: "https://cdnjs.cloudflare.com/ajax/libs/openseadragon/4.1.0/images/",
    tileSources: {
      type: 'image',
      url: '<?php echo URL . APPLVERSION . $script_url . '&only_main=1&action=get_file&file=' . $file_name; ?>'
    },
    showNavigator: false,
    animationTime: 0.5,
    blendTime: 0.1,
    constrainDuringPan: true,
    maxZoomPixelRatio: 2,
    minZoomLevel: 1,
    visibilityRatio: 1,
    zoomPerScroll: 1.2
  });

  viewer.addHandler('canvas-click', function(event) {
    let webPoint = event.position;
    let viewportPoint = viewer.viewport.pointFromPixel(webPoint);
    let imagePoint = viewer.viewport.viewportToImageCoordinates(viewportPoint);
    console.log(`Klick bei: X=${Math.floor(imagePoint.x)}, Y=${Math.floor(imagePoint.y)}`);

    // Bildgröße holen, um Koordinaten zu validieren
    let imgDims = viewer.world.getItemAt(0).getContentSize();

    if (
      imagePoint.x >= 0 && imagePoint.x < imgDims.x &&
      imagePoint.y >= 0 && imagePoint.y < imgDims.y
    ) {
      // document.getElementById('coords').innerText = `X: ${Math.floor(imagePoint.x)}, Y: ${Math.floor(imagePoint.y)}`;
      imagePoint = new OpenSeadragon.Point(1900, 90);
      viewportPoint = viewer.viewport.imageToViewportCoordinates(imagePoint);
      // Pan to that point
      viewer.viewport.panTo(viewportPoint);
    } else {
      console.log('außerhalb geklickt');
    }
  });



  // var map = L.map('map').setView(current_view.pan, zoom);
  // var canvas = document.createElement('canvas');
  // var layer;

  //  let files = fs.readdirSync('map_tiff');
  // console.log('files', files);
  // var path = require('path');
  // for (var i in files) {
  //   if ('file[i] is not georefrenced yet') {
  //     displayGeoTIFF(files[i]);
  //   }
  // }
  // displayGeoTIFF('<? echo $file_name; ?>');

  // L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
  //   maxZoom: 19,
  // }).addTo(map);

//   async function displayGeoTIFF(fileName) {
//     // console.log('displayGeoTIFFF: ', fileName);
//     document.getElementById('file_name').value = fileName.split('/').reverse()[0];
//     const url = '<?php echo URL . APPLVERSION . $script_url; ?>&only_main=1&action=get_file&file=' + fileName;
//     var response = await fetch(url);
//     var arrayBuffer = await response.arrayBuffer();

//     var tiff = await GeoTIFF.fromArrayBuffer(arrayBuffer);
//     var image = await tiff.getImage();

//     var width = image.getWidth();
//     var height = image.getHeight();
//     // console.log(width, height);
//     var values = await image.readRasters();

//     canvas.width = width;
//     canvas.height = height;

//     var ctx = canvas.getContext('2d');

//     var imageData = ctx.createImageData(width, height);
//     var data = imageData.data;
//     for (var i = 0; i < width * height; i++) {
//       data[i * 4] = values[0][i];
//       data[i * 4 + 1] = values[1][i];
//       data[i * 4 + 2] = values[2][i];
//       data[i * 4 + 3] = 255;
//     }

//     ctx.putImageData(imageData, 0, 0);

//     var bounds = [[0, 0], [19.73, 17.29]];
//     //var bounds = [[0, 0], [72.06, 82.21]];
//     layer = L.imageOverlay(canvas.toDataURL(), bounds).addTo(map);
// debug_height = height;
//     //map.fitBounds(bounds);
//     map.on("click", (evt) => {
//       let current_element = document.getElementById(current_view.key + 'x');
//       let x = Math.round(evt.latlng.lng * 100);
//       let y = Math.round(evt.latlng.lat * 100);
//       document.getElementById(current_view.key + 'x').value = x;
//       document.getElementById(current_view.key + 'y').value = height - y;
//       nextCorner();
//     });

//     map.on("keypress", function (evt) {
//       if (evt.originalEvent.key == 'n') {
//         console.log('keypress');
//         nextCorner();
//       }
//     });

//   }

  function nextCorner() {
      let next_view = views[current_view.next];
      console.log(`nextCorner`);
      document.getElementById(current_view.key + '_box').classList.toggle('focus');
      map.setView(next_view.pan, zoom);
      document.getElementById(next_view.key + '_box').classList.toggle('focus');
      current_view = next_view;
      if (
        document.getElementById('ulx').value != '' &&
        document.getElementById('urx').value != '' &&
        document.getElementById('lrx').value != '' &&
        document.getElementById('llx').value != '' &&
        document.getElementById('uly').value != '' &&
        document.getElementById('ury').value != '' &&
        document.getElementById('lry').value != '' &&
        document.getElementById('lly').value != ''
      ) {
        const ptsKarte = [
          [document.getElementById('ulx').value, 1973 - document.getElementById('uly').value],
          [document.getElementById('urx').value, 1973 - document.getElementById('ury').value],
          [document.getElementById('lrx').value, 1973 - document.getElementById('lry').value],
          [document.getElementById('llx').value, 1973 - document.getElementById('lly').value]
        ];
        const ptsNatur = [
          [document.getElementById('ulrw').value, document.getElementById('ulhw').value],
          [document.getElementById('urrw').value, document.getElementById('urhw').value],
          [document.getElementById('lrrw').value, document.getElementById('lrhw').value],
          [document.getElementById('llrw').value, document.getElementById('llhw').value]
        ];
        const result = helmert2D(ptsKarte, ptsNatur);
        document.getElementById('uldrw').value = result.v[0][0];
        document.getElementById('uldhw').value = result.v[0][1];
        document.getElementById('urdrw').value = result.v[1][0];
        document.getElementById('urdhw').value = result.v[1][1];
        document.getElementById('lrdrw').value = result.v[2][0];
        document.getElementById('lrdhw').value = result.v[2][1];
        document.getElementById('lldrw').value = result.v[3][0];
        document.getElementById('lldhw').value = result.v[3][1];
        console.log('result: %o', result);

        document.getElementById('georef_button').style.display = 'block';
      }
    }

  /**
   * Sendet das Formular zur Georeferenzierung und Speicherung der Ergebnisdaten ab und
   * läd das nächste Bild
   */
  function georef() {
    console.log('georef');
    console.log(document.getElementById('ulx').value + ' ' + document.getElementById('uly').value);
    console.log(document.getElementById('urx').value + ' ' + document.getElementById('ury').value);
    console.log(document.getElementById('lrx').value + ' ' + document.getElementById('lry').value);
    console.log(document.getElementById('llx').value + ' ' + document.getElementById('lly').value);
    document.GUI.submit();
  }

  /**
   * Rechnet die Helmert transformation und zeigt die Residuen an.
   */
  function calc_residues() {
    console.log('calc_residues');
  }
</script>