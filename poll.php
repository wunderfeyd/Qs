<?php
require("core.php");
QuasselHelper::killSession();

$core = new QuasselCore();

$put = QuasselHelper::readPutFile();
error_log("poll ".$put);
$poll = json_decode($put, true);
$uids = $poll["uids"];
$poll = $core->pollMessages("wunderfeyd", $uids);
if (!empty($poll)) {
  echo json_encode($poll);
} else {
  echo "argh";
}
?>
