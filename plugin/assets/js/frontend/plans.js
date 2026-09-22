/**
 * Storefront plan selector — simple and variable products.
 *
 * Pick a plan group (radio card), then a term (buttons). The chosen plan-term id
 * is written to a hidden field that posts with add-to-cart. Variable product
 * contexts are supplied by the server and selected from WooCommerce's resolved
 * variation events. Pure DOM apart from WooCommerce's existing jQuery event.
 */
(function () {
  "use strict";

  var box = document.querySelector("[data-subscrpt-buybox]");
  if (!box) {
    return;
  }

  var contexts = [];
  try {
    contexts = JSON.parse(box.getAttribute("data-subscrpt-plan-contexts") || "[]");
  } catch (err) {
    contexts = [];
  }

  /**
   * Render one server-provided plan context. Prices and notes are trusted
   * presentation fragments produced by the plugin's escaped WooCommerce
   * helpers; labels and ids are assigned as text/attributes.
   *
   * @param {Object[]} groups Plan groups.
   */
  function renderGroups(groups) {
    box.querySelectorAll("[data-subscrpt-card]").forEach(function (card) {
      card.remove();
    });

    (groups || []).forEach(function (group, groupIndex) {
      var card = document.createElement("label");
      card.className = "subscrpt-buybox__card" + (0 === groupIndex ? " is-selected" : "");
      card.setAttribute("data-subscrpt-card", "");
      card.setAttribute("for", "subscrpt-grp-" + group.id + "-" + groupIndex);

      if (group.badge) {
        var badge = document.createElement("span");
        badge.className = "subscrpt-buybox__badge";
        badge.textContent = group.badge;
        card.appendChild(badge);
      }

      var head = document.createElement("span");
      head.className = "subscrpt-buybox__head";
      var radio = document.createElement("input");
      radio.type = "radio";
      radio.className = "subscrpt-buybox__radio";
      radio.id = "subscrpt-grp-" + group.id + "-" + groupIndex;
      radio.name = "subscrpt_plan_group";
      radio.value = group.id;
      radio.checked = 0 === groupIndex;
      head.appendChild(radio);

      var label = document.createElement("span");
      label.className = "subscrpt-buybox__label";
      label.textContent = group.label || "";
      head.appendChild(label);

      if (group.price) {
        var price = document.createElement("span");
        price.className = "subscrpt-buybox__price";
        price.innerHTML = group.price;
        head.appendChild(price);
      }
      card.appendChild(head);

      var terms = group.terms || [];
      if (terms.length) {
        card.setAttribute("data-subscrpt-single-term", terms.length === 1 ? terms[0].id : "");
        var note = document.createElement("span");
        note.className = "subscrpt-buybox__note";
        note.setAttribute("data-subscrpt-note", "");
        note.innerHTML = terms[0].note || "";
        card.appendChild(note);

        if (terms.length > 1) {
          var termWrap = document.createElement("span");
          termWrap.className = "subscrpt-buybox__terms";
          termWrap.setAttribute("data-subscrpt-terms", "");
          terms.forEach(function (term, termIndex) {
            var button = document.createElement("button");
            button.type = "button";
            button.className = "subscrpt-buybox__term" + (0 === termIndex ? " is-active" : "");
            button.setAttribute("data-subscrpt-term-btn", "");
            button.setAttribute("data-term-id", term.id);
            button.setAttribute("data-price", term.price || "");
            button.setAttribute("data-note", term.note || "");
            button.textContent = term.label || "";
            termWrap.appendChild(button);
          });
          card.appendChild(termWrap);
        }
      }
      box.appendChild(card);
    });
  }

  /**
   * Switch the active variation context, or hide the selector when the chosen
   * variation has no subscription offer.
   *
   * @param {?number} variationId WooCommerce variation id.
   */
  function setContext(variationId) {
    var context = null;
    contexts.some(function (candidate) {
      if (String(candidate.variation_id) === String(variationId)) {
        context = candidate;
        return true;
      }
      return false;
    });

    if (!context) {
      box.hidden = true;
      var hidden = box.querySelector("[data-subscrpt-plan-id]");
      if (hidden) {
        hidden.value = "";
      }
      renderGroups([]);
      return;
    }

    box.hidden = false;
    renderGroups(context.groups || []);
    syncPlanId();
  }

  /**
   * Write the chosen plan-term id into the hidden field that posts with
   * add-to-cart.
   */
  function syncPlanId() {
    var hidden = box.querySelector("[data-subscrpt-plan-id]");
    if (!hidden) {
      return;
    }
    var checked = box.querySelector('input[name="subscrpt_plan_group"]:checked');
    var card = checked ? checked.closest("[data-subscrpt-card]") : null;
    var planId = "";
    if (card) {
      var activeBtn = card.querySelector("[data-subscrpt-term-btn].is-active");
      if (activeBtn) {
        planId = activeBtn.getAttribute("data-term-id");
      } else if (card.hasAttribute("data-subscrpt-single-term")) {
        planId = card.getAttribute("data-subscrpt-single-term");
      }
    }
    hidden.value = planId || "";
  }

  // Selecting a card (radio) marks it and syncs the posted plan id.
  box.addEventListener("change", function (e) {
    if (e.target.name !== "subscrpt_plan_group") {
      return;
    }
    box.querySelectorAll("[data-subscrpt-card]").forEach(function (card) {
      card.classList.remove("is-selected");
    });
    var card = e.target.closest("[data-subscrpt-card]");
    if (card) {
      card.classList.add("is-selected");
    }
    syncPlanId();
  });

  // Choosing a term button updates that card's note and selects the card.
  box.addEventListener("click", function (e) {
    var btn = e.target.closest("[data-subscrpt-term-btn]");
    if (!btn) {
      return;
    }
    e.preventDefault();
    var wrap = btn.closest("[data-subscrpt-card]");
    if (!wrap) {
      return;
    }

    wrap.querySelectorAll("[data-subscrpt-term-btn]").forEach(function (b) {
      b.classList.remove("is-active");
    });
    btn.classList.add("is-active");

    var noteEl = wrap.querySelector("[data-subscrpt-note]");
    if (noteEl) {
      // Note is server-built HTML (may include a struck-through regular price).
      noteEl.innerHTML = btn.getAttribute("data-note") || "";
    }

    var radio = wrap.querySelector('input[name="subscrpt_plan_group"]');
    if (radio && !radio.checked) {
      radio.checked = true;
      radio.dispatchEvent(new Event("change", { bubbles: true }));
    }
    syncPlanId();
  });

  // Initialise the hidden plan id from the pre-selected (first) card.
  if ("1" === box.getAttribute("data-subscrpt-variable")) {
    box.hidden = true;
    var initialHidden = box.querySelector("[data-subscrpt-plan-id]");
    if (initialHidden) {
      initialHidden.value = "";
    }
  } else {
    syncPlanId();
  }

  // WooCommerce's variation form emits the resolved variation object after
  // attribute matching. Bind through the existing jQuery event bus so this
  // works with both classic and block-compatible variable product forms.
  if ("1" === box.getAttribute("data-subscrpt-variable") && window.jQuery) {
    window.jQuery(document).on("found_variation", ".variations_form", function (e, variation) {
      setContext(variation && variation.variation_id ? variation.variation_id : 0);
    });
    window.jQuery(document).on("reset_data hide_variation", ".variations_form", function () {
      setContext(0);
    });
  }
})();
