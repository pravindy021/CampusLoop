async function getCsrfToken() {
  const response = await fetch("/api/v1/csrf", { credentials: "same-origin" });
  if (!response.ok) throw new Error("Could not start a secure session. Refresh and try again.");
  const data = await response.json();
  return data.csrf_token;
}

document.querySelectorAll('.mode-choice input[name="mode"]').forEach((input) => {
  input.addEventListener("change", () => {
    const label = document.querySelector("[data-mode-label]");
    label.textContent = label.dataset[`${input.value}Label`];
  });
});

document.querySelectorAll("[data-auth-form]").forEach((form) => {
  const message = form.querySelector("[data-form-message]");
  form.addEventListener("submit", async (event) => {
    event.preventDefault();
    message.textContent = "";
    const submit = form.querySelector('button[type="submit"]');
    const idleLabel = submit.textContent;
    submit.disabled = true;
    submit.setAttribute("aria-busy", "true");
    submit.textContent = "Please wait…";
    try {
      const csrfToken = await getCsrfToken();
      const data = Object.fromEntries(new FormData(form).entries());
      const response = await fetch(form.action, {
        method: "POST",
        credentials: "same-origin",
        headers: { "Content-Type": "application/json", "X-CSRF-Token": csrfToken },
        body: JSON.stringify(data)
      });
      const result = await response.json();
      if (!response.ok) throw new Error(result.error || "The request could not be completed.");
      if (!["/buyer/dashboard", "/seller/dashboard"].includes(result.redirect_url)) {
        throw new Error("The server returned an invalid destination.");
      }
      window.location.assign(result.redirect_url);
    } catch (error) {
      message.textContent = error instanceof Error ? error.message : "The request could not be completed.";
      message.setAttribute("role", "alert");
    } finally {
      submit.disabled = false;
      submit.removeAttribute("aria-busy");
      submit.textContent = idleLabel;
    }
  });
});

document.querySelectorAll("form[data-activate-seller]").forEach((form) => {
  const button = form.querySelector('button[type="submit"]');
  form.addEventListener("submit", async (event) => {
    event.preventDefault();
    const message = document.querySelector("[data-dashboard-message]");
    button.disabled = true;
    button.setAttribute("aria-busy", "true");
    try {
      const csrfToken = await getCsrfToken();
      const response = await fetch("/api/v1/seller/activate", {
        method: "POST",
        credentials: "same-origin",
        headers: { "X-CSRF-Token": csrfToken }
      });
      const result = await response.json();
      if (!response.ok) throw new Error(result.error || "Seller mode could not be enabled.");
      window.location.assign("/seller/dashboard");
    } catch (error) {
      message.textContent = error instanceof Error ? error.message : "Seller mode could not be enabled.";
      message.setAttribute("role", "alert");
      button.disabled = false;
      button.removeAttribute("aria-busy");
    }
  });
});

document.querySelectorAll("[data-logout-form]").forEach((form) => {
  const message = form.querySelector("[data-dashboard-message]");
  const submit = form.querySelector('button[type="submit"]');
  form.addEventListener("submit", async (event) => {
    event.preventDefault();
    const idleLabel = submit.textContent;
    submit.disabled = true;
    submit.setAttribute("aria-busy", "true");
    submit.textContent = "Signing out…";
    try {
      const csrfToken = await getCsrfToken();
      const response = await fetch("/api/v1/auth/logout", {
        method: "POST",
        credentials: "same-origin",
        headers: { "X-CSRF-Token": csrfToken }
      });
      const result = await response.json();
      if (!response.ok) throw new Error(result.error || "Sign out could not be completed.");
      window.location.assign("/");
    } catch (error) {
      message.textContent = error instanceof Error ? error.message : "Sign out could not be completed.";
      message.setAttribute("role", "alert");
      submit.disabled = false;
      submit.removeAttribute("aria-busy");
      submit.textContent = idleLabel;
    }
  });
});

document.querySelectorAll("form[data-wishlist-add], form[data-wishlist-remove]").forEach((form) => {
  const button = form.querySelector('button[type="submit"]');
  const message = form.querySelector("[data-dashboard-message]");
  form.addEventListener("submit", async (event) => {
    event.preventDefault();
    const idleLabel = button.textContent;
    button.disabled = true;
    button.setAttribute("aria-busy", "true");
    try {
      const csrfToken = await getCsrfToken();
      const response = await fetch(form.action, {
        method: form.matches("[data-wishlist-remove]") ? "DELETE" : "POST",
        credentials: "same-origin",
        headers: { "X-CSRF-Token": csrfToken }
      });
      const result = await response.json();
      if (!response.ok) throw new Error(result.error || "Wishlist could not be updated.");
      if (form.matches("[data-wishlist-remove]")) {
        window.location.reload();
        return;
      }
      message.textContent = result.message || "Saved to your wishlist.";
      message.setAttribute("role", "status");
      button.textContent = "Saved to wishlist";
      button.removeAttribute("aria-busy");
    } catch (error) {
      message.textContent = error instanceof Error ? error.message : "Wishlist could not be updated.";
      message.setAttribute("role", "alert");
      button.disabled = false;
      button.removeAttribute("aria-busy");
      button.textContent = idleLabel;
    }
  });
});
