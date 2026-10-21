/**
 * Onboarding Wizard — SPA-style.
 *
 * Flow (nothing is created until the final step):
 *   1. Create Plan — pick a plan type + name (name auto-fills from the type).
 *   2. Durations   — one or more billing durations.
 *   3. Connect     — choose a new or existing product to attach the plan to.
 *   4. Review      — explicit draft creation or confirmed activation/publication.
 *
 * Steps 1–3 only collect and validate input; the plan (group), durations
 * (terms) and product relations are all created together on step 4 through the
 * Plans REST API (wpsubscription/v1/plans). Creating a brand-new product uses
 * an admin-ajax handler. All PHP values arrive via `subscrpt_wizard`.
 */
(function ($) {
  "use strict";

  var INTERVAL_TO_INT = { day: 1, week: 2, month: 3, year: 4 };

  // The preview graph has 3 duration slots, so durations are capped there.
  var MAX_DURATIONS = 3;

  var Wizard = {
    cfg: {},
    MAX_DURATIONS: MAX_DURATIONS,
    autoName: "",
    // Everything below is created on the final step (page 4), not before.
    groupId: 0,
    termIds: [],
    planTitle: "",
    billingText: "",
    finalProductId: "",
    finalProductName: "",
    relationsCreated: false,
    finalizeRunning: false,
    planPending: null,
    intent: null,
    reviewedIntent: null,
    uncertainWrite: false,
    seededTermId: 0,
    relationIds: {},
    publicationComplete: false,
    durationSequence: 0,
    // Durations show as placeholder ghost cards in the preview until the user
    // reaches page 2 and starts editing them.
    reachedDurations: false,

    init: function () {
      this.cfg = window.subscrpt_wizard || {};
      this.hasProducts = $("#subscrpt-has-products").val() === "1";
      this.autoName = $.trim($("#subscrpt_plan_title").val());
      this.bindEvents();
      this.initLivePreview();
      this.initFocusZoom();
      this.initDurations();
      $("#subscrpt-link-plans").attr("href", this.cfg.plans_url || "#");
      $("#subscrpt-link-products").attr("href", this.cfg.products_url || "#");
      // Start on the welcome page (normalises stepper, footer nav and preview).
      this.switchSection(0);
    },

    // ----- REST helper -----

    api: function (method, path, body) {
      return fetch(this.cfg.rest_url + path, {
        method: method,
        credentials: "same-origin",
        headers: {
          "Content-Type": "application/json",
          "X-WP-Nonce": this.cfg.rest_nonce || "",
        },
        body: body ? JSON.stringify(body) : undefined,
      }).then(function (res) {
        return res.json().then(function (data) {
          if (!res.ok) {
            var error = new Error((data && data.message) || "Request failed.");
            var refusalCodes = ["rest_invalid_param", "rest_missing_callback_param", "rest_forbidden", "rest_cookie_invalid_nonce", "subscrpt_installment_count_invalid", "subscrpt_plan_group_missing", "subscrpt_plan_missing", "subscrpt_oid_missing", "subscrpt_variation_product_mismatch", "subscrpt_variation_mapping_editor"];
            error.safeRetry = res.status >= 400 && res.status < 500 && refusalCodes.indexOf(data && data.code) !== -1;
            throw error;
          }
          return data;
        });
      });
    },

    // ----- Events -----

    bindEvents: function () {
      // Page 0 (welcome).
      $(document).on("click", "#subscrpt-btn-start", $.proxy(this.goToPage, this, 1));
      $(document).on("click", "#subscrpt-btn-skip", $.proxy(this.skip, this));

      // Page 1 (plan).
      $(document).on("click", ".wpsubs-plan-type-card", $.proxy(this.selectPlanType, this));
      $(document).on("click", "#subscrpt-btn-back-0", $.proxy(this.goToPage, this, 0));
      $(document).on("click", "#subscrpt-btn-next-1", $.proxy(this.nextFromPlan, this));

      // Page 2 (durations).
      $(document).on("click", "#subscrpt-btn-back-1", $.proxy(this.goToPage, this, 1));
      $(document).on("click", "#subscrpt-btn-next-2", $.proxy(this.nextFromDurations, this));
      $(document).on("click", "#subscrpt-btn-add-duration", $.proxy(this.addDuration, this));
      $(document).on("click", "[data-dur-toggle]", $.proxy(this.onDurToggle, this));
      $(document).on("click", "[data-dur-remove]", $.proxy(this.onDurRemove, this));
      $(document).on("input", "[data-dur-freq]", $.proxy(this.onDurBillingInput, this));
      $(document).on("wpsubs:select", "[data-dur-interval]", $.proxy(this.onDurBillingInput, this));
      $(document).on("input", "[data-dur-name]", $.proxy(this.onDurNameInput, this));

      // Page 3 (product).
      $(document).on("click", "#subscrpt-btn-back-2", $.proxy(this.goToPage, this, 2));
      $(document).on("click", ".wpsubs-connect-mode-card", $.proxy(this.selectConnectMode, this));
      $(document).on("change", "[data-connect-enabled]", $.proxy(this.onConnectToggle, this));
      $(document).on("input", "[data-connect-price]", $.proxy(this.updatePreview, this));
      $(document).on("click", "#subscrpt-btn-next-3", $.proxy(this.nextFromProduct, this));
      $(document).on("focus", "#subscrpt-product-search-input", this.openProductSearch);
      $(document).on("input", "#subscrpt-product-search-input", this.filterProducts);
      $(document).on("click", ".wpsubs-p2-product-search__item", $.proxy(this.onProductPick, this));
      $(document).on("click", "#subscrpt-btn-clear-product", $.proxy(this.clearProduct, this));
      $(document).on("click", function (e) {
        if (!$(e.target).closest(".wpsubs-p2-product-search").length) {
          $("#subscrpt-product-search-dropdown").hide();
        }
      });

      // Page 4 (review, then explicit creation).
      $(document).on("click", "#subscrpt-btn-create-reviewed", $.proxy(this.finalize, this));
      $(document).on("click", "#subscrpt-btn-back-review", $.proxy(this.goToPage, this, 3));
      $(document).on("change", "#subscrpt-publish-choice", $.proxy(this.renderReview, this));
      $(document).on("input", "#subscrpt-installment-count", $.proxy(this.updatePreview, this));
      $(document).on("click", "#subscrpt-btn-retry-finalize", $.proxy(this.finalize, this));
      $(document).on("click", "#subscrpt-btn-add-another", $.proxy(this.restart, this));
    },

    // ----- Navigation -----

    goToPage: function (pageNum, e) {
      if (e && e.preventDefault) {
        e.preventDefault();
      }
      if (this.finalizeRunning || this.planPending || this.intent || this.uncertainWrite) {
        return;
      }
      this.switchSection(pageNum);
    },

    switchSection: function (pageNum) {
      if (pageNum !== 4 && (this.finalizeRunning || this.planPending || this.intent || this.uncertainWrite)) {
        return;
      }
      $("#subscrpt-wizard-page").val(pageNum);

      // Once the user lands on the durations step, the preview duration nodes
      // stop being placeholders and reflect the real durations.
      if (pageNum >= 2) {
        this.reachedDurations = true;
      }

      // The welcome page (0) has no stepper, no live preview and no footer nav —
      // it's a standalone intro with its own button.
      $("#subscrpt-stepper").toggle(pageNum !== 0);
      $(".wpsubs-wizard-layout").toggleClass("is-welcome", pageNum === 0);

      $(".wpsubs-wizard-stepper__step").removeClass("active done");
      $(".wpsubs-wizard-stepper__step").each(function () {
        var step = parseInt($(this).data("step"), 10);
        if (step < pageNum) {
          $(this).addClass("done");
        } else if (step === pageNum) {
          $(this).addClass("active");
        }
      });

      $(".wpsubs-wizard-section").removeClass("active");
      $("#subscrpt-section-" + pageNum).addClass("active");

      // Build the per-duration pricing rows from the durations set on step 2.
      if (pageNum === 3) {
        this.renderConnectDurations();
      }

      // Swap the footer nav to this step's buttons.
      $(".wpsubs-wizard-nav").attr("hidden", "hidden");
      $('.wpsubs-wizard-nav[data-nav="' + pageNum + '"]').removeAttr("hidden");

      // Light up the part of the preview this step fills in.
      var groups = { 0: "plan", 1: "plan", 2: "dur", 3: "prod", 4: "done" };
      $("#subscrpt-preview-graph").attr("data-active", groups[pageNum] || "plan");
      this.updatePreview();

      $("html, body").animate({ scrollTop: 0 }, 150);
    },

    skip: function (e) {
      e.preventDefault();
      if (this.finalizeRunning || this.planPending) {
        return;
      }
      window.location.href = this.cfg.subscriptions_url;
    },

    // ----- Page 1: plan type + name -----

    selectPlanType: function (e) {
      var card = $(e.currentTarget);
      $(".wpsubs-plan-type-card").removeClass("active");
      card.addClass("active");
      $("#subscrpt-plan-type").val(card.data("type"));

      // Auto-fill the name, but never clobber a name the user has edited.
      var suggested = card.data("name") ? String(card.data("name")) : "";
      var current = $.trim($("#subscrpt_plan_title").val());
      if (!current || current === this.autoName) {
        $("#subscrpt_plan_title").val(suggested);
      }
      this.autoName = suggested;

      $("#subscrpt-installment-settings").toggle(card.data("type") === "installments");
      this.updatePreview();
    },

    nextFromPlan: function (e) {
      e.preventDefault();
      if (!$.trim($("#subscrpt_plan_title").val())) {
        window.alert("Please enter a plan name.");
        return;
      }
      this.switchSection(2);
    },

    // ----- Page 2: live preview + create plan -----

    initLivePreview: function () {
      var self = this;
      var update = function () {
        self.updatePreview();
      };
      // Fields whose typing should reflect into the preview graph. Duration
      // fields update the preview through their own handlers.
      $(document).on("input", "#subscrpt_plan_title, #subscrpt_new_product_name", update);
    },

    // Zoom the matching preview card while its field is focused.
    initFocusZoom: function () {
      var graph = $("#subscrpt-preview-graph");
      var groups = [
        { sel: "#subscrpt_plan_title", group: "plan" },
        {
          sel: "#subscrpt-product-search-input, #subscrpt_new_product_name, #subscrpt-connect-durations [data-connect-price]",
          group: "prod",
        },
      ];
      groups.forEach(function (g) {
        $(document).on("focusin", g.sel, function () {
          graph.attr("data-focus", g.group);
        });
        $(document).on("focusout", g.sel, function () {
          graph.attr("data-focus", "");
        });
      });

      // Durations zoom per card: focusing one duration's field zooms only the
      // preview node that duration maps to (fill order = card order).
      var durFields = "#subscrpt-durations input, #subscrpt-durations .wpsubs-adv-select__trigger";
      $(document).on("focusin", durFields, function () {
        var idx = $("#subscrpt-durations [data-dur]").index($(this).closest("[data-dur]"));
        $("#subscrpt-preview-graph [data-preview-dur]").removeClass("is-zoom");
        $('#subscrpt-preview-graph [data-preview-dur="' + idx + '"]').addClass("is-zoom");
      });
      $(document).on("focusout", durFields, function () {
        $("#subscrpt-preview-graph [data-preview-dur]").removeClass("is-zoom");
      });
    },

    // The name shown on the preview's product node, from whichever connect mode
    // is active.
    previewProductName: function () {
      if (this.hasProducts && this.currentConnectMode() === "existing") {
        return this.selectedProductName || "";
      }
      return $.trim($("#subscrpt_new_product_name").val());
    },

    // Show the price range (min–max of the toggled-on durations' prices) on the
    // product node's subtitle; falls back to "Subscribable" when no price set.
    updateProductPriceSub: function () {
      var sym = this.cfg.currency_symbol || "$";
      var prices = [];
      $("#subscrpt-connect-durations [data-connect-row]").each(function () {
        if (!$(this).find("[data-connect-enabled]").is(":checked")) {
          return;
        }
        var raw = $.trim($(this).find("[data-connect-price]").val());
        if (raw === "") {
          return;
        }
        var n = parseFloat(raw);
        if (!isNaN(n) && n >= 0) {
          prices.push(n);
        }
      });

      var fmt = function (v) {
        return sym + v.toFixed(2);
      };
      var text = "Price";
      if (prices.length) {
        var min = Math.min.apply(null, prices);
        var max = Math.max.apply(null, prices);
        text = min === max ? fmt(min) : fmt(min) + " - " + fmt(max);
      }
      $("#subscrpt-preview-prod-sub").text($("#subscrpt-plan-type").val() === "installments" && prices.length ? "Total commitment: " + text : text);
    },

    // Fill the persistent preview graph from the current form state. Each step
    // updates its own node; empty fields keep the placeholder label.
    updatePreview: function () {
      $("#subscrpt-preview-plan").text($.trim($("#subscrpt_plan_title").val()) || "Your plan");
      $("#subscrpt-preview-plan-type").text($(".wpsubs-plan-type-card.active").data("label") || "Recurring");

      // Duration nodes: stay placeholder ghost cards until the user reaches
      // page 2, then each real duration lights up one node in fill order.
      var self = this;
      var durations = this.reachedDurations ? this.collectDurations() : [];
      $("#subscrpt-preview-graph [data-preview-dur]").each(function () {
        var $node = $(this);
        var idx = parseInt($node.data("preview-dur"), 10) || 0;
        var dur = durations[idx];
        if (dur) {
          $node.removeClass("wpsubs-p1-node--ghost");
          $node.find(".wpsubs-p1-node__title").text(dur.name);
          $node.find(".wpsubs-p1-node__sub").text(self.billingEvery(dur.freq, dur.interval));
        } else {
          $node.addClass("wpsubs-p1-node--ghost");
          $node.find(".wpsubs-p1-node__title").text("Duration");
          $node.find(".wpsubs-p1-node__sub").text("Add more");
        }
      });

      var prod = this.previewProductName();
      if (prod) {
        $("#subscrpt-preview-prod").text(prod);
      }
      // The product node's subtitle shows the price range from the set pricing.
      this.updateProductPriceSub();

      // Which durations are toggled on for the product (page 3). If the rows
      // aren't rendered yet, treat every duration as on.
      var enabled = {};
      $("#subscrpt-connect-durations [data-connect-row]").each(function () {
        enabled[parseInt($(this).data("connect-dur"), 10)] = $(this).find("[data-connect-enabled]").is(":checked");
      });

      // Connector lines: muted by default. A plan->duration line colours in
      // once its duration is filled; a duration->product line also needs a
      // product to be added and that duration's toggle to be on.
      var hasProduct = !!prod;
      $("#subscrpt-preview-graph [data-line-dur]").each(function () {
        var $line = $(this);
        var idx = parseInt($line.data("line-dur"), 10) || 0;
        var active = !!durations[idx];
        if ($line.attr("data-line-to") === "prod") {
          active = active && hasProduct && enabled[idx] !== false;
        }
        $line.toggleClass("is-active", active);
      });
    },

    // ----- Durations (accordion) -----

    // The "billing every" value: "1 month", "3 days".
    billingEvery: function (freq, interval) {
      var n = parseInt(freq, 10) || 1;
      return n + " " + (interval || "month") + (n > 1 ? "s" : "");
    },

    // "1 month" -> "Every Month", "3 days" -> "Every 3 Days".
    durationName: function (freq, interval) {
      var labels = { day: "Day", week: "Week", month: "Month", year: "Year" };
      var label = labels[interval] || "Month";
      var n = parseInt(freq, 10) || 1;
      return n > 1 ? "Every " + n + " " + label + "s" : "Every " + label;
    },

    initDurations: function () {
      if (!$("#subscrpt-durations [data-dur]").length) {
        this.addDuration();
      }
      this.refreshDurControls();
    },

    addDuration: function (e) {
      if (e && e.preventDefault) {
        e.preventDefault();
      }
      if ($("#subscrpt-durations [data-dur]").length >= this.MAX_DURATIONS) {
        return;
      }
      var tpl = document.getElementById("subscrpt-duration-tpl");
      if (!tpl || !tpl.content) {
        return;
      }
      $("#subscrpt-durations").append(tpl.content.cloneNode(true));
      var card = $("#subscrpt-durations [data-dur]").last();
      // Wire up the cloned cadence picker (adv-select).
      if (window.WPSubsAdvSelect) {
        window.WPSubsAdvSelect.init(card[0]);
      }
      this.syncDurName(card, true);
      this.openDuration(card);
      this.refreshDurControls();
      this.updatePreview();
    },

    openDuration: function (card) {
      $("#subscrpt-durations [data-dur]").removeClass("is-open");
      card.addClass("is-open");
    },

    onDurToggle: function (e) {
      if ($(e.target).closest("[data-dur-remove]").length) {
        return;
      }
      var card = $(e.currentTarget).closest("[data-dur]");
      if (card.hasClass("is-open")) {
        card.removeClass("is-open");
      } else {
        this.openDuration(card);
      }
    },

    onDurRemove: function (e) {
      e.preventDefault();
      e.stopPropagation();
      var cards = $("#subscrpt-durations [data-dur]");
      if (cards.length <= 1) {
        return;
      }
      var card = $(e.currentTarget).closest("[data-dur]");
      var wasOpen = card.hasClass("is-open");
      card.remove();
      if (wasOpen) {
        $("#subscrpt-durations [data-dur]").last().addClass("is-open");
      }
      this.refreshDurControls();
      this.updatePreview();
    },

    onDurBillingInput: function (e) {
      this.syncDurName($(e.target).closest("[data-dur]"), false);
      this.updatePreview();
    },

    onDurNameInput: function (e) {
      var card = $(e.target).closest("[data-dur]");
      card.attr("data-name-edited", "1");
      card.find("[data-dur-title]").text($.trim(card.find("[data-dur-name]").val()) || "Duration");
      this.updatePreview();
    },

    // The interval value ("day"/"week"/"month"/"year") from a card's cadence
    // picker — the adv-select's hidden input.
    durInterval: function (card) {
      return card.find("[data-dur-interval] input[type=hidden]").val() || "month";
    },

    // Refresh the auto name from the billing period (unless the user edited it)
    // and mirror the name into the card header.
    syncDurName: function (card, force) {
      var auto = this.durationName(card.find("[data-dur-freq]").val(), this.durInterval(card));
      if (force || card.attr("data-name-edited") !== "1") {
        card.find("[data-dur-name]").val(auto);
      }
      card.find("[data-dur-title]").text($.trim(card.find("[data-dur-name]").val()) || auto);
    },

    refreshDurControls: function () {
      var count = $("#subscrpt-durations [data-dur]").length;
      $("#subscrpt-durations").toggleClass("has-multiple", count > 1);
      // The preview has 3 duration slots — no more durations past that.
      $("#subscrpt-btn-add-duration").toggle(count < this.MAX_DURATIONS);
    },

    collectDurations: function () {
      var self = this;
      var out = [];
      $("#subscrpt-durations [data-dur]").each(function () {
        var $c = $(this);
        if (!$c.attr("data-dur-key")) {
          $c.attr("data-dur-key", ++self.durationSequence);
        }
        var freq = parseInt($c.find("[data-dur-freq]").val(), 10) || 1;
        var interval = self.durInterval($c);
        var name = $.trim($c.find("[data-dur-name]").val()) || self.durationName(freq, interval);
        out.push({ freq: freq, interval: interval, name: name, key: $c.attr("data-dur-key") });
      });
      return out;
    },

    // Nothing is created here — just validate and move on. The plan, durations
    // and product are all created together on the final step.
    nextFromDurations: function (e) {
      e.preventDefault();
      if (!$.trim($("#subscrpt_plan_title").val())) {
        window.alert("Please enter a plan name.");
        this.switchSection(1);
        return;
      }
      if (!this.collectDurations().length) {
        window.alert("Please add at least one duration.");
        return;
      }
      this.switchSection(3);
    },

    // ----- Page 3: connect to a product -----

    // Which connect mode is active. Without existing products the only option
    // is creating a new one.
    currentConnectMode: function () {
      if (!this.hasProducts) {
        return "new";
      }
      var active = $(".wpsubs-connect-mode-card.active");
      return active.length ? active.data("mode") : "existing";
    },

    selectConnectMode: function (e) {
      var card = $(e.currentTarget);
      var mode = card.data("mode");
      $(".wpsubs-connect-mode-card").removeClass("active");
      card.addClass("active");
      $("#subscrpt-connect-existing").toggle(mode === "existing");
      $("#subscrpt-connect-new").toggle(mode === "new");
      this.updatePreview();
    },

    openProductSearch: function () {
      $(".wpsubs-p2-product-search__item").show();
      $(".wpsubs-p2-product-search__empty").hide();
      $("#subscrpt-product-search-dropdown").show();
    },

    filterProducts: function (e) {
      var q = $(e.target).val().toLowerCase().trim();
      $("#subscrpt-product-search-dropdown").show();
      var visible = 0;
      $(".wpsubs-p2-product-search__item").each(function () {
        var name = $(this).data("name") ? String($(this).data("name")).toLowerCase() : "";
        var sku = $(this).data("sku") ? String($(this).data("sku")).toLowerCase() : "";
        if (!q || name.indexOf(q) >= 0 || sku.indexOf(q) >= 0) {
          $(this).show();
          visible++;
        } else {
          $(this).hide();
        }
      });
      $(".wpsubs-p2-product-search__empty").toggle(visible === 0);
    },

    onProductPick: function (e) {
      var item = $(e.currentTarget);
      // Products already attached to a plan can't be picked.
      if (item.attr("data-connected") === "1") {
        return;
      }
      var id = String(item.data("id"));
      var name = item.data("name") || "";
      var price = item.data("price") != null ? String(item.data("price")) : "";
      var type = item.data("type") || "";
      var sku = item.data("sku") ? String(item.data("sku")) : "";

      $("#subscrpt-existing-product-hidden").val(id);
      $("#subscrpt-product-search-dropdown").hide();

      var initials = name
        .split(" ")
        .filter(Boolean)
        .slice(0, 2)
        .map(function (w) {
          return w[0].toUpperCase();
        })
        .join("");
      var meta = [sku ? "SKU " + sku : null, type].filter(Boolean).join(" · ");

      $("#p3-chip-avatar").text(initials || "?");
      $("#p3-chip-name").text(name);
      $("#p3-chip-meta").text(meta);
      $("#subscrpt-product-select-wrap").hide();
      $("#subscrpt-selected-product-chip").show();

      // Prefill any empty duration price with the product's price as a starting
      // point; leave prices the user already typed alone.
      if (price) {
        $("#subscrpt-connect-durations [data-connect-price]").each(function () {
          if (!$.trim($(this).val())) {
            $(this).val(parseFloat(price).toFixed(2));
          }
        });
      }

      this.selectedProductName = name;
      this.updatePreview();
    },

    clearProduct: function () {
      $("#subscrpt-existing-product-hidden").val("");
      $("#subscrpt-product-search-input").val("");
      $(".wpsubs-p2-product-search__item").show();
      $("#subscrpt-selected-product-chip").hide();
      $("#subscrpt-product-select-wrap").show();
      this.selectedProductName = "";
    },

    // Snapshot the current price/toggle of each duration row, keyed by index,
    // so re-rendering doesn't lose what the user typed.
    readConnectState: function () {
      var state = {};
      $("#subscrpt-connect-durations [data-connect-row]").each(function () {
        var idx = parseInt($(this).data("connect-dur"), 10);
        state[idx] = {
          durationKey: $(this).attr("data-connect-key"),
          price: $(this).find("[data-connect-price]").val(),
          enabled: $(this).find("[data-connect-enabled]").is(":checked"),
        };
      });
      return state;
    },

    // Build one pricing row per duration (from step 2), preserving any values
    // already entered. All rows connect to the single product.
    renderConnectDurations: function () {
      var self = this;
      var tpl = document.getElementById("subscrpt-connect-dur-tpl");
      if (!tpl || !tpl.content) {
        return;
      }
      var prev = this.readConnectState();
      var durations = this.collectDurations();
      var $wrap = $("#subscrpt-connect-durations").empty();

      durations.forEach(function (dur, idx) {
        var $frag = $(tpl.content.cloneNode(true));
        var $row = $frag.find("[data-connect-row]");
        $row.attr("data-connect-dur", idx);
        $row.attr("data-connect-key", dur.key);
        $row.find("[data-connect-name]").text(dur.name);
        var installments = $("#subscrpt-plan-type").val() === "installments";
        $row.find("[data-connect-billing]").text((installments ? "Total commitment; payments every " : "Price per payment; billing every ") + self.billingEvery(dur.freq, dur.interval));
        $row.find("[data-connect-price]").attr("aria-label", dur.name + (installments ? " total commitment" : " price per payment"));
        var previous = Object.keys(prev).map(function (key) { return prev[key]; }).find(function (row) { return row.durationKey && row.durationKey === dur.key; });
        if (previous) {
          $row.find("[data-connect-price]").val(previous.price);
          $row.find("[data-connect-enabled]").prop("checked", previous.enabled);
        }
        $row.toggleClass("is-off", !$row.find("[data-connect-enabled]").is(":checked"));
        $wrap.append($frag);
      });
    },

    onConnectToggle: function (e) {
      var $row = $(e.target).closest("[data-connect-row]");
      $row.toggleClass("is-off", !$(e.target).is(":checked"));
      this.updatePreview();
    },

    // Nothing is created here either — validate the product choice and the
    // per-duration prices, then move to the final step which does all the work.
    nextFromProduct: function (e) {
      e.preventDefault();

      if (this.currentConnectMode() === "existing") {
        if (!$("#subscrpt-existing-product-hidden").val()) {
          window.alert("Please select a product.");
          return;
        }
      } else if (!$.trim($("#subscrpt_new_product_name").val())) {
        window.alert("Please enter a product name.");
        return;
      }

      var rows = $("#subscrpt-connect-durations [data-connect-row]");
      var enabledRows = rows.filter(function () {
        return $(this).find("[data-connect-enabled]").is(":checked");
      });
      if (!enabledRows.length) {
        window.alert("Please keep at least one duration on.");
        return;
      }
      var badPrice = false;
      enabledRows.each(function () {
        var p = $.trim($(this).find("[data-connect-price]").val());
        if (p && (!/^(?:\d+(?:\.\d*)?|\.\d+)$/.test(p) || !Number.isFinite(Number(p)))) {
          badPrice = true;
        }
      });
      if (badPrice) {
        window.alert("Please enter valid prices.");
        return;
      }

      if (this.finalizeRunning || this.planPending || this.intent || this.uncertainWrite) {
        return;
      }
      try {
        this.captureIntent();
        this.switchSection(4);
        this.renderReview();
      } catch (err) {
        window.alert(err.message);
      }
    },

    // ----- Page 4: review before any mutation -----

    renderReview: function () {
      if (this.intent || this.finalizeRunning) {
        return;
      }
      this.reviewedIntent = this.captureIntent();
      var intent = this.reviewedIntent;
      var sym = this.cfg.currency_symbol || "$";
      var lines = [intent.title + " — " + intent.productName];
      intent.terms.forEach(function (term, idx) {
        var row = intent.rows[idx];
        if (!row || !row.enabled) {
          return;
        }
        var price = row.price || "0";
        var cadence = this.billingEvery(term.billing_frequency, Object.keys(INTERVAL_TO_INT).find(function (key) {
          return INTERVAL_TO_INT[key] === term.billing_interval;
        }));
        if (intent.type === "installments") {
          var decimals = Number(this.cfg.currency_decimals == null ? $("#subscrpt-installment-count").attr("data-currency-decimals") : this.cfg.currency_decimals);
          var factor = Math.pow(10, decimals);
          var minor = Math.round((Number(price) + Number.EPSILON) * factor);
          var count = term.data.installment_count;
          var base = Math.floor(minor / count);
          var final = minor - base * (count - 1);
          lines.push(term.title + ": total " + sym + (minor / factor).toFixed(decimals) + " across " + count + " payments, every " + cadence + ". Base estimate due today: " + sym + (base / factor).toFixed(decimals) + "; first " + (count - 1) + " payments: " + sym + (base / factor).toFixed(decimals) + " each; final payment: " + sym + (final / factor).toFixed(decimals) + ". No trial or signup fee is added.");
        } else {
          lines.push(term.title + ": " + sym + price + " per payment, every " + cadence + "; recurring until cancelled.");
        }
      }, this);
      lines.push("Signup fee: 0. No free trial. Taxes, shipping and coupons are calculated at checkout, not included here.");
      lines.push(intent.publish ? (intent.mode === "new" ? "Activate this plan and publish the new product after setup. Customers may purchase it." : "Activate this plan on the existing product. Its publication status, native prices and one-time settings will not change; an already published product may offer these subscriptions.") : "Create draft plan and durations. A new product stays draft; an existing product keeps its publication status. This plan is not offered to customers until activated separately.");
      $("#subscrpt-review-summary").text(lines.join("\n"));
      $("#subscrpt-review-confirm").prop("checked", false);
      $("#subscrpt-btn-create-reviewed").text(intent.publish ? "Activate reviewed plan" : "Create reviewed draft");
      $("#subscrpt-finalize-review").removeAttr("hidden");
      $("#subscrpt-finalize-progress").attr("hidden", "hidden");
    },

    // Mark a progress step's state ("is-doing" / "is-done").
    finalizeStep: function (key, state) {
      $('[data-finalize-step="' + key + '"]')
        .removeClass("is-doing is-done")
        .addClass(state);
    },

    // Run the whole creation flow in order: plan -> durations -> product +
    // relations. Each sub-step is skipped if a retry already completed it.
    finalize: function (e) {
      if (e && e.preventDefault) {
        e.preventDefault();
      }
      if (this.finalizeRunning || this.planPending) {
        return;
      }
      try {
        if (this.uncertainWrite) {
          throw this.uncertainError();
        }
        var current = this.captureIntent();
        var approved = this.intent || this.reviewedIntent;
        if (!approved || !$("#subscrpt-review-confirm").is(":checked")) {
          throw new Error("Review the billing and publication choices, then confirm them before creating anything.");
        }
        if (JSON.stringify(current) !== JSON.stringify(approved)) {
          throw new Error("Fields changed after review. Restore the original reviewed values to resume. Check existing records before starting a different plan.");
        }
        if (!this.intent && approved.publish && !window.confirm("Activate the reviewed subscription plan? New products will be published; existing products keep their current publication status. Customers may be able to buy these subscriptions.")) {
          return;
        }
        this.intent = approved;
      } catch (err) {
        $("#subscrpt-finalize-error-msg").text(err.message);
        $("#subscrpt-finalize-error").removeAttr("hidden");
        return;
      }
      this.finalizeRunning = true;

      var self = this;
      $("#subscrpt-finalize-error, #subscrpt-finalize-success").attr("hidden", "hidden");
      $("#subscrpt-finalize-progress").removeAttr("hidden");
      $("#subscrpt-link-plans, #subscrpt-link-products").attr("hidden", "hidden");
      $("[data-finalize-step]").removeClass("is-doing is-done");

      $("#subscrpt-finalize-review").attr("hidden", "hidden");
      return this.ensurePlan()
        .then(function () {
          return self.ensureProductAndConnect();
        })
        .then(function () {
          return self.completePublication();
        })
        .then(function () {
          self.finalizeRunning = false;
          self.showDone(self.finalProductName);
        })
        .catch(function (err) {
          self.finalizeRunning = false;
          $("#subscrpt-finalize-progress").attr("hidden", "hidden");
          $("#subscrpt-finalize-error-msg").text(err && err.message ? err.message : "Please try again.");
          $("#subscrpt-finalize-error").removeAttr("hidden");
          $("#subscrpt-btn-retry-finalize").prop("disabled", self.uncertainWrite);
          $("#subscrpt-link-plans, #subscrpt-link-products").removeAttr("hidden");
        });
    },

    installmentCount: function () {
      var count = Number($("#subscrpt-installment-count").val());
      if (!Number.isSafeInteger(count) || count < 2) {
        throw new Error("Choose a whole number of payments (minimum 2).");
      }
      return count;
    },

    termBody: function (dur, groupId, type) {
      var data = { free_trial_interval: "day" };
      if (type === "installments") {
        data.installment_count = this.installmentCount();
      }
      return {
        plan_group_id: groupId,
        type: type,
        title: dur.name,
        billing_frequency: dur.freq,
        billing_interval: INTERVAL_TO_INT[dur.interval] || 3,
        billing_length: 0,
        free_trial: "",
        signup_fee: { amount: "" },
        status: "draft",
        data: data,
      };
    },

    // Freeze all mutation inputs before the first request, including prices.
    captureIntent: function () {
      var type = $("#subscrpt-plan-type").val() || "recurring";
      var rows = this.readConnectState();
      Object.keys(rows).forEach(function (key) {
        var row = rows[key];
        var price = $.trim(row.price);
        if (row.enabled && price && (!/^(?:\d+(?:\.\d*)?|\.\d+)$/.test(price) || !Number.isFinite(Number(price)))) {
          throw new Error("Please enter valid decimal prices.");
        }
      });
      return {
        title: $.trim($("#subscrpt_plan_title").val()),
        type: type,
        terms: this.collectDurations().map(function (dur) {
          return this.termBody(dur, 0, type);
        }, this),
        mode: this.currentConnectMode(),
        productId: $("#subscrpt-existing-product-hidden").val() || "",
        productName: this.previewProductName(),
        rows: rows,
        publish: $("#subscrpt-publish-choice").is(":checked"),
      };
    },

    uncertainError: function () {
      return new Error("Check existing plans and products before continuing. A request may have saved, but its outcome could not be confirmed. Do not create another copy.");
    },

    // There is no server-side idempotency contract for POST. Only an explicit
    // validation refusal is retryable; a lost/malformed success fails closed.
    writeRecord: function (method, path, body) {
      var self = this;
      if (this.uncertainWrite) {
        return Promise.reject(this.uncertainError());
      }
      return this.api(method, path, body).then(function (record) {
        if (!record || !Number.isSafeInteger(Number(record.id)) || Number(record.id) <= 0) {
          throw new Error("The saved record could not be confirmed.");
        }
        return record;
      }).catch(function (err) {
        if (!err.safeRetry) {
          self.uncertainWrite = true;
          throw self.uncertainError();
        }
        throw err;
      });
    },

    // Reuse the group, seeded ID and each individually completed duration.
    ensurePlan: function () {
      var self = this;
      if (this.uncertainWrite) {
        return Promise.reject(this.uncertainError());
      }
      if (this.planPending) {
        return this.planPending;
      }
      try {
        this.intent = this.intent || this.captureIntent();
      } catch (err) {
        return Promise.reject(err);
      }
      var intent = this.intent;
      this.finalizeStep("plan", "is-doing");
      this.planPending = Promise.resolve().then(function () {
        if (self.groupId) {
          return;
        }
        return self.writeRecord("POST", "/groups", { title: intent.title, type: intent.type, product_type: 1, status: "draft" }).then(function (group) {
          self.groupId = Number(group.id);
          self.seededTermId = group.plans && group.plans[0] ? Number(group.plans[0].id) : 0;
          // A missing seed is not permission to create a duplicate duration.
          if (!self.seededTermId) {
            self.uncertainWrite = true;
            throw self.uncertainError();
          }
          self.planTitle = intent.title;
          self.billingText = intent.terms[0].title;
        });
      }).then(function () {
        self.finalizeStep("plan", "is-done");
        self.finalizeStep("durations", "is-doing");
        return intent.terms.reduce(function (chain, term, idx) {
          return chain.then(function () {
            if (self.termIds[idx]) {
              return;
            }
            var body = Object.assign({}, term, { plan_group_id: self.groupId });
            var method = idx === 0 ? "PUT" : "POST";
            var path = idx === 0 ? "/terms/" + self.seededTermId : "/terms";
            return self.writeRecord(method, path, body).then(function (saved) {
              self.termIds[idx] = Number(saved.id);
            });
          });
        }, Promise.resolve());
      }).then(function () {
        self.finalizeStep("durations", "is-done");
      }).finally(function () {
        self.planPending = null;
      });
      return this.planPending;
    },

    // Create the product (if new) and connect the plan to it. No-op if a retry
    // already connected it.
    ensureProductAndConnect: function () {
      var self = this;
      if (this.relationsCreated) {
        this.finalizeStep("product", "is-done");
        return Promise.resolve();
      }
      this.finalizeStep("product", "is-doing");
      return this.ensureProduct()
        .then(function () {
          return self.createRelations();
        })
        .then(function () {
          self.relationsCreated = true;
          self.finalizeStep("product", "is-done");
        });
    },

    // Resolve from the frozen reviewed selection, never from changed fields.
    ensureProduct: function () {
      var self = this;
      if (this.finalProductId) {
        return Promise.resolve();
      }
      if (this.intent.mode === "existing") {
        this.finalProductId = this.intent.productId;
        this.finalProductName = this.intent.productName;
        return Promise.resolve();
      }
      return this.productRequest("subscrpt_create_wizard_product", { product_name: this.intent.productName }).then(function (record) {
        self.finalProductId = record.product_id;
        self.finalProductName = self.intent.productName;
      });
    },

    productRequest: function (action, fields) {
      var self = this;
      if (this.uncertainWrite) {
        return Promise.reject(this.uncertainError());
      }
      return new Promise(function (resolve, reject) {
        $.post(self.cfg.ajax_url, Object.assign({ action: action, nonce: $("#subscrpt_wizard_nonce").val() }, fields), function (response) {
          if (response && response.success && response.data && Number.isSafeInteger(Number(response.data.product_id)) && Number(response.data.product_id) > 0 && response.data.product_status === (action === "subscrpt_publish_wizard_product" ? "publish" : "draft")) {
            resolve(response.data);
          } else {
            // Only declared pre-write validation refusals permit resubmission.
            if (response && response.success === false && response.data && response.data.safe_retry === true) {
              reject(new Error(response.data.message));
            } else {
              self.uncertainWrite = true;
              reject(self.uncertainError());
            }
          }
        }).fail(function () {
          self.uncertainWrite = true;
          reject(self.uncertainError());
        });
      });
    },

    // Sequential writes checkpoint each relation, so later refusals don't replay
    // earlier writes. A lost response stops here instead of blindly POSTing again.
    createRelations: function () {
      var self = this;
      return this.termIds.reduce(function (chain, tid, idx) {
        return chain.then(function () {
          var row = self.intent.rows[idx];
          if (!row || !row.enabled || self.relationIds[idx]) {
            return;
          }
          return self.writeRecord("POST", "/relations", {
            plan_id: tid,
            oid: Number(self.finalProductId),
            vid: 0,
            type: 1,
            status: "draft",
            exclude: false,
            data: { regular_price: $.trim(row.price), sale_price: "", discount_value: 0 },
          }).then(function (record) {
            self.relationIds[idx] = Number(record.id);
          });
        });
      }, Promise.resolve());
    },

    // Read the canonical records independently before activation and before done.
    verifyStoredCommitment: function (status) {
      var self = this;
      return this.api("GET", "/groups/" + this.groupId).then(function (group) {
        if (!group || Number(group.id) !== self.groupId || group.status !== status || group.title !== self.intent.title) {
          throw new Error("Saved plan status could not be confirmed. Check existing plans and products.");
        }
        return self.termIds.reduce(function (pending, id, idx) {
          return pending.then(function () {
            return self.api("GET", "/terms/" + id).then(function (term) {
              var expected = self.intent.terms[idx];
              if (!term || Number(term.id) !== id || Number(term.plan_group_id) !== self.groupId || term.title !== expected.title || term.status !== status || Number(term.billing_frequency) !== expected.billing_frequency || Number(term.billing_interval) !== expected.billing_interval || Number(term.billing_length) !== expected.billing_length || String(term.free_trial || "") !== expected.free_trial || Number((term.signup_fee || {}).amount || 0) !== 0 || (self.intent.type === "installments" && Number((term.data || {}).installment_count) !== expected.data.installment_count)) {
                throw new Error("Saved billing commitment could not be confirmed. Check existing plans and products.");
              }
              var row = self.intent.rows[idx];
              if (row && row.enabled) {
                var plan = (group.plans || []).find(function (candidate) { return Number(candidate.id) === id; });
                var relation = plan && (plan.relations || []).find(function (candidate) { return Number(candidate.id) === self.relationIds[idx]; });
                if (!relation || Number(relation.oid) !== Number(self.finalProductId) || Number(relation.vid) !== 0 || relation.status !== status || String((relation.data || {}).regular_price || "") !== $.trim(row.price) || Number((relation.data || {}).discount_value || 0) !== 0 || String((relation.data || {}).sale_price || "") !== "") {
                  throw new Error("Saved product pricing could not be confirmed. Check existing plans and products.");
                }
              }
            });
          });
        }, Promise.resolve());
      });
    },

    // Verify staged records first; publish only after explicit opt-in activation.
    completePublication: function () {
      var self = this;
      if (this.publicationComplete) {
        return Promise.resolve();
      }
      var chain = this.verifyStoredCommitment("draft");
      if (this.intent.publish) {
        this.termIds.forEach(function (id) {
          chain = chain.then(function () {
            return self.writeRecord("PUT", "/terms/" + id, { status: "active" });
          });
        });
        chain = chain.then(function () {
          return self.writeRecord("PUT", "/groups/" + self.groupId, { status: "active" });
        });
        Object.keys(this.relationIds).forEach(function (idx) {
          chain = chain.then(function () {
            return self.writeRecord("PUT", "/relations/" + self.relationIds[idx], { status: "active" });
          });
        });
        chain = chain.then(function () {
          return self.verifyStoredCommitment("active");
        });
      }
      if (this.intent.publish && this.intent.mode === "new") {
        chain = chain.then(function () {
          return self.productRequest("subscrpt_publish_wizard_product", { product_id: self.finalProductId, publish_confirmed: "yes" });
        });
      }
      return chain.then(function () {
        self.publicationComplete = true;
      }).catch(function (err) {
        self.uncertainWrite = true;
        throw err;
      });
    },

    showDone: function (productName) {
      $("#subscrpt-done-heading").text(this.intent.publish ? "Your plan is active." : "Your draft is saved.");
      $("#subscrpt-done-summary").text(this.intent.publish ? (this.intent.mode === "new" ? "The plan is active and the new product is published. Checkout applies taxes, shipping and coupons." : "The plan is active. The existing product's publication status was not changed; storefront availability depends on that status.") : "The plan and durations are draft. A new product remains draft; an existing product's publication status was not changed. This plan is not offered to customers until activated separately.");
      if (productName) {
        $("#subscrpt-preview-prod").text(productName);
      }
      $("#subscrpt-finalize-progress, #subscrpt-finalize-error").attr("hidden", "hidden");
      $("#subscrpt-finalize-success").removeAttr("hidden");
      $("#subscrpt-link-plans, #subscrpt-link-products, #subscrpt-btn-finish").removeAttr("hidden");
      $("#subscrpt-preview-graph").attr("data-active", "done");
      this.updatePreview();
    },

    restart: function (e) {
      e.preventDefault();
      if (this.finalizeRunning || this.planPending || this.uncertainWrite || !this.publicationComplete) {
        return;
      }
      var self = this;
      $.post(
        this.cfg.ajax_url,
        {
          action: "subscrpt_reset_wizard",
          nonce: $("#subscrpt_wizard_nonce").val(),
        },
        function () {
          self.intent = null;
          self.reviewedIntent = null;
          self.seededTermId = 0;
          self.relationIds = {};
          self.publicationComplete = false;
          self.groupId = 0;
          self.termIds = [];
          self.planTitle = "";
          self.billingText = "";
          self.finalProductId = "";
          self.finalProductName = "";
          self.relationsCreated = false;
          self.finalizeRunning = false;
          // Reset the final step back to its progress state for a fresh run.
          $("#subscrpt-finalize-success, #subscrpt-finalize-error").attr("hidden", "hidden");
          $("#subscrpt-finalize-progress").removeAttr("hidden");
          $("[data-finalize-step]").removeClass("is-doing is-done");
          $("#subscrpt-link-plans, #subscrpt-link-products, #subscrpt-btn-finish").attr("hidden", "hidden");
          $("#subscrpt_new_product_name").val("");
          $("#subscrpt-connect-durations").empty();
          $("#subscrpt-durations").empty();
          self.initDurations();
          self.clearProduct();
          // Reset the preview nodes back to their placeholders.
          $("#subscrpt-preview-prod").text("Product");
          self.reachedDurations = false;
          $("#subscrpt-preview-graph [data-preview-dur]")
            .addClass("wpsubs-p1-node--ghost")
            .find(".wpsubs-p1-node__title")
            .text("Duration");
          $("#subscrpt-preview-graph [data-preview-dur] .wpsubs-p1-node__sub").text("Add more");
          self.switchSection(1);
        },
      );
    },
  };

  $(document).ready(function () {
    Wizard.init();
  });
})(jQuery);
