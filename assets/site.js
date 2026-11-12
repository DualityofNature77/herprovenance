/* Her Provenance — shared page script: mobile menu, launch waitlist, and the live book list from the vault. */
(function () {
  var $ = function (s) { return document.querySelector(s); };
  var esc = function (s) { return String(s == null ? "" : s).replace(/[&<>"']/g, function (c) { return { "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c]; }); };

  // menu
  var mb = $(".menu"), ul = $(".nav ul");
  if (mb && ul) mb.addEventListener("click", function () { var o = ul.classList.toggle("open"); mb.setAttribute("aria-expanded", o ? "true" : "false"); });
  document.querySelectorAll(".yr").forEach(function (y) { y.textContent = new Date().getFullYear(); });

  // waitlist (saves to herprovenance-data/waitlist.csv on our own server)
  var f = $("#wl");
  if (f) {
    var m = $("#wlMsg"), b = $("#wlBtn"), e = $("#wlEmail");
    f.addEventListener("submit", function (ev) {
      ev.preventDefault(); m.className = "msg"; m.textContent = "";
      if (!e.value || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(e.value)) { m.className = "msg err"; m.textContent = "Please enter a valid email address."; e.focus(); return; }
      b.disabled = true; b.textContent = "Sending…";
      fetch("/waitlist.php", { method: "POST", headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ email: e.value, interest: ($("#wlInterest") || {}).value || "", company_website: f.company_website.value }) })
        .then(function (r) { return r.json().catch(function () { return {}; }).then(function (j) { if (!r.ok) throw new Error(j.error || "Something went wrong. Please try again."); return j; }); })
        .then(function () { f.hidden = true; m.className = "msg ok"; m.textContent = "✦ You're on the list. We'll write when The Record opens."; })
        .catch(function (err) { m.className = "msg err"; m.textContent = err.message; b.disabled = false; b.textContent = "Notify me"; });
    });
  }

  // books: live from the Sieber Fillman vault (same covers, prices and links as sieberfillmanpublishing.com)
  var grid = $("[data-books]");
  if (grid && window.fetch) {
    var FEED = "https://sieberfillmanpublishing.online/api.php?a=catalog";
    var IMPRINT = grid.getAttribute("data-books");
    var limit = parseInt(grid.getAttribute("data-limit") || "0", 10);
    var safe = function (u) { return /^https:\/\//i.test(u || "") ? u : ""; };
    fetch(FEED, { mode: "cors", credentials: "omit" })
      .then(function (r) { return r.ok ? r.json() : Promise.reject(); })
      .then(function (j) {
        var list = (j.books || []).filter(function (b) { return b.imprint === IMPRINT; });
        if (!list.length) return;
        list.sort(function (a, b) { return (a.status === "Released" ? 0 : 1) - (b.status === "Released" ? 0 : 1); });
        if (limit) list = list.slice(0, limit);
        grid.innerHTML = list.map(function (b) {
          var live = (b.editions || []).filter(function (e) { return e.status === "Live" && safe(e.url); });
          var released = b.status === "Released";
          var cover = b.media && b.media.front && safe(b.media.front.thumb)
            ? '<img class="cv" src="' + esc(b.media.front.thumb) + '" alt="Cover of ' + esc(b.title) + '" loading="lazy" width="640" height="960">'
            : '<div class="ph" aria-hidden="true">' + esc(b.title) + "</div>";
          var btns = released && live.length ? live.map(function (e, i) {
            var p = parseFloat(e.price);
            return '<a class="btn ' + (i === 0 ? "btn-gold" : "btn-line") + '" href="' + esc(e.url) + '" target="_blank" rel="noopener">' + esc((i === 0 && isFinite(p) ? "$" + p.toFixed(2) + " · " : "") + (e.label || e.format || "Buy")) + "</a>";
          }).join("") : "";
          return '<article class="book">' + cover + '<div class="b"><span class="st' + (released ? " live" : "") + '">' + (released ? "Available now" : esc(b.status || "Coming soon")) + "</span>" +
            "<h3>" + esc(b.title) + "</h3>" + (b.subtitle ? '<div class="sub">' + esc(b.subtitle) + "</div>" : "") + "<p>" + esc(b.desc) + "</p>" + btns + "</div></article>";
        }).join("");
      })
      .catch(function () { /* keep the books already written into the page */ });
  }
})();
