(function () {
  async function request(action, options, parameters) {
    var query = new URLSearchParams(Object.assign({ action:action }, parameters || {}));
    var response = await fetch("api/index.php?" + query.toString(), Object.assign({
      credentials: "same-origin", headers: { "Content-Type": "application/json" }
    }, options || {}));
    var payload = await response.json().catch(function () { return {}; });
    if (!response.ok) throw new Error(payload.error || "Server request failed.");
    return payload;
  }
  function post(action, body) { return request(action, { method:"POST", body:JSON.stringify(body) }); }
  window.PortalData = {
    session:function(){return request("session");}, login:function(email,password){return post("login",{email:email,password:password});},
    register:function(token,password){return post("register",{token:token,password:password});},
    logout:function(){return post("logout",{});}, groups:async function(){return (await request("groups")).groups;},
    myGroups:async function(){return (await request("my-groups")).groups;},
    groupPartners:async function(groupId){return request("group-partners",null,{groupId:groupId});},
    meetings:async function(){return (await request("meetings")).meetings;}, partners:async function(){return (await request("partners")).partners;},
    partnerDetail:async function(id,groupId){var key=String(id||"");var params={groupId:groupId||""};if(/^\d+$/.test(key))params.id=key;else params.slug=key;return (await request("partner-detail",null,params)).partner;},
    labels:async function(){return (await request("labels")).labels;},
    myProfile:async function(){return (await request("my-profile")).partner;}, saveMyProfile:function(data){return post("my-profile",data);},
    adminUsers:function(parameters){return request("admin-users",null,parameters);}, addUser:function(data){return post("admin-users",data);},
    updateUser:function(data){return post("admin-update-user",data);}, deleteUser:function(userId){return post("admin-delete-user",{userId:userId});},
    saveUserGroups:function(data){return post("admin-user-groups",data);},
    addGroup:function(data){return post("admin-groups",data);}, updateGroup:function(data){return post("admin-update-group",data);}, deleteGroup:function(groupId){return post("admin-delete-group",{groupId:groupId});}, addMeeting:function(data){return post("admin-meetings",data);}, updateMeeting:function(data){return post("admin-update-meeting",data);}, deleteMeeting:function(meetingId){return post("admin-delete-meeting",{meetingId:meetingId});},
    addLabel:function(data){return post("admin-labels",data);}, deleteLabel:function(labelId){return post("admin-delete-label",{labelId:labelId});},
    invitations:async function(){return (await request("admin-invitations")).invitations;}, addInvitation:function(data){return post("admin-invitations",data);},
    requireSession:async function(role){var s=await this.session();if(!s.loggedIn||(role&&s.role!==role)){location.href="login.html";throw new Error("Login required.");}return s;},
    escape:function(value){return String(value==null?"":value).replace(/[&<>"']/g,function(c){return{"&":"&amp;","<":"&lt;",">":"&gt;",'"':"&quot;","'":"&#039;"}[c];});}
  };
})();
