(function () {
    const cfg = window.__OWNER_MESSAGES_CFG || {};
    const shopId = cfg.shopId;
    const autoBooking = cfg.autoBooking || null;
    const listInquiries = document.getElementById("listInquiries");
    const listBookings = document.getElementById("listBookings");
    const countInquiries = document.getElementById("countInquiries");
    const countBookings = document.getElementById("countBookings");
    const chatMessages = document.getElementById("chatMessages");
    const chatView = document.getElementById("chatView");
    const chatTitle = document.getElementById("chatTitle");
    const form = document.getElementById("chatSendForm");
    const ta = form ? form.querySelector("textarea") : null;
    let activeChannel = null;
    let lastTs = 0;
    let pollTimer;

    function esc(s) {
        return (s || "").toString().replace(
            /[&<>"']/g,
            (c) =>
                ({
                    "&": "&amp;",
                    "<": "&lt;",
                    ">": "&gt;",
                    '"': "&quot;",
                    "'": "&#39;",
                }[c])
        );
    }

    function fetchIndex() {
        const url = `../api/owner_chat_index.php?shop=${encodeURIComponent(
            shopId
        )}`;
        fetch(url)
            .then((r) => r.json())
            .then((d) => {
                if (!d.ok) return;
                partitionAndRender(d.conversations || []);
                // Priority 1: Auto-open booking chat if booking param present
                if (autoBooking && !activeChannel) {
                    const cand = (d.conversations || []).find((c) =>
                        new RegExp(`^bk_${autoBooking}_`).test(c.channel)
                    );
                    if (cand) {
                        openChannel(cand.channel, cand.type || "booking");
                        return; // skip fallback
                    }
                }
                // Priority 2: Auto-open most recent conversation where last_role was customer
                if (!activeChannel) {
                    const firstCustomer = (d.conversations || []).find(
                        (c) => c.last_role !== "owner"
                    );
                    if (firstCustomer) {
                        openChannel(
                            firstCustomer.channel,
                            firstCustomer.type ||
                                (/^bk_/i.test(firstCustomer.channel)
                                    ? "booking"
                                    : "inquiry")
                        );
                    }
                }
            })
            .catch(() => {});
    }

    function partitionAndRender(items) {
        const inquiries = items.filter(
            (i) => i.channel && /^pre_\d+_/.test(i.channel)
        );
        const bookings = items.filter(
            (i) => i.channel && /^bk_\d+_/.test(i.channel)
        );
        renderList(listInquiries, inquiries, "inquiry");
        renderList(listBookings, bookings, "booking");
        countInquiries.textContent = inquiries.length;
        countBookings.textContent = bookings.length;
    }

    function renderList(container, arr, kind) {
        container.innerHTML = "";
        if (!arr.length) {
            container.innerHTML = `<div class='msg-empty'>No ${
                kind === "inquiry" ? "inquiries" : "booking chats"
            }.</div>`;
            return;
        }
        arr.forEach((c) => {
            const when = c.last_ts
                ? new Date(c.last_ts * 1000).toLocaleTimeString([], {
                      hour: "2-digit",
                      minute: "2-digit",
                  })
                : "";
            const div = document.createElement("div");
            div.className =
                "msg-item" + (c.channel === activeChannel ? " active" : "");
            div.innerHTML = `<div class='mi-top'><span>${esc(
                c.type || kind
            )}</span><span>${esc(when)}</span></div><div class='mi-msg'>${esc(
                c.last_msg || ""
            )}</div>`;
            div.addEventListener("click", () =>
                openChannel(c.channel, c.type || kind)
            );
            container.appendChild(div);
        });
    }

    function openChannel(ch, type) {
        if (!ch) return;
        activeChannel = ch;
        lastTs = 0;
        chatMessages.innerHTML =
            '<div class="chat-hint">Loading messages…</div>';
        chatTitle.textContent = type === "booking" ? "Booking Chat" : "Inquiry";
        form.style.display = "flex";
        pollMessages();
    }

    function addMessage(m) {
        if (chatMessages.querySelector(".chat-hint"))
            chatMessages.innerHTML = "";
        const line = document.createElement("div");
        line.className = "chat-line" + (m.role === "owner" ? " owner" : "");
        const time = new Date(m.ts * 1000).toLocaleTimeString([], {
            hour: "2-digit",
            minute: "2-digit",
        });
        line.innerHTML = `<div class='chat-meta'>${esc(
            m.role === "owner" ? "You" : m.name || "Customer"
        )} • ${esc(time)}</div><div class='chat-bubble'>${esc(m.msg)}</div>`;
        chatMessages.appendChild(line);
        chatMessages.scrollTop = chatMessages.scrollHeight;
    }

    function pollMessages() {
        if (!activeChannel) return;
        fetch(
            `../api/chat_fetch.php?channel=${encodeURIComponent(
                activeChannel
            )}&since=${lastTs}`
        )
            .then((r) => r.json())
            .then((d) => {
                if (d.ok && d.messages) {
                    d.messages.forEach((m) => {
                        addMessage(m);
                        if (m.ts > lastTs) lastTs = m.ts;
                    });
                }
            })
            .catch(() => {})
            .finally(() => {
                pollTimer = setTimeout(pollMessages, 2500);
            });
    }

    if (form) {
        form.addEventListener("submit", (e) => {
            e.preventDefault();
            if (!activeChannel) return;
            const msg = ta.value.trim();
            if (!msg) return;
            ta.disabled = true;
            fetch("../api/chat_send.php", {
                method: "POST",
                headers: { "Content-Type": "application/json" },
                body: JSON.stringify({ channel: activeChannel, msg }),
            })
                .then((r) => r.json())
                .then((d) => {
                    if (d.ok && d.message) {
                        addMessage(d.message);
                        if (d.message.ts > lastTs) lastTs = d.message.ts;
                        fetchIndex();
                    }
                })
                .catch(() => {})
                .finally(() => {
                    ta.disabled = false;
                    ta.value = "";
                    ta.focus();
                });
        });
    }

    fetchIndex();
    setInterval(fetchIndex, 7000);
})();
