var screenversion = 0;  // To be written by php
                        // To be updated by json
//var blankout = 100;   // After losing a connection, the screen
                        // will autodecrement each prediction for
                        // this number of minutes.
var buslimit = 99;      // Bus limit per block
var firstload = true;
var UPDATE_FREQUENCY = 30;
var queryurl = '../../update/json/' + screen_id; //e.g. http://localhost/index.php/update/json/1

var blocks = [];    // Array of stop and arrival data


function translate_class_name(jsonname){
  switch(jsonname) {
    case 'bus':
      return 'bus';
    case 'subway':
    case 'metro':
      return 'metro';
    case 'cabi':
      return 'cabi';
    case 'custom':
      return 'custom';
    default:
      return 'unknown';
  }
}

function get_heading(vehicle){
  var destination = vehicle.destination;
  var agency = vehicle.agency;

  var output = '';

  var dirText = direction_text(vehicle.direction);
  if (dirText) {
    output += '<h3>' + dirText + '</h3>';
  }

  if(agency == 'metrorail'){
    output += '<h3>' + destination + '</h3>';
  } else {
    output += '<h4>' + destination + '</h4>';
  }

  return output;
}

function direction_text(direction) {
  switch(direction){
    case 'E':
      return 'Eastbound to:';
    case 'S':
      return 'Southbound to:';
    case 'N':
      return 'Northbound to:';
    case 'W':
      return 'Westbound to:';
    default:
      return null;
  }
}

function get_suffix(route, agency){
  if(agency == 'metrorail'){
    switch(route) {
      case 'RD':
        return 'red';
      case 'OR':
        return 'orange';
      case 'YL':
        return 'yellow';
      case 'GR':
        return 'green';
      case 'BL':
        return 'blue';
    }
    return route;
  }

  if(agency == 'Metrobus') {
    return 'wmata';

  } else if (agency === 'Montgomery County MD Ride On') {
    return 'rideon';
  }

  return agency;
}

function pluralize(num) {
  if(num != 1){
    return 'S';
  }
  return '';
}

var refresh_data = function(firstRun){

  var url = queryurl + '?' +  Date.now();

  $.getJSON(url, function(json){
    // If the script can get the file, do the following...

      // Regenerate the templates from the presented structure
      if(firstRun){
        screenversion = json.screen_version;
      }

      if(json.sleep) {
        $('.col').empty();
        return;
      }

      $('#loading-box').remove();

      // For each stop ...
      $.each(json.stops,function(i,stop){
        thisid = stop.id;
        blocks[thisid] = stop; // Update each block with new data.
      });

      // Call the function to create or recreate the blocks based
      // on the updated data.
      render();

  })
  .error(function() {
    console.error('Failed to get new data.');
  });
};

// Do this as the initial load
$(document).ready(function () {
  refresh_data(true);
  // This triggers the data update
  setInterval(refresh_data, UPDATE_FREQUENCY * 1000);
});

