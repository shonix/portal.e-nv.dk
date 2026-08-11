(function () {
  if (!document.querySelector('link[rel~="icon"]')) {
    var favicon = document.createElement("link");
    favicon.rel = "icon";
    favicon.type = "image/svg+xml";
    favicon.href = "favicon.svg";
    document.head.appendChild(favicon);
  }
  var style = document.createElement("style");
  style.textContent = [
    ".site-header,.site-header *{box-sizing:border-box}",
    ".site-header{position:fixed;top:0;left:0;right:0;z-index:20;display:flex;align-items:center;justify-content:space-between;gap:24px;min-height:77px;padding:14px 28px;border-bottom:1px solid #d6dde3;background:#fff;font-family:Arial,Helvetica,sans-serif}",
    ".site-brand{display:flex;align-items:center;gap:10px;color:#15243a;text-decoration:none;font-weight:bold}",
    ".site-logo{display:flex;width:48px;height:48px;align-items:center;justify-content:center;background:#c92b2b;color:#fff;font-size:19px;font-weight:bold;letter-spacing:-1px}",
    ".site-nav{display:flex;flex-wrap:wrap;justify-content:flex-end;gap:15px 22px}",
    ".site-nav a{color:#15243a;text-decoration:none;font-size:15px}",
    ".site-nav a:hover{text-decoration:underline;text-decoration-color:#163674;text-underline-offset:5px}",
    ".onboarding-notice{position:relative;left:-8px;box-sizing:border-box;width:100vw;border-bottom:1px solid #b9cbe0;background:#edf4fb;color:#183a63;font-family:Arial,Helvetica,sans-serif}",
    ".onboarding-notice-inner{display:grid;grid-template-columns:24px minmax(0,1fr);gap:12px;max-width:1180px;margin:0 auto;padding:14px 24px}",
    ".onboarding-notice-icon{display:flex;width:22px;height:22px;align-items:center;justify-content:center;margin-top:1px;border:2px solid currentColor;border-radius:50%;font-size:14px;font-style:normal;font-weight:bold;line-height:1}",
    ".onboarding-notice strong{display:block;margin-bottom:3px;font-size:15px}",
    ".onboarding-notice p{margin:0;color:inherit;font-size:14px;line-height:1.45}",
    "@media(max-width:700px){.onboarding-notice-inner{padding:13px 16px}}",
    "@media(max-width:700px){html{overflow-x:hidden}body{overflow-x:hidden}.site-header{align-items:stretch;flex-direction:column;gap:10px;min-height:112px;padding:12px 16px}.site-brand{min-width:0}.site-logo{width:40px;height:40px;flex:0 0 40px}.site-nav{width:100%;justify-content:flex-start;gap:8px;overflow-x:auto;padding-bottom:2px;white-space:nowrap;-webkit-overflow-scrolling:touch}.site-nav a{flex:0 0 auto;padding:4px 0;font-size:14px}main,.partner-portal,.partner-directory,.profile-page{box-sizing:border-box!important;width:100%;max-width:none!important;min-width:0;padding-left:16px!important;padding-right:16px!important;overflow-x:hidden}h1,.portal-title{font-size:28px!important;line-height:1.15!important}h2,.portal-heading{font-size:21px!important}.layout,.grid,.portal-grid,.profile-grid,.partner-filters,.guest-form,.toolbar,.filters,.picker,.group-picker{grid-template-columns:1fr!important}.layout>*,.grid>*,.portal-grid>*,form,section{min-width:0}.filters{position:static!important;justify-content:flex-start!important}.profile-card,.group-card,.card,.meeting,.item,.panel,.file-module,.guest-module,.partner-module{box-sizing:border-box;max-width:100%;min-width:0;border-radius:8px}.profile-card,.group-card,.card{grid-template-columns:48px minmax(0,1fr)!important;padding:14px!important}.avatar,.group-avatar{width:48px!important;height:48px!important;font-size:20px!important}input,select,textarea,button,.button{max-width:100%;min-height:42px;font-size:16px!important}.button-row,.actions,.file-actions,.pager-buttons{gap:8px}.button-row button,.button-row .button,.actions button,.actions .button,.picker button,.group-picker button{flex:1 1 auto;min-width:0;width:100%}.table-wrap{width:100%;max-width:100%;overflow-x:auto;-webkit-overflow-scrolling:touch}table{min-width:680px}.pager{align-items:stretch!important;flex-direction:column!important}.cover{padding:20px!important}.details{padding-left:18px!important;padding-right:18px!important}.picture-row{align-items:flex-start!important;flex-direction:column!important}.cropper-panel{max-height:calc(100vh - 32px);overflow:auto}}"
  ].join("");
  document.head.appendChild(style);
  function updateHeaderOffset() {
    document.body.style.paddingTop = window.matchMedia("(max-width:700px)").matches ? "112px" : "77px";
  }
  updateHeaderOffset();
  window.addEventListener("resize", updateHeaderOffset);
  var header = document.createElement("header");
  header.className = "site-header";
  header.innerHTML = '<a class="site-brand" href="index.html"><span class="site-logo">EP</span><span>Partnerportal</span></a><nav class="site-nav"><a href="index.html">Forside</a><a href="materialer.html" data-auth="member" style="display:none">Materiale</a><a href="login.html" data-auth="guest">Log ind</a><a href="min-profil.html" data-auth="member" style="display:none">Min profil</a><a href="admin.html" data-auth="admin" style="display:none">Administration</a><a href="#" data-auth="logout" style="display:none">Log ud</a></nav>';
  document.body.insertBefore(header, document.body.firstChild);
  PortalData.session().then(function (session) {
    header.querySelectorAll('[data-auth="guest"]').forEach(function (link) { link.style.display = session.loggedIn ? "none" : "inline"; });
    header.querySelectorAll('[data-auth="member"],[data-auth="logout"]').forEach(function (link) { link.style.display = session.loggedIn ? "inline" : "none"; });
    header.querySelectorAll('[data-auth="admin"]').forEach(function (link) { link.style.display = session.role === "admin" ? "inline" : "none"; });
    if (!session.loggedIn) return;
    return PortalData.bannerSettings().then(function (settings) {
      if (!settings.enabled) return;
      function showNotice() {
        var inner = document.createElement("div");
        var icon = document.createElement("i");
        var content = document.createElement("div");
        var title = document.createElement("strong");
        var message = document.createElement("p");
        inner.className = "onboarding-notice-inner";
        icon.className = "onboarding-notice-icon";
        icon.setAttribute("aria-hidden", "true");
        icon.textContent = "i";
        title.id = "onboarding-notice-title";
        title.textContent = settings.title;
        message.textContent = settings.message;
        content.appendChild(title);
        content.appendChild(message);
        inner.appendChild(icon);
        inner.appendChild(content);
        var notice = document.createElement("section");
        notice.className = "onboarding-notice";
        notice.setAttribute("role", "region");
        notice.setAttribute("aria-labelledby", "onboarding-notice-title");
        notice.appendChild(inner);
        header.insertAdjacentElement("afterend", notice);
      }
      if (settings.audience === "all") {
        showNotice();
        return;
      }
      if (session.role === "admin") return;
      return PortalData.myGroups().then(function (groups) {
        if (!groups.length) showNotice();
      });
    });
  }).catch(function () {});
  header.querySelector('[data-auth="logout"]').addEventListener("click", async function (event) {
    event.preventDefault();
    await PortalData.logout();
    location.href = "index.html";
  });
})();
