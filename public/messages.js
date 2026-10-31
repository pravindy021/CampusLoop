async function messageCsrfToken() {
  const response = await fetch("/api/v1/csrf", { credentials: "same-origin" });
  if (!response.ok) throw new Error("Your session expired. Sign in again.");
  const result = await response.json();
  if (typeof result.csrf_token !== "string") throw new Error("Could not verify the request.");
  return result.csrf_token;
}

async function messageApi(url, options = {}) {
  const response = await fetch(url, { credentials: "same-origin", ...options });
  const result = await response.json();
  if (!response.ok) throw new Error(result.error || "The messaging request could not be completed.");
  return result;
}

const productMessageForm = document.querySelector("[data-message-seller]");
if (productMessageForm) {
  productMessageForm.addEventListener("submit", async (event) => {
    event.preventDefault();
    const button = productMessageForm.querySelector('button[type="submit"]');
    const status = productMessageForm.querySelector("[data-product-message-status]");
    button.disabled = true;
    status.textContent = "";
    try {
      const token = await messageCsrfToken();
      const payload = Object.fromEntries(new FormData(productMessageForm).entries());
      const result = await messageApi(productMessageForm.action, {
        method: "POST",
        headers: { "Content-Type": "application/json", "X-CSRF-Token": token },
        body: JSON.stringify(payload)
      });

      const destination = new URL(result.redirect_url, window.location.origin);
      if (destination.origin !== window.location.origin || destination.pathname !== "/messages") {
        throw new Error("The server returned an invalid conversation destination.");
      }
      window.location.assign(destination.href);
    } catch (error) {
      status.textContent = error instanceof Error ? error.message : "The message could not be sent.";
      status.setAttribute("role", "alert");
      button.disabled = false;
    }
  });
}

const conversationList = document.querySelector("#conversation-list");
if (conversationList) {
  const threadTitle = document.querySelector("#message-thread-title");
  const productTitle = document.querySelector("#message-product-category");
  const otherUserLabel = document.querySelector("#message-other-user");
  const threadActions = document.querySelector("#message-thread-actions");
  const blockButton = document.querySelector("#message-block-button");
  const reportForm = document.querySelector("#message-report-form");
  const messageFeed = document.querySelector("#message-feed");
  const composer = document.querySelector("#message-composer");
  const messageInput = document.querySelector("#message-input");
  const statusLabel = document.querySelector("#message-status");
  const errorLabel = document.querySelector("#messages-error");
  const olderButton = document.querySelector("#message-load-older");
  let activeConversationId = null;
  const messageDrafts = new Map();
  let lastMessageId = 0;
  let olderMessagesAvailable = false;
  let pollInProgress = false;

  function showMessageError(message) {
    errorLabel.textContent = message;
    errorLabel.hidden = !message;
  }

  function setStatus(message) {
    statusLabel.textContent = message;
  }

  function createMessage(message, viewerId) {
    const article = document.createElement("article");
    article.className = Number(message.sender_id) === viewerId
      ? "message-bubble message-bubble--mine"
      : "message-bubble";
    article.dataset.messageId = String(message.id);

    const body = document.createElement("p");
    body.className = "message-bubble__body";
    body.textContent = message.body;

    const meta = document.createElement("div");
    meta.className = "message-bubble__meta";
    const time = document.createElement("time");
    time.dateTime = message.created_at.replace(" ", "T") + "Z";
    time.textContent = message.created_at;
    meta.append(time);
    if (Number(message.sender_id) === viewerId) {
      const readStatus = document.createElement("span");
      readStatus.textContent = message.read_at ? "Seen" : "Sent";
      readStatus.dataset.readStatus = String(message.id);
      meta.append(readStatus);
    }
    article.append(body, meta);
    return article;
  }

  function renderConversationList(conversations) {
    const focusedConversationId = conversationList.contains(document.activeElement)
      ? document.activeElement.dataset.conversationId
      : null;
    conversationList.replaceChildren();
    if (conversations.length === 0) {
      const empty = document.createElement("p");
      empty.className = "buyer-empty";
      empty.textContent = "No conversations yet. Message a seller from any available listing.";
      conversationList.append(empty);
      return;
    }
    for (const conversation of conversations) {
      const button = document.createElement("button");
      button.type = "button";
      button.className = "conversation-list__item";
      button.dataset.conversationId = String(conversation.id);
      if (Number(conversation.id) === activeConversationId) button.setAttribute("aria-current", "true");
      button.addEventListener("click", () => selectConversation(Number(conversation.id)));
      const heading = document.createElement("strong");
      heading.textContent = conversation.other_name;
      const product = document.createElement("span");
      product.className = "conversation-list__product";
      product.textContent = conversation.product_title;
      const preview = document.createElement("span");
      preview.className = "conversation-list__preview";
      preview.textContent = conversation.last_message || "Start the conversation";
      button.append(heading, product, preview);
      if (Number(conversation.unread_count) > 0) {
        const unread = document.createElement("span");
        unread.className = "conversation-list__unread";
        unread.textContent = String(conversation.unread_count);
        unread.setAttribute("aria-label", `${conversation.unread_count} unread messages`);
        button.append(unread);
      }
      conversationList.append(button);
    }
    if (focusedConversationId) {
      conversationList.querySelector(`[data-conversation-id="${CSS.escape(focusedConversationId)}"]`)?.focus();
    }
  }

  async function loadConversations() {
    const result = await messageApi("/api/v1/conversations");
    if (!Array.isArray(result.data)) throw new Error("The conversation list response was invalid.");
    renderConversationList(result.data);
    return result.data;
  }

  function setBlockedState(conversation) {
    const blockedByMe = Boolean(Number(conversation.blocked_by_me));
    const blockedByOther = Boolean(Number(conversation.blocked_by_other));
    threadActions.hidden = false;
    blockButton.textContent = blockedByMe ? "Unblock" : "Block";
    blockButton.dataset.action = blockedByMe ? "unblock" : "block";
    composer.hidden = blockedByMe || blockedByOther;
    messageInput.disabled = blockedByMe || blockedByOther;
    if (blockedByMe) setStatus("You blocked this conversation. Unblock it to send messages.");
    else if (blockedByOther) setStatus("Messaging is unavailable because the other participant blocked this conversation.");
    else setStatus("");
  }

  async function fetchMessages(mode = "after") {
    if (activeConversationId === null) return;
    const conversationId = activeConversationId;
    const query = mode === "initial"
      ? ""
      : mode === "older"
        ? `?before=${encodeURIComponent(messageFeed.firstElementChild?.dataset.messageId || "")}`
        : `?after=${encodeURIComponent(lastMessageId)}`;
    const result = await messageApi(`/api/v1/conversations/${conversationId}/messages${query}`);
    if (activeConversationId !== conversationId) return;
    if (!Array.isArray(result.messages) || !result.conversation) {
      throw new Error("The conversation response was invalid.");
    }
    threadTitle.textContent = result.conversation.other_name;
    productTitle.textContent = result.conversation.product_title;
    otherUserLabel.textContent = "Discuss this listing here. Contact details are not shown to other marketplace users.";
    setBlockedState(result.conversation);

    if (mode === "initial") messageFeed.replaceChildren();
    const rendered = result.messages.map((message) => createMessage(message, Number(result.viewer_id)));
    if (mode === "older") {
      const previousHeight = messageFeed.scrollHeight;
      messageFeed.prepend(...rendered);
      messageFeed.scrollTop = messageFeed.scrollHeight - previousHeight;
    } else if (rendered.length > 0) {
      if (messageFeed.firstElementChild && !messageFeed.firstElementChild.dataset.messageId) {
        messageFeed.replaceChildren();
      }
      messageFeed.append(...rendered);
      if (mode === "initial") messageFeed.scrollTop = messageFeed.scrollHeight;
      else if (messageFeed.scrollHeight - messageFeed.scrollTop < 1200) {
        messageFeed.scrollTop = messageFeed.scrollHeight;
      }
    } else if (mode === "initial") {
      const empty = document.createElement("p");
      empty.className = "buyer-empty";
      empty.textContent = "No messages yet. Send a note to start the conversation.";
      messageFeed.append(empty);
    }
    const ids = result.messages.map((message) => Number(message.id));
    if (ids.length > 0) {
      lastMessageId = Math.max(lastMessageId, ...ids);
    }
    for (const messageId of result.read_message_ids || []) {
      const readStatus = messageFeed.querySelector(`[data-read-status="${CSS.escape(String(messageId))}"]`);
      if (readStatus) readStatus.textContent = "Seen";
    }
    if (mode !== "after") {
      olderMessagesAvailable = Boolean(result.has_more);
      olderButton.hidden = !olderMessagesAvailable;
    }
  }

  async function selectConversation(conversationId) {
    if (activeConversationId !== null) {
      messageDrafts.set(activeConversationId, messageInput.value);
    }
    activeConversationId = conversationId;
    messageInput.value = messageDrafts.get(conversationId) || "";
    lastMessageId = 0;
    olderMessagesAvailable = false;
    messageFeed.replaceChildren();
    composer.hidden = true;
    threadActions.hidden = true;
    const url = new URL(window.location.href);
    url.searchParams.set("conversation_id", String(conversationId));
    window.history.replaceState({}, "", url);
    try {
      await fetchMessages("initial");
      if (activeConversationId !== conversationId) return;
      await loadConversations();
      if (activeConversationId !== conversationId) return;
      messageInput.focus();
    } catch (error) {
      showMessageError(error instanceof Error ? error.message : "Conversation could not be loaded.");
    }
  }

  composer.addEventListener("submit", async (event) => {
    event.preventDefault();
    if (activeConversationId === null || !messageInput.value.trim()) return;
    const conversationId = activeConversationId;
    const message = messageInput.value;
    const button = composer.querySelector('button[type="submit"]');
    button.disabled = true;
    try {
      const token = await messageCsrfToken();
      await messageApi(`/api/v1/conversations/${conversationId}/messages`, {
        method: "POST",
        headers: { "Content-Type": "application/json", "X-CSRF-Token": token },
        body: JSON.stringify({ message })
      });
      if (messageDrafts.get(conversationId) === message) messageDrafts.delete(conversationId);
      if (activeConversationId === conversationId) {
        if (messageInput.value === message) messageInput.value = "";
        await fetchMessages("after");
        setStatus("Message sent.");
      }
      await loadConversations();
    } catch (error) {
      if (activeConversationId === conversationId) {
        setStatus(error instanceof Error ? error.message : "Message could not be sent.");
      }
    } finally {
      button.disabled = false;
    }
  });

  messageInput.addEventListener("input", () => {
    if (activeConversationId !== null) messageDrafts.set(activeConversationId, messageInput.value);
  });

  blockButton.addEventListener("click", async () => {
    if (activeConversationId === null) return;
    const conversationId = activeConversationId;
    const action = blockButton.dataset.action;
    if (action === "block" && !window.confirm("Block this conversation? Neither participant will be able to send further messages.")) return;
    blockButton.disabled = true;
    try {
      const token = await messageCsrfToken();
      await messageApi(`/api/v1/conversations/${conversationId}/block`, {
        method: "POST",
        headers: { "Content-Type": "application/json", "X-CSRF-Token": token },
        body: JSON.stringify({ action })
      });
      if (activeConversationId === conversationId) await fetchMessages("after");
      await loadConversations();
    } catch (error) {
      if (activeConversationId === conversationId) {
        setStatus(error instanceof Error ? error.message : "The block action could not be completed.");
      }
    } finally {
      blockButton.disabled = false;
    }
  });

  reportForm.addEventListener("submit", async (event) => {
    event.preventDefault();
    if (activeConversationId === null) return;
    const conversationId = activeConversationId;
    const payload = Object.fromEntries(new FormData(reportForm).entries());
    try {
      const token = await messageCsrfToken();
      const result = await messageApi(`/api/v1/conversations/${conversationId}/report`, {
        method: "POST",
        headers: { "Content-Type": "application/json", "X-CSRF-Token": token },
        body: JSON.stringify(payload)
      });
      if (activeConversationId === conversationId) {
        reportForm.reset();
        reportForm.closest("details").open = false;
        setStatus(result.message || "Report submitted.");
      }
    } catch (error) {
      if (activeConversationId === conversationId) {
        setStatus(error instanceof Error ? error.message : "The report could not be submitted.");
      }
    }
  });

  olderButton.addEventListener("click", async () => {
    if (!olderMessagesAvailable || !messageFeed.firstElementChild?.dataset.messageId) return;
    olderButton.disabled = true;
    try {
      await fetchMessages("older");
    } catch (error) {
      showMessageError(error instanceof Error ? error.message : "Earlier messages could not be loaded.");
    } finally {
      olderButton.disabled = false;
    }
  });

  async function refresh() {
    if (pollInProgress || document.visibilityState !== "visible") return;
    pollInProgress = true;
    try {
      showMessageError("");
      const conversations = await loadConversations();
      if (activeConversationId === null) {
        const requestedId = new URLSearchParams(window.location.search).get("conversation_id");
        const requested = requestedId && /^\d+$/.test(requestedId)
          ? conversations.find((conversation) => Number(conversation.id) === Number(requestedId))
          : null;
        if (requested) await selectConversation(Number(requested.id));
        else if (conversations.length > 0) await selectConversation(Number(conversations[0].id));
      } else {
        await fetchMessages("after");
      }
    } catch (error) {
      showMessageError(error instanceof Error ? error.message : "Conversations could not be refreshed.");
    } finally {
      pollInProgress = false;
    }
  }

  refresh();
  window.setInterval(refresh, 5000);
}
