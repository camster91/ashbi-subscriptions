/* Confirmation improves the form; the capability, nonce and digest checks live on the server. */
(function () {
  var form = document.querySelector("[data-ashbi-migration]");
  if (!form) { return; }
  var confirmation = form.querySelector('[name="confirm_apply"]');
  var apply = form.querySelector('[value="apply"]');
  function update() {
    apply.disabled = apply.getAttribute("data-reviewed") !== "yes" || !confirmation.checked;
  }
  form.addEventListener("input", function (event) {
    if (["group_title", "attribute", "remove_stopgap"].indexOf(event.target.name) !== -1) {
      apply.setAttribute("data-reviewed", "no");
    }
    update();
  });
  form.addEventListener("change", update);
  update();
})();
