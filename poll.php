<?php
require("core.php");
QuasselHelper::killSession();

$core = new QuasselCore();
$poll = $core->pollMessages("wunderfeyd", array());
if (!empty($poll)) {
  echo "done".json_encode($poll);
} else {
  echo "argh";
}

$uids = $poll["uids"];
$poll = $core->pollMessages("wunderfeyd", $uids);
if (!empty($poll)) {
  echo "done".json_encode($poll);
} else {
  echo "argh";
}
?>
