<?php
require_once 'db.php';

// Fetch Clinic Schedule
$clinic_schedule = [];
$all_services = [];
try {
    // Attempt to fetch, if db.php is correctly loaded and $pdo exists
    if (isset($pdo)) {
        // Fetch Schedule
        $stmt = $pdo->query("SELECT * FROM tbl_clinic_schedule WHERE is_open = 1 ORDER BY FIELD(day_of_week, 'Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday')");
        $clinic_schedule = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Fetch All Services
        $service_stmt = $pdo->query("SELECT * FROM tbl_service ORDER BY category, service_name");
        $all_services = $service_stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Exception $e) {
    // Silent fail for public facing page, simplify defaults or empty
}

function formatTime($time) {
    return date('g:i A', strtotime($time));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Vanessa Nicolas Dental Clinic - Baliwag Branch</title>
    
    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@200;300;400;500;600;700;800&display=swap" rel="stylesheet">
    
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <!-- Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">

    <style>
        :root {
            --primary-purple: #8a11d4;
            --dark-purple: #4c0b75;
            --accent-gold: #FFD700;
            --text-main: #151118;
            --text-sub: #7a6189;
            --bg-light: #f8f9fa;
        }

        body {
            font-family: 'Manrope', sans-serif;
            color: var(--text-main);
            overflow-x: hidden;
        }

        /* Navbar */
        .navbar {
            background-color: white;
            box-shadow: 0 2px 15px rgba(0,0,0,0.05);
            padding: 1rem 0;
        }

        .navbar-brand {
            font-weight: 800;
            color: var(--primary-purple);
            font-size: 1.5rem;
        }

        .nav-link {
            color: var(--text-main);
            font-weight: 600;
            margin: 0 10px;
            transition: color 0.3s;
        }

        .nav-link:hover, .nav-link.active {
            color: var(--primary-purple);
        }

        .btn-gold {
            background-color: var(--accent-gold);
            color: var(--text-main);
            font-weight: 700;
            border: none;
            padding: 10px 25px;
            border-radius: 50px;
            transition: transform 0.2s, box-shadow 0.2s;
        }

        .btn-gold:hover {
            background-color: #e6c200;
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(255, 215, 0, 0.3);
            color: var(--text-main);
        }

        /* Hero Section */
        #hero {
            background: linear-gradient(135deg, rgba(138, 17, 212, 0.05) 0%, rgba(255, 215, 0, 0.05) 100%);
            padding: 120px 0 80px;
            position: relative;
        }

        .hero-title {
            font-weight: 800;
            font-size: 3.5rem;
            line-height: 1.2;
            color: var(--text-main);
            margin-bottom: 20px;
        }

        .hero-title span {
            color: var(--primary-purple);
        }

        .hero-subtitle {
            font-size: 1.25rem;
            color: var(--text-sub);
            margin-bottom: 30px;
            font-weight: 500;
        }

        /* Section Titles */
        .section-title {
            text-align: center;
            margin-bottom: 60px;
        }

        .section-title h2 {
            font-weight: 800;
            color: var(--primary-purple);
            text-transform: uppercase;
            font-size: 1rem;
            letter-spacing: 2px;
            margin-bottom: 10px;
        }

        .section-title p {
            font-size: 2.5rem;
            font-weight: 700;
            margin: 0;
        }

        /* Services */
        .service-card {
            background: white;
            border-radius: 20px;
            padding: 40px 30px;
            text-align: center;
            transition: all 0.3s ease;
            height: 100%;
            border: 1px solid rgba(0,0,0,0.05);
        }

        .service-card:hover {
            transform: translateY(-10px);
            box-shadow: 0 10px 30px rgba(138, 17, 212, 0.1);
            border-color: var(--primary-purple);
        }

        .icon-box {
            width: 80px;
            height: 80px;
            background: rgba(138, 17, 212, 0.1);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 25px;
            color: var(--primary-purple);
            font-size: 2rem;
            transition: all 0.3s;
        }

        .service-card:hover .icon-box {
            background: var(--primary-purple);
            color: white;
        }

        .service-card h3 {
            font-weight: 700;
            margin-bottom: 15px;
            font-size: 1.5rem;
        }

        .service-card p {
            color: var(--text-sub);
            margin: 0;
        }

        /* FAQ */
        .accordion-item {
            border: none;
            margin-bottom: 15px;
            border-radius: 15px !important;
            overflow: hidden;
            box-shadow: 0 2px 10px rgba(0,0,0,0.02);
        }

        .accordion-button {
            font-weight: 700;
            padding: 20px 25px;
            background: white;
            color: var(--text-main);
        }

        .accordion-button:not(.collapsed) {
            background-color: rgba(138, 17, 212, 0.05);
            color: var(--primary-purple);
            box-shadow: none;
        }

        .accordion-button:focus {
            box-shadow: none;
            border-color: rgba(138, 17, 212, 0.1);
        }

        /* Footer */
        .footer {
            background-color: #1a1a1a;
            color: white;
            padding: 80px 0 30px;
        }

        .footer h4 {
            color: var(--accent-gold);
            font-weight: 700;
            margin-bottom: 25px;
        }

        .footer p, .footer a {
            color: #ccc;
            text-decoration: none;
            margin-bottom: 10px;
            display: block;
        }
        
        .footer a:hover {
            color: var(--accent-gold);
        }

        .contact-info i {
            color: var(--primary-purple);
            margin-right: 10px;
        }

        /* Responsive Fixes */
        @media (max-width: 991px) {
            .hero-title {
                font-size: 2.5rem;
            }
            #hero {
                text-align: center;
                padding-top: 100px;
            }
            .hero-img {
                margin-top: 40px;
            }
        }
        
        /* Custom Alert Colors */
        .alert-purple {
            background-color: rgba(138, 17, 212, 0.1);
            border-color: rgba(138, 17, 212, 0.3);
            color: var(--primary-purple);
        }
        .alert-purple i {
            color: var(--primary-purple) !important;
        }
    </style>
</head>
<body>

    <!-- Navbar -->
    <nav class="navbar navbar-expand-lg fixed-top">
        <div class="container">
            <a class="navbar-brand" href="#">
                <img src="images/logo/logo.png" alt="Vanessa Nicolas Dental Clinic" style="height: 40px;">
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav ms-auto align-items-center">
                    <li class="nav-item"><a class="nav-link active" href="#hero">Home</a></li>
                    <li class="nav-item"><a class="nav-link" href="#about">About</a></li>
                    <li class="nav-item"><a class="nav-link" href="#services">Services</a></li>
                    <li class="nav-item"><a class="nav-link" href="#faq">FAQ</a></li>
                    <li class="nav-item"><a class="nav-link" href="#contact">Contact</a></li>
                    <li class="nav-item ms-lg-3">
                        <a href="landing.php" class="btn btn-gold">Log In</a>
                    </li>
                </ul>
            </div>
        </div>
    </nav>

    <!-- Holiday Banner Container -->
    <div id="holidayBanner" class="container d-none" style="margin-top: 100px;">
        <div class="alert alert-danger shadow-sm border-danger d-flex align-items-start" role="alert">
            <i class="bi bi-exclamation-triangle-fill fs-4 me-3 mt-1"></i>
            <div class="flex-grow-1">
                <h5 class="alert-heading fw-bold mb-1">Clinic Holiday / Schedule Notice</h5>
                <div id="holidayList" class="small">
                    <!-- Dynamic Content -->
                </div>
            </div>
            <button type="button" class="btn-close" aria-label="Close" onclick="document.getElementById('holidayBanner').classList.add('d-none')"></button>
        </div>
    </div>

    <!-- Hero Section -->
    <section id="hero" class="d-flex align-items-center">
        
    <!-- Holiday Banner Script -->
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            fetch('api/get_upcoming_holidays.php?t=' + Date.now())
                .then(r => r.json())
                .then(data => {
                    console.log('Holiday API Response:', data); // Debug
                    if (data.success && data.holidays.length > 0) {
                        const list = document.getElementById('holidayList');
                        const banner = document.getElementById('holidayBanner');
                        
                        const now = new Date();
                        // Reset time to 00:00 for accurate day diff
                        now.setHours(0,0,0,0);
                        
                        let minDiff = 999;
                        let bannerClass = 'alert-purple'; // Default 7 days
                        let iconClass = 'bi-info-circle-fill';
                        
                        const msPerDay = 1000 * 60 * 60 * 24;
                        const visibleHolidays = [];

                        data.holidays.forEach(h => {
                            const dateParts = h.block_date.split('-');
                            const hDate = new Date(dateParts[0], dateParts[1]-1, dateParts[2]);
                            
                            const diffTime = hDate - now;
                            const diffDays = Math.ceil(diffTime / msPerDay);
                            
                            // Show ALL upcoming holidays (no 7-day limit)
                            if (diffDays >= -1) {
                                if (diffDays < minDiff) minDiff = diffDays;
                                visibleHolidays.push(h);
                            }
                        });
                        
                        if (visibleHolidays.length > 0) {
                            // Determine Color Logic
                            if (minDiff <= 2) { // 2 days or less (RED)
                                bannerClass = 'alert-danger';
                                iconClass = 'bi-exclamation-triangle-fill text-danger';
                            } else if (minDiff <= 5) { // 5 days (ORANGE)
                                bannerClass = 'alert-warning';
                                iconClass = 'bi-exclamation-circle-fill text-warning';
                            } else { // > 5 days (PURPLE)
                                bannerClass = 'alert-purple';
                                iconClass = 'bi-calendar-event-fill';
                            }
                            
                            // Apply Classes
                            const alertBox = banner.querySelector('.alert');
                            alertBox.className = `alert ${bannerClass} shadow-sm d-flex align-items-start`;
                            
                            // Update Icon
                            const icon = banner.querySelector('i');
                            icon.className = `bi ${iconClass} fs-4 me-3 mt-1`;
                            
                            // Render List
                            visibleHolidays.forEach(h => {
                                const div = document.createElement('div');
                                div.className = 'mb-1';
                                div.innerHTML = `<strong>${h.formatted_date}</strong>: ${h.reason} <span class="badge bg-opacity-75 ms-2 ${minDiff <= 2 ? 'bg-danger' : 'bg-primary'}">${h.display_time}</span>`;
                                list.appendChild(div);
                            });
                            
                            banner.classList.remove('d-none');
                        }
                    }
                })
                .catch(err => console.error('Failed to load holidays:', err));
        });
    </script>

        <div class="container">
            <?php

            ?>
            <div class="row align-items-center">
                <div class="col-lg-6">
                    <h1 class="hero-title">Brightening Smiles in <span>Baliwag</span></h1>
                    <p class="hero-subtitle">Professional, affordable, and gentle dental care for the whole family.</p>
                    <div class="d-flex gap-3 justify-content-center justify-content-lg-start">
                        <a href="landing.php" class="btn btn-gold btn-lg shadow">Book an Appointment</a>
                        <a href="#services" class="btn btn-violet btn-lg shadow"><b>Our Services</b></a>
                    </div>
                </div>
                <div class="col-lg-6 hero-img">
                    <div class="rounded-4 overflow-hidden shadow-lg bg-white p-2">
                        <div class="bg-light rounded-3 d-flex align-items-center justify-content-center overflow-hidden" style="height: 400px;">
                            <img src="images/index/kids.png" alt="Happy Patient" class="img-fluid w-100 h-100 object-fit-cover">
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- About Section -->
    <section id="about" class="py-5">
        <div class="container py-5">
            <div class="row align-items-center">
                <div class="col-lg-6 mb-4 mb-lg-0">
                    <div class="rounded-4 overflow-hidden shadow-lg position-relative">
                        <div class="bg-light d-flex align-items-center justify-content-center overflow-hidden" style="height: 500px;">
                            <img src="images/index/front.png" alt="Clinic / Dr. Nicolas" class="img-fluid w-100 h-100 object-fit-cover">
                        </div>
                    </div>
                </div>
                <div class="col-lg-6 ps-lg-5">
                    <div class="section-title text-start mb-4">
                        <h2>About Us</h2>
                        <p>Welcome to Vanessa Nicolas Dental Clinic</p>
                    </div>
                    <p class="text-secondary lead mb-4">Located in the heart of Baliwag, Bulacan, we are dedicated to providing top-quality dental care in a warm and welcoming environment.</p>
                    <p class="text-secondary mb-4">Led by <strong>Dr. Vanessa Nicolas</strong>, our team prioritizes patient comfort and hygiene above all else. We understand that visiting the dentist can be daunting, which is why we use gentle techniques and state-of-the-art equipment to ensure a pain-free experience.</p>
                    <ul class="list-unstyled text-secondary">
                        <li class="mb-2"><i class="bi bi-check-circle-fill text-primary me-2"></i> Modern facility with strict sterilization protocols</li>
                        <li class="mb-2"><i class="bi bi-check-circle-fill text-primary me-2"></i> Clean, comfortable, and family-friendly atmosphere</li>
                        <li class="mb-2"><i class="bi bi-check-circle-fill text-primary me-2"></i> Personalized care plans for every patient</li>
                    </ul>
                </div>
            </div>
        </div>
    </section>

    <!-- Services Section -->
    <section id="services" class="py-5 bg-light">
        <div class="container py-5">
            <div class="section-title">
                <h2>What We Do</h2>
                <p>Our Comprehensive Services</p>
            </div>
            <div class="row g-4">
                <div class="col-lg-4 col-md-6">
                    <div class="service-card">
                        <div class="icon-box">
                            <i class="bi bi-emoji-smile"></i>
                        </div>
                        <h3>General Dentistry</h3>
                        <p>Routine checkups, thorough cleaning (prophylaxis), and durable fillings to keep your smile healthy and bright.</p>
                    </div>
                </div>
                <div class="col-lg-4 col-md-6">
                    <div class="service-card">
                        <div class="icon-box">
                            <i class="bi bi-braces"></i>
                        </div>
                        <h3>Orthodontics</h3>
                        <p>Comprehensive orthodontic solutions including traditional metal braces and retainers to align your teeth perfectly.</p>
                    </div>
                </div>
                <div class="col-lg-4 col-md-6">
                    <div class="service-card">
                        <div class="icon-box">
                            <i class="bi bi-gem"></i>
                        </div>
                        <h3>Cosmetic & Surgery</h3>
                        <p>Enhance your smile with whitening and veneers, or address complex issues with root canals and extractions.</p>
                    </div>
                </div>
            </div>
            <div class="text-center mt-5">
                <button type="button" class="btn btn-gold btn-lg shadow px-5" data-bs-toggle="modal" data-bs-target="#servicesModal">
                    More Services
                </button>
            </div>
        </div>
    </section>

    <!-- Services Modal -->
    <div class="modal fade" id="servicesModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content rounded-4 border-0 shadow-lg">
                <div class="modal-header border-0 bg-light p-4">
                    <div>
                        <h4 class="modal-title fw-bold" style="color: var(--primary-purple);">Our Complete Services</h4>
                        <p class="text-secondary mb-0 small">Comprehensive dental care tailored for you</p>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body p-4 bg-light">
                    <?php if (empty($all_services)): ?>
                        <div class="text-center py-5">
                            <i class="bi bi-exclamation-circle text-muted fs-1"></i>
                            <p class="text-muted mt-3">Services are currently being updated. Please check back later.</p>
                        </div>
                    <?php else: ?>
                        <div class="row g-4">
                            <?php foreach ($all_services as $service): ?>
                                <div class="col-lg-4 col-md-6">
                                    <div class="card h-100 border-0 shadow-sm rounded-4 hover-lift" style="transition: transform 0.2s;">
                                        <div class="card-body p-4">
                                            <div class="d-flex justify-content-between align-items-start mb-3">
                                                <span class="badge rounded-pill bg-primary bg-opacity-10 text-primary px-3 py-2">
                                                    <?php echo htmlspecialchars($service['category'] ?? 'General'); ?>
                                                </span>
                                                <span class="fw-bold text-dark">₱<?php echo number_format($service['base_price'], 2); ?></span>
                                            </div>
                                            
                                            <h5 class="card-title fw-bold text-dark mb-2"><?php echo htmlspecialchars($service['service_name']); ?></h5>
                                            
                                            <div class="d-flex align-items-center text-muted mb-3 small">
                                                <i class="bi bi-clock-history me-2"></i>
                                                <span><?php echo $service['estimated_duration_mins']; ?> mins</span>
                                            </div>

                                            <div class="d-flex flex-wrap gap-2 mt-3">
                                                <?php if ($service['is_installment_available']): ?>
                                                    <span class="badge bg-warning text-dark border border-warning bg-opacity-25">
                                                        <i class="bi bi-cash-coin me-1"></i> Installment Available
                                                    </span>
                                                <?php endif; ?>
                                                
                                                <?php if ($service['requires_checkup']): ?>
                                                    <span class="badge bg-info text-primary border border-info bg-opacity-10">
                                                        <i class="bi bi-clipboard-pulse me-1"></i> Check-up Required
                                                    </span>
                                                <?php endif; ?>

                                                <?php if ($service['requires_xray']): ?>
                                                    <span class="badge bg-secondary text-dark border border-secondary bg-opacity-10">
                                                        <i class="bi bi-camera me-1"></i> X-ray Required
                                                    </span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="modal-footer border-0 bg-white p-4 justify-content-center">
                     <a href="landing.php" class="btn btn-gold px-5 rounded-pill fw-bold">Book an Appointment Now</a>
                </div>
            </div>
        </div>
    </div>

    <!-- FAQ Section -->
    <section id="faq" class="py-5">
        <div class="container py-5">
            <div class="section-title">
                <h2>F.A.Q</h2>
                <p>Frequently Asked Questions</p>
            </div>
            <div class="row justify-content-center">
                <div class="col-lg-8">
                    <div class="accordion" id="faqAccordion">
                        <div class="accordion-item">
                            <h2 class="accordion-header">
                                <button class="accordion-button" type="button" data-bs-toggle="collapse" data-bs-target="#faq1">
                                    Do I need to book an appointment?
                                </button>
                            </h2>
                            <div id="faq1" class="accordion-collapse collapse show" data-bs-parent="#faqAccordion">
                                <div class="accordion-body text-secondary">
                                    We highly recommend booking an appointment to avoid long wait times, but we also accept walk-ins based on doctor availability.
                                </div>
                            </div>
                        </div>
                        <!-- ... (Other FAQs remain same, just ensuring context) -->
                        <div class="accordion-item">
                            <h2 class="accordion-header">
                                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faq2">
                                    What payment methods do you accept?
                                </button>
                            </h2>
                            <div id="faq2" class="accordion-collapse collapse" data-bs-parent="#faqAccordion">
                                <div class="accordion-body text-secondary">
                                    We accept Cash, GCash, and Bank Transfers.
                                </div>
                            </div>
                        </div>
                        <div class="accordion-item">
                            <h2 class="accordion-header">
                                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faq3">
                                    Where is the clinic located?
                                </button>
                            </h2>
                            <div id="faq3" class="accordion-collapse collapse" data-bs-parent="#faqAccordion">
                                <div class="accordion-body text-secondary">
                                    We are located at 0038 Nr. Benigno Aquino Ave. Stall 2, Zone 5 Bagong Nayon Baliwag Bulacan , Baliuag, Philippines, Between BPI and High Precision, above Ericahlicious Brew & Bake.
                                </div>
                            </div>
                        </div>
                        <div class="accordion-item">
                            <h2 class="accordion-header">
                                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faq4">
                                    Do you offer dental braces?
                                </button>
                            </h2>
                            <div id="faq4" class="accordion-collapse collapse" data-bs-parent="#faqAccordion">
                                <div class="accordion-body text-secondary">
                                    Yes! We offer comprehensive orthodontic treatments including metal and ceramic braces.
                                </div>
                            </div>
                        </div>
                        <div class="accordion-item">
                            <h2 class="accordion-header">
                                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faq5">
                                    Is the procedure painful?
                                </button>
                            </h2>
                            <div id="faq5" class="accordion-collapse collapse" data-bs-parent="#faqAccordion">
                                <div class="accordion-body text-secondary">
                                    We use modern techniques and anesthesia to ensure your treatment is as comfortable and pain-free as possible.
                                </div>
                            </div>
                        </div>
                        <div class="accordion-item">
                            <h2 class="accordion-header">
                                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faq6">
                                    How often should I visit the dentist?
                                </button>
                            </h2>
                            <div id="faq6" class="accordion-collapse collapse" data-bs-parent="#faqAccordion">
                                <div class="accordion-body text-secondary">
                                    It is recommended to visit every 6 months for a routine checkup and cleaning to maintain optimal oral health.
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Location Section -->
    <section id="location" class="py-5 bg-light">
        <div class="container py-5">
            <div class="section-title">
                <h2>Our Location</h2>
                <p>Visit Us Today</p>
            </div>
            <div class="row g-4 align-items-center">
                <div class="col-lg-6">
                    <div class="rounded-4 overflow-hidden shadow-lg bg-white p-2">
                        <!-- Google Map Embed using exact coordinates for pinpoint accuracy -->
                        <div class="bg-light rounded-3 overflow-hidden" style="height: 450px;">
                            <iframe width="100%" height="100%" frameborder="0" scrolling="no" marginheight="0" marginwidth="0" src="https://maps.google.com/maps?q=14.960528,120.898167&z=17&output=embed"></iframe>
                        </div>
                    </div>
                </div>
                <div class="col-lg-6 ps-lg-5">
                    <div class="info-box mb-4">
                        <h3 class="fw-bold mb-3 text-dark">Vanessa Nicolas Dental Clinic</h3>
                        <p class="text-secondary mb-4">We are conveniently located in the heart of Baliwag, accessible by public transport and private vehicles.</p>
                    </div>
                    
                    <div class="d-flex align-items-start mb-4">
                        <div class="icon-box-sm bg-primary text-white rounded-circle p-3 me-3">
                            <i class="bi bi-geo-alt-fill fs-5"></i>
                        </div>
                        <div>
                            <h5 class="fw-bold mb-1">Address</h5>
                            <p class="text-secondary mb-0">0038 Nr. Benigno Aquino Ave. Stall 2<br>Zone 5 Bagong Nayon, Baliwag, Bulacan<br>(Between BPI and High Precision, above Ericahlicious Brew & Bake)</p>
                        </div>
                    </div>

                    <div class="d-flex align-items-start mb-4">
                        <div class="icon-box-sm bg-primary text-white rounded-circle p-3 me-3">
                            <i class="bi bi-envelope-fill fs-5"></i>
                        </div>
                        <div>
                            <h5 class="fw-bold mb-1">Email Us</h5>
                            <p class="text-secondary mb-0">nicolasdentalclinic@gmail.com</p>
                        </div>
                    </div>

                    <div class="d-flex align-items-start">
                        <div class="icon-box-sm bg-primary text-white rounded-circle p-3 me-3">
                            <i class="bi bi-telephone-fill fs-5"></i>
                        </div>
                        <div>
                            <h5 class="fw-bold mb-1">Call Us</h5>
                            <p class="text-secondary mb-0">0981 398 7800</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Footer -->
    <footer id="contact" class="footer">
        <div class="container">
            <div class="row g-4 justify-content-between">
                <div class="col-lg-4">
                    <h4>Vanessa Nicolas Dental</h4>
                    <p>Providing quality dental care with a gentle touch. Your smile is our priority.</p>
                    <div class="mt-4">
                        <a href="https://www.facebook.com/vanessanicolasdentalclinic.baliuagbranch" target="_blank" class="text-white me-3"><i class="bi bi-facebook fs-4"></i></a>
                        <a href="https://www.instagram.com/vanessanicolasdentalclinic/?fbclid=IwY2xjawPT2ANleHRuA2FlbQIxMABicmlkETFqM3JLd1dFeWtPc3o4ZXhqc3J0YwZhcHBfaWQQMjIyMDM5MTc4ODIwMDg5MgABHpjJtc38ZqFbT1PzN86CcG5HJxgHzmVw6jrfARqyigR_yIi0E-RjEN_Ge3WA_aem_FWbsrGIubrgM6vzZxrgmXw#" target="_blank" class="text-white me-3"><i class="bi bi-instagram fs-4"></i></a>
                    </div>
                </div>
                <div class="col-lg-4">
                    <h4>Quick Links</h4>
                     <ul class="list-unstyled text-secondary">
                        <li><a href="#hero" class="text-white-50 text-decoration-none">Home</a></li>
                        <li><a href="#about" class="text-white-50 text-decoration-none">About Us</a></li>
                        <li><a href="#services" class="text-white-50 text-decoration-none">Services</a></li>
                        <li><a href="#location" class="text-white-50 text-decoration-none">Location</a></li>
                    </ul>
                </div>
                <div class="col-lg-3">
                    <h4>Clinic Hours</h4>
                    <p class="mb-3 text-secondary">
                        <i class="bi bi-clock-fill me-2 text-warning"></i> Open Hours
                    </p>
                    
                    <?php if (!empty($clinic_schedule)): ?>
                        <ul class="list-unstyled text-white-50 small">
                            <?php foreach ($clinic_schedule as $sched): ?>
                            <li class="d-flex justify-content-between mb-1" style="max-width: 200px;">
                                <span><?php echo substr($sched['day_of_week'], 0, 3); ?>:</span>
                                <span><?php echo formatTime($sched['start_time']) . ' - ' . formatTime($sched['end_time']); ?></span>
                            </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php else: ?>
                        <!-- Fallback if DB fetch fails or is empty -->
                        <p><i class="bi bi-clock-fill me-2 text-warning"></i> Monday - Saturday</p>
                        <p class="ms-4 fw-bold text-white">9:00 AM - 6:00 PM</p>
                        <p><i class="bi bi-clock-fill me-2 text-warning"></i> Sunday</p>
                        <p class="ms-4 fw-bold text-white">9:00 AM - 4:00 PM</p>
                    <?php endif; ?>
                </div>
            </div>
            <div class="row mt-5">
                <div class="col-12 text-center">
                    <p class="text-white-50 mb-0">&copy; 2024 Vanessa Nicolas Dental Clinic. All Rights Reserved.</p>
                </div>
            </div>
            <div class="row mt-4 pt-4 border-top border-secondary">
                <div class="col-md-12 text-center text-secondary small">
                    &copy; 2026 Vanessa Nicolas Dental Clinic. All Rights Reserved.
                </div>
            </div>
        </div>
    </footer>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
