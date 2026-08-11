(function () {
  // DAWA is scheduled to close on 2026-10-01. Replace this provider adapter before that date.
  var provider = {
    name: "Dataforsyningen",
    async suggestions(query, signal) {
      var parameters = new URLSearchParams({
        q: query,
        caretpos: String(query.length),
        type: "adresse",
        per_side: "8"
      });
      var response = await fetch("https://api.dataforsyningen.dk/autocomplete?" + parameters.toString(), { signal: signal });
      if (!response.ok) throw new Error("Address provider unavailable.");
      var rows = await response.json();
      return Array.isArray(rows) ? rows.map(function (row) {
        return { value: String(row.tekst || ""), label: String(row.forslagstekst || row.tekst || "") };
      }).filter(function (row) { return row.value; }) : [];
    }
  };

  var style = document.createElement("style");
  style.textContent = [
    ".address-autocomplete{position:relative;margin-top:5px}",
    ".address-autocomplete>input{margin-top:0!important}",
    ".address-autocomplete-list{position:absolute;z-index:50;top:calc(100% + 4px);right:0;left:0;max-height:260px;margin:0;padding:4px;overflow-y:auto;border:1px solid #b9c4d0;border-radius:6px;background:#fff;box-shadow:0 10px 24px rgba(21,36,58,.16);list-style:none}",
    ".address-autocomplete-list[hidden]{display:none}",
    ".address-autocomplete-option{padding:10px 11px;border-radius:4px;color:#15243a;font-size:14px;font-weight:normal;line-height:1.35;cursor:pointer}",
    ".address-autocomplete-option:hover,.address-autocomplete-option.is-active{background:#edf4fb;color:#163674}",
    ".address-autocomplete-status{position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0}",
    "@media(max-width:700px){.address-autocomplete-list{max-height:220px}.address-autocomplete-option{padding:12px 10px;font-size:16px}}"
  ].join("");
  document.head.appendChild(style);

  function enhanceInput(input) {
    if (input.dataset.addressAutocompleteReady === "true") return;
    input.dataset.addressAutocompleteReady = "true";
    input.setAttribute("autocomplete", "off");
    input.setAttribute("role", "combobox");
    input.setAttribute("aria-autocomplete", "list");
    input.setAttribute("aria-expanded", "false");

    var wrapper = document.createElement("div");
    var list = document.createElement("ul");
    var status = document.createElement("span");
    var listId = "address-suggestions-" + Math.random().toString(36).slice(2);
    var timer = null;
    var request = null;
    var rows = [];
    var activeIndex = -1;

    wrapper.className = "address-autocomplete";
    list.className = "address-autocomplete-list";
    list.id = listId;
    list.setAttribute("role", "listbox");
    list.hidden = true;
    status.className = "address-autocomplete-status";
    status.setAttribute("aria-live", "polite");
    input.setAttribute("aria-controls", listId);
    input.parentNode.insertBefore(wrapper, input);
    wrapper.appendChild(input);
    wrapper.appendChild(list);
    wrapper.appendChild(status);

    function close() {
      list.hidden = true;
      input.setAttribute("aria-expanded", "false");
      input.removeAttribute("aria-activedescendant");
      activeIndex = -1;
    }

    function select(row) {
      input.value = row.value;
      input.dispatchEvent(new Event("input", { bubbles: true }));
      input.dispatchEvent(new Event("change", { bubbles: true }));
      close();
    }

    function setActive(index) {
      if (!rows.length) return;
      activeIndex = (index + rows.length) % rows.length;
      list.querySelectorAll("[role=option]").forEach(function (option, optionIndex) {
        option.classList.toggle("is-active", optionIndex === activeIndex);
      });
      var active = list.children[activeIndex];
      input.setAttribute("aria-activedescendant", active.id);
      active.scrollIntoView({ block: "nearest" });
    }

    function render(suggestions) {
      rows = suggestions;
      list.replaceChildren();
      rows.forEach(function (row, index) {
        var option = document.createElement("li");
        option.className = "address-autocomplete-option";
        option.id = listId + "-" + index;
        option.setAttribute("role", "option");
        option.textContent = row.label;
        option.addEventListener("mousedown", function (event) {
          event.preventDefault();
          select(row);
        });
        list.appendChild(option);
      });
      list.hidden = !rows.length;
      input.setAttribute("aria-expanded", rows.length ? "true" : "false");
      status.textContent = rows.length + " adresseforslag";
      activeIndex = -1;
    }

    async function search() {
      var query = input.value.trim();
      if (query.length < 3) {
        close();
        return;
      }
      if (request) request.abort();
      request = new AbortController();
      try {
        render(await provider.suggestions(query, request.signal));
      } catch (error) {
        if (error.name !== "AbortError") close();
      }
    }

    input.addEventListener("input", function () {
      window.clearTimeout(timer);
      timer = window.setTimeout(search, 250);
    });
    input.addEventListener("keydown", function (event) {
      if (list.hidden && event.key !== "Escape") return;
      if (event.key === "ArrowDown") {
        event.preventDefault();
        setActive(activeIndex + 1);
      } else if (event.key === "ArrowUp") {
        event.preventDefault();
        setActive(activeIndex - 1);
      } else if (event.key === "Enter" && activeIndex >= 0) {
        event.preventDefault();
        select(rows[activeIndex]);
      } else if (event.key === "Escape") {
        close();
      }
    });
    input.addEventListener("blur", function () { window.setTimeout(close, 120); });
  }

  function enhance(root) {
    (root || document).querySelectorAll("input[data-address-autocomplete]").forEach(enhanceInput);
  }

  window.PortalAddressAutocomplete = { enhance: enhance, provider: provider.name };
  if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", function () { enhance(document); });
  else enhance(document);
})();
