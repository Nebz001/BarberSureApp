(function () {
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
    const openButtons = document.querySelectorAll(".booking-chat-open");
    const state = {}; // channel -> {lastTs, polling}

    function render(lineBox, m) {
        const time = new Date(m.ts * 1000).toLocaleTimeString([], {
            hour: "2-digit",
            minute: "2-digit",
        });
        const wrap = document.createElement("div");
        wrap.className = "bk-line";
        wrap.innerHTML = `<div style="font-size:.5rem;opacity:.6;">${esc(
            m.role === "owner" ? "Owner" : "You"
        )} • ${time}</div><div style="background:${
            m.role === "owner" ? "#1e2e17" : "#3b2f12"
        };border:1px solid ${
            m.role === "owner" ? "#395c2c" : "#926b1b"
        };padding:.35rem .5rem;border-radius:6px;">${esc(m.msg)}</div>`;
        lineBox.appendChild(wrap);
        lineBox.scrollTop = lineBox.scrollHeight;
    }

    async function poll(channel, lineBox) {
        if (!state[channel] || !state[channel].polling) return;
        const lastTs = state[channel].lastTs || 0;
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
                        render(lineBox, m);
                        if (m.ts > state[channel].lastTs)
                            state[channel].lastTs = m.ts;
                    });
                }
            }
        } catch (e) {}
        setTimeout(() => poll(channel, lineBox), 2500);
    }

    openButtons.forEach((btn) => {
        btn.addEventListener("click", () => {
            const apptId = btn.getAttribute("data-appt");
            const box = document.getElementById("chat-box-" + apptId);
            if (!box) return;
            const channel = btn.getAttribute("data-channel");
            const lineBox = box.querySelector(".chat-lines");
            const form = box.querySelector("form.chat-send");
            const ta = form.querySelector("textarea");
            const isHidden = box.style.display === "none";
            if (isHidden) {
                box.style.display = "block";
                if (!state[channel]) {
                    state[channel] = { lastTs: 0, polling: true };
                    poll(channel, lineBox);
                } else {
                    state[channel].polling = true;
                    poll(channel, lineBox);
                }
            } else {
                box.style.display = "none";
                if (state[channel]) state[channel].polling = false;
                return;
            }
            if (!form.dataset.bound) {
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
                                render(lineBox, d.message);
                                if (d.message.ts > state[channel].lastTs)
                                    state[channel].lastTs = d.message.ts;
                            }
                        }
                    } catch (err) {}
                    ta.value = "";
                    ta.disabled = false;
                    ta.focus();
                });
                form.dataset.bound = "1";
            }
        });
    });
})();
