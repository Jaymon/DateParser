<?php

include("./parse_date_class.php");


date_default_timezone_set('UTC');

$shortopts  = "";
$shortopts .= "f:";
$shortopts .= "t:";

$longopts  = array(
    "field:",
    "text:",
    "tz-offset:",
    "timestamp:"
);

$options = getopt($shortopts, $longopts);
if (empty($options["tz-offset"])) {
  $options["tz-offset"] = 0;
}
if (empty($options["timestamp"])) {
  $options["timestamp"] = time();
}

//var_dump($options);

echo "Start and stop values are unix timestamps.\n\n";

$dparse = new parse_date();
$dparse->setNow($options["timestamp"]);

if (!empty($options["field"]) || !empty($options["f"])) {

  foreach(array($options["field"], $options["f"]) as $v) {
    if (empty($v)) { continue; }

    $date_map_list = $dparse->findInField($v, $options["tz-offset"]);
    //print_r($date_map_list);
    foreach($date_map_list as $dm) {
      echo $dm->text(), " -> start: ", $dm->start(), ", stop: ", $dm->stop(), PHP_EOL;
    }

  }

}


if (!empty($options["text"]) || !empty($options["t"])) {

  foreach(array($options["text"], $options["t"]) as $v) {
    if (empty($v)) { continue; }

    $date_map_list = $dparse->findInText($v, $options["tz-offset"]);
    //print_r($date_map_list);
    foreach($date_map_list as $dm) {
      echo $dm->text(), " -> start: ", $dm->start(), ", stop: ", $dm->stop(), PHP_EOL;
    }

  }

}

