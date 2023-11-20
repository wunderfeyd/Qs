"use strict";

var poll = new Channel("0");
poll.onMessageScan = function(id) {
  console.log("msgs ", id);
  let e = document.getElementById("messages");
  let n = document.createElement("div");
  n.setAttribute("id", "msg_"+id);
  e.appendChild(n);
  poll.message(id);
}

poll.onMessageReceive = function(id, json) {
  console.log("msg ", id, json);
  let e = document.getElementById("msg_"+id);
  if (!e) {
    return;
  }

  e.textContent = json.toString();
}
