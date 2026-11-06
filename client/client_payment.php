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
    <title>My Payments - Vanessa Nicolas Dental Clinic</title>
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
            <a class="flex items-center gap-4 px-4 py-3 rounded-xl bg-white text-royal-purple shadow-lg transition-all group" href="client_payment.php">
                <span class="material-symbols-outlined">payments</span>
                <span class="font-bold">Payments</span>
            </a>
            <a class="flex items-center gap-4 px-4 py-3 rounded-xl hover:bg-white/10 text-white/90 hover:text-white transition-all group" href="client_feedback.php">
                <span class="material-symbols-outlined">reviews</span>
                <span class="font-medium">Feedback</span>
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
                <div class="flex items-center gap-3">
                    <h2 class="text-3xl font-black text-[#151118] tracking-tight">Payments</h2>
                    <!-- Discount Status Badge -->
                    <div id="verifiedDiscountBadge" class="hidden px-3 py-1 bg-purple-100 border border-purple-200 rounded-full flex items-center gap-2 animate-fade-in">
                        <span class="material-symbols-outlined text-purple-600 text-sm">verified</span>
                        <span id="verifiedDiscountText" class="text-xs font-bold text-purple-700 uppercase tracking-wide">Verified</span>
                    </div>
                </div>
                <p class="text-slate-500 font-medium flex items-center gap-2">
                    <span class="material-symbols-outlined text-lg">account_balance_wallet</span>
                    View pending dues and make GCash payments.
                </p>
            </div>
            <button onclick="showReceiptHistory()" class="flex items-center gap-2 px-5 py-2.5 bg-gradient-to-r from-purple-600 to-purple-700 hover:from-purple-700 hover:to-purple-800 text-white font-bold rounded-lg transition-all shadow-lg hover:shadow-xl">
                <span class="material-symbols-outlined text-xl">receipt_long</span>
                <span>Receipt History</span>
            </button>
        </header>

        <!-- Content Area -->
        <div class="flex-1 overflow-y-auto px-8 py-6 scrollbar-hide">
            <!-- Payment Methods Alert -->
            <div class="mb-8 p-4 bg-gradient-to-r from-blue-50 to-indigo-50 border border-blue-100 rounded-2xl flex items-start gap-4">
                <div class="w-12 h-12 bg-blue-100 text-blue-600 rounded-xl flex items-center justify-center flex-shrink-0">
                    <span class="material-symbols-outlined text-2xl">account_balance_wallet</span>
                </div>
                <div>
                    <h3 class="font-bold text-blue-900 mb-1">GCash Payment Only</h3>
                    <p class="text-sm text-blue-700">
                        For online payments, we currently accept GCash. Please ensure you have your reference number ready after sending payment.
                    </p>
                </div>
            </div>

            <div class="grid gap-8 lg:grid-cols-2">
                <!-- Appointment Fees -->
                <div>
                    <h3 class="text-lg font-bold text-slate-800 mb-4 flex items-center gap-2">
                        <span class="material-symbols-outlined text-purple-600">receipt_long</span>
                        Pending Appointment Fees
                    </h3>
                    <div id="apptFeeContainer" class="space-y-3">
                        <!-- JS Injected -->
                        <div class="animate-pulse bg-white h-24 rounded-2xl"></div>
                    </div>
                </div>

                <!-- Unpaid Invoices -->
                <div>
                    <h3 class="text-lg font-bold text-slate-800 mb-4 flex items-center gap-2">
                        <span class="material-symbols-outlined text-orange-500">warning</span>
                        Unpaid Medical Invoices
                    </h3>
                    <div id="invoiceContainer" class="space-y-3">
                        <div class="animate-pulse bg-white h-24 rounded-2xl"></div>
                    </div>
                </div>
                
                <!-- Installments -->
                <div class="lg:col-span-2">
                    <h3 class="text-lg font-bold text-slate-800 mb-4 flex items-center gap-2">
                        <span class="material-symbols-outlined text-teal-600">credit_score</span>
                        Active Installments
                    </h3>
                    <div id="installmentContainer" class="grid sm:grid-cols-2 gap-4">
                        <div class="animate-pulse bg-white h-32 rounded-2xl"></div>
                    </div>
                
                <!-- Payment History Section -->
                <div class="mt-12">
                    <div class="flex items-center justify-between mb-4">
                        <h3 class="text-lg font-bold text-slate-800 flex items-center gap-2">
                            <span class="material-symbols-outlined text-green-600">history</span>
                            Payment History
                        </h3>
                    </div>
                    
                    <!-- Filters & Export -->
                    <div class="bg-white p-4 rounded-2xl border border-slate-200 mb-4 flex flex-wrap items-end gap-3">
                        <div class="flex-1 min-w-[150px]">
                            <label class="block text-xs font-bold text-slate-500 uppercase mb-1">From Date</label>
                            <input type="date" id="historyStartDate" onchange="loadPaymentHistory()" 
                                class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:border-purple-500 focus:outline-none text-sm">
                        </div>
                        <div class="flex-1 min-w-[150px]">
                            <label class="block text-xs font-bold text-slate-500 uppercase mb-1">To Date</label>
                            <input type="date" id="historyEndDate" onchange="loadPaymentHistory()" 
                                class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:border-purple-500 focus:outline-none text-sm">
                        </div>
                        <div class="flex-1 min-w-[150px]">
                            <label class="block text-xs font-bold text-slate-500 uppercase mb-1">Payment Type</label>
                            <select id="historyTypeFilter" onchange="loadPaymentHistory()" 
                                class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:border-purple-500 focus:outline-none text-sm">
                                <option value="">All Types</option>
                                <option value="Medical Invoice">Medical Invoice</option>
                                <option value="Appointment Fee">Appointment Fee</option>
                                <option value="Installment">Installment</option>
                            </select>
                        </div>
                        <button onclick="clearHistoryFilters()" class="px-4 py-2 bg-slate-100 hover:bg-slate-200 text-slate-700 font-bold rounded-lg transition-colors text-sm flex items-center gap-2">
                            <span class="material-symbols-outlined text-lg">filter_alt_off</span>
                            Clear
                        </button>
                        <button onclick="exportHistoryToPDF()" class="px-4 py-2 bg-gradient-to-r from-red-600 to-red-700 hover:from-red-700 hover:to-red-800 text-white font-bold rounded-lg transition-all shadow-lg flex items-center gap-2 text-sm">
                            <span class="material-symbols-outlined text-lg">picture_as_pdf</span>
                            Export PDF
                        </button>
                    </div>
                    
                    <!-- History Cards with Fixed Height -->
                    <div id="paymentHistoryContainer" class="space-y-3 max-h-[400px] overflow-y-auto">
                        <div class="animate-pulse bg-white h-24 rounded-2xl"></div>
                    </div>
                </div>
            </div>
        </div>
    </main>
</div>

    <!-- Payment Modal -->
    <div id="paymentModal" class="fixed inset-0 z-50 hidden">
        <div class="absolute inset-0 bg-slate-900/60 backdrop-blur-sm" onclick="closePaymentModal()"></div>
        <div class="absolute top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 bg-white w-full max-w-md rounded-2xl shadow-2xl overflow-hidden p-6 transform transition-all scale-100 max-h-[90vh] overflow-y-auto" id="paymentPanel">
            <div class="text-center mb-6">
                <div class="w-16 h-16 bg-gradient-to-br from-blue-500 to-indigo-600 text-white rounded-full flex items-center justify-center mx-auto mb-3">
                    <span class="material-symbols-outlined text-3xl">qr_code_scanner</span>
                </div>
                <h2 class="text-xl font-black text-slate-800">Confirm Payment</h2>
                <p class="text-sm text-slate-500">Paying for <span id="lblPaymentType" class="font-bold">Item</span></p>
            </div>
            
            <!-- Details Section -->
            <div id="paymentDetailsContainer" class="hidden mb-6 p-4 bg-slate-50 rounded-xl text-sm border border-slate-100 text-slate-600 space-y-2">
                <!-- Injected via JS -->
            </div>
            
            <!-- Amount Section (Editable) -->
            <div class="mb-6 p-4 bg-slate-50 rounded-xl text-center border border-slate-100 hidden" id="amountSection">
                <label class="block text-xs text-slate-500 uppercase font-bold mb-2">Amount to Pay</label>
                <div class="relative w-2/3 mx-auto">
                    <span class="absolute left-3 top-1/2 -translate-y-1/2 font-black text-slate-400">₱</span>
                    <input type="number" step="0.01" id="payAmountInput" class="w-full pl-8 pr-4 py-3 bg-white border border-slate-200 rounded-xl font-black text-2xl text-slate-800 text-center focus:outline-none focus:border-clinic-gold transition-colors" placeholder="0.00">
                </div>
                <!-- Helper Text for Limits -->
                <p class="text-xs text-slate-400 mt-2" id="amountDetailsText">Min: ₱500.00</p>
            </div>
            
            <!-- Standard Amount Display (For Fixed Invoices) -->
            <div class="mb-6 text-center" id="amountDisplayFixed">
                 <p class="text-xs text-slate-400 uppercase font-bold mb-1">Total Amount Due</p>
                 <p class="text-4xl font-black text-royal-purple" id="lblAmountMain">₱0.00</p>
            </div>
    
            <!-- Discount Section (Auto-applied if verified) -->
            <div id="discountSection" class="hidden mb-6 p-4 bg-gradient-to-r from-green-50 to-emerald-50 border-l-4 border-green-500 rounded-xl">
                <div class="flex items-center gap-2 mb-3">
                    <span class="material-symbols-outlined text-green-600">verified</span>
                    <p class="font-bold text-green-800">Valid Discount: <span id="discountTypeBadge" class="px-2 py-0.5 bg-green-100 rounded text-sm">PWD</span></p>
                </div>
                <div class="space-y-2 text-sm">
                    <div class="flex justify-between text-slate-600">
                        <span>Subtotal (VAT-Inclusive):</span>
                        <span id="subtotalAmountDisplay" class="font-semibold">₱0.00</span>
                    </div>
                    <div class="flex justify-between text-green-600 font-semibold">
                        <span>Less: VAT Exemption</span>
                        <span id="vatExemptDisplay">-₱0.00</span>
                    </div>
                    <div class="flex justify-between text-green-600 font-semibold">
                        <span>Less: 20% Discount</span>
                        <span id="discountAmountDisplay">-₱0.00</span>
                    </div>
                    <div class="flex justify-between font-bold text-green-700 pt-2 border-t border-green-200">
                        <span>Amount to Pay:</span>
                        <span id="finalAmountDisplay">₱0.00</span>
                    </div>
                </div>
                <p class="text-xs text-green-600 mt-3 italic">Verified on: <span id="discountVerifiedDate">N/A</span></p>
            </div>
            
            <!-- Expired/Invalid Discount Warning -->
            <div id="discountExpiredWarning" class="hidden mb-6 p-4 bg-amber-50 border-l-4 border-amber-500 rounded-xl">
                <div class="flex items-center gap-2 mb-2">
                    <span class="material-symbols-outlined text-amber-600">warning</span>
                    <p class="font-bold text-amber-800">Discount Expired</p>
                </div>
                <p class="text-sm text-amber-700" id="discountExpiredMessage">Your discount ID needs renewal. Please visit the clinic to update your information.</p>
            </div>
    
            <!-- GCash QR Code Toggle -->
            <div class="mb-6">
                <button type="button" onclick="document.getElementById('qrCodeContainer').classList.toggle('hidden')" class="w-full py-3 bg-blue-50 hover:bg-blue-100 text-blue-700 font-bold rounded-xl transition-colors flex items-center justify-center gap-2 border border-blue-200">
                    <span class="material-symbols-outlined">qr_code_2</span>
                    View GCash QR Code
                </button>
                
                <div id="qrCodeContainer" class="hidden mt-4 p-4 bg-gradient-to-br from-blue-50 to-indigo-50 rounded-xl border border-blue-100">
                    <p class="text-xs font-bold text-blue-900 uppercase mb-3 text-center">Scan to Pay via GCash</p>
                    <div class="bg-white p-3 rounded-xl shadow-sm mx-auto w-fit">
                        <img src="../images/Gcash.jpg" alt="GCash QR Code" class="w-48 h-48 object-contain mx-auto">
                    </div>
                    <p class="text-[10px] text-blue-700 mt-3 text-center font-medium">
                        Scan this QR code with your GCash app or send to the number shown above
                    </p>
                </div>
            </div>
    
            <form id="paymentForm" onsubmit="event.preventDefault(); submitPayment();">
                <input type="hidden" id="payType">
                <input type="hidden" id="payId">
                <input type="hidden" id="payAmountVal">
                
                <div class="mb-6">
                    <label class="block text-xs font-bold text-slate-500 uppercase mb-2">GCash Reference Number</label>
                    <input type="text" id="refNum" required placeholder="e.g. 1002345689" class="w-full px-4 py-3 bg-white border border-slate-300 rounded-xl font-bold text-slate-800 focus:outline-none focus:border-blue-500 focus:ring-4 focus:ring-blue-500/10 transition-all">
                    <p class="text-[10px] text-slate-400 mt-1">Please double-check the reference number from your SMS/App.</p>
                </div>
    
                <button type="submit" id="btnPay" class="w-full py-4 bg-clinic-gold hover:bg-yellow-400 text-royal-purple font-bold rounded-xl shadow-lg shadow-yellow-400/20 transition-all flex items-center justify-center gap-2">
                    <span class="material-symbols-outlined">check</span>
                    Submit Payment
                </button>
            </form>
            
            <button onclick="closePaymentModal()" class="w-full mt-3 py-3 text-slate-500 font-bold hover:text-slate-800 transition-colors">Cancel</button>
        </div>
    </div>
    
    <!-- Receipt History Modal -->
    <div id="receiptHistoryModal" class="fixed inset-0 z-50 hidden">
        <div class="absolute inset-0 bg-slate-900/60 backdrop-blur-sm" onclick="closeReceiptHistory()"></div>
        <div class="absolute top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 bg-white w-full max-w-4xl rounded-2xl shadow-2xl overflow-hidden p-6 transform transition-all scale-100 max-h-[90vh] overflow-y-auto">
            <div class="flex items-center justify-between mb-6 pb-4 border-b border-slate-200">
                <div>
                    <h2 class="text-2xl font-black text-slate-800">Receipt History</h2>
                    <p class="text-sm text-slate-500">View all your payment receipts</p>
                </div>
                <button onclick="closeReceiptHistory()" class="text-slate-400 hover:text-slate-600 transition-colors">
                    <span class="material-symbols-outlined text-3xl">close</span>
                </button>
            </div>
            
            <!-- Filters -->
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
                <div>
                    <label class="block text-xs font-bold text-slate-500 uppercase mb-2">Search</label>
                    <input type="text" id="receiptSearch" onkeyup="applyReceiptFilters()" placeholder="Doctor, service..." class="w-full px-4 py-2 border border-slate-300 rounded-lg focus:border-purple-500 focus:outline-none">
                </div>
                <div>
                    <label class="block text-xs font-bold text-slate-500 uppercase mb-2">Month/Year</label>
                    <input type="month" id="receiptDateFilter" onchange="applyReceiptFilters()" class="w-full px-4 py-2 border border-slate-300 rounded-lg focus:border-purple-500 focus:outline-none">
                </div>
                <div>
                    <label class="block text-xs font-bold text-slate-500 uppercase mb-2">Payment Type</label>
                    <select id="receiptCategoryFilter" onchange="applyReceiptFilters()" class="w-full px-4 py-2 border border-slate-300 rounded-lg focus:border-purple-500 focus:outline-none">
                        <option value="">All Payment Types</option>
                    </select>
                </div>
            </div>
            
            <!-- Receipt List -->
            <div id="receiptListContainer" class="space-y-4">
                <div class="animate-pulse bg-slate-100 h-24 rounded-xl"></div>
            </div>
        </div>
    </div>

    <script>
        const API_URL = '../api/api_payments.php';
        
        // Global Data Stores
        let gFees = [];
        let gInvoices = [];
        let gInstallments = [];
        let gPatientDiscount = null; // Store patient discount info
    
        document.addEventListener('DOMContentLoaded', () => {
            fetchUnpaid();
            loadPaymentHistory();
            // checkPatientDiscount(); // Removed - data now comes from fetchUnpaid
        });
    
        function fetchUnpaid() {
            fetch(`${API_URL}?action=unpaid`)
                .then(r => r.json())
                .then(d => {
                    if(d.success) {
                        // Store globally
                        gFees = d.appointment_fees || [];
                        gInvoices = d.invoices || [];
                        gInstallments = d.installments || [];
                        gPatientDiscount = d.patient_discount_profile || null; // Store Profile
                        
                        renderFees(gFees);
                        renderInvoices(gInvoices);
                        renderInstallments(gInstallments);
                        
                        // Update Header Badge
                        const badge = document.getElementById('verifiedDiscountBadge');
                        const badgeText = document.getElementById('verifiedDiscountText');
                        const badgeIcon = badge ? badge.querySelector('.material-symbols-outlined') : null;
                        
                        if(badge && badgeText && badgeIcon) {
                            badge.classList.remove('hidden'); // Always show
                            
                            if (gPatientDiscount && gPatientDiscount.has_valid_discount) {
                                // Verified Style (Purple)
                                badge.className = "px-3 py-1 bg-purple-100 border border-purple-200 rounded-full flex items-center gap-2 animate-fade-in";
                                badgeIcon.className = "material-symbols-outlined text-purple-600 text-sm";
                                badgeText.className = "text-xs font-bold text-purple-700 uppercase tracking-wide";
                                badgeText.textContent = `${gPatientDiscount.discount_type} Verified`;
                            } else {
                                // Deactivated Style (Gray)
                                badge.className = "px-3 py-1 bg-slate-100 border border-slate-200 rounded-full flex items-center gap-2 animate-fade-in";
                                badgeIcon.className = "material-symbols-outlined text-slate-400 text-sm";
                                badgeText.className = "text-xs font-bold text-slate-400 uppercase tracking-wide";
                                badgeText.textContent = 'No Verified Discount';
                            }
                        }
                    }
                });
        }
    
        function renderFees(list) {
            const container = document.getElementById('apptFeeContainer');
            if(!list || list.length === 0) {
                container.innerHTML = `<div class="p-6 bg-white rounded-2xl border border-dashed border-slate-200 text-center text-slate-400 text-sm">No pending appointment fees.</div>`;
                return;
            }
            container.innerHTML = list.map((item, index) => `
                <div class="bg-white p-5 rounded-2xl border border-purple-100 shadow-sm flex justify-between items-center group hover:border-purple-300 hover:shadow-md transition-all">
                    <div>
                        <p class="font-bold text-purple-900">${item.doctor_name}</p>
                        <p class="text-xs text-slate-500 mb-1">${formatDate(item.appointment_date)} @ ${formatTime(item.time_slot)}</p>
                        <span class="px-2 py-0.5 bg-yellow-100 text-yellow-700 text-[10px] uppercase font-bold rounded-full">Pending Fee</span>
                    </div>
                    <div class="text-right">
                        <p class="font-black text-lg text-slate-800">₱${parseFloat(item.fee).toLocaleString()}</p>
                        <button onclick="initPayment('appointment_fee', ${index})" class="mt-2 px-4 py-2 bg-clinic-gold hover:bg-yellow-400 text-royal-purple text-xs font-bold rounded-lg transition-colors shadow-sm">Pay Now</button>
                    </div>
                </div>
            `).join('');
        }
    
        function renderInvoices(list) {
            const container = document.getElementById('invoiceContainer');
            if(!list || list.length === 0) {
                container.innerHTML = `<div class="p-6 bg-white rounded-2xl border border-dashed border-slate-200 text-center text-slate-400 text-sm">No unpaid invoices.</div>`;
                return;
            }
            container.innerHTML = list.map((item, index) => `
                <div class="bg-white p-5 rounded-2xl border border-orange-100 shadow-sm flex justify-between items-center group hover:border-orange-300 hover:shadow-md transition-all">
                    <div>
                        <p class="font-bold text-slate-800">Inv #${item.reference_number || item.invoice_id}</p>
                        <p class="text-xs text-slate-500 mb-1">${formatDate(item.created_at)}</p>
                        <p class="text-xs text-slate-700 font-medium">${item.service_names || 'Services Unavailable'}</p>
                        <p class="text-[10px] text-slate-500">Dr. ${item.doctor_name || 'Assigned Doctor'}</p>
                        <span class="inline-block mt-1 px-2 py-0.5 bg-orange-100 text-orange-700 text-[10px] uppercase font-bold rounded-full">Items Due</span>
                    </div>
                    <div class="text-right">
                        <p class="font-black text-lg text-slate-800">₱${parseFloat(item.total_amount).toLocaleString()}</p>
                        <button onclick="initPayment('invoice', ${index})" class="mt-2 px-4 py-2 bg-orange-500 hover:bg-orange-600 text-white text-xs font-bold rounded-lg transition-colors shadow-sm">Pay Now</button>
                    </div>
                </div>
            `).join('');
        }
    
        function renderInstallments(list) {
            const container = document.getElementById('installmentContainer');
            if(!list || list.length === 0) {
                container.innerHTML = `<div class="col-span-full p-6 bg-white rounded-2xl border border-dashed border-slate-200 text-center text-slate-400 text-sm">No active installments.</div>`;
                return;
            }
            container.innerHTML = list.map((item, index) => `
                <div class="bg-white p-5 rounded-2xl border border-teal-100 shadow-sm hover:shadow-md transition-shadow">
                    <div class="flex justify-between items-start mb-4">
                        <div>
                            <p class="font-bold text-slate-800 truncate">${item.service_name}</p>
                            <p class="text-xs text-slate-500">Dr. ${item.doctor_name || 'Assigned Doctor'}</p>
                            <p class="text-[10px] text-slate-400">Installment Plan</p>
                        </div>
                        <span class="px-2 py-0.5 bg-teal-50 text-teal-700 text-[10px] uppercase font-bold rounded-full">Active</span>
                    </div>
                    <div class="space-y-2 mb-4">
                        <div class="flex justify-between text-sm">
                            <span class="text-slate-500">Monthly Due:</span>
                            <span class="font-bold text-slate-700">₱${parseFloat(item.monthly_amount).toLocaleString()}</span>
                        </div>
                        <div class="flex justify-between text-sm">
                            <span class="text-slate-500">Next Due:</span>
                            <span class="font-bold text-slate-700">${formatDate(item.next_payment_date)}</span>
                        </div>
                        <div class="flex justify-between text-sm pt-2 border-t border-slate-50">
                            <span class="text-slate-500">Remaining:</span>
                            <span class="font-bold text-teal-600">₱${parseFloat(item.balance_remaining).toLocaleString()}</span>
                        </div>
                    </div>
                    <button onclick="initPayment('installment', ${index})" class="w-full py-3 bg-teal-600 hover:bg-teal-700 text-white text-sm font-bold rounded-xl transition-colors">
                        Pay Monthly (₱${parseFloat(item.monthly_amount).toLocaleString()})
                    </button>
                </div>
            `).join('');
        }
    
        // Modal Logic
        const modal = document.getElementById('paymentModal');
        
        function initPayment(type, index) {
            let item, amount, id, label;
            let detailsHtml = '';
            let minAmount = 0, maxAmount = 999999;
            let isEditable = true;
            
            // Reset QR code visibility
            document.getElementById('qrCodeContainer').classList.add('hidden');
            
            let payableAmount = 0;
            
            if (type === 'appointment_fee') {
                item = gFees[index];
                id = item.appointment_id;
                amount = parseFloat(item.fee);
                payableAmount = amount;
                label = 'Appointment Fee';
                isEditable = false; // Static - No advance payment for fee anymore
                
                minAmount = amount; 
                maxAmount = amount; // Fixed to fee
                
                detailsHtml = `
                    <div class="flex justify-between">
                        <span>Payment Type:</span>
                        <span class="font-bold">Appointment Fee</span>
                    </div>
                    <div class="flex justify-between">
                        <span>Doctor:</span>
                        <span class="font-bold">Dr. ${item.doctor_name}</span>
                    </div>
                    <div class="flex justify-between">
                        <span>Services:</span>
                        <span class="font-bold text-xs">${item.service_names || 'General Consultation'}</span>
                    </div>
                     <div class="flex justify-between">
                        <span>Amount Due:</span>
                        <span class="font-bold">₱${amount.toLocaleString()}</span>
                    </div>
                `;
                
                document.getElementById('amountDetailsText').textContent = `Total Due: ₱${amount.toFixed(2)}`;
                
            } else if (type === 'invoice') {
                item = gInvoices[index];
                id = item.invoice_id;
                amount = parseFloat(item.total_amount);
                label = 'Medical Invoice';
                
                let feePaid = parseFloat(item.appointment_fee_paid || 0);
                let amountPaid = parseFloat(item.amount_paid || 0);
                
                let remaining = amount - feePaid - amountPaid;
                if (remaining < 0) remaining = 0;
                payableAmount = remaining;

                // Handle Pending Status
                let statusHtml = '';
                if (item.payment_status === 'Pending') {
                     statusHtml = `<div class="bg-yellow-50 border border-yellow-100 text-yellow-700 text-xs font-bold px-3 py-2 rounded-lg mb-3 flex items-center gap-2">
                        <span class="material-symbols-outlined text-sm">pending</span>
                        Payment Pending Verification
                     </div>`;
                     // Allow editing even if pending, to update or add payment
                     isEditable = true; 
                     minAmount = 1;
                     maxAmount = remaining;
                } else {
                     isEditable = true;
                     minAmount = 1; // Allow partial advance payment
                     maxAmount = remaining; // Prevent Overpayment
                }
                
                detailsHtml = `
                    ${statusHtml}
                     <div class="flex justify-between">
                        <span>Ref #:</span>
                        <span class="font-mono text-xs font-bold bg-slate-200 px-1 rounded">${item.reference_number || item.invoice_id}</span>
                    </div>
                    <div class="flex justify-between">
                        <span>Date Issued:</span>
                        <span class="font-bold">${formatDate(item.created_at)}</span>
                    </div>
                    <div class="pt-2 mt-2 border-t border-dashed border-slate-200">
                        <span class="block text-xs uppercase font-bold text-slate-400 mb-1">Services</span>
                        <span class="block text-xs font-medium leading-relaxed">${item.service_names}</span>
                    </div>
                    <div class="flex justify-between mt-2 pt-2 border-t border-slate-200">
                        <span>Total Bill:</span>
                        <span class="font-bold">₱${amount.toLocaleString()}</span>
                    </div>
                `;
                
                // Add Fee Deduction Display
                if (feePaid > 0) {
                     detailsHtml += `
                     <div class="flex justify-between text-blue-600">
                        <span>Less: Appointment Fee:</span>
                        <span class="font-bold">-₱${feePaid.toLocaleString()}</span>
                    </div>
                     `;
                }
                
                if (amountPaid > 0) {
                     detailsHtml += `
                     <div class="flex justify-between text-emerald-600">
                        <span>Less: Advance Payment:</span>
                        <span class="font-bold">-₱${amountPaid.toLocaleString()}</span>
                    </div>
                     `;
                }
                
                // DISCOUNT LOGIC
                if (gPatientDiscount && (gPatientDiscount.discount_type === 'PWD' || gPatientDiscount.discount_type === 'Senior') && !gPatientDiscount.is_expired) {
                    
                    const vatExemptSales = amount / 1.12;
                    const discountAmt = vatExemptSales * 0.20;
                    const discountedTotal = amount - (amount - vatExemptSales) - discountAmt; // Total - VAT - Discount
                    
                    // Recalculate remaining based on discounted total
                    let newRemaining = discountedTotal - feePaid - amountPaid;
                    if (newRemaining < 0) newRemaining = 0;
                    
                    payableAmount = newRemaining;
                    finalAmount = newRemaining; // Update the final amount used for input
                    
                    // Update limits
                    if (isEditable) {
                         maxAmount = newRemaining;
                    }
                    
                    detailsHtml += `
                    <div class="mt-3 pt-2 border-t border-dashed border-slate-200">
                        <div class="flex justify-between items-center bg-purple-50 p-2 rounded-lg mb-2">
                             <div class="flex items-center gap-2">
                                <span class="material-symbols-outlined text-purple-600 text-sm">verified</span>
                                <span class="text-xs font-bold text-purple-700">${gPatientDiscount.discount_type} Discount Applied</span>
                             </div>
                             <span class="text-[10px] text-purple-600 font-mono">${gPatientDiscount.discount_id_number || ''}</span>
                        </div>
                        <div class="flex justify-between text-xs text-slate-500">
                            <span>VAT Exempt Sales:</span>
                            <span>₱${vatExemptSales.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2})}</span>
                        </div>
                        <div class="flex justify-between text-xs text-purple-600 font-bold">
                            <span>Less: Discount (20%):</span>
                            <span>-₱${discountAmt.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2})}</span>
                        </div>
                    </div>
                    `;
                    
                    // Allow use of 'remaining' variable in next block to refer to discounted remaining
                    remaining = newRemaining;
                }
                
                // Always show Remaining Balance if deductions exist
                if (feePaid > 0 || amountPaid > 0) {
                     detailsHtml += `
                     <div class="flex justify-between pt-2 border-t border-dashed border-slate-200">
                        <span>${isEditable ? 'Remaining Balance' : 'Net Due'}:</span>
                        <span class="font-bold text-royal-purple">₱${remaining.toLocaleString()}</span>
                    </div>
                     `;
                } else {
                     // No deductions yet
                     detailsHtml += `
                     <div class="flex justify-between pt-2 border-t border-dashed border-slate-200">
                        <span>Amount Due:</span>
                        <span class="font-bold text-royal-purple">₱${amount.toLocaleString()}</span>
                    </div>
                     `;
                }
                
                if (isEditable) {
                    if (remaining <= 0) {
                         document.getElementById('amountDetailsText').textContent = "Fully Paid / No Balance Due";
                         isEditable = false; // Disable payment if balance is 0
                    } else {
                         document.getElementById('amountDetailsText').textContent = `Total Due: ₱${remaining.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2})}`;
                    }
                } else {
                     if (item.payment_status === 'Pending') {
                         document.getElementById('amountDetailsText').textContent = `Payment verification in progress. Update payment if needed.`;
                     } else {
                         document.getElementById('amountDetailsText').textContent = `No Payment Required`;
                     }
                }

            } else if (type === 'installment') {
                item = gInstallments[index];
                id = item.installment_id;
                amount = parseFloat(item.monthly_amount);
                payableAmount = amount;
                label = 'Installment Payment';
                
                let balance = parseFloat(item.balance_remaining);
                let penalty = Math.max(0, (parseFloat(item.penalty_accumulated || 0) - parseFloat(item.penalty_paid || 0)));
                maxAmount = balance + penalty;
                minAmount = 1;
                
                detailsHtml = `
                    <div class="flex justify-between">
                        <span>Plan For:</span>
                        <span class="font-bold">${item.service_name}</span>
                    </div>
                    <div class="flex justify-between">
                        <span>Monthly Due:</span>
                        <span class="font-bold">₱${amount.toLocaleString()}</span>
                    </div>
                    <div class="flex justify-between pt-2 mt-2 border-t border-dashed border-slate-200">
                        <span>Total Outstanding:</span>
                        <span class="font-bold text-slate-800">₱${maxAmount.toLocaleString()}</span>
                    </div>
                `;
                
                document.getElementById('amountDetailsText').textContent = `Suggested: ₱${amount.toFixed(2)} (Max: ₱${maxAmount.toFixed(2)})`;
            }
            
            openPaymentModal(type, id, payableAmount, label, detailsHtml, isEditable, minAmount, maxAmount);
        }
        
        function openPaymentModal(type, id, amount, label, detailsHtml, isEditable, min, max) {
            document.getElementById('lblPaymentType').textContent = label;
            
            document.getElementById('payType').value = type;
            document.getElementById('payId').value = id;
            document.getElementById('refNum').value = '';
            
            const detailsContainer = document.getElementById('paymentDetailsContainer');
            if (detailsHtml) {
                detailsContainer.innerHTML = detailsHtml;
                detailsContainer.classList.remove('hidden');
            } else {
                detailsContainer.classList.add('hidden');
            }
            
            const amountSection = document.getElementById('amountSection');
            const amountDisplayFixed = document.getElementById('amountDisplayFixed');
            const amountInput = document.getElementById('payAmountInput');
            const btnPay = document.getElementById('btnPay');
            
            // Use passed amount which might be discounted already
            let finalAmount = amount;

            if (isEditable) {
                amountSection.classList.remove('hidden');
                amountDisplayFixed.classList.add('hidden');
                amountInput.value = finalAmount.toFixed(2); 
                amountInput.dataset.min = min;
                amountInput.dataset.max = max;
                amountInput.disabled = false;
            } else {
                amountSection.classList.add('hidden');
                amountDisplayFixed.classList.remove('hidden');
                document.getElementById('lblAmountMain').textContent = '₱' + finalAmount.toLocaleString(undefined, {minimumFractionDigits: 2});
                amountInput.value = finalAmount;
            }
            
            // Lock submit if amount is 0 or less
            if (finalAmount <= 0) {
                 btnPay.disabled = true;
                 btnPay.classList.add('opacity-50', 'cursor-not-allowed');
                 btnPay.innerHTML = '<span class="material-symbols-outlined">lock</span> No Payment Needed';
            } else {
                 btnPay.disabled = false;
                 btnPay.classList.remove('opacity-50', 'cursor-not-allowed');
                 btnPay.innerHTML = '<span class="material-symbols-outlined">check</span> Submit Payment';
            }
            
            modal.classList.remove('hidden');
        }
    
        function closePaymentModal() {
            modal.classList.add('hidden');
        }

    function submitPayment() {
        const type = document.getElementById('payType').value;
        const id = document.getElementById('payId').value;
        const ref = document.getElementById('refNum').value;
        const amountInput = document.getElementById('payAmountInput');
        
        // Use input value
        let amount = parseFloat(amountInput.value);
        
        // Validation for editable fields
        if (!document.getElementById('amountSection').classList.contains('hidden')) {
             const max = parseFloat(amountInput.dataset.max || 999999);
             const min = parseFloat(amountInput.dataset.min || 0);
             if (amount > max) return Swal.fire('Limit Exceeded', `Amount cannot exceed ₱${max.toLocaleString()}`, 'warning');
             if (amount < min && min > 1) return Swal.fire('Invalid Amount', `Minimum amount is ₱${min.toLocaleString()}`, 'warning');
             if (amount <= 0) return Swal.fire('Invalid Amount', "Please enter a valid amount", 'warning');
        }

        if(!ref) return Swal.fire('Missing Reference', 'Please enter your GCash reference number', 'warning');

        const btn = document.getElementById('btnPay');
        const originalText = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = 'Verifying...';

        fetch(`${API_URL}?action=pay`, {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({
                type: type,
                id: id,
                amount: amount,
                gcash_reference: ref
            })
        })
        .then(r => r.json())
        .then(d => {
            btn.disabled = false;
            btn.innerHTML = originalText;
            
            if(d.success) {
                closePaymentModal();
                if(type === 'appointment_fee' && d.appointment_id) {
                    Swal.fire({
                        title: 'Payment Successful!',
                        text: 'Your appointment is now confirmed. View your receipt?',
                        icon: 'success',
                        showCancelButton: true,
                        confirmButtonText: 'View Receipt',
                        cancelButtonText: 'Close',
                        confirmButtonColor: '#6A1B9A'
                    }).then((result) => {
                        if (result.isConfirmed) {
                            window.location.href = `appointment_fee_receipt.php?appointment_id=${d.appointment_id}`;
                        } else {
                            fetchUnpaid();
                        }
                    });
                } else {
                    Swal.fire('Payment Submitted', 'Your payment reference has been submitted. Please wait for receptionist verification.', 'success')
                    .then(fetchUnpaid);
                }
            } else {
                Swal.fire('Error', d.message, 'error');
            }
        })
        .catch(e => {
            btn.disabled = false;
            btn.innerHTML = originalText;
            Swal.fire('Error', 'Server connection error', 'error');
        });
    }

    // ========================================
    // DISCOUNT FUNCTIONS
    // ========================================
    
    function checkPatientDiscount() {
        fetch('../api/get_patient_discount.php')
            .then(r => r.json())
            .then(d => {
                if (d.success) {
                    gPatientDiscount = d;
                    
                    if (d.has_valid_discount) {
                        console.log('✓ Patient has valid discount:', d.discount_type);
                    } else if (d.has_discount && d.is_expired) {
                        console.log('⚠ Patient discount expired:', d.validation_message);
                    }
                }
            })
            .catch(e => console.error('Error checking discount:', e));
    }
    
    function applyDiscountToAmount(baseAmount) {
        if (!gPatientDiscount || !gPatientDiscount.has_valid_discount) {
            return baseAmount;
        }
        
        // Calculate discount: Remove VAT first, then apply 20% discount
        const vatExcludedAmount = baseAmount / 1.12; // Remove 12% VAT
        const discountAmount = vatExcludedAmount * 0.20; // 20% discount
        const finalAmount = baseAmount - (baseAmount / 1.12 * 0.12) - discountAmount;
        
        // Update discount display
        document.getElementById('subtotalAmountDisplay').textContent = '₱' + baseAmount.toLocaleString(undefined, {minimumFractionDigits: 2});
        document.getElementById('vatExemptDisplay').textContent = '-₱' + (baseAmount / 1.12 * 0.12).toLocaleString(undefined, {minimumFractionDigits: 2});
        document.getElementById('discountAmountDisplay').textContent = '-₱' + discountAmount.toLocaleString(undefined, {minimumFractionDigits: 2});
        document.getElementById('finalAmountDisplay').textContent = '₱' + finalAmount.toLocaleString(undefined, {minimumFractionDigits: 2});
        
        return finalAmount;
    }
    
    function showDiscountSection(discountType, verifiedDate) {
        const section = document.getElementById('discountSection');
        const badge = document.getElementById('discountTypeBadge');
        const dateSpan = document.getElementById('discountVerifiedDate');
        
        badge.textContent = discountType;
        dateSpan.textContent = verifiedDate ? new Date(verifiedDate).toLocaleDateString() : 'N/A';
        section.classList.remove('hidden');
    }
    
    function hideDiscountSection() {
        document.getElementById('discountSection').classList.add('hidden');
    }
    
    function showDiscountExpiredWarning(message) {
        const warning = document.getElementById('discountExpiredWarning');
        const messageEl = document.getElementById('discountExpiredMessage');
        messageEl.textContent = message;
        warning.classList.remove('hidden');
    }
    
    function hideDiscountExpiredWarning() {
        document.getElementById('discountExpiredWarning').classList.add('hidden');
    }

    // Receipt History Functions
    let allReceipts = [];

    function showReceiptHistory() {
        document.getElementById('receiptHistoryModal').classList.remove('hidden');
        loadReceiptHistory();
    }

    function closeReceiptHistory() {
        document.getElementById('receiptHistoryModal').classList.add('hidden');
    }

    function loadReceiptHistory() {
        // Fetch all payment history using new API
        fetch('../api/api_payments.php?action=get_payment_history')
            .then(r => r.json())
            .then(d => {
                if (d.success) {
                    allReceipts = d.history;
                    
                    // Populate Type filter
                    const types = [...new Set(allReceipts.map(r => r.type))].sort();
                    
                    const categoryFilter = document.getElementById('receiptCategoryFilter');
                    categoryFilter.innerHTML = '<option value="">All Payment Types</option>' +
                        types.map(t => `<option value="${t}">${t}</option>`).join('');
                    
                    renderReceipts(allReceipts);
                }
            })
            .catch(e => {
                console.error('Error loading receipts:', e);
                document.getElementById('receiptListContainer').innerHTML = `
                    <div class="text-center py-12 text-red-500">
                        <span class="material-symbols-outlined text-5xl mb-2">error</span>
                        <p>Error loading receipts</p>
                    </div>
                `;
            });
    }

    function applyReceiptFilters() {
        const searchTerm = document.getElementById('receiptSearch').value.toLowerCase();
        const dateFilter = document.getElementById('receiptDateFilter').value; // YYYY-MM format
        const categoryFilter = document.getElementById('receiptCategoryFilter').value;

        let filtered = allReceipts;

        // Filter by search term (doctor name or services)
        if (searchTerm) {
            filtered = filtered.filter(r =>
                r.doctor.toLowerCase().includes(searchTerm) ||
                (r.services && r.services.toLowerCase().includes(searchTerm)) ||
                r.type.toLowerCase().includes(searchTerm)
            );
        }

        // Filter by date (month/year)
        if (dateFilter) {
            filtered = filtered.filter(r => {
                const receiptMonth = r.date.substring(0, 7); // YYYY-MM
                return receiptMonth === dateFilter;
            });
        }

        // Filter by Payment Type
        if (categoryFilter) {
            filtered = filtered.filter(r =>
                r.type === categoryFilter
            );
        }

        renderReceipts(filtered);
    }

    function renderReceipts(receipts) {
        const container = document.getElementById('receiptListContainer');

        if (receipts.length === 0) {
            container.innerHTML = `
                <div class="text-center py-12 text-slate-400">
                    <span class="material-symbols-outlined text-6xl mb-3">receipt_long</span>
                    <p class="text-lg font-medium">No receipts found</p>
                    <p class="text-sm mt-1">Try adjusting your filters</p>
                </div>
            `;
            return;
        }

        container.innerHTML = `
            <div class="grid gap-4">
                ${receipts.map(receipt => `
                    <div class="bg-white border-2 border-purple-100 rounded-xl p-5 hover:border-purple-300 hover:shadow-lg transition-all">
                        <div class="flex items-start justify-between gap-4">
                            <div class="flex-1">
                                <div class="flex items-center gap-2 mb-2">
                                    <span class="material-symbols-outlined text-purple-600">receipt</span>
                                    <h3 class="font-bold text-slate-800">${receipt.type}</h3>
                                    <span class="px-2 py-0.5 bg-green-100 text-green-700 text-xs font-bold rounded-full">${receipt.status}</span>
                                </div>
                                
                                <div class="grid grid-cols-2 gap-3 text-sm mb-3">
                                    <div>
                                        <p class="text-xs text-slate-500 mb-0.5">Date</p>
                                        <p class="font-semibold text-slate-700">${new Date(receipt.date).toLocaleDateString('en-US', {month: 'long', day: 'numeric', year: 'numeric'})}</p>
                                    </div>
                                    <div>
                                        <p class="text-xs text-slate-500 mb-0.5">Time</p>
                                        <p class="font-semibold text-slate-700">${formatTime(receipt.time)}</p>
                                    </div>
                                    <div>
                                        <p class="text-xs text-slate-500 mb-0.5">Doctor</p>
                                        <p class="font-semibold text-slate-700">Dr. ${receipt.doctor}</p>
                                    </div>
                                    <div>
                                        <p class="text-xs text-slate-500 mb-0.5">Amount Paid</p>
                                        <p class="font-bold text-green-600">₱${parseFloat(receipt.amount).toLocaleString()}</p>
                                    </div>
                                </div>
                                
                                <div class="p-3 bg-slate-50 rounded-lg">
                                    <p class="text-xs font-bold text-slate-600 mb-1">Details</p>
                                    <p class="text-sm text-slate-700">${receipt.services || 'Transaction details unavailable'}</p>
                                </div>
                            </div>
                            
                            ${receipt.link && receipt.link !== '#' ? `
                            <a href="${receipt.link}" target="_blank" 
                                class="px-4 py-2 bg-purple-600 hover:bg-purple-700 text-white font-bold rounded-lg transition-colors flex items-center gap-2 text-sm whitespace-nowrap">
                                <span class="material-symbols-outlined text-lg">print</span>
                                View Receipt
                            </a>
                            ` : `
                            <button disabled class="px-4 py-2 bg-gray-100 text-gray-400 font-bold rounded-lg cursor-not-allowed flex items-center gap-2 text-sm whitespace-nowrap">
                                <span class="material-symbols-outlined text-lg">print_disabled</span>
                                No Receipt
                            </button>
                            `}
                        </div>
                    </div>
                `).join('')}
            </div>
        `;
    }

    // Payment History Functions
    let allPaymentHistory = []; // Store all data for client-side filtering

    function loadPaymentHistory() {
        const startDate = document.getElementById('historyStartDate').value;
        const endDate = document.getElementById('historyEndDate').value;
        
        let url = `${API_URL}?action=get_payment_history`;
        if (startDate && endDate) {
            url += `&start_date=${startDate}&end_date=${endDate}`;
        }
        
        fetch(url)
            .then(r => r.json())
            .then(d => {
                if(d.success) {
                    allPaymentHistory = d.history;
                    applyPaymentTypeFilter();
                }
            })
            .catch(e => {
                console.error('Error loading payment history:', e);
                document.getElementById('paymentHistoryContainer').innerHTML = 
                    '<div class="p-6 bg-white rounded-2xl border border-red-100 text-center text-red-500 text-sm">Error loading payment history</div>';
            });
    }

    function applyPaymentTypeFilter() {
        const typeFilter = document.getElementById('historyTypeFilter').value;
        
        let filtered = allPaymentHistory;
        if (typeFilter) {
            filtered = allPaymentHistory.filter(item => item.type === typeFilter);
        }
        
        renderPaymentHistory(filtered);
    }

    function renderPaymentHistory(list) {
        const container = document.getElementById('paymentHistoryContainer');
        if(!list || list.length === 0) {
            container.innerHTML = `
                <div class="p-8 bg-white rounded-2xl border border-dashed border-slate-200 text-center">
                    <span class="material-symbols-outlined text-5xl text-slate-300 mb-2">receipt_long</span>
                    <p class="text-slate-400 text-sm">No payment history found</p>
                    <p class="text-xs text-slate-400 mt-1">Try adjusting your date filters</p>
                </div>`;
            return;
        }
        
        container.innerHTML = list.map(item => `
            <div class="bg-white p-5 rounded-2xl border border-green-100 shadow-sm hover:shadow-md transition-all">
                <div class="flex justify-between items-start gap-4">
                    <div class="flex-1">
                        <div class="flex items-center gap-2 mb-2">
                            <span class="material-symbols-outlined text-green-600 text-sm">check_circle</span>
                            <p class="font-bold text-slate-800">${item.reference_number ? 'Inv #' + item.reference_number : item.type}</p>
                            <span class="px-2 py-0.5 bg-green-50 text-green-700 text-[10px] uppercase font-bold rounded-full">${item.payment_method || 'Cash'}</span>
                        </div>
                        <p class="text-xs text-slate-600 mb-1">${item.service_names || item.services || 'Medical Services'}</p>
                        <p class="text-xs text-slate-400">Dr. ${item.doctor_name || item.doctor} • ${formatDateTime(item.paid_at || (item.date + ' ' + item.time))}</p>
                    </div>
                    <div class="text-right flex flex-col items-end gap-2">
                        <div>
                            <p class="font-black text-lg text-green-600">₱${parseFloat(item.total_amount || item.amount).toLocaleString()}</p>
                            ${(item.discount_amount && item.discount_amount > 0) ? `<p class="text-xs text-purple-600 font-medium">-₱${parseFloat(item.discount_amount).toLocaleString()} discount</p>` : ''}
                        </div>
                        ${item.link && item.link !== '#' ? `
                        <a href="${item.link}" target="_blank" class="px-3 py-1.5 bg-purple-600 hover:bg-purple-700 text-white text-xs font-bold rounded-lg transition-colors flex items-center gap-1">
                            <span class="material-symbols-outlined text-sm">receipt</span>
                            View Receipt
                        </a>
                        ` : ''}
                    </div>
                </div>
            </div>
        `).join('');
    }

    function clearHistoryFilters() {
        document.getElementById('historyStartDate').value = '';
        document.getElementById('historyEndDate').value = '';
        document.getElementById('historyTypeFilter').value = '';
        loadPaymentHistory();
    }

    function formatDateTime(dateTimeStr) {
        const date = new Date(dateTimeStr);
        return date.toLocaleDateString('en-US', {month: 'short', day: 'numeric', year: 'numeric'}) + 
               ' at ' + date.toLocaleTimeString('en-US', {hour: 'numeric', minute: '2-digit'});
    }

    function exportHistoryToPDF() {
        const startDate = document.getElementById('historyStartDate').value;
        const endDate = document.getElementById('historyEndDate').value;
        
        let url = '../api/export_payment_history_pdf.php?';
        if (startDate && endDate) {
            url += `start_date=${startDate}&end_date=${endDate}`;
        }
        
        window.open(url, '_blank');
    }

    function formatDate(dateStr) {
        return new Date(dateStr).toLocaleDateString('en-US', {month:'short', day:'numeric'});
    }
    function formatTime(timeStr) {
        // Handle full H:i:s or H:i formats
        const [hours, minutes] = timeStr.split(':');
        const date = new Date();
        date.setHours(parseInt(hours));
        date.setMinutes(parseInt(minutes));
        return date.toLocaleTimeString('en-US', {hour:'numeric', minute:'2-digit'});
    }
</script>
</body>
</html>
