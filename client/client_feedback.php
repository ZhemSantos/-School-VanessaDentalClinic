<?php
session_start();
require_once '../db.php';

if (!isset($_SESSION['patient_id'])) {
    header("Location: ../landing.php");
    exit;
}

$patient_name = $_SESSION['patient_name'] ?? 'Patient';
$patient_id = $_SESSION['patient_id'] ?? 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Feedback - Vanessa Nicolas Dental Clinic</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@200..800&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        "royal-purple": "#6A1B9A",
                        "clinic-gold": "#FFD700",
                        "background-light": "#f7f6f8",
                    },
                    fontFamily: {
                        "display": ["Manrope", "sans-serif"]
                    },
                },
            },
        }
    </script>
    <style>
        .scrollbar-hide::-webkit-scrollbar { display: none; }
        .scrollbar-hide { -ms-overflow-style: none; scrollbar-width: none; }
        
        /* Star Rating */
        .star-group { display: flex; flex-direction: row-reverse; justify-content: flex-end; gap: 4px; }
        .star-group input { display: none; }
        .star-group label { cursor: pointer; color: #cbd5e1; transition: color 0.2s; }
        .star-group label:hover,
        .star-group label:hover ~ label,
        .star-group input:checked ~ label { color: #FFD700; }
        .star-group label .material-symbols-outlined { font-variation-settings: 'FILL' 1; font-size: 28px; }
    </style>
</head>
<body class="bg-background-light font-display antialiased overflow-hidden">

<div class="flex h-screen w-full">
    <!-- Sidebar -->
    <aside class="w-72 bg-royal-purple text-white flex flex-col flex-shrink-0 h-full shadow-2xl z-20 relative">
        <div class="p-8 pb-4">
            <a href="client_dashboard.php" class="flex flex-col gap-1">
                <h1 class="text-xl font-bold leading-tight tracking-wide">Vanessa Nicolas</h1>
                <p class="text-white/70 text-sm font-medium">Dental Clinic System</p>
            </a>
        </div>
        
        <!-- User Widget -->
        <div class="px-8 py-4 mb-2">
            <a href="client_profile_info.php" class="block">
                <div class="flex items-center gap-3 bg-white/10 p-3 rounded-xl border border-white/10 backdrop-blur-sm hover:bg-white/20 transition-all cursor-pointer">
                    <div class="w-10 h-10 rounded-full bg-clinic-gold text-royal-purple flex items-center justify-center font-bold text-lg flex-shrink-0">
                        <?php echo strtoupper(substr($patient_name, 0, 1)); ?>
                    </div>
                <div class="flex flex-col overflow-hidden">
                    <span class="text-sm font-bold truncate"><?php echo htmlspecialchars($patient_name); ?></span>
                    <span class="text-xs text-white/70 truncate">ID: <?php echo str_pad($patient_id, 4, '0', STR_PAD_LEFT); ?></span>
                </div>
            </div>
            </a>
        </div>
        
        <!-- Navigation -->
        <nav class="flex-1 px-4 gap-2 flex flex-col overflow-y-auto py-4">
            <a class="flex items-center gap-4 px-4 py-3 rounded-xl hover:bg-white/10 text-white/90 hover:text-white transition-all group" href="client_dashboard.php">
                <span class="material-symbols-outlined">dashboard</span>
                <span class="font-medium">Dashboard</span>
            </a>
            <a class="flex items-center gap-4 px-4 py-3 rounded-xl hover:bg-white/10 text-white/90 hover:text-white transition-all group" href="client_appointment.php">
                <span class="material-symbols-outlined">calendar_month</span>
                <span class="font-medium">Appointments</span>
            </a>
            <a class="flex items-center gap-4 px-4 py-3 rounded-xl hover:bg-white/10 text-white/90 hover:text-white transition-all group" href="client_payment.php">
                <span class="material-symbols-outlined">payments</span>
                <span class="font-medium">Payments</span>
            </a>
            <a class="flex items-center gap-4 px-4 py-3 rounded-xl bg-white text-royal-purple shadow-lg transition-all group" href="client_feedback.php">
                <span class="material-symbols-outlined">reviews</span>
                <span class="font-bold">Feedback</span>
            </a>
            <a class="flex items-center gap-4 px-4 py-3 rounded-xl hover:bg-white/10 text-white/90 hover:text-white transition-all group" href="client_medical_records.php">
                <span class="material-symbols-outlined">medical_information</span>
                <span class="font-medium">Medical Records</span>
            </a>
            <a class="flex items-center gap-4 px-4 py-3 rounded-xl hover:bg-white/10 text-white/90 hover:text-white transition-all group" href="client_dependencies.php">
                <span class="material-symbols-outlined">group</span>
                <span class="font-medium">My Dependents</span>
            </a>
        </nav>
        
        <!-- Footer -->
        <div class="p-6">
            <div class="text-xs text-white/40 text-center font-medium">v2.0.0 • Client Portal</div>
            <a href="../logout.php" class="flex items-center justify-center gap-2 mt-4 w-full py-2 rounded-xl text-white/70 hover:text-white hover:bg-white/10 transition-colors text-sm font-medium">
                <span class="material-symbols-outlined text-[18px]">logout</span>
                Logout
            </a>
        </div>
    </aside>

    <!-- Main Content -->
    <main class="flex-1 flex flex-col h-full overflow-hidden bg-background-light relative">
        <!-- Header -->
        <header class="w-full px-8 py-6 flex items-start justify-between bg-white/50 backdrop-blur-md sticky top-0 z-10 border-b border-gray-100">
            <div class="flex flex-col gap-1">
                <h2 class="text-3xl font-black text-[#151118] tracking-tight">Feedback</h2>
                <p class="text-slate-500 font-medium flex items-center gap-2">
                    <span class="material-symbols-outlined text-lg">rate_review</span>
                    Rate your experience with us.
                </p>
            </div>
        </header>

        <!-- Content Area -->
        <div class="flex-1 overflow-y-auto px-8 py-6 scrollbar-hide">
            <div class="grid gap-8 lg:grid-cols-2">
                <!-- Pending Feedback -->
                <div>
                    <h3 class="text-lg font-bold text-slate-800 mb-4 flex items-center gap-2">
                        <span class="material-symbols-outlined text-purple-600">rate_review</span>
                        Rate Recent Visits
                    </h3>
                    <div id="pendingContainer" class="space-y-3">
                        <div class="animate-pulse bg-white h-24 rounded-2xl"></div>
                    </div>
                </div>

                <!-- History -->
                <div>
                    <h3 class="text-lg font-bold text-slate-800 mb-4 flex items-center gap-2">
                        <span class="material-symbols-outlined text-slate-400">history_edu</span>
                        My Reviews
                    </h3>
                    <div id="historyContainer" class="space-y-3">
                        <div class="animate-pulse bg-white h-24 rounded-2xl"></div>
                    </div>
                </div>
            </div>
        </div>
    </main>
</div>

<!-- Feedback Modal -->
<div id="feedbackModal" class="fixed inset-0 z-50 hidden">
    <div class="absolute inset-0 bg-slate-900/60 backdrop-blur-sm" onclick="closeFeedbackModal()"></div>
    <div class="absolute inset-y-0 right-0 w-full max-w-lg bg-white shadow-2xl overflow-y-auto transform transition-transform translate-x-full scrollbar-hide" id="feedbackPanel">
        <div class="p-6 border-b border-gray-100 flex justify-between items-center bg-gradient-to-r from-royal-purple to-purple-700">
            <div>
                <h2 class="text-xl font-black text-white">Submit Feedback</h2>
                <p class="text-sm text-white/80" id="feedbackSubtitle">Invoice #...</p>
            </div>
            <button onclick="closeFeedbackModal()" class="w-8 h-8 rounded-full bg-white/20 text-white hover:bg-white/30 flex items-center justify-center transition-all">
                <span class="material-symbols-outlined text-lg">close</span>
            </button>
        </div>

        <div class="p-6 space-y-8">
            <input type="hidden" id="fbInvoiceId">

            <!-- Booking Rating -->
            <div>
                <h3 class="text-sm font-bold text-slate-800 uppercase mb-2">1. Booking Experience</h3>
                <p class="text-xs text-slate-500 mb-3">How was the appointment scheduling process?</p>
                <div class="star-group justify-start">
                    <input type="radio" id="b5" name="booking" value="5"><label for="b5"><span class="material-symbols-outlined">star</span></label>
                    <input type="radio" id="b4" name="booking" value="4"><label for="b4"><span class="material-symbols-outlined">star</span></label>
                    <input type="radio" id="b3" name="booking" value="3"><label for="b3"><span class="material-symbols-outlined">star</span></label>
                    <input type="radio" id="b2" name="booking" value="2"><label for="b2"><span class="material-symbols-outlined">star</span></label>
                    <input type="radio" id="b1" name="booking" value="1"><label for="b1"><span class="material-symbols-outlined">star</span></label>
                </div>
            </div>

            <!-- Procedure Rating -->
            <div>
                <h3 class="text-sm font-bold text-slate-800 uppercase mb-2">2. Procedure Experience</h3>
                <p class="text-xs text-slate-500 mb-3">How was the medical service provided?</p>
                <div class="star-group justify-start">
                    <input type="radio" id="p5" name="procedure" value="5"><label for="p5"><span class="material-symbols-outlined">star</span></label>
                    <input type="radio" id="p4" name="procedure" value="4"><label for="p4"><span class="material-symbols-outlined">star</span></label>
                    <input type="radio" id="p3" name="procedure" value="3"><label for="p3"><span class="material-symbols-outlined">star</span></label>
                    <input type="radio" id="p2" name="procedure" value="2"><label for="p2"><span class="material-symbols-outlined">star</span></label>
                    <input type="radio" id="p1" name="procedure" value="1"><label for="p1"><span class="material-symbols-outlined">star</span></label>
                </div>
            </div>

            <!-- Payment Rating -->
            <div>
                <h3 class="text-sm font-bold text-slate-800 uppercase mb-2">3. Payment Process</h3>
                <p class="text-xs text-slate-500 mb-3">How easy was the billing and payment?</p>
                <div class="star-group justify-start">
                    <input type="radio" id="m5" name="payment" value="5"><label for="m5"><span class="material-symbols-outlined">star</span></label>
                    <input type="radio" id="m4" name="payment" value="4"><label for="m4"><span class="material-symbols-outlined">star</span></label>
                    <input type="radio" id="m3" name="payment" value="3"><label for="m3"><span class="material-symbols-outlined">star</span></label>
                    <input type="radio" id="m2" name="payment" value="2"><label for="m2"><span class="material-symbols-outlined">star</span></label>
                    <input type="radio" id="m1" name="payment" value="1"><label for="m1"><span class="material-symbols-outlined">star</span></label>
                </div>
            </div>

            <!-- Comments -->
            <div>
                <h3 class="text-sm font-bold text-slate-800 uppercase mb-2">4. Comments (Optional)</h3>
                <textarea id="fbComments" rows="4" class="w-full p-4 bg-white border border-slate-300 rounded-xl focus:outline-none focus:border-purple-500 focus:ring-4 focus:ring-purple-500/10 transition-all" placeholder="Share your thoughts..."></textarea>
            </div>

            <button onclick="submitFeedback()" id="btnSubmit" class="w-full py-4 bg-clinic-gold hover:bg-yellow-400 text-royal-purple font-bold rounded-xl shadow-lg shadow-yellow-400/20 transition-all flex items-center justify-center gap-2">
                <span class="material-symbols-outlined">check</span>
                Submit Review
            </button>
        </div>
    </div>
</div>

<script>
    const API_URL = '../api/api_feedback.php';

    document.addEventListener('DOMContentLoaded', () => {
        loadEligible();
        loadHistory();
    });

    function loadEligible() {
        fetch(`${API_URL}?action=eligible`)
            .then(r => r.json())
            .then(d => {
                if(d.success) renderPending(d.invoices);
            });
    }

    function loadHistory() {
        fetch(`${API_URL}?action=history`)
            .then(r => r.json())
            .then(d => {
                if(d.success) renderHistory(d.feedback);
            });
    }

    function renderPending(list) {
        const container = document.getElementById('pendingContainer');
        if(!list || list.length === 0) {
            container.innerHTML = `<div class="p-6 bg-white rounded-2xl border border-dashed border-slate-200 text-center text-slate-400 text-sm">No new invoices to rate.</div>`;
            return;
        }
        container.innerHTML = list.map(item => `
            <div class="bg-white p-5 rounded-2xl border border-purple-100 shadow-sm flex flex-col gap-3 hover:border-purple-300 hover:shadow-md transition-all">
                <div class="flex justify-between items-start">
                    <div>
                        <p class="font-bold text-slate-800">${item.service_name || 'Medical Service'}</p>
                        <p class="text-xs text-slate-500">Visit: ${formatDate(item.appointment_date)}</p>
                    </div>
                    <span class="px-2 py-0.5 bg-green-50 text-green-700 text-[10px] font-bold rounded-full">Paid</span>
                </div>
                <button onclick="openFeedbackModal(${item.invoice_id}, '${item.reference_number || item.invoice_id}', '${item.service_name}')" class="w-full py-3 bg-gradient-to-r from-purple-600 to-purple-700 text-white font-bold text-sm rounded-xl hover:from-purple-700 hover:to-purple-800 transition-all shadow-sm">
                    Rate Experience
                </button>
            </div>
        `).join('');
    }

    function renderHistory(list) {
        const container = document.getElementById('historyContainer');
        if(!list || list.length === 0) {
            container.innerHTML = `<div class="p-6 bg-white rounded-2xl border border-dashed border-slate-200 text-center text-slate-400 text-sm">No reviews yet.</div>`;
            return;
        }
        container.innerHTML = list.map(item => `
            <div class="bg-white p-5 rounded-2xl border border-gray-100 shadow-sm hover:shadow-md transition-shadow">
                <div class="flex justify-between items-center mb-3">
                    <div class="flex items-center gap-2">
                        <span class="material-symbols-outlined text-2xl text-yellow-400" style="font-variation-settings:'FILL' 1">star</span>
                        <span class="font-black text-xl text-slate-800">${parseFloat(item.overall_rating).toFixed(1)}</span>
                    </div>
                    <span class="text-[10px] text-slate-400 font-bold uppercase">${item.date_fmt}</span>
                </div>
                ${item.comments ? `<p class="text-sm text-slate-600 italic mb-3">"${item.comments}"</p>` : ''}
                <div class="flex gap-2 overflow-x-auto text-[10px] font-medium">
                    <span class="px-3 py-1.5 bg-blue-50 text-blue-700 rounded-lg whitespace-nowrap">Booking: ${item.booking_rating}/5</span>
                    <span class="px-3 py-1.5 bg-purple-50 text-purple-700 rounded-lg whitespace-nowrap">Procedure: ${item.procedure_rating}/5</span>
                    <span class="px-3 py-1.5 bg-green-50 text-green-700 rounded-lg whitespace-nowrap">Payment: ${item.payment_rating}/5</span>
                </div>
            </div>
        `).join('');
    }

    // Modal
    const modal = document.getElementById('feedbackModal');
    const panel = document.getElementById('feedbackPanel');

    function openFeedbackModal(id, ref, service) {
        document.getElementById('fbInvoiceId').value = id;
        document.getElementById('feedbackSubtitle').textContent = `Reviewing: ${service || 'Service'} (Inv #${ref})`;
        
        document.querySelectorAll('input[type="radio"]').forEach(el => el.checked = false);
        document.getElementById('fbComments').value = '';

        modal.classList.remove('hidden');
        setTimeout(() => panel.classList.remove('translate-x-full'), 10);
    }

    function closeFeedbackModal() {
        panel.classList.add('translate-x-full');
        setTimeout(() => modal.classList.add('hidden'), 300);
    }

    function submitFeedback() {
        const id = document.getElementById('fbInvoiceId').value;
        const b = document.querySelector('input[name="booking"]:checked')?.value;
        const p = document.querySelector('input[name="procedure"]:checked')?.value;
        const m = document.querySelector('input[name="payment"]:checked')?.value;
        const comments = document.getElementById('fbComments').value;

        if(!b || !p || !m) {
            Swal.fire('Incomplete', 'Please rate all 3 categories.', 'warning');
            return;
        }

        const btn = document.getElementById('btnSubmit');
        const originalText = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = 'Submitting...';

        fetch(`${API_URL}?action=submit`, {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({
                invoice_id: id,
                booking_rating: b,
                procedure_rating: p,
                payment_rating: m,
                comments: comments
            })
        })
        .then(r => r.json())
        .then(d => {
            btn.disabled = false;
            btn.innerHTML = originalText;
            
            if(d.success) {
                closeFeedbackModal();
                Swal.fire('Thank You', 'Your feedback has been submitted!', 'success');
                loadEligible();
                loadHistory();
            } else {
                Swal.fire('Error', d.message, 'error');
            }
        });
    }

    function formatDate(d) {
        return new Date(d).toLocaleDateString('en-US', {month:'short', day:'numeric', year:'numeric'});
    }
</script>
</body>
</html>
