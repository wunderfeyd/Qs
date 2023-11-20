<?php
require("core.php");
QuasselHelper::killSession();

$core = new QuasselCore();

$put = QuasselHelper::readPutFile();
$poll = json_decode($put, true);
if ($core->routeMessage("wunderfeyd", $poll["data"])) {
  echo "done";
} else {
  echo "argh";
}
?>
