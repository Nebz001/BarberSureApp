<?php
session_start();
$isLoggedIn = isset($_SESSION['user_id']);
$currentPage = 'home';
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>BarberSure • Batangas Barbershop Discovery & Booking</title>
    <meta name="description" content="BarberSure is the local Batangas hub for discovering verified barbershops, booking appointments without online payment, and helping shop owners build trusted profiles." />
    <!-- Open Graph / Social Meta -->
    <meta property="og:title" content="BarberSure • Batangas Barbershop Discovery & Booking" />
    <meta property="og:description" content="Discover verified Batangas barbershops and book with no online payment required. Claim your shop profile and build local trust." />
    <meta property="og:type" content="website" />
    <meta property="og:url" content="https://example.com/" />
    <meta property="og:image" content="https://example.com/assets/images/og-barbersure.png" />
    <meta name="twitter:card" content="summary_large_image" />
    <meta name="twitter:title" content="BarberSure • Batangas Barbershop Discovery" />
    <meta name="twitter:description" content="Browse & book trusted Batangas barbershops. No gateway setup required." />
    <meta name="twitter:image" content="https://example.com/assets/images/og-barbersure.png" />
    <link rel="canonical" href="https://example.com/" />
    <script type="application/ld+json">
        {
            "@context": "https://schema.org",
            "@type": "WebSite",
            "name": "BarberSure",
            "url": "https://example.com/",
            "description": "Local Batangas hub for discovering verified barbershops and booking without online payment.",
            "potentialAction": {
                "@type": "SearchAction",
                "target": "https://example.com/discover.php?q={search_term}",
                "query-input": "required name=search_term"
            }
        }
    </script>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <!-- Shared public styles (loaded after Bootstrap so overrides take effect) -->
    <link rel="stylesheet" href="assets/css/public.css" />
    <style>
        /* Page-specific (hero + sections) styles; shared base in assets/css/public.css */
        .hero {
            position: relative;
            /* Added extra top offset to avoid overlap with fixed navbar */
            --hero-extra-offset: 1.75rem;
            padding: calc(clamp(4rem, 9vh, 7rem) + var(--hero-extra-offset)) 0 5rem;
            overflow: hidden;
        }

        .hero:before,
        .hero:after {
            content: "";
            position: absolute;
            width: 680px;
            height: 680px;
            border-radius: 50%;
            filter: blur(110px);
            opacity: .28;
        }

        .hero:before {
            background: #1d3b6a;
            top: -140px;
            left: -160px;
        }

        .hero:after {
            background: #432371;
            bottom: -200px;
            right: -160px;
        }

        .badge-soft {
            background: rgba(99, 102, 241, .12);
            color: #9ea5ff;
            border: 1px solid rgba(99, 102, 241, .25);
            font-weight: 500;
        }

        .hero h1 {
            font-weight: 800;
            letter-spacing: -1px;
            line-height: 1.08;
            text-shadow: 0 3px 14px rgba(0, 0, 0, .6), 0 1px 0 rgba(255, 255, 255, .04);
        }

        .gradient-text {
            background: var(--grad-primary);
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
        }

        .lead {
            font-size: 1.18rem;
            max-width: 760px;
            line-height: 1.55;
            color: #dfe5ea;
            text-shadow: 0 2px 8px rgba(0, 0, 0, .55);
        }

        .cta-btn {
            background: var(--grad-primary);
            border: 0;
            font-weight: 600;
            letter-spacing: .5px;
            box-shadow: 0 8px 30px -6px rgba(0, 0, 0, .4);
        }

        .cta-btn:hover {
            filter: brightness(1.08);
        }

        .glass {
            background: rgba(255, 255, 255, 0.04);
            border: 1px solid rgba(255, 255, 255, 0.07);
            backdrop-filter: blur(6px);
        }

        .feature-icon {
            width: 54px;
            height: 54px;
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.4rem;
            background: linear-gradient(145deg, #1d2834, #141b22);
            border: 1px solid #1f2a36;
            color: #fbbf24;
            box-shadow: 0 4px 18px -6px rgba(0, 0, 0, .6);
        }

        .feature-card {
            transition: background .35s, transform .35s, border-color .35s, box-shadow .35s;
            cursor: default;
        }

        .feature-card:hover {
            background: #1d2732;
            border-color: #314457;
            transform: translateY(-4px);
            box-shadow: 0 10px 32px -10px rgba(0, 0, 0, .6);
        }

        .section-title {
            font-weight: 750;
            letter-spacing: .5px;
            line-height: 1.25;
            text-shadow: 0 2px 10px rgba(0, 0, 0, .6);
        }

        .metrics-grid .metric-box {
            background: linear-gradient(145deg, #16212b, #11181f);
            border: 1px solid #263746;
            border-radius: 18px;
            padding: 1.5rem 1.25rem;
            position: relative;
            overflow: hidden;
        }

        .metric-box:before {
            content: "";
            position: absolute;
            inset: 0;
            background: radial-gradient(circle at 25% 15%, rgba(99, 102, 241, .22), transparent 60%);
            opacity: .65;
        }

        .metric-value {
            font-size: 2.1rem;
            font-weight: 700;
            letter-spacing: -1px;
        }

        .metric-label {
            font-size: .75rem;
            letter-spacing: 1.2px;
            text-transform: uppercase;
            font-weight: 600;
            opacity: .75;
        }

        .divider-fade {
            height: 1px;
            background: linear-gradient(90deg, transparent, #25313f, transparent);
            margin: 5rem auto;
            width: 100%;
        }

        .check-icon {
            color: #10b981;
        }

        .why-card {
            background: #141b22;
            border: 1px solid #202b37;
            border-radius: 22px;
            padding: 2rem 1.75rem;
            position: relative;
            overflow: hidden;
        }

        .why-card:before {
            content: "";
            position: absolute;
            inset: 0;
            background: radial-gradient(circle at 70% 30%, rgba(14, 165, 233, .18), transparent 60%);
            opacity: .55;
        }

        .pricing {
            background: linear-gradient(180deg, #131b24, #0f141a);
            border: 1px solid #1f2a36;
            border-radius: 28px;
            padding: 3rem 2rem;
        }

        .pricing-badge {
            background: linear-gradient(90deg, #f59e0b, #fbbf24);
            font-size: .65rem;
            letter-spacing: 1px;
            font-weight: 600;
        }

        .plan-price {
            font-size: 2.75rem;
            font-weight: 700;
            letter-spacing: -2px;
        }

        .animate-fade-up {
            opacity: 0;
            transform: translateY(30px);
        }

        .reveal {
            opacity: 1 !important;
            transform: translateY(0) !important;
            transition: 1s cubic-bezier(.16, .8, .24, 1);
        }

        .floating {
            animation: floating 9s ease-in-out infinite;
        }

        @keyframes floating {

            0%,
            100% {
                transform: translateY(-10px);
            }

            50% {
                transform: translateY(12px);
            }
        }

        .spark {
            position: absolute;
            width: 6px;
            height: 6px;
            background: #f59e0b;
            border-radius: 50%;
            box-shadow: 0 0 12px 4px rgba(245, 158, 11, .7);
            animation: spark 5.5s linear infinite;
            opacity: .7;
        }

        @keyframes spark {
            0% {
                transform: translate(-20px, 10px) scale(.4);
            }

            50% {
                transform: translate(300px, -40px) scale(.9);
            }

            100% {
                transform: translate(620px, 50px) scale(.3);
            }
        }

        .hero-visual {
            position: relative;
            max-width: 560px;
        }

        .hero-terminal {
            background: #111c27;
            border: 1px solid #223243;
            border-radius: 18px;
            padding: 1.25rem 1.25rem 1.4rem;
            font-family: "SFMono-Regular", Consolas, monospace;
            font-size: .82rem;
            line-height: 1.45;
            box-shadow: 0 22px 46px -14px rgba(0, 0, 0, .65);
        }

        .circle {
            width: 12px;
            height: 12px;
            border-radius: 50%;
            background: #253341;
        }

        .circle.red {
            background: #ef4444;
        }

        .circle.yellow {
            background: #f59e0b;
        }

        .circle.green {
            background: #10b981;
        }

        .hero-terminal code {
            color: #b3c9d8;
        }

        .hero-terminal .hl {
            color: #7cb7ff;
        }

        .hero-terminal .hl2 {
            color: #c1abff;
        }

        .shadow-soft {
            box-shadow: 0 8px 28px -10px rgba(0, 0, 0, .55);
        }

        .bg-faint {
            background: rgba(255, 255, 255, 0.04);
        }

        /* Readability improvements */
        p,
        li {
            text-shadow: 0 1px 3px rgba(0, 0, 0, .5);
        }

        h2,
        h3,
        h4,
        h5,
        h6 {
            text-shadow: 0 2px 8px rgba(0, 0, 0, .55);
        }

        .feature-card h5 {
            color: #f3f6f9;
        }

        .why-card p,
        .feature-card p {
            line-height: 1.5;
        }
    </style>
</head>

<body class="text-light">
    <?php include __DIR__ . '/partials/public_header.php'; ?>
    <header class="hero">
        <div class="spark"></div>
        <div class="spark" style="animation-delay:1.8s; top:40%; left:10%;"></div>
        <div class="spark" style="animation-delay:3.2s; top:65%; left:55%;"></div>
        <div class="container position-relative">
            <div class="row align-items-center g-5">
                <div class="col-lg-6 animate-fade-up">
                    <span class="badge rounded-pill badge-soft mb-3"><i class="bi bi-lightning-charge-fill me-1"></i> Discover • Book • Local</span>
                    <h1 class="display-5 mb-3">Batangas' Local Hub for <span class="gradient-text">Verified Barbershops</span></h1>
                    <p class="lead mb-4">Find trusted barbershops across Batangas and reserve a seat in seconds—no online payment required. For shop owners, BarberSure amplifies local visibility, streamlines booking requests, and builds client trust through verification.</p>
                    <div class="d-flex flex-wrap gap-3">
                        <a href="register.php" class="btn btn-lg cta-btn px-4 py-2"><i class="bi bi-rocket-takeoff-fill me-2"></i>Start Free</a>
                        <a href="#features" class="btn btn-lg btn-outline-light px-4 py-2"><i class="bi bi-eye-fill me-2"></i>Explore Features</a>
                    </div>
                    <div class="mt-4 small text-muted">No credit card needed • Launch in minutes • Cancel anytime</div>
                </div>
                <div class="col-lg-6 animate-fade-up" style="animation-delay:.15s;">
                    <div class="hero-visual mx-auto floating">
                        <div class="hero-terminal">
                            <div class="d-flex gap-2 mb-3">
                                <div class="circle red"></div>
                                <div class="circle yellow"></div>
                                <div class="circle green"></div>
                            </div>
                            <code><span class="hl">$shop</span> = BarberSure::register([
                                'name' => 'Fade District',
                                'city' => 'Batangas',
                                'services' => ['Skin Fade','Beard Trim']
                                ]);

                                <span class="hl2">$today</span> = $shop->bookings()->today();
                                <span class="hl">echo</span> "Bookings Today: {<span class="hl2">$today->count()</span>}";
                                // -> Local discovery boosts walk-ins
                                // -> No payment gateway needed
                                // -> Verification builds client trust
                            </code>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </header>
    <section id="features" class="py-5 position-relative">
        <div class="container">
            <h2 class="section-title h3 mb-4 text-center animate-fade-up">Tools that Connect <span class="gradient-text">Batangas Customers & Shops</span></h2>
            <p class="text-muted text-center mb-5 animate-fade-up" style="max-width:760px;margin:0 auto;">BarberSure bridges people looking for reliable grooming with verified local barbershops—while giving owners simple, trust-first booking management.</p>
            <div class="row g-4">
                <div class="col-md-6 col-lg-4 animate-fade-up">
                    <div class="p-4 rounded-4 glass feature-card h-100">
                        <div class="feature-icon mb-3"><i class="bi bi-calendar2-check"></i></div>
                        <h5 class="fw-semibold mb-2">Smart Local Booking</h5>
                        <p class="text-muted small mb-0">Real-time availability and clean scheduling—reserve now, pay in person. Less messaging back-and-forth, fewer no‑shows.</p>
                    </div>
                </div>
                <div class="col-md-6 col-lg-4 animate-fade-up" style="animation-delay:.05s;">
                    <div class="p-4 rounded-4 glass feature-card h-100">
                        <div class="feature-icon mb-3" style="color:#10b981;"><i class="bi bi-graph-up-arrow"></i></div>
                        <h5 class="fw-semibold mb-2">Shop Insights (Owners)</h5>
                        <p class="text-muted small mb-0">Daily utilization & popular services—helping owners understand demand patterns without complicated reports.</p>
                    </div>
                </div>
                <div class="col-md-6 col-lg-4 animate-fade-up" style="animation-delay:.1s;">
                    <div class="p-4 rounded-4 glass feature-card h-100">
                        <div class="feature-icon mb-3" style="color:#f59e0b;"><i class="bi bi-credit-card"></i></div>
                        <h5 class="fw-semibold mb-2">No Payment Setup Needed</h5>
                        <p class="text-muted small mb-0">Bookings first, in-person payment later. Launch faster without gateways, fees, or technical setup.</p>
                    </div>
                </div>
                <div class="col-md-6 col-lg-4 animate-fade-up" style="animation-delay:.15s;">
                    <div class="p-4 rounded-4 glass feature-card h-100">
                        <div class="feature-icon mb-3" style="color:#c084fc;"><i class="bi bi-stars"></i></div>
                        <h5 class="fw-semibold mb-2">Verified Shop Profiles</h5>
                        <p class="text-muted small mb-0">Owner and shop verification workflows build public trust and encourage first-time bookings.</p>
                    </div>
                </div>
                <div class="col-md-6 col-lg-4 animate-fade-up" style="animation-delay:.2s;">
                    <div class="p-4 rounded-4 glass feature-card h-100">
                        <div class="feature-icon mb-3" style="color:#ef4444;"><i class="bi bi-shield-lock"></i></div>
                        <h5 class="fw-semibold mb-2">Trust & Security</h5>
                        <p class="text-muted small mb-0">Role-based access, transparent verification, and logged actions keep the ecosystem accountable.</p>
                    </div>
                </div>
                <div class="col-md-6 col-lg-4 animate-fade-up" style="animation-delay:.25s;">
                    <div class="p-4 rounded-4 glass feature-card h-100">
                        <div class="feature-icon mb-3" style="color:#fbbf24;"><i class="bi bi-phone-flip"></i></div>
                        <h5 class="fw-semibold mb-2">Mobile-Ready UX</h5>
                        <p class="text-muted small mb-0">Fast, responsive experience for discovering nearby shops & managing bookings on any device.</p>
                    </div>
                </div>
            </div>
        </div>
    </section>
    <div class="divider-fade"></div>
    <section id="why" class="py-5">
        <div class="container">
            <h2 class="section-title h3 mb-4 text-center animate-fade-up">Why Batangas Shops Choose <span class="gradient-text">BarberSure</span></h2>
            <div class="row g-4 mt-2">
                <div class="col-md-6 col-lg-3 animate-fade-up">
                    <div class="why-card h-100">
                        <h6 class="fw-semibold mb-2"><i class="bi bi-activity me-2 text-info"></i> Local Visibility</h6>
                        <p class="text-muted small mb-0">Be discoverable by residents & visitors searching for verified grooming in Batangas.</p>
                    </div>
                </div>
                <div class="col-md-6 col-lg-3 animate-fade-up" style="animation-delay:.05s;">
                    <div class="why-card h-100">
                        <h6 class="fw-semibold mb-2"><i class="bi bi-people-fill me-2 text-success"></i> Verified Trust</h6>
                        <p class="text-muted small mb-0">Structured owner & shop verification increases confidence for first-time bookings.</p>
                    </div>
                </div>
                <div class="col-md-6 col-lg-3 animate-fade-up" style="animation-delay:.1s;">
                    <div class="why-card h-100">
                        <h6 class="fw-semibold mb-2"><i class="bi bi-safe2-fill me-2 text-warning"></i> Simple & Secure</h6>
                        <p class="text-muted small mb-0">Clear workflows & logged actions maintain a safe, professional environment.</p>
                    </div>
                </div>
                <div class="col-md-6 col-lg-3 animate-fade-up" style="animation-delay:.15s;">
                    <div class="why-card h-100">
                        <h6 class="fw-semibold mb-2"><i class="bi bi-speedometer2 me-2 text-danger"></i> Ready to Grow</h6>
                        <p class="text-muted small mb-0">Add services, seats or barbers as demand builds—without technical overhead.</p>
                    </div>
                </div>
            </div>
            <div class="row mt-5 g-4 align-items-center">
                <div class="col-lg-6 animate-fade-up">
                    <h3 class="h4 fw-semibold mb-3">What You Get Day One</h3>
                    <ul class="list-unstyled small text-muted mb-4 d-grid gap-2">
                        <li><i class="bi bi-check-circle-fill me-2 check-icon"></i>Local discovery listing</li>
                        <li><i class="bi bi-check-circle-fill me-2 check-icon"></i>Reservation & scheduling engine</li>
                        <li><i class="bi bi-check-circle-fill me-2 check-icon"></i>Owner & shop verification flow</li>
                        <li><i class="bi bi-check-circle-fill me-2 check-icon"></i>Core utilization insights</li>
                        <li><i class="bi bi-check-circle-fill me-2 check-icon"></i>Secure authentication & roles</li>
                        <li><i class="bi bi-check-circle-fill me-2 check-icon"></i>No payment gateway required</li>
                    </ul>
                    <a href="register.php" class="btn btn-primary cta-btn px-4"><i class="bi bi-sparkle me-2"></i>Claim Your Profile</a>
                </div>
                <div class="col-lg-6 animate-fade-up" style="animation-delay:.08s;">
                    <div class="metrics-grid row g-3">
                        <div class="col-6">
                            <div class="metric-box h-100">
                                <div class="metric-value">97%</div>
                                <div class="metric-label">Booking Satisfaction</div>
                            </div>
                        </div>
                        <div class="col-6">
                            <div class="metric-box h-100">
                                <div class="metric-value">2.3x</div>
                                <div class="metric-label">Faster Repeat</div>
                            </div>
                        </div>
                        <div class="col-6">
                            <div class="metric-box h-100">
                                <div class="metric-value">-34%</div>
                                <div class="metric-label">No‑Show Rate</div>
                            </div>
                        </div>
                        <div class="col-6">
                            <div class="metric-box h-100">
                                <div class="metric-value">+48%</div>
                                <div class="metric-label">Service Upsell</div>
                            </div>
                        </div>
                    </div>
                    <p class="text-muted xsmall mt-3 mb-0" style="font-size:.6rem;">Illustrative performance indicators based on typical adoption patterns.</p>
                </div>
            </div>
        </div>
    </section>
    <div class="divider-fade"></div>
    <section id="pricing" class="py-5">
        <div class="container">
            <h2 class="section-title h3 mb-5 text-center animate-fade-up">Early Access. <span class="gradient-text">Free During Launch.</span></h2>
            <div class="row justify-content-center">
                <div class="col-md-8 col-lg-6 animate-fade-up">
                    <div class="pricing text-center position-relative shadow-soft">
                        <span class="badge pricing-badge rounded-pill position-absolute top-0 start-50 translate-middle">LAUNCH</span>
                        <h3 class="h5 fw-semibold mb-3">Founding Shop Access</h3>
                        <div class="plan-price mb-1">₱0<span style="font-size:1rem;" class="text-muted ms-1">/launch phase</span></div>
                        <div class="text-muted small mb-4">Join the Batangas rollout. Help shape the roadmap while enjoying core features free.</div>
                        <ul class="list-unstyled small text-start d-inline-block mb-4" style="max-width:360px;">
                            <li class="mb-2"><i class="bi bi-check-lg text-success me-2"></i>Unlimited local bookings</li>
                            <li class="mb-2"><i class="bi bi-check-lg text-success me-2"></i>Core utilization insights</li>
                            <li class="mb-2"><i class="bi bi-check-lg text-success me-2"></i>Owner & shop verification badge</li>
                            <li class="mb-2"><i class="bi bi-check-lg text-success me-2"></i>No payment gateway required</li>
                            <li class="mb-2"><i class="bi bi-check-lg text-success me-2"></i>Roadmap influence (founding phase)</li>
                        </ul>
                        <a href="register.php" class="btn btn-lg cta-btn px-4 w-100"><i class="bi bi-cart-check me-2"></i>Reserve My Spot</a>
                        <div class="text-muted mt-3 small">Future premium plans will be optional. Pay in person for services—never required online.</div>
                    </div>
                </div>
            </div>
        </div>
    </section>
    <div class="divider-fade" id="growth"></div>
    <section class="py-5">
        <div class="container">
            <div class="row align-items-center g-5">
                <div class="col-lg-6 animate-fade-up">
                    <h2 class="h3 section-title mb-3">Building a Connected Grooming Community in Batangas</h2>
                    <p class="text-muted small mb-4">BarberSure is more than a booking tool—it’s a local network that increases trust, highlights quality, and helps both residents and travelers find the right chair faster.</p>
                    <ul class="list-unstyled small text-muted d-grid gap-2 mb-4">
                        <li><i class="bi bi-check2-circle text-success me-2"></i>Trust-first owner & shop verification</li>
                        <li><i class="bi bi-check2-circle text-success me-2"></i>Demand insights (busy vs. quiet hours)</li>
                        <li><i class="bi bi-check2-circle text-success me-2"></i>Simple reservation & profile management</li>
                        <li><i class="bi bi-check2-circle text-success me-2"></i>Foundation for future loyalty integrations</li>
                    </ul>
                    <a href="register.php" class="btn btn-primary cta-btn px-4"><i class="bi bi-clipboard2-plus me-2"></i>Create My Account</a>
                </div>
                <div class="col-lg-6 animate-fade-up" style="animation-delay:.1s;">
                    <div class="rounded-4 glass p-4 h-100">
                        <h6 class="text-uppercase small fw-semibold mb-3 text-info">Momentum Tracker (Concept)</h6>
                        <div class="row g-3">
                            <div class="col-6">
                                <div class="bg-faint rounded-4 p-3 text-center">
                                    <div class="fw-bold fs-4">82%</div>
                                    <div class="xsmall text-muted">Seat Utilization</div>
                                </div>
                            </div>
                            <div class="col-6">
                                <div class="bg-faint rounded-4 p-3 text-center">
                                    <div class="fw-bold fs-4">+19%</div>
                                    <div class="xsmall text-muted">Repeat Rate</div>
                                </div>
                            </div>
                            <div class="col-6">
                                <div class="bg-faint rounded-4 p-3 text-center">
                                    <div class="fw-bold fs-4">4.8★</div>
                                    <div class="xsmall text-muted">Avg. Feedback</div>
                                </div>
                            </div>
                            <div class="col-6">
                                <div class="bg-faint rounded-4 p-3 text-center">
                                    <div class="fw-bold fs-4">-27%</div>
                                    <div class="xsmall text-muted">No‑Shows</div>
                                </div>
                            </div>
                        </div>
                        <p class="text-muted xsmall mt-3 mb-0" style="font-size:.6rem;">Sample future analytics module preview.</p>
                    </div>
                </div>
            </div>
        </div>
    </section>
    <section class="py-5">
        <div class="container text-center">
            <h2 class="h3 fw-semibold mb-3 animate-fade-up">Ready to Join the Batangas Grooming Network?</h2>
            <p class="text-muted small mb-4 animate-fade-up" style="max-width:640px;margin:0 auto;">Register now to discover verified shops—or claim your own profile and start receiving local bookings.</p>
            <a href="register.php" class="btn btn-lg cta-btn px-5 animate-fade-up" style="animation-delay:.05s;"><i class="bi bi-fire me-2"></i>Get Started Free</a>
        </div>
    </section>
    <?php include __DIR__ . '/partials/public_footer.php'; ?>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"></script>
    <script>
        // Reveal animations for elements with animate-fade-up
        const revealObserver = new IntersectionObserver(entries => {
            entries.forEach(e => {
                if (e.isIntersecting) {
                    e.target.classList.add('reveal');
                    revealObserver.unobserve(e.target);
                }
            });
        }, {
            threshold: 0.12
        });
        document.querySelectorAll('.animate-fade-up').forEach(el => revealObserver.observe(el));

        // Scroll spy for primary sections to update nav active state
        const sectionIds = ['features', 'why', 'pricing', 'growth'];
        const navLinks = Array.from(document.querySelectorAll('nav a.nav-link'));
        const sectionObserver = new IntersectionObserver(entries => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    const id = entry.target.getAttribute('id');
                    if (!id) return;
                    navLinks.forEach(l => {
                        const href = l.getAttribute('href') || '';
                        if (href === '#' + id || href.endsWith('#' + id)) {
                            l.classList.add('active');
                        } else if (!href.startsWith('discover.php')) {
                            // Avoid stripping active state on external page links
                            if (!href.includes('#') || sectionIds.some(s => href.endsWith('#' + s))) {
                                l.classList.remove('active');
                            }
                        }
                    });
                }
            });
        }, {
            threshold: 0.45
        });
        sectionIds.forEach(id => {
            const el = document.getElementById(id);
            if (el) sectionObserver.observe(el);
        });
    </script>
</body>

</html>