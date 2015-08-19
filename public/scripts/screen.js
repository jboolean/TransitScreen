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

function render() {
  // clear everything and re-render.
  $('.col').empty();

  // loop through local data and create templates.
  for(var key in blocks){
    var output = '';
    var vcount = 0;

    classname_base = translate_class_name(blocks[key].type);
    if(classname_base == 'bus'){
      containerclass = 'bus_stop_container';
    }
    else {
      containerclass = classname_base + '_container';
    }

    // For bus or rail, output data this way
    if(blocks[key].type == 'subway' || blocks[key].type == 'bus'){

      output += '<div class="' + containerclass + '">';
      output += ' <div class="' + classname_base + '_location">';
      output += '   <div id="' + classname_base + '_logo"></div>';
      output += '   <h2>' + blocks[key].name + '</h2>';
      output += ' </div>';
      output += ' <table id="' + classname_base + '_table">';

      // Sometimes insteady of a vehicles array it is the route.
      var vehicles = blocks[key].vehicles;

      $.each(vehicles, function(v,vehicle){

        var vout = '';

        if(vehicle.predictions.length > 0){
          var subsequent = '';
          var class_suffix = get_suffix(vehicle.route, vehicle.agency);

          if(blocks[key].type == 'subway') {
            railsuffix = '_dark transparent';
          }
          else  {
            railsuffix = '';
          }

          vout += '   <tr class="' + classname_base + '_table_module" id="block-' + blocks[key].id + '-vehicle-' + v + '">';
          vout += '     <td class="' + classname_base + '_table_line">';
          vout += '       <div class="' + classname_base + '_line ' + classname_base + '_line_' + class_suffix + '">';
          vout += '         <h3>' + vehicle.route + '</h3>';
          vout += '       </div>';
          vout += '     </td>';
          vout += '     <td class="' + classname_base + '_table_destination ' + classname_base + '_line_' + class_suffix + railsuffix + '">';
          vout += get_heading(vehicle);
          vout += '     </td>';
          $.each(vehicle.predictions, function(p, prediction) {
            // 1st prediction
            if(p == 0) {
              vout += '     <td class="' + classname_base + '_table_time">';
              vout += '       <h3>' + prediction + '</h3>';
              vout += '       <span class="' + classname_base + '_min">MINUTE' + pluralize(prediction) + '</span>';
              vout += '     </td>';
            }

            // 2nd & 3rd predictions
            if(p >= 1 && p <= 2) {
              subsequent += '       <h4>' + prediction + '</h4>';
            }

          });

          vout += '     <td class="' + classname_base + '_table_upcoming">' + subsequent + '</td>';
          vout += '   </tr>';

          // Get rid of buses over block limit
          if(blocks[key].type == 'bus' && (vcount >= buslimit)){
            vout = '';
          }

          output += vout;
          vcount++;
        }
      });
      output += '   </table>';
      output += '</div>';
    }

    // For CaBi, output data this way
    if(blocks[key].type == 'cabi'){

      var bikelist = '';

      // For each station, assemble the table row
      $.each(blocks[key].stations, function(c, cabistation) {
        bikelist += '   <tr class="cabi_data">';
        bikelist += '     <td class="pie"><img src="https://chart.googleapis.com/chart?cht=p&chs=100x80&chd=t:' + cabistation.bikes + ',' + cabistation.docks + '&chco=ff0000|b3b3b3&chf=bg,s,000000&chp=1.58" /></td>';
        bikelist += '     <td class="cabi_location">';
        bikelist += '       <span class="cabi_dock_location">' + cabistation.stop_name + '</span>';
        bikelist += '     </td>';
        bikelist += '     <td>';
        bikelist += '       <h3 class="cabi_bikes">' + cabistation.bikes + '</h3>';
        bikelist += '     </td>';
        bikelist += '     <td>';
        bikelist += '       <h3 class="cabi_docks">' + cabistation.docks + '</h3>';
        bikelist += '     </td>';
        bikelist += '   </tr>';
      });

      output += '<div id="block-' + blocks[key].id + '" class="' + containerclass + '">';
      output += ' <table id="' + classname_base + '_table">';
      output += '   <tr class="' + classname_base + '_header">';
      output += '     <td colspan="2">';
      output += '       <span class="cabi_icon">&nbsp;</span>';
      output += '     </td>';
      output += '     <td class="bikes">';
      output += '       <h4>BIKES</h4>';
      output += '     </td>';
      output += '     <td class="docks">';
      output += '       <h4>DOCKS</h4>';
      output += '     </td>';
      output += '   </tr>';
      output += bikelist;
      output += ' </table>';
      output += '</div>';
    }

    if(blocks[key].type == 'custom'){
      output += '<div id="block-' + blocks[key].id + '" class="' + containerclass + '">';
      output +=   blocks[key].custom_body;
      output += '</div>';
    }

    if('vehicles' in blocks[key] || blocks[key].type == 'cabi' || blocks[key].type == 'custom'){
      if($('#block-' + blocks[key].id).length > 0){
        $('#block-' + blocks[key].id).html(output);
      }
      else {
        //$("#results").append('<div class="block" id="block-' + blocks[key].id + '">' + output + '</div>');
        $("#col-" + blocks[key].column).append('<div class="block" id="block-' + blocks[key].id + '" order="' + blocks[key].order + '">' + output + '</div>');
      }
    }
    else {
      $('#block-' + blocks[key].id).empty();
    }
  }
  reorder_blocks();
}

function reorder_blocks() {
  $.each($('.col'), function(c,colm){
    var mylist = $('#col-' + (c+1));
    var listitems = mylist.children('.block').get();

    listitems.sort(function(a, b) {
      var compA = $(a).attr('order');
      var compB = $(b).attr('order');
      return (compA < compB) ? -1 : (compA > compB) ? 1 : 0;
    })
    $.each(listitems, function(idx, itm) { mylist.append(itm); });
  });
}


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

