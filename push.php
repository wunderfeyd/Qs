<?php
require("core.php");
QuasselHelper::killSession();

$core = new QuasselCore();
if ($core->routeMessage("wunderfeyd", "hallo")) {
  echo "done";
} else {
  echo "argh";
}
?>
