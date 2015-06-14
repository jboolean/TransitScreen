<?php

  // This page is the "super page" that loads the IFRAME that contains the actual
  // screen information.

  $callurl = base_url() . 'index.php/screen/inner/'   . $id;  // The url to call for prediction updates
  $pollurl = base_url() . 'index.php/update/version/' . $id;  // The url to call to check whether the screen needs
                                                              // needs to be refreshed.
?><html>
  <head>
    <title>Transit Screen</title>
    <meta name="robots" content="none">
    <link rel="shortcut icon" href="<?php print base_url(); ?>/public/images/favicon.ico" />
    <link rel="apple-touch-icon" href="<?php print base_url(); ?>/public/images/CPlogo.png" />
    <script type="text/javascript" src="<?php print base_url(); ?>/public/scripts/jquery-1.7.1.min.js"></script>
    <script type="text/javascript" src="<?php print base_url(); ?>/public/scripts/jquery.timers-1.2.js"></script>

    <script type="text/javascript">
      var CHECK_FREQUENCY = 10;
      var latestVersion = '';

      $(document).ready(function(){
        //Call the update function
        get_update();
        setInterval(get_update, CHECK_FREQUENCY * 1000);
      });

      function get_update() {
        // Poll the server for the latest version number

        $.getJSON('<?= $pollurl ?>',function(newVersion){
          // If that version number differs from the current version number,
          // create a new hidden iframe and append it to the body.  ID = version num
          if(newVersion === latestVersion) {
            return;
          }

          var oldVersion = latestVersion;
          //If the element already exists, remove it and replace it with a new version
          $('#frame-' + newVersion).remove();

          var newFrame = $('<iframe>', {
            id: 'frame-' + newVersion,
            src: '<?= $callurl ?>?' + Date.now()
          })
          .hide()
          .load(function() {
            debugger;
            $('#frame-' + oldVersion).remove();
            latestVersion = newVersion;
            $(this).show();
          })
          .appendTo($('body'));

        });
      }
    </script>

    <style type="text/css">
      body {
        margin: 0;
        background-color: #000;
      }
      iframe {
        border: 0;
        width: 100%;
        height: 100%;
      }
      .hidden {
        display: none;
      }
    </style>

  </head>

  <body>
    <noscript>
    <div id="noscript-padding"></div>
    <div id="noscript-warning" style="color:red">Transit Screen requires a JavaScript-enabled browser. <a href="https://www.google.com/support/adsense/bin/answer.py?answer=12654" target="_blank">Not sure how to enable it?</a></div>
    </noscript>
    <script type="text/javascript">
      if( navigator.userAgent.match(/Mobile/i) &&
          navigator.userAgent.match(/Safari/i)
        ) {
             document.title = "Transit Screen";
          }
    </script>
  </body>
</html>