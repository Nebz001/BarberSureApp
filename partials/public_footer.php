<?php
// Shared public footer for BarberSure
?>
<footer class="py-5 mt-4">
    <div class="container small">
        <div class="row g-4">
            <div class="col-md-4">
                <h6 class="fw-semibold mb-3">BarberSure</h6>
                <p class="text-muted small mb-3">Connecting Batangas clients with verified barbershops—simple booking, local trust, zero payment hurdles.</p>
                <div class="d-flex gap-3 fs-5">
                    <a href="#" class="link-light opacity-50 hover-opacity" aria-label="Facebook"><i class="bi bi-facebook"></i></a>
                    <a href="#" class="link-light opacity-50" aria-label="Instagram"><i class="bi bi-instagram"></i></a>
                    <a href="#" class="link-light opacity-50" aria-label="Twitter"><i class="bi bi-twitter-x"></i></a>
                </div>
            </div>
            <div class="col-md-2">
                <h6 class="fw-semibold mb-3">Platform</h6>
                <ul class="list-unstyled d-grid gap-2 text-muted">
                    <li><a href="index.php#features" class="link-light text-decoration-none">Features</a></li>
                    <li><a href="index.php#pricing" class="link-light text-decoration-none">Pricing</a></li>
                    <li><a href="index.php#why" class="link-light text-decoration-none">Why Us</a></li>
                </ul>
            </div>
            <div class="col-md-3">
                <h6 class="fw-semibold mb-3">Resources</h6>
                <ul class="list-unstyled d-grid gap-2 text-muted">
                    <li><span class="text-decoration-none">Owner Guide</span></li>
                    <li><span class="text-decoration-none">Verification Process</span></li>
                    <li><span class="text-decoration-none">Roadmap (Soon)</span></li>
                </ul>
            </div>
            <div class="col-md-3">
                <h6 class="fw-semibold mb-3">Get Started</h6>
                <p class="text-muted small mb-3">Claim your shop or start booking trusted barbers today—help shape the local platform.</p>
                <a href="register.php" class="btn btn-sm cta-btn w-100"><i class="bi bi-rocket-fill me-2"></i>Get Started</a>
            </div>
        </div>
        <div class="mt-5 pt-4 border-top border-secondary text-muted d-flex flex-wrap justify-content-between gap-3">
            <span>&copy; <?= date('Y') ?> BarberSure. All rights reserved.</span>
            <span class="small">Crafted for Batangas • Trust • Simplicity.</span>
        </div>
    </div>
</footer>