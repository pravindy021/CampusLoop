async function sellerCsrfToken() {
  const response = await fetch("/api/v1/csrf", { credentials: "same-origin" });
  if (!response.ok) throw new Error("Your session expired. Refresh the page and sign in again.");
  const result = await response.json();
  if (typeof result.csrf_token !== "string") throw new Error("Could not verify the request.");
  return result.csrf_token;
}

function showSellerMessage(message, isError = false) {
  const target = document.querySelector("[data-seller-message]");
  if (!target) return;
  target.textContent = message;
  target.setAttribute("role", isError ? "alert" : "status");
}

const listingForm = document.querySelector("#seller-listing-form");
if (listingForm) {
  const typeSelect = listingForm.querySelector("#seller-listing-type");
  const categorySelect = listingForm.querySelector("#seller-category");
  const subcategorySelect = listingForm.querySelector("#seller-subcategory");
  const imagesInput = listingForm.querySelector("#seller-images");
  const imagePreview = listingForm.querySelector("#seller-image-preview");
  const message = listingForm.querySelector("#seller-form-message");
  const maxImages = Number(listingForm.dataset.maxImages);
  const maxBytes = Number(listingForm.dataset.maxImageBytes);
  let previewUrls = [];

  function updateListingFields() {
    const type = typeSelect.value;
    for (const field of listingForm.querySelectorAll("[data-seller-sale-field]")) {
      const input = field.querySelector("input");
      field.hidden = !["sell_only", "rent_and_sell"].includes(type);
      input.required = !field.hidden;
      input.disabled = field.hidden;
    }
    for (const field of listingForm.querySelectorAll("[data-seller-rental-field]")) {
      const input = field.querySelector("input");
      field.hidden = !["rent_only", "rent_and_sell"].includes(type);
      input.required = !field.hidden && input.name === "daily_rental_price";
      input.disabled = field.hidden;
    }
    const selectedCategory = categorySelect.selectedOptions[0];
    const categorySlug = selectedCategory?.dataset.categorySlug || "";
    const sharingRightsField = listingForm.querySelector("[data-study-material-terms]");
    const sharingRightsInput = sharingRightsField.querySelector("input");
    sharingRightsField.hidden = categorySlug !== "college-school";
    sharingRightsInput.required = !sharingRightsField.hidden;
    sharingRightsInput.disabled = sharingRightsField.hidden;
    if (sharingRightsField.hidden) sharingRightsInput.checked = false;
    for (const option of subcategorySelect.options) {
      if (!option.value) continue;
      const visible = option.dataset.parentCategory === categorySelect.value;
      option.hidden = !visible;
      option.disabled = !visible;
      if (!visible && option.selected) subcategorySelect.value = "";
    }
  }

  function renderImagePreviews() {
    for (const url of previewUrls) URL.revokeObjectURL(url);
    previewUrls = [];
    imagePreview.replaceChildren();
    const currentCount = listingForm.querySelectorAll('.seller-image-remove input:not(:checked)').length;
    for (const [index, file] of Array.from(imagesInput.files).entries()) {
      const figure = document.createElement("figure");
      figure.className = "seller-image-preview";
      const image = document.createElement("img");
      const url = URL.createObjectURL(file);
      previewUrls.push(url);
      image.src = url;
      image.alt = `Preview of ${file.name}`;
      const primaryLabel = document.createElement("label");
      const radio = document.createElement("input");
      radio.type = "radio";
      radio.name = "primary_image";
      radio.value = `new-${index}`;
      radio.checked = !listingForm.querySelector('input[name="primary_image"]:checked');
      primaryLabel.append(radio, document.createTextNode(" Primary"));
      const name = document.createElement("figcaption");
      name.textContent = file.name;
      figure.append(image, primaryLabel, name);
      imagePreview.append(figure);
    }
    if (currentCount + imagesInput.files.length > maxImages) {
      message.textContent = `Keep no more than ${maxImages} images in total.`;
      message.setAttribute("role", "alert");
    } else {
      message.textContent = "";
    }
  }

  typeSelect.addEventListener("change", updateListingFields);
  categorySelect.addEventListener("change", updateListingFields);
  imagesInput.addEventListener("change", renderImagePreviews);
  listingForm.addEventListener("change", (event) => {
    if (event.target.matches(".seller-image-remove input")) {
      const radio = event.target.closest("figure")?.querySelector('input[name="primary_image"]');
      if (event.target.checked && radio?.checked) {
        radio.checked = false;
        const replacement = Array.from(listingForm.querySelectorAll('input[name="primary_image"]'))
          .find((candidate) => !candidate.closest("figure")?.querySelector(".seller-image-remove input:checked"));
        if (replacement) replacement.checked = true;
      }
      renderImagePreviews();
    }
  });
  updateListingFields();
  renderImagePreviews();

  listingForm.addEventListener("submit", async (event) => {
    event.preventDefault();
    message.textContent = "";
    const files = Array.from(imagesInput.files);
    const retainedImages = listingForm.querySelectorAll(".seller-image-remove input:not(:checked)").length;
    if (files.some((file) => file.size <= 0 || file.size > maxBytes
      || !["image/jpeg", "image/png", "image/webp"].includes(file.type))) {
      message.textContent = "Choose valid JPEG, PNG, or WebP images within the configured file-size limit.";
      return;
    }
    if (files.length + retainedImages > maxImages) {
      message.textContent = `A listing can have at most ${maxImages} images. Remove an existing image or choose fewer files.`;
      return;
    }
    const submitter = event.submitter;
    const formData = new FormData(listingForm);
    formData.set("intent", submitter?.value || "submit");
    const buttons = Array.from(listingForm.querySelectorAll('button[type="submit"]'));
    buttons.forEach((button) => {
      button.disabled = true;
      button.setAttribute("aria-busy", "true");
    });
    try {
      const csrfToken = await sellerCsrfToken();
      const response = await fetch(listingForm.action, {
        method: "POST",
        credentials: "same-origin",
        headers: { "X-CSRF-Token": csrfToken },
        body: formData
      });
      const result = await response.json();
      if (!response.ok) throw new Error(result.error || "The listing could not be saved.");
      window.location.assign(result.redirect_url || "/seller/listings");
    } catch (error) {
      message.textContent = error instanceof Error ? error.message : "The listing could not be saved.";
      message.setAttribute("role", "alert");
      buttons.forEach((button) => {
        button.disabled = false;
        button.removeAttribute("aria-busy");
      });
    }
  });
}

document.querySelectorAll("form[data-listing-action]").forEach((form) => {
  const button = form.querySelector('button[type="submit"]');
  form.addEventListener("submit", async (event) => {
    event.preventDefault();
    const confirmation = form.dataset.confirm;
    if (confirmation && !window.confirm(confirmation)) return;
    button.disabled = true;
    try {
      const csrfToken = await sellerCsrfToken();
      const payload = Object.fromEntries(new FormData(form).entries());
      const response = await fetch(form.action, {
        method: "POST",
        credentials: "same-origin",
        headers: { "Content-Type": "application/json", "X-CSRF-Token": csrfToken },
        body: JSON.stringify(payload)
      });
      const result = await response.json();
      if (!response.ok) throw new Error(result.error || "The listing action could not be completed.");
      window.location.reload();
    } catch (error) {
      showSellerMessage(error instanceof Error ? error.message : "The listing action could not be completed.", true);
      button.disabled = false;
    }
  });
});
