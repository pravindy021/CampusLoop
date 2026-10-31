const filterForm = document.querySelector("#buyer-filters");
const searchForm = document.querySelector("#buyer-search-form");
const productGrid = document.querySelector("#buyer-products");
const resultLabel = document.querySelector("#buyer-results");
const emptyLabel = document.querySelector("#buyer-empty");
const errorLabel = document.querySelector("#buyer-error");
const pagination = document.querySelector("#buyer-pagination");
const wishlistStatus = document.querySelector("#buyer-wishlist-status");
const profileButton = document.querySelector("#buyer-profile-button");
const profileMenu = document.querySelector("#buyer-profile-menu");
const wishlistIds = new Set();
let activePage = 1;
let totalPages = 1;

const conditionLabels = {
  new: "New",
  like_new: "Like new",
  good: "Good",
  fair: "Fair"
};
const listingLabels = {
  sell_only: "For sale",
  rent_only: "For rent",
  rent_and_sell: "For sale or rent"
};

function setBuyerProfileOpen(open) {
  profileButton.setAttribute("aria-expanded", String(open));
  profileMenu.hidden = !open;
}

profileButton.addEventListener("click", () => {
  setBuyerProfileOpen(profileButton.getAttribute("aria-expanded") !== "true");
});
document.addEventListener("click", (event) => {
  if (!event.target.closest(".buyer-profile")) setBuyerProfileOpen(false);
});
document.addEventListener("keydown", (event) => {
  if (event.key === "Escape" && !profileMenu.hidden) {
    setBuyerProfileOpen(false);
    profileButton.focus();
  }
});

function setError(message) {
  errorLabel.textContent = message;
  errorLabel.hidden = !message;
}

function collectFilters() {
  const filters = new URLSearchParams();
  const values = new FormData(filterForm);
  const query = new FormData(searchForm).get("q");
  if (typeof query === "string" && query.trim()) filters.set("q", query.trim());
  for (const [key, value] of values) {
    if (typeof value === "string" && value.trim()) filters.set(key, value.trim());
  }
  const city = document.querySelector("#buyer-city").value.trim();
  if (city) filters.set("city", city);
  filters.set("page", String(activePage));
  filters.set("per_page", "12");
  filters.set("sort", "newest");
  return filters;
}

function formatPrice(value) {
  if (value === null || value === undefined || value === "") return "";
  const amount = Number(value);
  return Number.isFinite(amount)
    ? new Intl.NumberFormat(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(amount)
    : "";
}

function appendPrice(parent, label, amount) {
  if (amount === null || amount === undefined || amount === "") return;
  const line = document.createElement("span");
  line.className = "buyer-product__price";
  const prefix = document.createElement("small");
  prefix.textContent = label;
  const price = document.createElement("strong");
  price.textContent = formatPrice(amount);
  line.append(prefix, price);
  parent.append(line);
}

function safeSameOriginImage(path) {
  if (typeof path !== "string" || !path.trim()) return null;
  const segments = path.slice(1).split("/");
  if (!/^\/(?!\/)[A-Za-z0-9_./-]+$/.test(path)
    || segments.some((segment) => !segment || segment === "." || segment === "..")) return null;
  try {
    const url = new URL(path, window.location.origin);
    return url.origin === window.location.origin && ["http:", "https:"].includes(url.protocol)
      ? url.href
      : null;
  } catch {
    return null;
  }
}

function createProductCard(product) {
  const card = document.createElement("article");
  card.className = "buyer-product";

  const link = document.createElement("a");
  link.className = "buyer-product__link";
  link.href = `/products/${encodeURIComponent(product.id)}`;
  link.setAttribute("aria-label", `View ${product.title}`);

  const visual = document.createElement("div");
  visual.className = "buyer-product__visual";
  const imageUrl = safeSameOriginImage(product.image);
  if (imageUrl) {
    const image = document.createElement("img");
    image.src = imageUrl;
    image.alt = product.title;
    image.loading = "lazy";
    image.addEventListener("error", () => image.remove(), { once: true });
    visual.append(image);
  } else {
    const placeholder = document.createElement("span");
    placeholder.className = "buyer-product__placeholder";
    placeholder.setAttribute("aria-hidden", "true");
    placeholder.textContent = "CampusLoop";
    visual.append(placeholder);
  }

  const badge = document.createElement("span");
  badge.className = "buyer-product__badge";
  badge.textContent = listingLabels[product.listing_type] || "Listing";
  visual.append(badge);

  const details = document.createElement("div");
  details.className = "buyer-product__details";
  const category = document.createElement("p");
  category.className = "buyer-product__category";
  category.textContent = product.category;
  const title = document.createElement("h3");
  title.textContent = product.title;
  const condition = document.createElement("p");
  condition.className = "buyer-product__meta";
  condition.textContent = conditionLabels[product.condition_label] || product.condition_label;
  const seller = document.createElement("p");
  seller.className = "buyer-product__meta";
  seller.textContent = `${product.seller_name || `Seller #${product.seller_id}`} · ${[product.city, product.country_code].filter(Boolean).join(", ")}`;

  const prices = document.createElement("div");
  prices.className = "buyer-product__prices";
  if (product.listing_type !== "rent_only") appendPrice(prices, "Sale", product.sale_price);
  if (product.listing_type !== "sell_only") appendPrice(prices, "Per day", product.daily_rental_price);
  const availability = document.createElement("p");
  availability.className = "buyer-product__availability";
  availability.textContent = "Available";

  const audience = [product.class_level, product.exam_category, product.brand, product.subject]
    .filter((value) => typeof value === "string" && value.trim())
    .join(" · ");
  if (audience) {
    const metadata = document.createElement("p");
    metadata.className = "buyer-product__meta";
    metadata.textContent = audience;
    details.append(category, title, condition, metadata, seller, prices, availability);
  } else {
    details.append(category, title, condition, seller, prices, availability);
  }
  link.append(visual, details);

  const save = document.createElement("button");
  save.className = "buyer-product__save";
  save.type = "button";
  save.setAttribute("aria-label", `Save ${product.title} to wishlist`);
  save.setAttribute("aria-pressed", String(wishlistIds.has(Number(product.id))));
  save.textContent = wishlistIds.has(Number(product.id)) ? "♥" : "♡";
  save.addEventListener("click", () => toggleWishlist(product.id, product.title, save));
  card.append(link, save);
  return card;
}

function renderPagination() {
  pagination.replaceChildren();
  if (totalPages <= 1) return;
  const previous = document.createElement("button");
  previous.type = "button";
  previous.className = "button button--outline";
  previous.textContent = "Previous";
  previous.disabled = activePage <= 1;
  previous.addEventListener("click", () => loadProducts(activePage - 1));
  const pageText = document.createElement("span");
  pageText.textContent = `Page ${activePage} of ${totalPages}`;
  const next = document.createElement("button");
  next.type = "button";
  next.className = "button button--outline";
  next.textContent = "Next";
  next.disabled = activePage >= totalPages;
  next.addEventListener("click", () => loadProducts(activePage + 1));
  pagination.append(previous, pageText, next);
}

async function loadProducts(page = 1) {
  activePage = page;
  setError("");
  resultLabel.textContent = "Loading current listings…";
  productGrid.setAttribute("aria-busy", "true");
  try {
    const filters = collectFilters();
    const response = await fetch(`/api/v1/products?${filters}`, { credentials: "same-origin" });
    const result = await response.json();
    if (!response.ok) throw new Error(result.error || "Products could not be loaded.");
    if (!Array.isArray(result.data) || !result.pagination || !Number.isInteger(result.pagination.pages)
      || !Number.isInteger(result.pagination.total)) {
      throw new Error("The marketplace returned an invalid listing response.");
    }
    productGrid.replaceChildren(...result.data.map(createProductCard));
    totalPages = Math.max(1, result.pagination.pages);
    resultLabel.textContent = `${result.pagination.total} currently available listing${result.pagination.total === 1 ? "" : "s"}`;
    emptyLabel.hidden = result.data.length > 0;
    renderPagination();
    const url = new URL(window.location.href);
    for (const key of ["category", "q"]) {
      const value = filters.get(key);
      if (value) url.searchParams.set(key, value);
      else url.searchParams.delete(key);
    }
    window.history.replaceState({}, "", url);
  } catch (error) {
    productGrid.replaceChildren();
    emptyLabel.hidden = true;
    resultLabel.textContent = "";
    setError(error instanceof Error ? error.message : "Products could not be loaded.");
  } finally {
    productGrid.removeAttribute("aria-busy");
  }
}

async function loadWishlist() {
  const response = await fetch("/api/v1/wishlist", { credentials: "same-origin" });
  if (!response.ok) throw new Error("Saved listings could not be loaded.");
  const result = await response.json();
  if (!Array.isArray(result.data)) throw new Error("The wishlist returned an invalid response.");
  for (const product of result.data) wishlistIds.add(Number(product.id));
}

async function getCsrfToken() {
  const response = await fetch("/api/v1/csrf", { credentials: "same-origin" });
  if (!response.ok) throw new Error("Your session has expired. Sign in again.");
  const result = await response.json();
  return result.csrf_token;
}

async function toggleWishlist(productId, title, button) {
  const isSaved = button.getAttribute("aria-pressed") === "true";
  wishlistStatus.textContent = "";
  button.disabled = true;
  try {
    const csrfToken = await getCsrfToken();
    const response = await fetch(`/api/v1/wishlist/${encodeURIComponent(productId)}`, {
      method: isSaved ? "DELETE" : "POST",
      credentials: "same-origin",
      headers: { "X-CSRF-Token": csrfToken }
    });
    const result = await response.json();
    if (!response.ok) throw new Error(result.error || "Wishlist could not be updated.");
    if (isSaved) wishlistIds.delete(Number(productId));
    else wishlistIds.add(Number(productId));
    button.setAttribute("aria-pressed", String(!isSaved));
    button.textContent = isSaved ? "♡" : "♥";
    button.setAttribute("aria-label", `${isSaved ? "Save" : "Remove"} ${title} ${isSaved ? "to" : "from"} wishlist`);
    wishlistStatus.textContent = result.message || (isSaved ? "Removed from wishlist." : "Saved to wishlist.");
  } catch (error) {
    wishlistStatus.textContent = error instanceof Error ? error.message : "Wishlist could not be updated.";
  } finally {
    button.disabled = false;
  }
}

function applyQueryFilters() {
  const params = new URLSearchParams(window.location.search);
  for (const key of ["category", "mode", "condition", "education_type", "class_level", "exam_category", "brand", "locality", "min_price", "max_price", "country"]) {
    const element = filterForm.elements.namedItem(key);
    if (element && params.has(key)) element.value = params.get(key);
  }
  if (params.has("q")) searchForm.elements.namedItem("q").value = params.get("q");
  if (params.has("city")) document.querySelector("#buyer-city").value = params.get("city");
}

filterForm.addEventListener("submit", (event) => {
  event.preventDefault();
  loadProducts(1);
});
filterForm.addEventListener("reset", () => {
  window.setTimeout(() => {
    searchForm.reset();
    document.querySelector("#buyer-city").value = "";
    loadProducts(1);
  });
});
searchForm.addEventListener("submit", (event) => {
  event.preventDefault();
  loadProducts(1);
});
document.querySelector("#buyer-city").addEventListener("change", () => loadProducts(1));
document.querySelectorAll("[data-category-link]").forEach((link) => {
  link.addEventListener("click", (event) => {
    event.preventDefault();
    document.querySelector("#buyer-category").value = link.dataset.categoryLink;
    loadProducts(1);
    document.querySelector("#listings").scrollIntoView({ behavior: "smooth" });
  });
});

applyQueryFilters();
loadWishlist()
  .catch((error) => {
    wishlistStatus.textContent = error instanceof Error ? error.message : "Wishlist could not be loaded.";
  })
  .finally(() => loadProducts(1));
