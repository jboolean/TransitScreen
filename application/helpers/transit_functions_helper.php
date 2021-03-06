<?php
/**
 * Function: clean_destination
 * @param string $s
 * @return string
 *
 * Receives an API-generated destination and fixes it to make it fit better
 * or read better on the screen.  For instance, the WMATA API prints out 'NW'
 * incorrectly as 'Nw'.  This function fixes that.  This is also a good place
 * to add other API corrections as you find them.
 *
 */
function clean_destination($s){
  // Make ALL CAPS more paletable
  if (preg_match('/[\WA-Z]+/', $s)) {
    $s = ucwords(strtolower($s));
  }

  $s = str_replace('North to ','',$s);
  $s = str_replace('South to ','',$s);
  $s = str_replace('East to ','',$s);
  $s = str_replace('West to ','',$s);

  $s = str_replace(' + ', ' & ', $s);

  //$s = str_replace('Station','',$s);
  $s = str_replace('Square','Sq',$s);
  $s = str_replace('Pike','Pk',$s);

  $s = str_replace(', ',' &raquo; ',$s);

  $s = preg_replace('/Nw(\s|$)/','NW', $s);
  $s = preg_replace('/Ne(\s|$)/','NE', $s);
  $s = preg_replace('/Sw(\s|$)/','SW', $s);
  $s = preg_replace('/Se(\s|$)/','SE', $s);

  $s = str_replace('Court House Metro - ','', $s);
  $s = str_replace('Court House Metro to ','', $s);
  $s = str_replace('Columbia Pk/Dinwiddie - ','', $s);
  $s = str_replace('Shirlington Station to ','', $s);

  return $s;
}

/* Convert minutes from now to seconds since the epoch */
function future_mins_to_abs_secs($minsFromNow) {
  return time() + $minsFromNow * 60;
}

function get_full_direction_text($shortDirection) {
  $FULL_DIRECTIONS = [
    'N' => 'North',
    'S' => 'South',
    'E' => 'East',
    'W' => 'West'
  ];
  return $FULL_DIRECTIONS[$shortDirection];
}


/**
 * Function: get_rail_predictions
 * @param int $station_id - the WMATA station id
 * @param string $api_key - the WMATA API key
 * @return mixed - the returned array (data)
 *
 * This function gets the rail predictions from the WMATA API, formats the data
 * nicely and returns the data.
 *
 */
function get_rail_predictions($station_id, $api_key){
  $trains = array();

  // Load the train prediction XML from the API
  $url = "http://api.wmata.com/StationPrediction.svc/GetPrediction/$station_id?api_key=$api_key";
  $railxml;
  try {
    $railxml = load_remote_xml($url);
  } catch (RemoteLoadException $e) {
    error_log($e);
    return [];
  }

  $predictions = $railxml->Trains->AIMPredictionTrainInfo;

  // For each prediction, put the data into an array to return
  for($t = 0; $t < count($predictions); $t++){

    $newitem['stop_name'] = (string) $predictions[$t]->LocationName;
    $newitem['agency'] = 'metrorail';
    $newitem['route'] = (string) $predictions[$t]->Line;
    $newitem['destination'] = (string) $predictions[$t]->DestinationName;

    // Ignore "No passengers" and no destination
    if (($newitem['destination'] != '') && ($newitem['route'] != 'No')) {
      switch ((string) $predictions[$t]->Min) {
        case 'ARR':
        case 'BRD':
          // Predictions 'ARR' and 'BRD' will be omitted
          // $newitem['prediction'] = 0;
          break;
        default:
          $newitem['prediction'] = future_mins_to_abs_secs((int) $predictions[$t]->Min);
          $trains[] = $newitem;
      }
    }
  }

  // Do an array_multisort to sort by prediction time, then color, then destination
  foreach($trains as $key => $row){
    $r[$key] = $row['route'];
    $d[$key] = $row['destination'];
    $p[$key] = $row['prediction'];
  }
  array_multisort($p, SORT_ASC, $r, SORT_ASC, $d, SORT_ASC, $trains);

  return $trains;
}

/**
 * Function: combine_agencies
 *
 * @param array $busgroups
 * @param int $max
 * @return array
 *
 * Take the groups of bus predictions from different agencies (but for one stop),
 * merge them together and sort them by prediction regardless of agency.  Return
 * the newly sorted array.
 *
 */
function combine_agencies(array $busgroups, $max = 99) {
  $combined = array();
  for($g = 0; $g < count($busgroups); $g++) {
    $combined = array_merge($combined,$busgroups[$g]);
  }

  // Sort by prediction, then route, then destination
  foreach($combined as $key => $row){
    $r[$key] = $row['route'];
    $d[$key] = $row['destination'];
    $p[$key] = $row['prediction'];
    $a[$key] = $row['agency'];
    $s[$key] = $row['stop_name'];
    $i[$key] = $row['direction'];
  }
  array_multisort($p, SORT_ASC, $r, SORT_DESC, $d, SORT_ASC, $i, SORT_DESC, $combined);

  return $combined;
}

/**
 * Function: get_bus_predictions
 *
 * @param mixed $stop_id - the stop id
 * @param string $api_key - the API key for the agency
 * @param string $agency - the agency id
 * @return mixed - array of data (unrendered) or a string (rendered)
 *
 *
 */
function get_bus_predictions($stop_id,$keys,$agency) {
  $out = '';

  // Call the different API function based on the agency name.
  switch ($agency) {
    case 'wmata':
    case 'metrobus':
      $out = get_metrobus_predictions($stop_id, $keys['wmata']);
      break;
    case 'dc-circulator':
    case 'circulator':
      $out = get_nextbus_predictions($stop_id, 'dc-circulator');
      break;
    case 'pgc':
      $out = get_nextbus_predictions($stop_id, 'pgc');
      break;
    case 'umd':
      $out = get_nextbus_predictions($stop_id, 'umd');
      break;
    case 'art':
      $out = get_connexionz_predictions($stop_id, 'art');
      break;
    case 'rideon':
      $out = get_rideon_predictions($stop_id, $keys['rideon']);
      break;
  }

  return $out;
}

/**
 * Function: get_metrobus_predictions
 *
 * @param int $stop_id - the stop id
 * @param string $api_key - the WMATA API key
 * @return array - the Metrobus prediction data for this stop
 *
 * This function gets the Metrobus arrival predictions for a given Metrbus stop
 * and returns the predictions in an array.
 *
 */
function get_metrobus_predictions($stop_id,$api_key){
  $out = [];
  // Call the API
  $url = "http://api.wmata.com/NextBusService.svc/Predictions?StopID=$stop_id&api_key=$api_key";

  $busxml;
  try {
    $busxml = load_remote_xml($url);
  } catch (RemoteLoadException $e) {
    error_log($e);
    return $out;
  }

  $stop_name = (string) $busxml->StopName;
  $predictions = $busxml->Predictions->NextBusPrediction;

  $limit = min(count($predictions), 4);

  // Add the predictions into an array
  for($b = 0; $b < $limit; $b++){
    $rollsign = (string) $predictions[$b]->DirectionText;
    $direction = '';
    if (strpos($rollsign, 'North to') === 0) {
      $direction = 'N';
      $rollsign = substr($rollsign, 9);
    } else if (strpos($rollsign, 'South to') === 0) {
      $direction = 'S';
      $rollsign = substr($rollsign, 9);
    } else if (strpos($rollsign, 'East to') === 0) {
      $direction = 'E';
      $rollsign = substr($rollsign, 8);
    } else if (strpos($rollsign, 'West to') === 0) {
      $direction = 'W';
      $rollsign = substr($rollsign, 8);
    }

    $newitem['stop_name'] = $stop_name;
    $newitem['agency'] = 'Metrobus';
    $newitem['route'] = (string) $predictions[$b]->RouteID;
    $newitem['destination'] = $rollsign;
    $newitem['direction'] = $direction;
    $newitem['prediction'] = future_mins_to_abs_secs((int) $predictions[$b]->Minutes);
    $out[] = $newitem;
  }

  // Return the array of predictions.
  return $out;
}


function get_rideon_predictions($stop_id, $api_key) {
  // The official RideOn server is a custom API that is often broken… and the docs are down… here goes…
  $url = "http://rideonrealtime.net/arrivals/$stop_id.xml?auth_token=$api_key";

  $arrival;
  try {
    $arrival = load_remote_xml($url);
  } catch (RemoteLoadException $e) {
    error_log($e);
    return [];
  }


  $stop_name = (string) $arrival->stop->stop_name;

  $out = [];
  foreach ($arrival->calculated_arrivals->calculated_arrival as $calculated_arrival) {    
    $newitem['stop_name'] = $stop_name;
    $newitem['agency'] = 'rideon';
    $newitem['route'] = (string) $calculated_arrival->route->route_short_name;
    $newitem['destination'] = (string) $calculated_arrival->trip->trip_headsign;
    $newitem['prediction'] = strtotime((string) $calculated_arrival->calculated_time);
    $out[] = $newitem;
  }

  return $out;
}

// any OneBusAway deployment
function get_oba_predictions($stop_id, $deployment, $api_key) {
  $url = "http://$deployment/api/where/arrivals-and-departures-for-stop/$stop_id.xml?minutesBefore=0&minutesAfter=60&key=$api_key";
  $busxml;
  try {
    $busxml = load_remote_xml($url);
  } catch (RemoteLoadException $e) {
    error_log($e);
    return [];
  }

  $busxml = $busxml->data;

  $stop = $busxml->xpath("//references/stops/stop[id = '$stop_id']")[0];
  $stop_name = (string) $stop->name;

  $out = [];
  foreach ($busxml->entry->arrivalsAndDepartures->arrivalAndDeparture as $visit) {
    // stopTime is predicted time in sec since epoch
    $stopTime = $visit->predictedDepartureTime / 1000;
    if ($stopTime == 0) {
      $stopTime = $visit->scheduledDepartureTime / 1000;
    }
    if ($stopTime < time()) {
      continue;
    }
    $routeId = $visit->routeId;
    $route = $busxml->xpath("//references/routes/route[id = '$routeId']")[0];
    $agencyId = (string) $route->agencyId;
    $agency = $busxml->xpath("//references/agencies/agency[id = '$agencyId']")[0];

    $newitem['stop_name'] = $stop_name;
    $newitem['agency'] = (string) $agency->name;
    $newitem['route'] = (string) $visit->routeShortName;
    $newitem['destination'] = (string) $visit->tripHeadsign;
    $newitem['prediction'] = $stopTime;
    $newitem['direction'] = (string) $stop->direction;
    $out[] = $newitem;
  }

  return $out;

}

/**
 * Function get_nextbus_predictions
 *
 * @param int $stop_id
 * @param string $agency_tag
 * @return array
 *
 * Get the NextBus predictions for this bus stop and return the data in an array.
 * This is what we will use for the DC Circulator, Shuttle UM and Prince George's County's TheBus.
 *
 */
function get_nextbus_predictions($stop_id,$agency_tag){

  $out = [];
  if($agency_tag == 'dc-circulator'){
    $agency = 'Circulator';
    $url = "http://webservices.nextbus.com/service/publicXMLFeed?command=predictions&a=$agency_tag&stopId=$stop_id";
  }
  elseif($agency_tag == 'pgc'){
	  $agency = 'pgc';
    $url = "http://webservices.nextbus.com/service/publicXMLFeed?command=predictions&a=$agency_tag&$stop_id";
  }
  elseif($agency_tag == 'umd'){
    $agency = 'umd';
    $url = "http://webservices.nextbus.com/service/publicXMLFeed?command=predictions&a=$agency_tag&stopId=$stop_id";
  }

  try {
    $busxml = load_remote_xml($url);
  } catch (RemoteLoadException $e) {
    error_log($e);
    return $out;
  }

  //foreach predictions
  foreach($busxml->predictions as $pred){
    $stopname = (string) $pred->attributes()->stopTitle;
    $routename = (string) $pred->attributes()->routeTag;
    //foreach direction
    foreach($pred->direction as $dir){
      $destination = (string) $dir->attributes()->title;
      //foreach prediction
      foreach($dir->prediction as $p){
        unset($newitem);
        $newitem['stop_name'] = $stopname;
        $newitem['agency'] = $agency;
        $newitem['route'] = $routename;
        $newitem['destination'] = $destination;
        $newitem['prediction'] = ((int) $p['epochTime'] / 1000);
        $out[] = $newitem;
      }
    }
  }

  return $out;
}

/**
 * Function: get_connexionz_predictions
 *
 * @param mixed $stop_id - the stop id
 * @param string $agency - the agency name
 * @return array - an array of bus predictions from Connexionz
 *
 * This function collects the bus arrival predictions from ART's Connexionz API
 * and returns the data in an array.
 *
 */
function get_connexionz_predictions($stop_id,$agency) {
  if($agency == 'art'){
    // Call the XML from the API
    $url = "http://realtime.commuterpage.com/RTT/Public/Utility/File.aspx?ContentType=SQLXML&Name=RoutePositionET.xml&PlatformTag=$stop_id";
    $agency_name = 'ART';
  } else {
    return [];
  }

  $out = [];

  try {
    $busxml = load_remote_xml($url);
  } catch (RemoteLoadException $e) {
    error_log($e);
    return [];
  }

  // Put the predictions into an array
  $predictions = $busxml->Platform;
  $stop_name = (string) $busxml->Platform['Name'];
  foreach($predictions->Route as $route){ //For each route
    foreach($route->Destination->Trip as $trip){
      $newitem['stop_name'] = $stop_name;
      $newitem['agency'] = $agency_name;
      $newitem['route'] = (string) $route['RouteNo'];
      $newitem['destination'] = (string) $route['Name'];
      $newitem['prediction'] = future_mins_to_abs_secs((int) $trip['ETA']);
      $out[] = $newitem;
    }
  }

  // Use array_multisort to sort the predictions by time, then route, then
  // destination, then agency
  foreach($out as $key => $row){
    $a[$key] = $row['agency'];
    $r[$key] = $row['route'];
    $d[$key] = $row['destination'];
    $p[$key] = $row['prediction'];
  }
  array_multisort($p, SORT_ASC, $r, SORT_DESC, $d, SORT_ASC, $a, SORT_ASC, $out);

  return $out;
}

/**
 * Function: get_cabi_status
 *
 * @param int $station_id - the id of the CaBi station
 * @return array - an array of the station data
 *
 * Given a CaBi station id, get the station data and return an array with the
 * station status, e.g. number of bikes, number of docks, and the station name.
 * or FALSE if load failed.
 *
 */
function get_cabi_status($station_id){
  // Load the XML file for the entire system.
  $cabixml = "http://www.capitalbikeshare.com/stations/bikeStations.xml";
  try {
    $cabixml = load_remote_xml($url);
  } catch (RemoteLoadException $e) {
    error_log($e);
    return FALSE;
  }

  // Find the station with the parameter id and get the data for it.
  $stations = $cabixml->station;
  foreach($stations as $station){
    if((int) $station->id == $station_id) {
      $cabi['stop_name'] = (string) $station->name;
      $cabi['bikes'] = (int) $station->nbBikes;
      $cabi['docks'] = (int) $station->nbEmptyDocks;
      break;
    }
  }

  // Return an array with the cabi station data.
  return $cabi;
}

class RemoteLoadException extends Exception {
}

function load_remote_xml($url) {
  $timeStart = time();
  $ch = curl_init();
  $timeout = 7;
  curl_setopt($ch, CURLOPT_URL, $url);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
  curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $timeout);
  curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);

  $data = curl_exec($ch);
  if ($data === FALSE) {
    throw new RemoteLoadException('Failed to load '.$url);
  }
  curl_close($ch);
  $xml = simplexml_load_string($data);

  if ($xml === FALSE) {
    throw new RemoteLoadException('Couldn\'t parse '.$url);
  }
  $timeElapsed = time() - $timeStart;
  if ($timeElapsed > 2) {
    error_log("[Instrumentation] Slow load for url=$url time=$timeElapsed");
  }

  return $xml;
}

?>
