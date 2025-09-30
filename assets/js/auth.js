// Shared auth page interactions (password toggles, reduced duplication)
(function () {
    const toggles = document.querySelectorAll(".toggle-password[data-target]");
    toggles.forEach((btn) => {
        if (btn.dataset.bound) return; // avoid double binding
        btn.dataset.bound = "1";
        btn.addEventListener("click", () => {
            const id = btn.getAttribute("data-target");
            const field = document.getElementById(id);
            if (!field) return;
            const toType = field.type === "password" ? "text" : "password";
            field.type = toType;
            btn.textContent = toType === "password" ? "Show" : "Hide";
            field.focus();
        });
    });
})();
