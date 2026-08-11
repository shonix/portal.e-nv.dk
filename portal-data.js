(function () {
  async function request(action, options, parameters) {
    var query = new URLSearchParams(Object.assign({ action:action }, parameters || {}));
    var settings = Object.assign({ credentials: "same-origin" }, options || {});
    if (!(settings.body instanceof FormData)) settings.headers = Object.assign({ "Content-Type": "application/json" }, settings.headers || {});
    var response = await fetch("api/index.php?" + query.toString(), settings);
    var payload = await response.json().catch(function () { return {}; });
    if (!response.ok) throw new Error(payload.error || "Server request failed.");
    return payload;
  }
  function post(action, body) { return request(action, { method:"POST", body:JSON.stringify(body) }); }
  window.PortalData = {
    session:function(){return request("session");}, login:function(email,password){return post("login",{email:email,password:password});},
    register:function(token,password){return post("register",{token:token,password:password});},
    passwordResetInfo:function(token){return request("password-reset-info",null,{token:token});},
    resetPassword:function(token,password){return post("password-reset",{token:token,password:password});},
    logout:function(){return post("logout",{});}, groups:async function(){return (await request("groups")).groups;},
    myGroups:async function(){return (await request("my-groups")).groups;},
    bannerSettings:async function(){return (await request("portal-banner")).banner;},
    adminBannerSettings:async function(){return (await request("admin-banner-settings")).banner;},
    saveBannerSettings:function(data){return post("admin-banner-settings",data);},
    groupPartners:async function(groupId){return request("group-partners",null,{groupId:groupId});},
    meetingPartners:function(parameters){return request("meeting-partners",null,parameters);},
    meetingGuests:async function(meetingId){return (await request("meeting-guests",null,{id:meetingId})).guests;}, addMeetingGuest:function(data){return post("meeting-guests",data);},
    importMeetingGuests:function(data){return post("admin-import-meeting-guests",data);},
    meetings:async function(){return (await request("meetings")).meetings;}, partners:async function(){return (await request("partners")).partners;},
    partnerDetail:async function(id,groupId){var key=String(id||"");var params={groupId:groupId||""};if(/^\d+$/.test(key))params.id=key;else params.slug=key;return (await request("partner-detail",null,params)).partner;},
    labels:async function(){return (await request("labels")).labels;},
    myProfile:async function(){return (await request("my-profile")).partner;}, saveMyProfile:function(data){return post("my-profile",data);},
    uploadProfilePicture:function(file){var data=new FormData();data.append("file",file,file.name||"profile-picture.jpg");return request("profile-picture",{method:"POST",body:data});},
    adminProfile:function(userId){return request("admin-profile",null,{userId:userId});}, saveAdminProfile:function(data){return post("admin-profile",data);},
    adminUsers:function(parameters){return request("admin-users",null,parameters);}, addUser:function(data){return post("admin-users",data);},
    updateUser:function(data){return post("admin-update-user",data);}, deleteUser:function(userId){return post("admin-delete-user",{userId:userId});},
    createPasswordResetLink:function(userId){return post("admin-password-reset-link",{userId:userId});},
    saveUserGroups:function(data){return post("admin-user-groups",data);},
    groupDetail:function(groupId){return request("admin-group-detail",null,{groupId:groupId});},
    groupBulletins:async function(groupId){return (await request("group-bulletins",null,{groupId:groupId})).bulletins;},
    addGroupBulletin:function(data){return post("admin-group-bulletins",data);}, deleteGroupBulletin:function(bulletinId){return post("admin-delete-group-bulletin",{bulletinId:bulletinId});},
    changeGroupMember:function(data){return post("admin-group-member",data);},
    addGroup:function(data){return post("admin-groups",data);}, updateGroup:function(data){return post("admin-update-group",data);}, deleteGroup:function(groupId){return post("admin-delete-group",{groupId:groupId});}, addMeeting:function(data){return post("admin-meetings",data);}, updateMeeting:function(data){return post("admin-update-meeting",data);}, deleteMeeting:function(meetingId){return post("admin-delete-meeting",{meetingId:meetingId});},
    adminLabels:function(parameters){return request("admin-labels",null,parameters);}, addLabel:function(data){return post("admin-labels",data);}, deleteLabel:function(labelId){return post("admin-delete-label",{labelId:labelId});}, importLabels:function(names){return post("admin-import-labels",{names:names});},
    invitations:async function(){return (await request("admin-invitations")).invitations;}, addInvitation:function(data){return post("admin-invitations",data);},
    meetingInvitations:function(meetingId){return request("admin-meeting-invitations",null,{meetingId:meetingId});},
    saveMeetingSettings:function(meetingId,approvalMode){return post("admin-meeting-settings",{meetingId:meetingId,approvalMode:approvalMode});},
    sendMeetingInvitations:function(data){return post("admin-send-meeting-invitations",data);},
    rotateMeetingInvitation:function(meetingId){return post("admin-rotate-meeting-invitation",{meetingId:meetingId});},
    meetingInvitation:function(token){return request("meeting-invitation",null,{token:token});},
    saveMeetingRsvp:function(token,response){return post("meeting-rsvp",{token:token,response:response});},
    reviewRsvp:function(data){return post("admin-review-rsvp",data);},
    meetingAttendance:function(meetingId){return request("admin-meeting-attendance",null,{meetingId:meetingId});},
    saveMeetingAttendance:function(data){return post("admin-meeting-attendance",data);},
    removeMeetingGuest:function(data){return post("admin-remove-meeting-guest",data);},
    meetingAttachments:async function(meetingId){return (await request("admin-meeting-attachments",null,{meetingId:meetingId})).attachments;},
    publicMeetingAttachments:async function(meetingId){return (await request("meeting-attachments",null,{meetingId:meetingId})).attachments;},
    uploadMeetingAttachment:function(meetingId,file){var data=new FormData();data.append("meetingId",meetingId);data.append("file",file);return request("admin-upload-attachment",{method:"POST",body:data});},
    deleteMeetingAttachment:function(attachmentId){return post("admin-delete-attachment",{attachmentId:attachmentId});},
    requireSession:async function(role){var s=await this.session();if(!s.loggedIn||(role&&s.role!==role)){var next=location.pathname.split("/").pop()+location.search;location.href="login.html?next="+encodeURIComponent(next);throw new Error("Login required.");}return s;},
    escape:function(value){return String(value==null?"":value).replace(/[&<>"']/g,function(c){return{"&":"&amp;","<":"&lt;",">":"&gt;",'"':"&quot;","'":"&#039;"}[c];});}
  };
})();
