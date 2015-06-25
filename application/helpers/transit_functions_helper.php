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
  $s = str_replace('North to ','',$s);
  $s = str_replace('South to ','',$s);
  $s = str_replace('East to ','',$s);
  $s = str_replace('West to ','',$s);

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
  $railxml = simplexml_load_file("http://api.wmata.com/StationPrediction.svc/GetPrediction/$station_id?api_key=$api_key");
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
          $newitem['prediction'] = (int) $predictions[$t]->Min;
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
      $buses = get_nextbus_predictions($stop_id, 'umd');
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
  if(!($busxml = simplexml_load_file("http://api.wmata.com/NextBusService.svc/Predictions?StopID=$stop_id&api_key=$api_key"))){
    return false;
  }
  $stop_name = (string) $busxml->StopName;
  $predictions = $busxml->Predictions->NextBusPrediction;

  $limit = min(count($predictions), 4);

  // Add the predictions into an array
  for($b = 0; $b < $limit; $b++){
    $rollsign = (string) $predictions[$b]->DirectionText;
    $direction = '';
    if (strpos($rollsign, 'North to') == 0) {
      $direction = 'N';
      $rollsign = substr($rollsign, 9);
    } else if (strpos($rollsign, 'South to') == 0) {
      $direction = 'S';
      $rollsign = substr($rollsign, 9);
    } else if (strpos($rollsign, 'East to') == 0) {
      $direction = 'E';
      $rollsign = substr($rollsign, 8);
    } else if (strpos($rollsign, 'West to') == 0) {
      $direction = 'W';
      $rollsign = substr($rollsign, 8);
    }

    $newitem['stop_name'] = $stop_name;
    $newitem['agency'] = 'Metrobus';
    $newitem['route'] = (string) $predictions[$b]->RouteID;
    $newitem['destination'] = $rollsign;
    $newitem['direction'] = $direction;
    $newitem['prediction'] = (int) $predictions[$b]->Minutes;
    $out[] = $newitem;
  }

  // Return the array of predictions.
  return $out;
}

//The RideOn server is very experimental and not production-ready.
// The official RideOn API is broken
function get_rideon_predictions($stop_id, $api_key) {
  return get_oba_predictions($stop_id, 'rideon.julianboilen.com:8080', $api_key);
}

// any OneBusAway deployment
function get_oba_predictions($stop_id, $deployment, $api_key) {
  $out = [];

  $busxml = simplexml_load_file("http://$deployment/api/where/arrivals-and-departures-for-stop/$stop_id.xml?minutesBefore=0&key=$api_key");

  if (!($busxml)) {
    return false;
  }

  $busxml = $busxml->data;

  $stop = $busxml->xpath("//references/stops/stop[id = '$stop_id']")[0];
  $stop_name = (string) $stop->name;

  $out = [];
  foreach ($busxml->entry->arrivalsAndDepartures->arrivalAndDeparture as $visit) {
    $stopTime = $visit->predictedDepartureTime;
    if ($stopTime == 0) {
      $stopTime = $visit->scheduledDepartureTime;
    }
    $routeId = $visit->routeId;
    $route = $busxml->xpath("//references/routes/route[id = '$routeId']")[0];
    $agencyId = (string) $route->agencyId;
    $agency = $busxml->xpath("//references/agencies/agency[id = '$agencyId']")[0];

    $newitem['stop_name'] = $stop_name;
    $newitem['agency'] = (string) $agency->name;
    $newitem['route'] = (string) $visit->routeShortName;
    $newitem['destination'] = (string) $visit->tripHeadsign;
    $newitem['prediction'] = (int) round(((float)$stopTime - ((float)time() * 1000))/(60*1000));
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

  if($agency_tag == 'dc-circulator'){
    $agency = 'Circulator';
    $busxml = simplexml_load_file("http://webservices.nextbus.com/service/publicXMLFeed?command=predictions&a=$agency_tag&stopId=$stop_id");
  }
  elseif($agency_tag == 'pgc'){
	  $agency = 'pgc';
    $busxml = simplexml_load_file("http://webservices.nextbus.com/service/publicXMLFeed?command=predictions&a=$agency_tag&$stop_id");
  }
  elseif($agency_tag == 'umd'){
    $agency = 'umd';
    $busxml = simplexml_load_file("http://webservices.nextbus.com/service/publicXMLFeed?command=predictions&a=$agency_tag&stopId=$stop_id");
  }

  //foreach predictions
  foreach($busxml->predictions as $pred){
    $stopname = (string) $pred->attributes()->stopTitle;
    $routename = (string) $pred->attributes()->routeTitle;
    //foreach direction
    foreach($pred->direction as $dir){
      $destination = (string) $dir->attributes()->title;
      //foreach prediction
      foreach($dir->prediction as $p){
        unset($newitem);
        $newitem['stop_name'] = $stopname;
        $newitem['agency'] = $agency;
        $newitem['route'] = $routename;
        $newitem['destination'] = $routename . ' (' . $destination . ')';
        $newitem['prediction'] = (int) $p['minutes'];
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
    $busxml = simplexml_load_file("http://realtime.commuterpage.com/RTT/Public/Utility/File.aspx?ContentType=SQLXML&Name=RoutePositionET.xml&PlatformTag=$stop_id");
    $agency_name = 'ART';
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
      $newitem['prediction'] = (int) $trip['ETA'];
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
 *
 */
function get_cabi_status($station_id){
  // Load the XML file for the entire system.
  $cabixml = simplexml_load_file("http://www.capitalbikeshare.com/stations/bikeStations.xml");

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

?>
