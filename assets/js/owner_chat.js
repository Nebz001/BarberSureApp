(function () {
    const wrap = document.getElementById("ownerChatPanel");
    if (!wrap) return;
    const listEl = wrap.querySelector(".oc-list");
    const viewEl = wrap.querySelector(".oc-view");
    const msgWrap = viewEl.querySelector(".oc-messages");
    const form = viewEl.querySelector("form");
    const ta = form.querySelector("textarea");
    let activeChannel = null;
    let lastTs = 0;
    let pollTimer;

    function esc(s) {
        return s.replace(
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

    function loadIndex() {
        const shopId = wrap.getAttribute("data-shop-id");
        const url = shopId
            ? `../api/owner_chat_index.php?shop=${encodeURIComponent(shopId)}`
            : "../api/owner_chat_index.php";
        fetch(url)
            .then((r) => r.json())
            .then((d) => {
                if (!d.ok) return;
                renderIndex(d.conversations || []);
            })
            .catch(() => {});
    }

    function renderIndex(items) {
        listEl.innerHTML = "";
        if (!items.length) {
            listEl.innerHTML =
                '<div class="oc-empty">No conversations yet.</div>';
            return;
        }
        items.forEach((c) => {
            const div = document.createElement("div");
            div.className =
                "oc-item" + (c.channel === activeChannel ? " active" : "");
            const when = c.last_ts
                ? new Date(c.last_ts * 1000).toLocaleTimeString([], {
                      hour: "2-digit",
                      minute: "2-digit",
                  })
                : "";
            div.innerHTML = `<div class="oc-top"><span class="oc-type">${esc(
                c.type || "?"
            )}</span><span class="oc-time">${esc(
                when
            )}</span></div><div class="oc-msg">${esc(c.last_msg || "")}</div>`;
            div.addEventListener("click", () => {
                openChannel(c.channel);
            });
            listEl.appendChild(div);
        });
    }

    function openChannel(ch) {
        if (!ch) return;
        activeChannel = ch;
        lastTs = 0;
        msgWrap.innerHTML = '<div class="oc-hint">Loading messages…</div>';
        pollMessages();
    }

    function addMessage(m) {
        if (msgWrap.querySelector(".oc-hint")) msgWrap.innerHTML = "";
        const line = document.createElement("div");
        line.className = "oc-line oc-" + esc(m.role || "customer");
        const time = new Date(m.ts * 1000).toLocaleTimeString([], {
            hour: "2-digit",
            minute: "2-digit",
        });
        line.innerHTML = `<div class="oc-meta">${esc(
            m.role === "owner" ? "You" : m.name || "Customer"
        )} • ${esc(time)}</div><div class="oc-bubble">${esc(m.msg)}</div>`;
        msgWrap.appendChild(line);
        msgWrap.scrollTop = msgWrap.scrollHeight;
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
            .catch(() => {});
        pollTimer = setTimeout(pollMessages, 2500);
    }

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
                    loadIndex();
                }
            })
            .catch(() => {})
            .finally(() => {
                ta.disabled = false;
                ta.value = "";
                ta.focus();
            });
    });

    // Initial load
    loadIndex();
    setInterval(loadIndex, 7000);
})();
