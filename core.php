<?php
declare(strict_types=1);

// Helpers
class QuasselHelper {
  // Session deadlock prevention
  public static function killSession() {
    session_start(["cookie_lifetime" => 86400, "read_and_close" => true]);
  }

  // Read file by handle to eof
  public static function readFullFile(mixed $file) : string {
    $data = "";
    while (!feof($file)) {
      $data .= fread($file, 8192);
    }

    return $data;
  }

  // Read file from standard input
  public static function readPutFile() : string {
    $file = fopen("php://input", "r");
    if ($file===false) {
      return "";
    }

    $data = QuasselHelper::readFullFile($file);
    fclose($file);
    return $data;
  }

  // Common hash function here
  public static function hash(string $node) : string {
    return hash("sha256", $node, true, []);
  }

  // Build unique node ID
  public static function uniqueID() : string {
    return bin2hex(QuasselHelper::hash(strval(time())."_".random_bytes(64)));
  }
}

// Physical storage of nodes (on disk/per file at the moment)
class QuasselDataStore {
  public $directoryLevels;
  public $storeDirectory;

  public function __construct() {
    $this->directoryLevels = 4;
    $this->storeDirectory = "store/";
  }

  public function __destruct() {
  }

  // Update node index or data
  public function update(string $data) : bool {
    $rpc = json_decode($data, true);
    if (isset($rpc["node"]) && isset($rpc["type"]) && isset($rpc["chat"])) {
      $file = $this->openFile($rpc["chat"]."_".$rpc["node"], "c+");
      if ($file===false) {
        return false;
      }

      // TODO: Lock upgradable (from shared to exclusive)?
      flock($file, LOCK_EX);
      $stored = QuasselHelper::readFullFile($file);
      $stored = json_decode($stored, true);
      if (!isset($stored)) {
        $stored = array();
      }

      $result = $this->merge($stored, $rpc);
      if (!empty($result)) {
        fseek($file, 0, SEEK_SET);
        fwrite($file, json_encode($result));
        ftruncate($file, ftell($file));
        fflush($file);
      }

      flock($file, LOCK_UN);
      fclose($file);
    }

    return true;
  }

  // Return updates or wait if no updates are available
  public function poll(string $data) : string {
    $result = "";
    $rpc = json_decode($data, true);
    if (isset($rpc["node"]) && isset($rpc["type"]) && isset($rpc["chat"])) {
      $file = $this->openFile($rpc["chat"]."_".$rpc["node"], "r+");
      if ($file===false) {
        return "";
      }

      $count = 0;
      while (true) {
        if ($count==60) {
          return "";
        }

        $count++;
        flock($file, LOCK_SH);
        fseek($file, 0, SEEK_SET);
        $stored = QuasselHelper::readFullFile($file);
        $stored = json_decode($stored, true);
        if (!isset($stored)) {
          $stored = array();
        }

        $result = $this->index($stored, $rpc);
        flock($file, LOCK_UN);

        if ($rpc["type"]!==$result["type"]) {
          return "";
        }

        $uid = $result["uid"];
        if ((!isset($rpc["uid"]) || $rpc["uid"]!==$uid)) {
          $result["timestamp"] = time();
          if ($result["type"]==="data") {
          } elseif ($result["type"]==="index") {
            $result["uid"] = $uid;
            if (isset($rpc["uid"])) {
              $needed = $rpc["uid"];
              $messages = array_keys($result["messages"]);
              foreach ($messages as $message) {
                if (!isset($result["messages"][$message]["uid"]) || $result["messages"][$message]["uid"]<=$needed) {
                  unset($result["messages"][$message]);
                }
              }
            }
          } else {
            return "";
          }

          break;
        }

        // TODO: dirty! Use some kind of synchronization here in future (at best conditional variables or socket_pair?)
        sleep(1);
      }

      fclose($file);
    }

    return json_encode($result);
  }

  // Merge incoming data with on disk data
  public function merge(array &$stored, array $rpc) : array {
    if (isset($stored["type"])) {
      if ($stored["type"]!==$rpc["type"]) {
        error_log("type mismatch on store merge");
        return array();
      }
    } else {
      $stored["type"] = $rpc["type"];
    }

    if (isset($stored["chat"])) {
      if ($stored["chat"]!==$rpc["chat"]) {
        error_log("chat mismatch on store merge");
        return array();
      }
    } else {
      $stored["chat"] = $rpc["chat"];
    }

    if (isset($stored["uid"])) {
      $stored["uid"]++;
    } else {
      $stored["uid"] = 1;
    }

    if ($stored["type"]==="index") {
      if (isset($rpc["append"])) {
        $id = $rpc["append"];
        if (!isset($stored["messages"]) || !is_array($stored["messages"])) {
          $stored["messages"] = array();
        }

        foreach ($stored["messages"] as $message) {
          if ($message["id"]===$id) {
            return array();
          }
        }

        array_push($stored["messages"], array("id" => $id, "uid" => $stored["uid"], "timestamp" => time()));
      }
    } elseif ($stored["type"]==="data") {
      if (isset($rpc["data"])) {
        $stored["data"] = $rpc["data"];
      }
    }

    return $stored;
  }

  // Update index (design?)
  public function index(array $stored, array $rpc) : array {
    if (isset($stored["type"])) {
      if ($stored["type"]!==$rpc["type"]) {
        error_log("type mismatch on store index");
        return array();
      }
    }

    if (isset($stored["chat"])) {
      if ($stored["chat"]!==$rpc["chat"]) {
        error_log("chat mismatch on store index");
        return array();
      }
    }

    return $stored;
  }

  // Open storage file
  public function openFile(string $fileName, string $mode) : mixed {
    $complete = $this->storeDirectory;
    $n = 0;
    // TODO: Insert Unicode checks here if filesystem supports unicode/utf8
    $length = strlen($fileName);
    while ($n<$length-1 && $n<$this->directoryLevels) {
      $complete .= $fileName[$n].DIRECTORY_SEPARATOR;
      $n++;
    }

    $complete .= substr($fileName, $n);

    error_log($complete);
    if ($mode=="c+") {
      $dirname = dirname($complete);
      if (!is_dir($dirname) && !mkdir($dirname, 0777, true)) {
        return false;
      }
    }

    return fopen($complete, $mode);
  }
}

// Virtual storage over multiple instances
class QuasselWebStore {
  public function __construct() {
  }

  public function __destruct() {
  }

  // Store data to multiple instances
  public function store(array $servers, array $data) : bool {
    $multi = curl_multi_init();
    $requests = array();
    foreach ($servers as $url => $dht) {
      $request = json_encode($data);
      $requests[$url] = curl_init();
      curl_setopt($requests[$url], CURLOPT_RETURNTRANSFER, true);
      curl_setopt($requests[$url], CURLOPT_URL, "http://".$url."/store.php");
      curl_setopt($requests[$url], CURLOPT_CUSTOMREQUEST, "PUT");
      curl_setopt($requests[$url], CURLOPT_HTTPHEADER, array("Content-Type: application/json", "Content-Length: ".strlen($request)));
      curl_setopt($requests[$url], CURLOPT_POSTFIELDS, $request);
      curl_multi_add_handle($multi, $requests[$url]);
    }

    while (true) {
      $status = curl_multi_exec($multi, $active);

      if ($active) {
        curl_multi_select($multi);
      }

      while (true) {
        $info = curl_multi_info_read($multi);
        if ($info===false) {
          break;
        }
      }

      if (!$active || $status!=CURLM_OK) {
        break;
      }
    }

    $done = true;
    foreach ($servers as $url => $dht) {
      $content = curl_multi_getcontent($requests[$url]);
      if ($content!=="done") {
        $done = false;
      }

      curl_close($requests[$url]);
    }

    curl_multi_close($multi);

    return $done;
  }

  // Retrieve data from multiple instances
  public function retrieve(array $servers, array $data, array $uids) : array {
    $multi = curl_multi_init();
    $requests = array();
    foreach ($servers as $url => $dht) {
      $copy = $data;
      $hash = bin2hex($dht["hash"]);
      if (isset($uids[$hash])) {
        $copy["uid"] = $uids[$hash];
      }

      $request = json_encode($copy);
      $requests[$url] = curl_init();
      curl_setopt($requests[$url], CURLOPT_RETURNTRANSFER, true);
      curl_setopt($requests[$url], CURLOPT_URL, "http://".$url."/retrieve.php");
      curl_setopt($requests[$url], CURLOPT_CUSTOMREQUEST, "PUT");
      curl_setopt($requests[$url], CURLOPT_HTTPHEADER, array("Content-Type: application/json", "Content-Length: ".strlen($request)));
      curl_setopt($requests[$url], CURLOPT_POSTFIELDS, $request);
      curl_multi_add_handle($multi, $requests[$url]);
    }

    while (true) {
      $status = curl_multi_exec($multi, $active);

      if ($active) {
        curl_multi_select($multi);
      }

      while (true) {
        $info = curl_multi_info_read($multi);
        if ($info===false) {
          break;
        }
      }

      if (!$active || $status!=CURLM_OK) {
        break;
      }
    }

    $merged = array();
    $merged["messages"] = array();
    $merged["uids"] = array();
    $merged["node"] = $data["node"];
    foreach ($servers as $url => $dht) {
      $content = curl_multi_getcontent($requests[$url]);
      $stored = json_decode($content, true);
      $hash = bin2hex($dht["hash"]);
      if (isset($stored)) {
        $merged["uids"][$hash] = $stored["uid"];
      }

      // TODO: merge indexes
      if (isset($stored["messages"])) {
        $merged["messages"] = $stored["messages"];
      }

      if (isset($stored["data"])) {
        $merged["data"] = $stored["data"];
      }

      curl_close($requests[$url]);
    }

    curl_multi_close($multi);

    return $merged;
  }
}

// Distributed hash table
class QuasselDHT {
  public $maxServers;
  public $servers;

  public function __construct() {
    $this->maxServers = 3;
    $this->servers = array();
    $this->appendServer($_SERVER["HTTP_HOST"]);
  }

  public function __destruct() {
  }

  // Return distributed instances which could hold the data
  public function keying(string $node) : array {
    $node = QuasselHelper::hash($node);
    $sort = array();
    foreach ($this->servers as $key => $value) {
      $sort[$key] = $this->diff($value["hash"], $node);
    }

    asort($sort);
    $sort = array_slice($sort, 0, $this->maxServers, true);
    foreach ($sort as $key => $value) {
      $sort[$key] = $this->servers[$key];
    }

    return $sort;
  }

  // Distance between an instance and node information
  public function diff(string $hash, string $node) : string {
    $diff = "";
    for ($n = 0; $n<strlen($hash); $n++) {
      $diff .= chr(ord($hash[$n])^ord($node[$n]));
    }

    return $diff;
  }

  // Append an instance
  public function appendServer(string $server) {
    $this->servers[$server] = array("hash"=>QuasselHelper::hash($server));
  }

  // Send data to the webstore from identified instances
  public function send(string $node, array $data) : bool {
    $servers = $this->keying($node);
    $store = new QuasselWebStore();
    return $store->store($servers, $data);
  }

  // Retrieve data to the webstore from identified instances
  public function receive(string $node, array $data, array $uids) : array {
    $servers = $this->keying($node);
    $store = new QuasselWebStore();
    return $store->retrieve($servers, $data, $uids);
  }
}

// Upper layer chat functionality
class QuasselChat {
  public $dht;
  public $node;
  public $hashed;

  public function __construct(object $dht, string $node) {
    $this->dht = $dht;
    $this->node = $node;
    $this->hashed = QuasselHelper::hash($node);
  }

  public function __destruct() {
  }

  // Place chat message
  public function place(string $data) : bool {
    $uid = QuasselHelper::uniqueID();
    $rpcMessageUpdate = array("node" => $uid, "chat" => bin2hex($this->hashed), "type" => "data", "data" => $data);
    if (!$this->dht->send($this->hashed, $rpcMessageUpdate)) {
      return false;
    }

    $rpcIndexUpdate = array("node" => bin2hex($this->hashed), "chat" => bin2hex($this->hashed), "type" => "index", "append" => $uid);
    return $this->dht->send($this->hashed, $rpcIndexUpdate);
  }

  // Poll index
  public function index(array $uids) : array {
    $rpcIndexPoll = array("node" => bin2hex($this->hashed), "chat" => bin2hex($this->hashed), "type" => "index");
    return $this->dht->receive($this->hashed, $rpcIndexPoll, $uids);
  }

  // Poll message
  public function message(string $message) : array {
    $rpcMessagePoll = array("node" => $message, "chat" => bin2hex($this->hashed), "type" => "data");
    return $this->dht->receive($this->hashed, $rpcMessagePoll, array());
  }
}

// API base
class QuasselCore {
  public $dht;

  public function __construct() {
    $this->dht = new QuasselDHT();
  }

  public function __destruct() {
  }

  public function routeMessage(string $node, string $data) : bool {
    $chat = new QuasselChat($this->dht, $node);
    return $chat->place($data);
  }

  public function pollMessages(string $node, array $uids) : array {
    $chat = new QuasselChat($this->dht, $node);
    return $chat->index($uids);
  }

  public function pollMessage(string $node, string $message) : array {
    $chat = new QuasselChat($this->dht, $node);
    return $chat->message($message);
  }
}
?>
