"use strict";

function Channel(chat) {
  this.chat = chat;
  this.pollUIDs = {};
  this.pollRequest = this.createRequest();
  this.pollRequest.onreadystatechange = this.receivePoll.bind(this);
  this.nextPoll();

  this.pushRequest = this.createRequest();
  this.pushRequest.onreadystatechange = this.receivePush.bind(this);
  this.pushLeft = [];
  this.pushing = false;

  this.messageRequest = this.createRequest();
  this.messageRequest.onreadystatechange = this.receiveMessage.bind(this);
  this.messageLeft = [];
  this.messaging = false;

  this.onMessageScan = null;
  this.onMessageReceive = null;
}

Channel.prototype.createRequest = function() {
  return new XMLHttpRequest();
}

Channel.prototype.receivePoll = function() {
  if (this.pollRequest.readyState!=4) {
    return false;
  }

  if (this.pollRequest.status!=200) {
    this.nextPoll();
    return false;
  }

  let json = JSON.parse(this.pollRequest.responseText);
  if (json["uids"]!==undefined && Object.keys(json["uids"]).length>0) {
    this.pollUIDs = json["uids"];
  }

  if (json["messages"]!==undefined) {
    for (let v in json["messages"]) {
      let id = json["messages"][v]["id"];
      this.onMessageScan(id);
    }
  }

  this.nextPoll();
}

Channel.prototype.nextPoll = function() {
  let json = {"node":this.chat, "chat":this.chat, "uids":this.pollUIDs};
  this.pollRequest.open("PUT", "poll.php", true);
  this.pollRequest.send(JSON.stringify(json));
}

Channel.prototype.push = function(data) {
  this.pushLeft.push(data);
  this.nextPush();
}

Channel.prototype.receivePush = function() {
  if (this.pushRequest.readyState!=4) {
    return false;
  }

  if (this.pushRequest.status!=200) {
    this.nextPush();
    return false;
  }

  this.pushLeft.shift();
  this.pushing = false;
  this.nextPush();
}

Channel.prototype.nextPush = function() {
  if (this.pushLeft.length==0) {
    return;
  }

  if (this.pushing) {
    return;
  }

  let json = {"node":"0", "chat":this.chat, "data":this.pushLeft[0]};
  this.pushRequest.open("PUT", "push.php", true);
  this.pushRequest.send(JSON.stringify(json));
  this.pushing = true;
}

Channel.prototype.message = function(node) {
  this.messageLeft.push(node);
  this.nextMessage();
}

Channel.prototype.receiveMessage = function() {
  if (this.messageRequest.readyState!=4) {
    return false;
  }

  if (this.messageRequest.status!=200) {
    this.nextMessage();
    return false;
  }

  let json = JSON.parse(this.messageRequest.responseText);
  this.onMessageReceive(json["node"], json["data"]);
  this.messageLeft.shift();
  this.messaging = false;
  this.nextMessage();
}

Channel.prototype.nextMessage = function() {
  if (this.messageLeft.length==0) {
    return;
  }

  if (this.messaging) {
    return;
  }

  let json = {"node":this.messageLeft[0], "chat":this.chat};
  this.messageRequest.open("PUT", "message.php", true);
  this.messageRequest.send(JSON.stringify(json));
  this.messaging = true;
}
