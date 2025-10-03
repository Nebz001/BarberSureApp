(function () {
    const btn = document.getElementById("openPreChat");
    if (!btn) return;
    const channel = btn.getAttribute("data-channel");
    const modalEl = document.getElementById("preChatModal");
    const msgBox = modalEl.querySelector(".pre-chat-messages");
    const form = modalEl.querySelector("#preChatForm");
    const ta = form.querySelector("textarea");
    const label = document.getElementById("preChatChannelLabel");
    let lastTs = 0;
    let open = false;
    let timer;

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
    function line(role, name, msg, ts) {
        const time = new Date(ts * 1000).toLocaleTimeString([], {
            hour: "2-digit",
            minute: "2-digit",
        });
        const wrap = document.createElement("div");
        wrap.className = "pc-line";
        wrap.innerHTML = `<div style="font-size:.55rem;opacity:.6;">${esc(
            role === "owner" ? "Owner" : "You"
        )} • ${time}</div><div style="background:${
            role === "owner" ? "#1e2e17" : "#3b2f12"
        };border:1px solid ${
            role === "owner" ? "#395c2c" : "#926b1b"
        };padding:.4rem .55rem;border-radius:8px;font-size:.65rem;">${esc(
            msg
        )}</div>`;
        msgBox.appendChild(wrap);
        msgBox.scrollTop = msgBox.scrollHeight;
    }
    async function poll() {
        if (!open) return;
        try {
            const r = await fetch(
                `../api/chat_fetch.php?channel=${encodeURIComponent(
                    channel
                )}&since=${lastTs}`
            );
            if (r.ok) {
                const d = await r.json();
                if (d.ok) {
                    d.messages.forEach((m) => {
                        line(m.role, m.name, m.msg, m.ts);
                        if (m.ts > lastTs) lastTs = m.ts;
                    });
                }
            }
        } catch (e) {}
        timer = setTimeout(poll, 2500);
    }
    form.addEventListener("submit", async (e) => {
        e.preventDefault();
        const msg = ta.value.trim();
        if (!msg) return;
        ta.disabled = true;
        try {
            const r = await fetch("../api/chat_send.php", {
                method: "POST",
                headers: { "Content-Type": "application/json" },
                body: JSON.stringify({ channel, msg }),
            });
            if (r.ok) {
                const d = await r.json();
                if (d.ok) {
                    line(
                        d.message.role,
                        d.message.name,
                        d.message.msg,
                        d.message.ts
                    );
                    if (d.message.ts > lastTs) lastTs = d.message.ts;
                }
            }
        } catch (err) {}
        ta.value = "";
        ta.disabled = false;
        ta.focus();
    });
    btn.addEventListener("click", () => {
        const bsModal = new bootstrap.Modal(modalEl);
        if (label) label.textContent = channel;
        bsModal.show();
        open = true;
        poll();
    });
    modalEl.addEventListener("hidden.bs.modal", () => {
        open = false;
        clearTimeout(timer);
    });
})();
