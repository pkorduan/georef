<?php
include_once(CLASSPATH . 'PgObject.php');
include_once(CLASSPATH . 'BackgroundJob.php');
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

function get_next_blatt($data_path) {
  global $GUI;
  $src_path = $data_path . 'source/';
  $pg_obj = new PgObject($GUI, 'hist_maps', 'blattschnitte');

  $sql = "
    UPDATE
      hist_maps.blattschnitte u
    SET
      state = 'Bearbeitung angefragt',
      updated_at = now()
    FROM
      (
        SELECT
          id
        FROM
          hist_maps.blattschnitte
        WHERE
          file_name IS NOT NULL AND
          state IS NULL
        ORDER BY
          ordner,
          file_name
        LIMIT 1
      ) s
    WHERE
      u.id = s.id
    RETURNING
      u.id
  ";
  $results = $pg_obj->getSQLResults($sql);
  if (count($results) == 0) {
    return array(
      'success' => false,
      'msg' => 'Keinen unbearbeiten Blattschnitt gefunden.'
    );
  }
  $blatt = $pg_obj->find_where(
    "id = " . $results[0]['id'],
    NULL,
    "*, llrw, llhw, ulrw, ulhw, urrw, urhw, lrrw, lrhw"
  )[0];

  if (!file_exists($src_path . $blatt->get('ordner') . '/' . $blatt->get('file_name'))) {
    return array(
      'success' => false,
      'msg' => 'Die Datei ' . $src_path . $blatt->get('ordner') . '/' . $blatt->get('file_name') . ' existiert nicht!'
    );
  }

  $blatt->set('type', $blatt->get('ordner') === 'ordner1' ? 0 : 1);
  return array(
    'success' => true,
    'blatt' => $blatt
  );
}

function list_errors($pg_obj, $msg = NULL) {
  echo '<h2 style="margin: 10px;">Fehlerliste</h2>';
  if ($msg) {
    echo $msg . '<p>';
  }
  $fehlerblaetter = $pg_obj->find_where(
    "state LIKE '%Fehler%'",
    "updated_at DESC",
    "id, ordner, file_name, state, updated_at, msg"
  );
  if (count($fehlerblaetter) > 0) {
    echo '<table border="1" cellspacing="0" cellpadding="0">' .
    '<tr>' .
      implode('', array_map(
        function($key) {
          return '<th>' . $key . '</th>';
        },
        $fehlerblaetter[0]->getKeys()
      )) .
    '<th>Funktionen</th>' .
    '</tr>' .
    implode('', array_map(
      function($blatt) {
        return '<tr>' .
          implode('', array_map(
            function($value) {
              return '<td>' . $value . '</td>';
            },
            $blatt->getValues()
          )) .
          '<td style="text-align: center"><a href="index.php?go=show_snippet&snippet=georef/georef&action=reset_file&file_id=' . $blatt->get_id() . '&csrf_token=' . $_SESSION['csrf_token'] . '">reset</a></td>' .
        '</tr>';
      },
      $fehlerblaetter
    ));
  }  
  else {
    echo 'Keine Fehler gefunden!';
  } 
}

switch ($this->formvars['action']) {
  case 'cancel_file' : {
    $this->sanitize([
      'file_id' => 'integer'
    ]);
    $pg_obj = new PgObject($this, 'hist_maps', 'blattschnitte');
    $blatt = $pg_obj->find_where("id = " . $this->formvars['file_id'])[0];
    $blatt->update_attr("state = NULL");
    echo '<p>Abbruch Georeferenzierung für Blatt ' . $blatt->get('blattnummer') . ' id: ' . $blatt->get_id();
    exit;
  } break;
  case 'reset_file' : {
    $this->sanitize([
      'file_id' => 'integer'
    ]);
    $pg_obj = new PgObject($this, 'hist_maps', 'blattschnitte');
    $blatt = $pg_obj->find_where("id = " . $this->formvars['file_id'])[0];
    $blatt->update_attr(array(
      "state = NULL",
      "updated_at = NULL",
      "msg = NULL"
    ));
    $georef_file =  $data_path . 'georef/' . $blatt->get('ordner') . '/' . $blatt->get('file_name');
    unlink($georef_file);
    list_errors($pg_obj, '<p>Georeferenzierung für Blatt ' . $blatt->get('blattnummer') . ' id: ' . $blatt->get_id() . ' zurückgesetzt und Datei ' . $georef_file . ' gelöscht.');
    exit;
  } break;
  case 'list_errors' : {
    $pg_obj = new PgObject($this, 'hist_maps', 'blattschnitte');
    list_errors($pg_obj);
    exit;
  } break;
  case 'get_file' : {
    $this->sanitize([
      'ordner' => 'text',
      'file_name' => 'text',
      'view' => 'text',
      'offset_x' => 'numeric',
      'offset y' => 'numeric',
      'size' => 'integer'
    ]);

    // ordner muss mit dem String ordner beginnen und darf keine .. enthalten.
    if (strpos($this->formvars['ordner'], 'ordner') !== 0 OR strpos($this->formvars['ordner'], '..') !== false) {
      echo '<p>Fehler beim Anfragen der Datei. Der Wert in Variable ordner ' . $this->formvars['ordner'] . ' ist nicht korrekt!';
      exit;
    }

    $src_file  = $data_path . 'source/' . $this->formvars['ordner'] . '/' . $this->formvars['file_name'];
    if (!file_exists($src_file)) {
      echo '<p>Fehler beim Anfragen der Datei. Die Datei ' . $src_file . ' existiert nicht auf dem Server!';
      exit;
    }

    // Extrahiere das view_file mit gdal
    // Beispiel: gdal_translate -srcwin 500 500 2000 2000 $input_file $output_file --config GDAL_PAM_ENABLED NO
    $view_file = $data_path . 'tmp/'    . $this->formvars['ordner'] . '/' . str_replace('tif', $this->formvars['view'] . '.png', $this->formvars['file_name']);

    if (!file_exists($view_file)) {
      $gdal_container_connect = 'gdalcmdserver:8080/t/?tool=';
      $tool = 'gdal_translate';
      $param = '-srcwin ' . $this->formvars['offset_x'] . ' ' . $this->formvars['offset_y'] . ' ' . $this->formvars['size'] . ' ' . $this->formvars['size']
        . " '" . $src_file . "' '" . $view_file . "' --config GDAL_PAM_ENABLED NO";
      $url = $gdal_container_connect . $tool . '&param=' . urlencode($param);
      // echo '<br>Exec cmd: ' . $url; exit;

      $result = exec_cmd($url);
      if (!$result['success']) {
        echo '<p>Fehler beim Befehl: ' . $url . ' result: ' . print_r($result['output'], true);
        exit;
      }
      if (!file_exists($view_file)) {
        echo 'Datei ' . $view_file . ' wurde nicht gefunden!';
        exit;
      }
    }
    // Liefere Datei an den Client aus.
    readfile($view_file);
    // nicht mehr ausliefern
    exit;
  } break;
  case 'georef' : {
    $this->sanitize([
      'file_id' => 'integer',
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
      'lldhw' => 'numeric',

      'ulx' => 'numeric',
      'uly' => 'numeric',
      'urx' => 'numeric',
      'ury' => 'numeric',
      'lrx' => 'numeric',
      'lry' => 'numeric',
      'llx' => 'numeric',
      'lly' => 'numeric',

      'uldx' => 'numeric',
      'uldy' => 'numeric',
      'urdx' => 'numeric',
      'urdy' => 'numeric',
      'lrdx' => 'numeric',
      'lrdy' => 'numeric',
      'lldx' => 'numeric',
      'lldy' => 'numeric'
    ]);
    $c = $this->formvars;
    $file_id = $this->formvars['file_id'];
    $pg_obj = new PgObject($this, 'hist_maps', 'blattschnitte');
    $blatt = $pg_obj->find_where("id = " . $file_id)[0];
    if (php_sapi_name() !== 'cli' AND $this->formvars['run_background_jobs'] == '') {
      // Write results of georeferencing to database
      $blatt->update_attr(array(
        "ulx = "  .  $c['ulx'],
        "uly = "  .  $c['uly'],
        "urx = "  .  $c['urx'],
        "ury = "  .  $c['ury'],
        "lrx = "  .  $c['lrx'],
        "lry = "  .  $c['lry'],
        "llx = "  .  $c['llx'],
        "lly = "  .  $c['lly'],
        "uldx = " .  $c['uldx'],
        "uldy = " .  $c['uldy'],
        "urdx = " .  $c['urdx'],
        "urdy = " .  $c['urdy'],
        "lrdx = " .  $c['lrdx'],
        "lrdy = " .  $c['lrdy'],
        "lldx = " .  $c['lldx'],
        "lldy = " .  $c['lldy'],
        "uldrw = " . $c['uldrw'],
        "uldhw = " . $c['uldhw'],
        "urdrw = " . $c['urdrw'],
        "urdhw = " . $c['urdhw'],
        "lrdrw = " . $c['lrdrw'],
        "lrdhw = " . $c['lrdhw'],
        "lldrw = " . $c['lldrw'],
        "lldhw = " . $c['lldhw']
      ));
      $this->add_background_job(
        'Georeferenzierung ',
        'index.php',
        http_build_query($this->formvars)
      );
      $this->start_background_task();
    }
    else {
      $job = BackgroundJob::find($this, "id = " . $this->formvars['background_job_id'])[0];
      // Translate image to georeferenced Tiff
      $src_file = $data_path . 'source/' . $blatt->get('ordner') . '/'. $blatt->get('file_name');
      $tmp_file = $data_path . 'tmp/' . $blatt->get('ordner') . '/' . $blatt->get('file_name');

      $gdal_container_connect = 'gdalcmdserver:8080/t/?tool=';
      $tool = 'gdal_translate';
      $param = '-of GTiff'
        . ' -gcp ' . $c['ulx'] . ' ' . $c['uly'] . ' ' . $c['ulrw'] . ' ' . $c['ulhw']
        . ' -gcp ' . $c['urx'] . ' ' . $c['ury'] . ' ' . $c['urrw'] . ' ' . $c['urhw']
        . ' -gcp ' . $c['lrx'] . ' ' . $c['lry'] . ' ' . $c['lrrw'] . ' ' . $c['lrhw']
        . ' -gcp ' . $c['llx'] . ' ' . $c['lly'] . ' ' . $c['llrw'] . ' ' . $c['llhw']
        . ' "' . $src_file . '" "' . $tmp_file . '"';
      echo '<br>transform: ' . $tool . ' ' . $param;
      $url = $gdal_container_connect . $tool . '&param=' . urlencode($param);
      $result = exec_cmd($url);
      if (!$result['success']) {
        $msg = '<p>Fehler beim Befehl: ' . $url . ' result: ' . print_r($result['output'], true);
        $job->update_attr(array("job_status = 'Fehler'"));
        $blatt->update_attr(array("state = 'Fehler'", "msg = '" . pg_escape_string($msg) . "'"));
        echo $msg;
        exit;
      }

      $georef_file =  $data_path . 'georef/' . $blatt->get('ordner') . '/' . $blatt->get('file_name');
      $tool = 'gdalwarp';
      $param = "-r bilinear -tps -overwrite -dstalpha -setci -co COMPRESS=DEFLATE -co PREDICTOR=2 -co TILED=YES -s_srs EPSG:25833 -t_srs EPSG:25833 -te_srs EPSG:25833 -cutline_srs EPSG:25833 -cutline 'POLYGON((" . $c['llrw'] . ' ' . $c['llhw'] . ',' . $c['ulrw'] . ' ' . $c['ulhw'] . ',' . $c['urrw'] . ' ' . $c['urhw'] . ',' . $c['lrrw'] . ' ' . $c['lrhw'] . ',' . $c['llrw'] . ' ' . $c['llhw'] . "))' -crop_to_cutline " . '"' . $tmp_file . '" "' . $georef_file . '"';
      echo '<br>warp: ' . $tool . ' ' . $param;
      $url = $gdal_container_connect . $tool . '&param=' . urlencode($param);
      $result = exec_cmd($url);
      if (!$result['success']) {
        $msg = '<p>Fehler beim Befehl: ' . $url . ' result: ' . print_r($result['output'], true);
        $job->update_attr(array("job_status = 'Fehler'"));
        $blatt->update_attr(array("state = 'Fehler'", "msg = '" . pg_escape_string($msg) . "'"));
        echo $msg;
        exit;
      }

      $shp_file = $data_path . 'Blattschnitt_' . $blatt->get('ordner') . '.shp';
      $tool = 'gdaltindex';
      $param = "'" . $shp_file . "' '" . $georef_file . "'";
      echo '<br>gdaltindex: ' . $tool . ' ' . $param;
      $url = $gdal_container_connect . $tool . '&param=' . urlencode($param);
      $result = exec_cmd($url);
      if (!$result['success']) {
        $msg = '<p>Fehler beim Befehl: ' . $url . ' result: ' . print_r($result['output'], true);
        $job->update_attr(array("job_status = 'Fehler'"));
        $blatt->update_attr(array("state = 'Fehler'", "msg = '" . pg_escape_string($msg) . "'"));
        echo $msg;
        exit;
      }
      $job->update_attr(array("job_status = 'ok'"));
      $blatt->update_attr(array(
        "state = 'fertig'",
        "updated_at = now()"
      ));
      echo '<br>Status für Datei: ' . $blatt->get('pfad') . ' auf fertig gesetzt.';
      // Dieser Prozess ist im Hintergrund gelaufen und es muss nichts weiter ausgegeben werden.
      exit;
    }
  } break;
  default : {
    // Do Nothing
  }
}

$result = get_next_blatt($data_path);
if (!$result['success']) {
  echo '<div style="margin-top: 50px">' . $result['msg'] . '</div>';
  exit;
}
$blatt = $result['blatt'];
// echo 'Nächste Datei: ' . $data_path . $blatt->get('pfad'); exit; ?>
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.7.1/dist/leaflet.css" />
<script src="https://unpkg.com/leaflet@1.7.1/dist/leaflet.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/openseadragon/4.1.0/openseadragon.min.js"></script>
<script src="<? echo $js_path; ?>geotiff.js"></script>
<script src="<? echo $js_path; ?>helmert2D.js"></script>
<style>
  #georef {
    height: 800px
  }

  #viewer {
    /* position: absolute; */
    top: 0; left: 0;
    width: 100%;
    height: 637px;
    overflow: hidden; /* Nur hier begrenzen */
    background: black;
    cursor: url('<? echo $img_path; ?>crosshair-pnt64.png') 48 48, auto;
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

  #georef_button {
    float: left;
    margin-left: 5px;
    display: none;
  }

  #cancel_button {
    float: left;
    margin-left: 45%;
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

  .residues {
    border: 2px solid white;
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

  .canvas {
    width: 600px;
    float: left;
    height: 400px;
    margin: 5px;
    top: 0; left: 0;
    overflow: hidden; /* Nur hier begrenzen */
    background: black;
    cursor: url('<? echo $img_path; ?>crosshair-pnt64.png') 48 48, auto;
  }

  #gui-table {
    width: 100%;
  }

  .clear {
    clear: both;
  }

</style>

<div id="georef">
  <div id="canvas_ul" class="canvas"></div><div id="canvas_ur" class="canvas"></div>
  <div class="clear"></div>
  <div id="canvas_ll" class="canvas"></div><div id="canvas_lr" class="canvas"></div>
  <div class="clear"></div>
  <input type="hidden" name="go" value="show_snippet"/>
  <input type="hidden" name="snippet" value="georef/georef"/>
  <input type="hidden" name="action" value="georef"/>
  <div class="headleft">Datei:</div>
  <div class="file-box">
    <input type="hidden" name="file_id" value="<? echo $blatt->get_id(); ?>">
    <input id="ordner" name="ordner" value="<? echo $blatt->get('ordner'); ?>">
    <input id="file_name" name="file_name" value="<? echo $blatt->get('file_name'); ?>">
  </div>
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

  <div id="ul_box" class="coord-box">
    <div class="headleft">Oben links:</div>
    <div class="cell"><input id="ulrw" name="ulrw" value="<?php echo $blatt->get('ulrw'); ?>"></div>
    <div class="cell"><input id="ulhw" name="ulhw" value="<?php echo $blatt->get('ulhw'); ?>"></div>
    <div class="cell"><input id="ulx"  name="ulx"></div>
    <div class="cell"><input id="uly"  name="uly"></div>
    <div class="cell residues"><input id="uldrw" name="uldrw"></div>
    <div class="cell residues"><input id="uldhw" name="uldhw"></div>
    <div class="cell residues"><input id="uldx"  name="uldx"></div>
    <div class="cell residues"><input id="uldy"  name="uldy"></div>
  </div>
  <div class="clear"></div>

  <div id="ur_box" class="coord-box">
    <div class="headleft">Oben rechts:</div>
    <div class="cell"><input id="urrw" name="urrw" value="<?php echo $blatt->get('urrw'); ?>"></div>
    <div class="cell"><input id="urhw" name="urhw" value="<?php echo $blatt->get('urhw'); ?>"></div>
    <div class="cell"><input id="urx"  name="urx"></div>
    <div class="cell"><input id="ury"  name="ury"></div>
    <div class="cell residues"><input id="urdrw" name="urdrw"></div>
    <div class="cell residues"><input id="urdhw" name="urdhw"></div>
    <div class="cell residues"><input id="urdx"  name="urdx"></div>
    <div class="cell residues"><input id="urdy"  name="urdy"></div>
  </div>
  <div class="clear"></div>

  <div id="lr_box" class="coord-box">
    <div class="headleft">Unten rechts:</div>
    <div class="cell"><input id="lrrw" name="lrrw" value="<?php echo $blatt->get('lrrw'); ?>"></div>
    <div class="cell"><input id="lrhw" name="lrhw" value="<?php echo $blatt->get('lrhw'); ?>"></div>
    <div class="cell"><input id="lrx"  name="lrx"></div>
    <div class="cell"><input id="lry"  name="lry"></div>
    <div class="cell residues"><input id="lrdrw" name="lrdrw"></div>
    <div class="cell residues"><input id="lrdhw" name="lrdhw"></div>
    <div class="cell residues"><input id="lrdx"  name="lrdx"></div>
    <div class="cell residues"><input id="lrdy"  name="lrdy"></div>
  </div>
  <div class="clear"></div>

  <div id="ll_box" class="coord-box">
    <div class="headleft">Unten links:</div>
    <div class="cell"><input id="llrw" name="llrw" value="<?php echo $blatt->get('llrw'); ?>"></div>
    <div class="cell"><input id="llhw" name="llhw" value="<?php echo $blatt->get('llhw'); ?>"></div>
    <div class="cell"><input id="llx"  name="llx"></div>
    <div class="cell"><input id="lly"  name="lly"></div>
    <div class="cell residues"><input id="lldrw" name="lldrw"></div>
    <div class="cell residues"><input id="lldhw" name="lldhw"></div>
    <div class="cell residues"><input id="lldx"  name="lldx"></div>
    <div class="cell residues"><input id="lldy"  name="lldy"></div> ds [m] <div class="cell residues"><input id="ds"  name="ds"></div>
  </div>
  <div class="clear"></div>

  <div class="submit-box">
    <input id="cancel_button" type="button" name="go" value="Abbrechen" onclick="cancel();">
    <input id="georef_button" type="button" name="go" value="Georeferenzieren" onclick="georef();">
  </div>
  <div class="clear"></div>
</div>

<script><?
  if ($blatt->get('ordner') === 'ordner1') { ?>
    let views = {
      ul: {
        key: 'ul',
        offset: [500, 100],
        size: 2000
      },
      ur:
      {
        key: 'ur',
        offset: [<? echo $blatt->get('width') - 2000; ?>, 100],
        size: 2000
      },
      lr:
      {
        key: 'lr',
        offset: [<? echo $blatt->get('width') - 2000; ?>, <? echo $blatt->get('height') - 2700; ?>],
        size: 2000
      },
      ll:
      {
        key: 'll',
        offset: [500, <? echo $blatt->get('height') - 2700; ?>],
        size: 2000
      }
    }; <?
  }
  else { ?>
    views = {
      ul: {
        key: 'ul',
        offset: [0, 0],
        size: 2000
      },
      ur:
      {
        key: 'ur',
        offset: [<? echo $blatt->get('width') - 2000; ?>, 0],
        size: 2000
      },
      lr:
      {
        key: 'lr',
        offset: [<? echo $blatt->get('width') - 2000; ?>, <? echo $blatt->get('height') - 3500; ?>],
        size: 2000
      },
      ll:
      {
        key: 'll',
        offset: [0, <? echo $blatt->get('height') - 3500; ?>],
        size: 2000
      }
    }; <?
  } ?>

  const ordner = '<? echo $blatt->get('ordner'); ?>';
  const file_name = '<? echo $blatt->get('file_name'); ?>';
  const getPixSize = (ptsKarte, ptsNatur) => {
    return [
      (parseFloat(ptsNatur[1][0] - ptsNatur[0][0]) + parseFloat(ptsNatur[2][0] - ptsNatur[3][0])) / (parseFloat(ptsKarte[1][0] - ptsKarte[0][0]) + parseFloat(ptsKarte[2][0] - ptsKarte[3][0])),
      (parseFloat(ptsNatur[0][1] - ptsNatur[3][1]) + parseFloat(ptsNatur[1][1] - ptsNatur[2][1])) / (parseFloat(ptsKarte[0][1] - ptsKarte[3][1]) + parseFloat(ptsKarte[1][1] - ptsKarte[2][1]))
    ];
  }
  Object.keys(views).forEach((key) => {
    const params = {
      only_main: '1',
      action: 'get_file',
      ordner: ordner,
      file_name : file_name,
      view : key,
      offset_x : views[key].offset[0],
      offset_y : views[key].offset[1],
      size : views[key].size
    };
    const queryString = Object.entries(params)
      .map(([key, value]) => `${encodeURIComponent(key)}=${encodeURIComponent(value)}`)
      .join("&");
    const url = `<?php echo URL . APPLVERSION . $script_url; ?>&${queryString}`;
    console.log(`Get view image with url: ${url}`);

    views[key].viewer = OpenSeadragon({
      id: "canvas_" + key,
      prefixUrl: "https://cdnjs.cloudflare.com/ajax/libs/openseadragon/4.1.0/images/",
      showNavigator: false,
      animationTime: 0.5,
      blendTime: 0.1,
      constrainDuringPan: true,
      maxZoomPixelRatio: 2,
      minZoomLevel: 1,
      visibilityRatio: 1,
      zoomPerScroll: 1.2,
      tileSources: {
        type: 'image',
        url: url
      }
    });

    views[key].viewer.addHandler('canvas-click', function(event) {
      let webPoint = event.position;
      let viewportPoint = views[key].viewer.viewport.pointFromPixel(webPoint);
      let imagePoint = views[key].viewer.viewport.viewportToImageCoordinates(viewportPoint);
      console.log(`Klick bei: X=${Math.floor(imagePoint.x)}, Y=${Math.floor(imagePoint.y)}`);

      if (
        imagePoint.x >= 0 && imagePoint.x < views[key].size &&
        imagePoint.y >= 0 && imagePoint.y < views[key].size
      ) {

        views[key].viewer.viewport.zoomTo(7, viewportPoint, true);
        views[key].viewer.viewport.panTo(viewportPoint);
        console.log(`Wert ${key}.x: ${Math.round(imagePoint.x)} + ${views[key].offset[0]}`);
        document.getElementById(key + 'x').value = Math.round(imagePoint.x) + views[key].offset[0];
        console.log(`Wert ${key}.y: ${Math.round(imagePoint.y)} + ${views[key].offset[1]}`);
        document.getElementById(key + 'y').value = Math.round(imagePoint.y) + views[key].offset[1];
      } else {
        console.log('außerhalb geklickt');
      }

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
          [document.getElementById('ulx').value, <? echo $blatt->get('height'); ?> - document.getElementById('uly').value],
          [document.getElementById('urx').value, <? echo $blatt->get('height'); ?> - document.getElementById('ury').value],
          [document.getElementById('lrx').value, <? echo $blatt->get('height'); ?> - document.getElementById('lry').value],
          [document.getElementById('llx').value, <? echo $blatt->get('height'); ?> - document.getElementById('lly').value]
        ];
        const ptsNatur = [
          [document.getElementById('ulrw').value, document.getElementById('ulhw').value],
          [document.getElementById('urrw').value, document.getElementById('urhw').value],
          [document.getElementById('lrrw').value, document.getElementById('lrhw').value],
          [document.getElementById('llrw').value, document.getElementById('llhw').value]
        ];
        const pixSize = getPixSize(ptsKarte, ptsNatur);
        const result = helmert2D(ptsKarte, ptsNatur);
        document.getElementById('uldrw').value = result.v[0][0];
        document.getElementById('uldhw').value = result.v[0][1];
        document.getElementById('urdrw').value = result.v[1][0];
        document.getElementById('urdhw').value = result.v[1][1];
        document.getElementById('lrdrw').value = result.v[2][0];
        document.getElementById('lrdhw').value = result.v[2][1];
        document.getElementById('lldrw').value = result.v[3][0];
        document.getElementById('lldhw').value = result.v[3][1];
        document.getElementById('uldx').value = result.v[0][0] / pixSize[0];
        document.getElementById('uldy').value = result.v[0][1] / pixSize[1];
        document.getElementById('urdx').value = result.v[1][0] / pixSize[0];
        document.getElementById('urdy').value = result.v[1][1] / pixSize[1];
        document.getElementById('lrdx').value = result.v[2][0] / pixSize[0];
        document.getElementById('lrdy').value = result.v[2][1] / pixSize[1];
        document.getElementById('lldx').value = result.v[3][0] / pixSize[0];
        document.getElementById('lldy').value = result.v[3][1] / pixSize[1];
        document.getElementById('ds').value = result.s;
        $('.residues').css('border-color', (result.s > 1.5 ? 'red' : 'green'));
        $('.residues').css('color', (result.s > 1.5 ? 'red' : 'green'));
        console.log('result: %o', result);
        debug_r = result;

        document.getElementById('georef_button').style.display = 'block';
      }
    });
  });

  /**
   * Sendet das Formular zur Georeferenzierung und Speicherung der Ergebnisdaten ab und
   * läd das nächste Bild
   */
  function georef() {
    document.GUI.submit();
  }

  function cancel() {
    $('input[name="action"]').val('cancel_file');
    document.GUI.submit();
  }

</script>