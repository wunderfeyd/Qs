<?php
require("core.php");
QuasselHelper::killSession();

$put = QuasselHelper::readPutFile();

$store = new QuasselDataStore();
$store->update($put);
echo "done";
?>
