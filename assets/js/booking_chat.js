(function () {
    const chatBox = document.getElementById("bookingChat");
    if (!chatBox) return;
    const form = chatBox.querySelector("form");
    const list = chatBox.querySelector(".chat-messages");
    const input = chatBox.querySelector("textarea");
    const channel = chatBox.getAttribute("data-channel");
    let lastTs = 0;
    let polling = true;

    function escapeHtml(s) {
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

    function renderMessage(m) {
        const who = m.role === "owner" ? "Owner" : "You";
        const time = new Date(m.ts * 1000).toLocaleTimeString([], {
            hour: "2-digit",
            minute: "2-digit",
        });
        const div = document.createElement("div");
        div.className = "chat-line chat-" + escapeHtml(m.role);
        div.innerHTML = `<span class="chat-meta">${escapeHtml(
            who
        )} • ${time}</span><div class="chat-text">${escapeHtml(m.msg)}</div>`;
        list.appendChild(div);
        list.scrollTop = list.scrollHeight;
    }

    async function fetchLoop() {
        if (!polling) return;
        try {
            const resp = await fetch(
                `../api/chat_fetch.php?channel=${encodeURIComponent(
                    channel
                )}&since=${lastTs}`
            );
            if (resp.ok) {
                const data = await resp.json();
                if (data.ok && data.messages) {
                    data.messages.forEach((m) => {
                        renderMessage(m);
                        if (m.ts > lastTs) lastTs = m.ts;
                    });
                }
            }
        } catch (e) {
            /* ignore */
        }
        setTimeout(fetchLoop, 2500);
    }

    form.addEventListener("submit", async (e) => {
        e.preventDefault();
        const msg = input.value.trim();
        if (!msg) return;
        input.disabled = true;
        try {
            const resp = await fetch("../api/chat_send.php", {
                method: "POST",
                headers: { "Content-Type": "application/json" },
                body: JSON.stringify({ channel, msg }),
            });
            if (resp.ok) {
                const data = await resp.json();
                if (data.ok && data.message) {
                    renderMessage(data.message);
                    if (data.message.ts > lastTs) lastTs = data.message.ts;
                }
            }
        } catch (err) {
            /* ignore */
        }
        input.value = "";
        input.disabled = false;
        input.focus();
    });

    fetchLoop();
})();
