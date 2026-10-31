const countryConfig = {
  IN: { name: "India", currency: "INR", inrPerUnit: 1, cities: ["Bengaluru", "Chennai", "Delhi", "Hyderabad", "Kolkata", "Mumbai", "Pune"] },
  US: { name: "United States", currency: "USD", inrPerUnit: 86, cities: ["Austin", "Boston", "Chicago", "New York", "San Francisco"] },
  GB: { name: "United Kingdom", currency: "GBP", inrPerUnit: 113, cities: ["Birmingham", "Bristol", "London", "Manchester", "Oxford"] },
  AE: { name: "United Arab Emirates", currency: "AED", inrPerUnit: 23.4, cities: ["Abu Dhabi", "Dubai", "Sharjah"] },
  SG: { name: "Singapore", currency: "SGD", inrPerUnit: 65, cities: ["Singapore"] }
};

const products = [...document.querySelectorAll(".product-card")];
const locationDialog = document.querySelector("#location-dialog");
const countrySelect = document.querySelector("#country-select");
const cityInput = document.querySelector("#city-input");
const citySuggestions = document.querySelector("#city-suggestions");
const searchInput = document.querySelector("#search-input");
const categorySelect = document.querySelector("#category-select");
const sortSelect = document.querySelector("#sort-select");
const productGrid = document.querySelector("#product-grid");
const emptyState = document.querySelector("#empty-state");
const resultsMessage = document.querySelector("#results-message");
const toastRegion = document.querySelector("#toast-region");
const menuToggle = document.querySelector(".menu-toggle");
const primaryNav = document.querySelector("#primary-nav");
const profileButton = document.querySelector("#profile-button");
const profileMenu = document.querySelector("#profile-menu");
const notificationButton = document.querySelector("#notification-button");
const notificationMenu = document.querySelector("#notification-menu");
const buyerEntry = document.querySelector("[data-buyer-entry]");
const sellerEntry = document.querySelector("[data-seller-entry]");
let activeFilter = "All";
let wishlistCount = 0;
let detectedCountry = "IN";
let currentLocation = { country: "IN", city: "Pune" };
let currentAccount = null;
let sellerActivationPending = false;

function setProfileMenuOpen(isOpen) {
  profileButton.setAttribute("aria-expanded", String(isOpen));
  profileMenu.hidden = !isOpen;
}

profileButton.addEventListener("click", () => {
  setNotificationMenuOpen(false);
  setProfileMenuOpen(profileButton.getAttribute("aria-expanded") !== "true");
});

function setNotificationMenuOpen(isOpen) {
  notificationButton.setAttribute("aria-expanded", String(isOpen));
  notificationMenu.hidden = !isOpen;
}

notificationButton.addEventListener("click", () => {
  setProfileMenuOpen(false);
  setNotificationMenuOpen(notificationButton.getAttribute("aria-expanded") !== "true");
});

document.addEventListener("click", (event) => {
  if (!event.target.closest(".profile-control")) setProfileMenuOpen(false);
  if (!event.target.closest(".notification-control")) setNotificationMenuOpen(false);
});

document.addEventListener("keydown", (event) => {
  if (event.key === "Escape") {
    if (!profileMenu.hidden) {
      setProfileMenuOpen(false);
      profileButton.focus();
    } else if (!notificationMenu.hidden) {
      setNotificationMenuOpen(false);
      notificationButton.focus();
    }
  }
});

function getBrowserCountry() {
  const region = (navigator.languages || [navigator.language])
    .map((language) => language?.match(/[-_]([a-z]{2})(?:-|$)/i)?.[1]?.toUpperCase())
    .find((country) => country && countryConfig[country]);
  return region || "IN";
}

function setLocation(countryCode, city = "") {
  const config = countryConfig[countryCode] || countryConfig.IN;
  const cleanCity = city.trim();
  currentLocation = { country: countryCode, city: cleanCity };
  const displayLocation = [cleanCity, config.name].filter(Boolean).join(", ");
  document.querySelector("#header-location").textContent = displayLocation;
  document.querySelector("#search-location").textContent = displayLocation;
  document.querySelector("#region-caption").textContent = displayLocation;
  document.querySelectorAll(".product-location").forEach((element) => {
    element.textContent = cleanCity || config.name;
  });
  document.querySelectorAll(".item-price").forEach((element) => {
    const amount = Number(element.dataset.amount);
    const formatted = new Intl.NumberFormat(navigator.language || "en-IN", {
      style: "currency",
      currency: config.currency,
      maximumFractionDigits: countryCode === "IN" ? 0 : 2
    }).format(amount / config.inrPerUnit);
    element.textContent = formatted;
  });
  document.documentElement.lang = countryCode === "IN" ? "en-IN" : "en";
}

function showToast(message) {
  const toast = document.createElement("div");
  toast.className = "toast";
  toast.textContent = message;
  toastRegion.append(toast);
  window.setTimeout(() => toast.remove(), 3400);
}

function updateModeEntryLabels() {
  if (!currentAccount) return;
  const roles = Array.isArray(currentAccount.roles) ? currentAccount.roles : [];
  buyerEntry.href = "/buyer/dashboard";
  buyerEntry.querySelector("[data-buyer-label]").textContent = "Browse Marketplace";
  sellerEntry.querySelector("[data-seller-label]").textContent = roles.includes("seller")
    ? "Seller Mode Active"
    : "Activate Seller Mode";
  if (roles.includes("seller")) sellerEntry.href = "/seller/dashboard";
}

async function loadCurrentAccount() {
  if (window.location.protocol === "file:") return;
  try {
    const response = await fetch("/api/v1/auth/me", { credentials: "same-origin" });
    if (response.status === 401) return;
    const result = await response.json();
    if (!response.ok) throw new Error(result.error || "Account mode could not be checked.");
    currentAccount = result.user;
    updateModeEntryLabels();

    const welcome = new URLSearchParams(window.location.search).get("welcome");
    if (welcome === "buyer" || welcome === "seller") {
      showToast(welcome === "seller"
        ? "Seller mode is ready. Your dashboard and listing tools are available."
        : "You're signed in. Browse current listings from your buyer dashboard.");
      window.history.replaceState({}, "", `${window.location.pathname}${window.location.hash}`);
    }
  } catch (error) {
    console.error("Could not load the current CampusLoop account.", error);
    showToast("Account mode could not be checked. Please sign in again before activating seller mode.");
  }
}

async function activateSellerMode() {
  if (sellerActivationPending) return;
  sellerActivationPending = true;
  sellerEntry.setAttribute("aria-disabled", "true");
  try {
    const tokenResponse = await fetch("/api/v1/csrf", { credentials: "same-origin" });
    if (!tokenResponse.ok) throw new Error("Could not start a secure session. Refresh and try again.");
    const tokenData = await tokenResponse.json();
    const response = await fetch("/api/v1/seller/activate", {
      method: "POST",
      credentials: "same-origin",
      headers: { "X-CSRF-Token": tokenData.csrf_token }
    });
    const result = await response.json();
    if (!response.ok) throw new Error(result.error || "Seller mode could not be enabled.");
    currentAccount = result.user;
    updateModeEntryLabels();
    window.location.assign("/seller/dashboard");
  } catch (error) {
    showToast(error instanceof Error ? error.message : "Seller mode could not be enabled. Please try again.");
  } finally {
    sellerActivationPending = false;
    sellerEntry.removeAttribute("aria-disabled");
  }
}

buyerEntry.addEventListener("click", () => {
  if (currentAccount) {
    window.location.assign("/buyer/dashboard");
  }
});

sellerEntry.addEventListener("click", (event) => {
  if (!currentAccount) return;
  event.preventDefault();
  const roles = Array.isArray(currentAccount.roles) ? currentAccount.roles : [];
  if (roles.includes("seller")) {
    window.location.assign("/seller/dashboard");
    return;
  }
  activateSellerMode();
});

function populateCitySuggestions(countryCode) {
  citySuggestions.replaceChildren(...(countryConfig[countryCode]?.cities || []).map((city) => {
    const option = document.createElement("option");
    option.value = city;
    return option;
  }));
}

function openLocationDialog() {
  countrySelect.value = currentLocation.country;
  cityInput.value = currentLocation.city;
  populateCitySuggestions(currentLocation.country);
  if (typeof locationDialog.showModal === "function") {
    locationDialog.showModal();
  } else {
    showToast("Location settings are not supported by this browser.");
  }
}

function updateProducts() {
  const query = searchInput.value.trim().toLocaleLowerCase();
  const sort = sortSelect.value;
  const visible = products.filter((product) => {
    const matchesCategory = activeFilter === "All"
      || (activeFilter === "Rentals" ? product.dataset.mode === "rent" : product.dataset.category === activeFilter);
    const matchesSearch = !query || product.dataset.search.includes(query)
      || product.querySelector("h3").textContent.toLocaleLowerCase().includes(query);
    product.hidden = !matchesCategory || !matchesSearch;
    return !product.hidden;
  });

  visible.sort((a, b) => {
    if (sort === "price-low") return Number(a.dataset.price) - Number(b.dataset.price);
    if (sort === "price-high") return Number(b.dataset.price) - Number(a.dataset.price);
    if (sort === "newest") return Number(a.dataset.age) - Number(b.dataset.age);
    return products.indexOf(a) - products.indexOf(b);
  }).forEach((product) => productGrid.append(product));

  emptyState.hidden = visible.length > 0;
  resultsMessage.textContent = visible.length ? `${visible.length} demo ${visible.length === 1 ? "find" : "finds"} · availability is illustrative` : "";
}

function updateActiveFilter(filter) {
  activeFilter = filter;
  if ([...categorySelect.options].some((option) => option.value === filter)) {
    categorySelect.value = filter;
  }
  document.querySelectorAll(".filter-pill").forEach((button) => {
    const selected = button.dataset.filter === filter;
    button.classList.toggle("filter-pill--active", selected);
    button.setAttribute("aria-pressed", String(selected));
  });
  updateProducts();
}

document.querySelectorAll(".location-control, .search-bar__location, [data-open-location]").forEach((button) => {
  button.addEventListener("click", openLocationDialog);
});

countrySelect.addEventListener("change", () => {
  cityInput.value = "";
  populateCitySuggestions(countrySelect.value);
});

document.querySelector("#apply-location").addEventListener("click", () => {
  const countryCode = countrySelect.value;
  const config = countryConfig[countryCode];
  const city = cityInput.value.trim();
  if (city && !config.cities.some((knownCity) => knownCity.toLocaleLowerCase() === city.toLocaleLowerCase())) {
    showToast(`Showing ${config.name} finds for “${city}”. Listings depend on availability.`);
  }
  setLocation(countryCode, city);
  locationDialog.close();
});

document.querySelector("#use-detected").addEventListener("click", () => {
  const config = countryConfig[detectedCountry];
  setLocation(detectedCountry, config.cities[0] || "");
  locationDialog.close();
});

document.querySelectorAll(".category-card[data-filter]").forEach((link) => {
  link.addEventListener("click", () => {
    updateActiveFilter(link.dataset.filter);
  });
});

document.querySelectorAll(".filter-pill").forEach((button) => {
  button.addEventListener("click", () => updateActiveFilter(button.dataset.filter));
});

document.querySelectorAll("[data-filter-link]").forEach((link) => {
  link.addEventListener("click", (event) => {
    event.preventDefault();
    updateActiveFilter(link.dataset.filterLink);
    document.querySelector("#featured").scrollIntoView({ behavior: "smooth" });
  });
});

document.querySelector("#search-form").addEventListener("submit", (event) => {
  event.preventDefault();
  updateActiveFilter(categorySelect.value);
  updateProducts();
  document.querySelector("#featured").scrollIntoView({ behavior: "smooth" });
});
searchInput.addEventListener("input", updateProducts);
sortSelect.addEventListener("change", updateProducts);
categorySelect.addEventListener("change", () => updateActiveFilter(categorySelect.value));

document.querySelectorAll(".save-button").forEach((button) => {
  button.addEventListener("click", () => {
    const isSaved = button.getAttribute("aria-pressed") !== "true";
    button.setAttribute("aria-pressed", String(isSaved));
    button.textContent = isSaved ? "♥" : "♡";
    wishlistCount += isSaved ? 1 : -1;
    document.querySelector("#wishlist-count").textContent = String(wishlistCount);
    showToast(isSaved
      ? "Saved in this demo session only; it is not synced to an account."
      : "Removed from this demo session's wishlist.");
  });
});

document.querySelector("#clear-filters").addEventListener("click", () => {
  searchInput.value = "";
  updateActiveFilter("All");
});

document.querySelectorAll("[data-toast]").forEach((button) => {
  button.addEventListener("click", (event) => {
    event.preventDefault();
    showToast(button.dataset.toast);
  });
});

menuToggle.addEventListener("click", () => {
  const isExpanded = menuToggle.getAttribute("aria-expanded") === "true";
  menuToggle.setAttribute("aria-expanded", String(!isExpanded));
  menuToggle.setAttribute("aria-label", isExpanded ? "Open navigation" : "Close navigation");
  primaryNav.classList.toggle("is-open", !isExpanded);
});

primaryNav.querySelectorAll("a").forEach((link) => {
  link.addEventListener("click", () => {
    menuToggle.setAttribute("aria-expanded", "false");
    menuToggle.setAttribute("aria-label", "Open navigation");
    primaryNav.classList.remove("is-open");
  });
});

document.querySelectorAll("[data-scroll-to]").forEach((button) => {
  button.addEventListener("click", () => {
    document.querySelector(`#${button.dataset.scrollTo}`).scrollIntoView({ behavior: "smooth" });
    showToast(wishlistCount ? `${wishlistCount} item${wishlistCount === 1 ? "" : "s"} saved in this demo session.` : "Save a find with the heart button to start your wishlist.");
  });
});

detectedCountry = getBrowserCountry();
const detectedConfig = countryConfig[detectedCountry];
document.querySelector("#detect-label").textContent = `${detectedConfig.name} is your starting point`;
document.querySelector("#detect-description").textContent = detectedCountry === "IN"
  ? "Based on your browser region, or our India-first default."
  : "Based on your browser's regional setting. Choose another country anytime.";
setLocation(detectedCountry, detectedConfig.cities[0] || "");
populateCitySuggestions(detectedCountry);
const categoryFilters = {
  "electronics-gadgets": "Tech & gadgets",
  "home-accessories-appliances": "Room & living",
  "college-school": "Books & study",
  "other-products": "Other Products & Rentals"
};
const requestedCategory = new URLSearchParams(window.location.search).get("category");
updateActiveFilter(categoryFilters[requestedCategory] || "All");
loadCurrentAccount();
