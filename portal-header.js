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
    "@media(max-width:700px){.site-header{align-items:flex-start;min-height:73px;padding:12px 16px}.site-nav{gap:10px 14px}.site-nav a{font-size:13px}.site-logo{width:40px;height:40px}}"
  ].join("");
  document.head.appendChild(style);
  document.body.style.paddingTop = getComputedStyle(document.documentElement).getPropertyValue("--portal-header-height").trim() || "77px";
  var header = document.createElement("header");
  header.className = "site-header";
  header.innerHTML = '<a class="site-brand" href="index.html"><span class="site-logo">EP</span><span>Partnerportal</span></a><nav class="site-nav"><a href="index.html">Forside</a><a href="login.html" data-auth="guest">Log ind</a><a href="min-profil.html" data-auth="member" style="display:none">Min profil</a><a href="admin.html" data-auth="admin" style="display:none">Administration</a><a href="#" data-auth="logout" style="display:none">Log ud</a></nav>';
  document.body.insertBefore(header, document.body.firstChild);
  PortalData.session().then(function (session) {
    header.querySelectorAll('[data-auth="guest"]').forEach(function (link) { link.style.display = session.loggedIn ? "none" : "inline"; });
    header.querySelectorAll('[data-auth="member"],[data-auth="logout"]').forEach(function (link) { link.style.display = session.loggedIn ? "inline" : "none"; });
    header.querySelectorAll('[data-auth="admin"]').forEach(function (link) { link.style.display = session.role === "admin" ? "inline" : "none"; });
  });
  header.querySelector('[data-auth="logout"]').addEventListener("click", async function (event) {
    event.preventDefault();
    await PortalData.logout();
    location.href = "index.html";
  });
})();
