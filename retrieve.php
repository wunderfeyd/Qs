<?php
require("core.php");
QuasselHelper::killSession();

$put = QuasselHelper::readPutFile();

$store = new QuasselDataStore();
$result = $store->poll($put);
error_log($result);
echo $result;
?>
