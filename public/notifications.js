async function notificationApi(url, options = {}) {
  const response = await fetch(url, { credentials: "same-origin", ...options });
  const result = await response.json();
  if (!response.ok) throw new Error(result.error || "Notifications could not be loaded.");
  return result;
}

async function notificationCsrfToken() {
  const result = await notificationApi("/api/v1/csrf");
  if (typeof result.csrf_token !== "string") throw new Error("Could not verify the request.");
  return result.csrf_token;
}

function updateCountBadge(element, count) {
  const value = Number(count);
  element.textContent = value > 99 ? "99+" : String(value);
  element.hidden = !Number.isFinite(value) || value <= 0;
  if (value > 0) element.setAttribute("aria-label", `${value} unread`);
  else element.removeAttribute("aria-label");
}

async function refreshNavigationCounts() {
  const messageBadge = document.querySelector("[data-message-unread-count]");
  const notificationBadge = document.querySelector("[data-notification-unread-count]");
  if (!messageBadge && !notificationBadge) return;
  try {
    const requests = [];
    if (messageBadge) requests.push(notificationApi("/api/v1/conversations/unread-count"));
    if (notificationBadge) requests.push(notificationApi("/api/v1/notifications/unread-count"));
    const results = await Promise.all(requests);
    let resultIndex = 0;
    if (messageBadge) updateCountBadge(messageBadge, results[resultIndex++].unread_count);
    if (notificationBadge) updateCountBadge(notificationBadge, results[resultIndex].unread_count);
    document.querySelectorAll("[data-count-status]").forEach((target) => {
      target.textContent = "";
    });
  } catch (error) {
    const status = document.querySelector("#notifications-error");
    const message = error instanceof Error ? error.message : "Notification counts could not be loaded.";
    if (status) {
      status.textContent = message;
      status.hidden = false;
    } else {
      document.querySelectorAll("[data-count-status]").forEach((target) => {
        target.textContent = message;
      });
    }
  }
}

const notificationList = document.querySelector("#notification-list");
if (notificationList) {
  const errorLabel = document.querySelector("#notifications-error");
  const markAllButton = document.querySelector("#notifications-read-all");

  function notificationLink(path) {
    if (typeof path !== "string" || !path.startsWith("/") || path.startsWith("//")) return null;
    try {
      const url = new URL(path, window.location.origin);
      return url.origin === window.location.origin ? url.href : null;
    } catch {
      return null;
    }
  }

  function createNotification(notification) {
    const article = document.createElement("article");
    article.dataset.notificationId = String(notification.id);
    article.className = notification.read_at ? "notification-card" : "notification-card notification-card--unread";
    const heading = document.createElement("h2");
    const target = notificationLink(notification.action_url);
    if (target) {
      const link = document.createElement("a");
      link.href = target;
      link.textContent = notification.title;
      heading.append(link);
    } else {
      heading.textContent = notification.title;
    }
    const body = document.createElement("p");
    body.textContent = notification.body;
    const time = document.createElement("time");
    time.dateTime = notification.created_at.replace(" ", "T") + "Z";
    time.textContent = notification.created_at;
    article.append(heading, body, time);
    if (!notification.read_at) {
      const button = document.createElement("button");
      button.type = "button";
      button.className = "button button--outline";
      button.textContent = "Mark as read";
      button.addEventListener("click", () => markRead(notification.id));
      article.append(button);
    }
    return article;
  }

  async function loadNotifications() {
    errorLabel.hidden = true;
    const focusedNotification = notificationList.contains(document.activeElement)
      ? document.activeElement.closest("[data-notification-id]")
      : null;
    const focusedNotificationId = focusedNotification?.dataset.notificationId || null;
    const focusedElement = focusedNotification && document.activeElement.tagName === "A" ? "a" : "button";
    try {
      const result = await notificationApi("/api/v1/notifications");
      if (!Array.isArray(result.data)) throw new Error("The notification response was invalid.");
      notificationList.replaceChildren();
      if (result.data.length === 0) {
        const empty = document.createElement("p");
        empty.className = "buyer-empty";
        empty.textContent = "You are all caught up. New account activity will appear here.";
        notificationList.append(empty);
      } else {
        notificationList.append(...result.data.map(createNotification));
      }
      updateCountBadge(document.querySelector("[data-notification-unread-count]"), result.unread_count);
      errorLabel.hidden = true;
      if (focusedNotificationId) {
        notificationList
          .querySelector(`[data-notification-id="${CSS.escape(focusedNotificationId)}"] ${focusedElement}`)
          ?.focus();
      }
    } catch (error) {
      errorLabel.textContent = error instanceof Error ? error.message : "Notifications could not be loaded.";
      errorLabel.hidden = false;
    }
  }

  async function markRead(id) {
    try {
      const token = await notificationCsrfToken();
      await notificationApi(`/api/v1/notifications/${encodeURIComponent(id)}/read`, {
        method: "POST",
        headers: { "X-CSRF-Token": token }
      });
      await loadNotifications();
      await refreshNavigationCounts();
    } catch (error) {
      errorLabel.textContent = error instanceof Error ? error.message : "Notification could not be updated.";
      errorLabel.hidden = false;
    }
  }

  markAllButton.addEventListener("click", async () => {
    markAllButton.disabled = true;
    try {
      const token = await notificationCsrfToken();
      await notificationApi("/api/v1/notifications/read-all", {
        method: "POST",
        headers: { "X-CSRF-Token": token }
      });
      await loadNotifications();
      await refreshNavigationCounts();
    } catch (error) {
      errorLabel.textContent = error instanceof Error ? error.message : "Notifications could not be updated.";
      errorLabel.hidden = false;
    } finally {
      markAllButton.disabled = false;
    }
  });

  loadNotifications();
  window.setInterval(() => {
    if (document.visibilityState === "visible") loadNotifications();
  }, 15000);
}

refreshNavigationCounts();
window.setInterval(refreshNavigationCounts, 15000);
