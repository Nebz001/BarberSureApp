(function () {
    function setup(btn, nav) {
        if (!btn || !nav) return;
        btn.addEventListener("click", function () {
            const open = nav.classList.toggle("open");
            btn.setAttribute("aria-expanded", open ? "true" : "false");
        });
    }
    document.addEventListener("DOMContentLoaded", function () {
        setup(
            document.querySelector(".owner-header .nav-hamburger"),
            document.getElementById("ownerNav")
        );
        setup(
            document.querySelector(".header-bar .nav-hamburger"),
            document.getElementById("customerNav")
        );
    });
})();
