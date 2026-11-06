<?php
session_start();
require_once '../db.php';

// Secure Session Check
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
    <title>My Appointments - Vanessa Nicolas Dental Clinic</title>
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
    
    function showServicesInfo() {
        const modal = document.getElementById('servicesInfoModal');
        const content = document.getElementById('servicesInfoContent');
        modal.classList.remove('hidden');
        
        // Show loading state
        content.innerHTML = '<div class="flex items-center justify-center py-20"><div class="animate-spin rounded-full h-12 w-12 border-b-2 border-purple-600"></div></div>';
        
        // Fetch services if not already loaded
        if (!services || services.length === 0) {
            fetch(`${API_URL}?action=get_services`)
                .then(r => r.json())
                .then(d => {
                    if(d.success) {
                        services = d.services;
                        renderServicesInfo();
                    }
                });
        } else {
            renderServicesInfo();
        }
    }
    
    function renderServicesInfo() {
        const content = document.getElementById('servicesInfoContent');
        
        // Render all services with details
        content.innerHTML = `
            <div class="grid gap-4 sm:grid-cols-2">
                ${services.map(svc => {
                    const hasInstallment = svc.is_installment_available == 1 || svc.is_installment_available === '1' || svc.is_installment_available === true;
                    const downPayment = parseFloat(svc.installment_down_payment || 0);
                    const basePrice = parseFloat(svc.base_price);
                    console.log(`Service: ${svc.service_name}, is_installment_available:`, svc.is_installment_available, 'hasInstallment:', hasInstallment);
                    
                    return `
                        <div class="bg-white border-2 border-slate-200 rounded-xl p-5 hover:border-purple-300 hover:shadow-lg transition-all">
                            <div class="flex items-start justify-between mb-3">
                                <h4 class="font-bold text-lg text-slate-800">${svc.service_name}</h4>
                                <span class="text-xs px-2 py-1 bg-slate-100 text-slate-600 rounded-full">${svc.category}</span>
                            </div>
                            
                            <div class="space-y-2 mb-4">
                                <div class="flex justify-between text-sm">
                                    <span class="text-slate-600">Base Price:</span>
                                    <span class="font-bold text-green-700">₱${basePrice.toLocaleString()}</span>
                                </div>
                                <div class="flex justify-between text-sm">
                                    <span class="text-slate-600">Duration:</span>
                                    <span class="font-bold text-slate-700">${svc.estimated_duration_mins} mins</span>
                                </div>
                            </div>
                            
                            ${hasInstallment ? `
                                <div class="p-3 bg-amber-50 border-2 border-amber-200 rounded-lg mb-3">
                                    <p class="font-bold text-amber-900 text-sm mb-2 flex items-center gap-1">
                                        <span class="material-symbols-outlined text-base">payments</span>
                                        Installment Available
                                    </p>
                                    <div class="space-y-1 text-xs">
                                        <div class="flex justify-between text-amber-800">
                                            <span>Down Payment:</span>
                                            <span class="font-bold">₱${downPayment.toLocaleString()}</span>
                                        </div>
                                        <div class="flex justify-between text-amber-800">
                                            <span>Remaining Balance:</span>
                                            <span class="font-bold">₱${(basePrice - downPayment).toLocaleString()}</span>
                                        </div>
                                        <p class="text-amber-700 mt-2">Balance can be paid in installments as agreed with the clinic.</p>
                                    </div>
                                </div>
                            ` : ''}
                            
                            <div class="flex flex-wrap gap-1.5">
                                ${svc.requires_checkup == 1 ? '<span class="text-[10px] px-2 py-1 bg-yellow-100 text-yellow-700 rounded-full flex items-center gap-1"><span class="material-symbols-outlined text-[10px]">medical_services</span> Check-up Required</span>' : ''}
                                ${svc.requires_xray == 1 ? '<span class="text-[10px] px-2 py-1 bg-blue-100 text-blue-700 rounded-full flex items-center gap-1"><span class="material-symbols-outlined text-[10px]">radiology</span> X-Ray Required</span>' : ''}
                                ${hasInstallment ? '<span class="text-[10px] px-2 py-1 bg-amber-100 text-amber-700 rounded-full font-bold flex items-center gap-1"><span class="material-symbols-outlined text-[10px]">payments</span> Installment Available</span>' : ''}
                            </div>
                        </div>
                    `;
                }).join('')}
            </div>
        `;
        
        // Populate category filter
        const categories = [...new Set(services.map(s => s.category))].sort();
        const categoryFilter = document.getElementById('categoryFilter');
        categoryFilter.innerHTML = '<option value="">All Categories</option>' + 
            categories.map(cat => `<option value="${cat}">${cat}</option>`).join('');
    }
    
    function applyServiceFilters() {
        const categoryFilter = document.getElementById('categoryFilter').value;
        const installmentFilter = document.getElementById('installmentFilter').value;
        
        let filtered = services;
        
        // Filter by category
        if (categoryFilter) {
            filtered = filtered.filter(s => s.category === categoryFilter);
        }
        
        // Filter by installment
        if (installmentFilter === 'available') {
            filtered = filtered.filter(s => s.is_installment_available == 1);
        } else if (installmentFilter === 'not_available') {
            filtered = filtered.filter(s => s.is_installment_available != 1);
        }
        
        // Re-render with filtered services
        renderFilteredServices(filtered);
    }
    
    function renderFilteredServices(filteredServices) {
        const content = document.getElementById('servicesInfoContent');
        
        if (filteredServices.length === 0) {
            content.innerHTML = `
                <div class="flex items-center justify-center h-full">
                    <div class="text-center">
                        <span class="material-symbols-outlined text-6xl text-slate-300 mb-3">search_off</span>
                        <p class="text-slate-500 font-medium">No services match your filters</p>
                    </div>
                </div>
            `;
            return;
        }
        
        content.innerHTML = `
            <div class="grid gap-4 sm:grid-cols-2">
                ${filteredServices.map(svc => {
                    const hasInstallment = svc.is_installment_available == 1 || svc.is_installment_available === '1' || svc.is_installment_available === true;
                    const downPayment = parseFloat(svc.installment_down_payment || 0);
                    const basePrice = parseFloat(svc.base_price);
                    
                    return `
                        <div class="bg-white border-2 border-slate-200 rounded-xl p-5 hover:border-purple-300 hover:shadow-lg transition-all">
                            <div class="flex items-start justify-between mb-3">
                                <h4 class="font-bold text-lg text-slate-800">${svc.service_name}</h4>
                                <span class="text-xs px-2 py-1 bg-slate-100 text-slate-600 rounded-full">${svc.category}</span>
                            </div>
                            
                            <div class="space-y-2 mb-4">
                                <div class="flex justify-between text-sm">
                                    <span class="text-slate-600">Base Price:</span>
                                    <span class="font-bold text-green-700">₱${basePrice.toLocaleString()}</span>
                                </div>
                                <div class="flex justify-between text-sm">
                                    <span class="text-slate-600">Duration:</span>
                                    <span class="font-bold text-slate-700">${svc.estimated_duration_mins} mins</span>
                                </div>
                            </div>
                            
                            ${hasInstallment ? `
                                <div class="p-3 bg-amber-50 border-2 border-amber-200 rounded-lg mb-3">
                                    <p class="font-bold text-amber-900 text-sm mb-2 flex items-center gap-1">
                                        <span class="material-symbols-outlined text-base">payments</span>
                                        Installment Available
                                    </p>
                                    <div class="space-y-1 text-xs">
                                        <div class="flex justify-between text-amber-800">
                                            <span>Down Payment:</span>
                                            <span class="font-bold">₱${downPayment.toLocaleString()}</span>
                                        </div>
                                        <div class="flex justify-between text-amber-800">
                                            <span>Remaining Balance:</span>
                                            <span class="font-bold">₱${(basePrice - downPayment).toLocaleString()}</span>
                                        </div>
                                        <p class="text-[10px] text-amber-700 mt-2 italic">Installment can be set up in the clinic after your appointment.</p>
                                    </div>
                                </div>
                            ` : ''}
                            
                            ${svc.requires_checkup || svc.requires_xray ? `
                                <div class="p-2 bg-blue-50 border border-blue-200 rounded-lg text-xs">
                                    <p class="font-bold text-blue-900 mb-1">Prerequisites Required:</p>
                                    <div class="flex gap-2 flex-wrap">
                                        ${svc.requires_checkup ? '<span class="px-2 py-0.5 bg-blue-200 text-blue-900 rounded">Check-up</span>' : ''}
                                        ${svc.requires_xray ? '<span class="px-2 py-0.5 bg-blue-200 text-blue-900 rounded">X-ray</span>' : ''}
                                    </div>
                                </div>
                            ` : ''}
                        </div>
                    `;
                }).join('')}
            </div>
        `;
    }
    
    function closeServicesInfo() {
        document.getElementById('servicesInfoModal').classList.add('hidden');
    }
    
    function showClinicPolicy() {
        document.getElementById('policyModal').classList.remove('hidden');
    }
    
    function closePolicyModal() {
        document.getElementById('policyModal').classList.add('hidden');
    }
    
    function showServiceDetail(serviceId) {
        const svc = services.find(s => s.service_id == serviceId);
        if (!svc) return;
        
        const hasInstallment = svc.is_installment_available == 1;
        const downPayment = parseFloat(svc.installment_down_payment || 0);
        const basePrice = parseFloat(svc.base_price);
        
        Swal.fire({
            title: svc.service_name,
            html: `
                <div class="text-left space-y-3">
                    <div class="flex justify-between text-sm border-b pb-2">
                        <span class="text-slate-600">Category:</span>
                        <span class="font-bold">${svc.category}</span>
                    </div>
                    <div class="flex justify-between text-sm border-b pb-2">
                        <span class="text-slate-600">Base Price:</span>
                        <span class="font-bold text-green-700">₱${basePrice.toLocaleString()}</span>
                    </div>
                    <div class="flex justify-between text-sm border-b pb-2">
                        <span class="text-slate-600">Duration:</span>
                        <span class="font-bold">${svc.estimated_duration_mins} minutes</span>
                    </div>
                    
                    ${hasInstallment ? `
                        <div class="p-3 bg-amber-50 border border-amber-200 rounded-lg mt-3">
                            <p class="font-bold text-amber-900 text-sm mb-2">💳 Installment Option Available</p>
                            <div class="space-y-1 text-xs">
                                <div class="flex justify-between">
                                    <span>Down Payment:</span>
                                    <span class="font-bold">₱${downPayment.toLocaleString()}</span>
                                </div>
                                <div class="flex justify-between">
                                    <span>Remaining Balance:</span>
                                    <span class="font-bold">₱${(basePrice - downPayment).toLocaleString()}</span>
                                </div>
                            </div>
                        </div>
                    ` : '<p class="text-sm text-slate-500 italic">Full payment required at time of service.</p>'}
                    
                    ${svc.requires_checkup == 1 || svc.requires_xray == 1 ? `
                        <div class="mt-3 p-2 bg-blue-50 border border-blue-200 rounded text-xs">
                            <p class="font-bold text-blue-900 mb-1">Requirements:</p>
                            ${svc.requires_checkup == 1 ? '<p class="text-blue-800">• Check-up required before procedure</p>' : ''}
                            ${svc.requires_xray == 1 ? '<p class="text-blue-800">• X-Ray required before procedure</p>' : ''}
                        </div>
                    ` : ''}
                </div>
            `,
            confirmButtonColor: '#6A1B9A',
            confirmButtonText: 'Got it!'
        });
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
            <a class="flex items-center gap-4 px-4 py-3 rounded-xl bg-white text-royal-purple shadow-lg transition-all group" href="client_appointment.php">
                <span class="material-symbols-outlined">calendar_month</span>
                <span class="font-bold">Appointments</span>
            </a>
            <a class="flex items-center gap-4 px-4 py-3 rounded-xl hover:bg-white/10 text-white/90 hover:text-white transition-all group" href="client_payment.php">
                <span class="material-symbols-outlined">payments</span>
                <span class="font-medium">Payments</span>
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
                <h2 class="text-3xl font-black text-[#151118] tracking-tight">My Appointments</h2>
                <p class="text-slate-500 font-medium flex items-center gap-2">
                    <span class="material-symbols-outlined text-lg">calendar_month</span>
                    Manage your visits and schedule new ones.
                </p>
            </div>
            <div class="flex items-center gap-3">
                <button onclick="showServicesInfo()" class="flex items-center gap-2 px-4 py-2.5 bg-gradient-to-r from-purple-600 to-purple-700 hover:from-purple-700 hover:to-purple-800 text-white font-bold rounded-lg transition-all shadow-lg hover:shadow-xl">
                    <span class="material-symbols-outlined text-xl">info</span>
                    Services & Pricing
                </button>
                <button onclick="showClinicPolicy()" class="flex items-center gap-2 px-4 py-2.5 bg-gradient-to-r from-amber-500 to-amber-600 hover:from-amber-600 hover:to-amber-700 text-white font-bold rounded-lg transition-all shadow-lg hover:shadow-xl" title="Clinic Appointment Policy">
                    <span class="material-symbols-outlined text-xl">shield</span>
                    Policy
                </button>
                <button onclick="openBookingModal()" class="flex items-center gap-2 px-5 py-2.5 bg-gradient-to-r from-purple-600 to-purple-700 hover:from-purple-700 hover:to-purple-800 text-white font-bold rounded-lg transition-all shadow-lg hover:shadow-xl">
                    <span class="material-symbols-outlined text-xl">add_circle</span>
                    <span>Book Appointment</span>
                </button>
            </div>
        </header>

        <!-- Content Area -->
        <div class="flex-1 overflow-y-auto px-8 py-6 scrollbar-hide">
            <!-- Upcoming Appointments -->
            <section class="mb-10">
                <h3 class="text-lg font-bold text-slate-800 mb-4 flex items-center gap-2">
                    <span class="material-symbols-outlined text-purple-600">event_upcoming</span>
                    Upcoming Appointments
                </h3>
                <div id="upcomingContainer" class="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
                    <!-- Loaded via JS -->
                    <div class="col-span-full text-center py-10 bg-white rounded-2xl border border-dashed border-slate-200">
                        <span class="material-symbols-outlined text-4xl text-slate-300 mb-2">event_busy</span>
                        <p class="text-slate-500 font-medium">No upcoming appointments found.</p>
                    </div>
                </div>
            </section>

            <!-- Past Appointments -->
            <section>
                <h3 class="text-lg font-bold text-slate-800 mb-4 flex items-center gap-2">
                    <span class="material-symbols-outlined text-slate-400">history</span>
                    Recent History
                </h3>
                <div class="bg-white rounded-2xl border border-gray-100 overflow-hidden shadow-sm">
                    <div class="overflow-x-auto">
                        <table class="w-full text-left border-collapse">
                            <thead class="bg-slate-50 text-slate-500 text-xs uppercase font-bold text-center">
                                <tr>
                                    <th class="px-6 py-4">Date & Time</th>
                                    <th class="px-6 py-4">Doctor</th>
                                    <th class="px-6 py-4">Service(s)</th>
                                    <th class="px-6 py-4">Status</th>
                                </tr>
                            </thead>
                            <tbody id="historyTableBody" class="text-sm font-medium text-slate-700 text-center divide-y divide-gray-100">
                                <!-- Loaded via JS -->
                            </tbody>
                        </table>
                    </div>
                    <div id="historyEmptyState" class="hidden text-center py-8">
                        <p class="text-slate-400">No past appointments.</p>
                    </div>
                </div>
            </section>
        </div>
    </main>
</div>

<!-- Services Info Modal -->
<div id="servicesInfoModal" class="fixed inset-0 z-50 hidden">
    <div class="absolute inset-0 bg-slate-900/90 backdrop-blur-sm" onclick="closeServicesInfo()"></div>
    <div class="absolute top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 bg-white w-[90vw] max-w-4xl h-[85vh] rounded-2xl shadow-2xl overflow-hidden flex flex-col">
        <div class="p-6 border-b flex justify-between items-center bg-gradient-to-r from-purple-600 to-purple-700">
            <div>
                <h3 class="font-bold text-white text-xl">Available Services & Pricing</h3>
                <p class="text-white/80 text-sm mt-1">Browse our dental services and payment options</p>
            </div>
            <button onclick="closeServicesInfo()" class="px-4 py-2 bg-white/20 hover:bg-white/30 text-white rounded-lg font-bold text-sm transition-colors">Close</button>
        </div>
        
        <!-- Filters -->
        <div class="p-4 bg-slate-50 border-b border-slate-200 flex gap-3">
            <div class="flex-1">
                <label class="block text-xs font-bold text-slate-700 mb-1">Filter by Category</label>
                <select id="categoryFilter" onchange="applyServiceFilters()" class="w-full px-3 py-2 border-2 border-slate-300 rounded-lg focus:border-purple-500 focus:outline-none text-sm">
                    <option value="">All Categories</option>
                </select>
            </div>
            <div class="flex-1">
                <label class="block text-xs font-bold text-slate-700 mb-1">Installment</label>
                <select id="installmentFilter" onchange="applyServiceFilters()" class="w-full px-3 py-2 border-2 border-slate-300 rounded-lg focus:border-purple-500 focus:outline-none text-sm">
                    <option value="">All Services</option>
                    <option value="available">Installment Available</option>
                    <option value="not_available">No Installment</option>
                </select>
            </div>
        </div>
        
        <div id="servicesInfoContent" class="flex-1 overflow-auto p-6">
            <div class="flex items-center justify-center h-full">
                <div class="animate-spin rounded-full h-12 w-12 border-b-2 border-purple-600"></div>
            </div>
        </div>
    </div>
</div>

<!-- Clinic Policy Modal -->
<div id="policyModal" class="hidden fixed inset-0 bg-black/50 backdrop-blur-sm z-50 flex items-center justify-center p-4">
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-3xl max-h-[85vh] overflow-hidden">
        <div class="bg-gradient-to-r from-amber-500 to-amber-600 p-6 flex items-center justify-between">
            <div class="flex items-center gap-3">
                <span class="material-symbols-outlined text-white text-4xl">shield</span>
                <h2 class="text-2xl font-black text-white">Clinic Appointment Policy</h2>
            </div>
            <button onclick="closePolicyModal()" class="text-white hover:bg-white/20 rounded-full p-2 transition-colors">
                <span class="material-symbols-outlined text-3xl">close</span>
            </button>
        </div>
        <div class="p-6 overflow-y-auto max-h-[calc(85vh-100px)]">
            <div class="space-y-6">
                <!-- One Appointment Rule -->
                <div class="border-l-4 border-purple-500 pl-4">
                    <h3 class="text-lg font-bold text-purple-900 mb-2 flex items-center gap-2">
                        <span class="material-symbols-outlined">event</span>
                        One Appointment Per Patient
                    </h3>
                    <p class="text-slate-700">Each patient may only have <strong>one active appointment</strong> at a time. You must cancel your existing appointment before booking a new one.</p>
                </div>

                <!-- Payment Priority -->
                <div class="border-l-4 border-amber-500 pl-4">
                    <h3 class="text-lg font-bold text-amber-900 mb-2 flex items-center gap-2">
                        <span class="material-symbols-outlined">payments</span>
                        Payment Priority System
                    </h3>
                    <p class="text-slate-700 mb-2"><strong>Pending (Unpaid) Appointments:</strong></p>
                    <ul class="list-disc list-inside text-slate-700 space-y-1 ml-4">
                        <li>Are <strong>NOT protected</strong> and may be overridden</li>
                        <li>Can be taken by other patients who complete payment first</li>
                        <li>You will receive email notification if your appointment is cancelled</li>
                    </ul>
                    <p class="text-slate-700 mt-3 mb-2"><strong>Booked (Paid) Appointments:</strong></p>
                    <ul class="list-disc list-inside text-slate-700 space-y-1 ml-4">
                        <li>Are <strong>fully protected</strong> and cannot be overridden</li>
                        <li>Your time slot is secured and guaranteed</li>
                    </ul>
                </div>

                <!-- Clinic Hours -->
                <div class="border-l-4 border-blue-500 pl-4">
                    <h3 class="text-lg font-bold text-blue-900 mb-2 flex items-center gap-2">
                        <span class="material-symbols-outlined">schedule</span>
                        Operating Hours
                    </h3>
                    <p class="text-slate-700">All appointments must be scheduled within clinic operating hours: <strong>9:00 AM - 7:00 PM</strong></p>
                    <p class="text-slate-600 text-sm mt-2">Time slots shown are based on both clinic hours and your selected doctor's availability.</p>
                </div>

                <!-- Recommendation -->
                <div class="bg-green-50 border border-green-200 rounded-lg p-4">
                    <div class="flex items-start gap-3">
                        <span class="material-symbols-outlined text-green-600 text-2xl">lightbulb</span>
                        <div>
                            <h4 class="font-bold text-green-900 mb-1">Recommendation</h4>
                            <p class="text-green-800 text-sm">To secure your appointment and avoid cancellation, please complete payment as soon as possible after booking.</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Booking Modal -->
<div id="bookingModal" class="fixed inset-0 z-50 hidden">
    <div class="absolute inset-0 bg-slate-900/60 backdrop-blur-sm" onclick="closeBookingModal()"></div>
    <div class="absolute top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 bg-white w-[95vw] max-w-6xl h-[92vh] rounded-2xl shadow-2xl overflow-hidden flex">
        
        <!-- Left Panel - Booking Form -->
        <div class="flex-1 flex flex-col overflow-hidden">
            <div class="p-6 border-b flex justify-between items-center bg-gradient-to-r from-purple-600 to-purple-700">
                <div>
                    <h3 class="font-bold text-white text-xl">Book New Appointment</h3>
                    <p class="text-white/80 text-sm mt-1">Fill in the details below</p>
                </div>
                <button onclick="closeBookingModal()" class="px-4 py-2 bg-white/20 hover:bg-white/30 text-white rounded-lg font-bold text-sm transition-colors">Close</button>
            </div>

            <div class="flex-1 overflow-y-auto p-6 space-y-6">
                <!-- Step 1: Doctor -->
                <div>
                    <label class="block text-xs font-bold text-slate-500 uppercase mb-2">Select Doctor</label>
                    <div id="doctorList" class="grid grid-cols-2 gap-3">
                        <!-- JS injected -->
                    </div>
                </div>

                <!-- Step 2: Date -->
                <div>
                    <label class="block text-xs font-bold text-slate-500 uppercase mb-2">Appointment Date</label>
                    <input type="date" id="apptDate" onchange="fetchSlots()" class="w-full px-4 py-3 border border-slate-200 rounded-xl focus:ring-2 focus:ring-purple-500 focus:border-transparent" min="<?php echo date('Y-m-d'); ?>">
                </div>

                <!-- Step 3: Time -->
                <div>
                    <label class="block text-xs font-bold text-slate-500 uppercase mb-2">Available Time Slots</label>
                    <div id="slotContainer" class="grid grid-cols-4 gap-2">
                        <p class="col-span-4 text-sm text-slate-400 italic">Select a doctor and date first.</p>
                    </div>
                    <input type="hidden" id="selectedTime">
                </div>

                <!-- Step 4: Services -->
                <div>
                    <label class="block text-xs font-bold text-slate-500 uppercase mb-2">Select Services</label>
                    
                    <!-- Search Bar -->
                    <div class="mb-3">
                        <div class="relative">
                            <span class="material-symbols-outlined absolute left-3 top-1/2 -translate-y-1/2 text-slate-400">search</span>
                            <input type="text" id="serviceSearch" oninput="applyBookingFilters()" placeholder="Search services..." 
                                class="w-full pl-10 pr-4 py-2 border-2 border-slate-300 rounded-lg focus:border-purple-500 focus:outline-none text-sm">
                        </div>
                    </div>
                    
                    <!-- Filters -->
                    <div class="grid grid-cols-2 gap-2 mb-3">
                        <select id="bookingCategoryFilter" onchange="applyBookingFilters()" class="px-3 py-2 border-2 border-slate-300 rounded-lg focus:border-purple-500 focus:outline-none text-xs">
                            <option value="">All Categories</option>
                        </select>
                        <select id="bookingInstallmentFilter" onchange="applyBookingFilters()" class="px-3 py-2 border-2 border-slate-300 rounded-lg focus:border-purple-500 focus:outline-none text-xs">
                            <option value="">All Services</option>
                            <option value="available">Installment Available</option>
                            <option value="not_available">No Installment</option>
                        </select>
                    </div>
                    
                    <div id="serviceList" class="space-y-2 max-h-80 overflow-y-auto pr-2 scrollbar-hide">
                        <!-- JS injected -->
                    </div>
                </div>
            </div>

            <div class="p-6 border-t border-gray-100 bg-white">
                <button onclick="submitBooking()" id="btnBook" class="w-full py-4 bg-clinic-gold hover:bg-yellow-400 text-royal-purple font-bold rounded-xl shadow-lg shadow-yellow-400/20 transition-all flex items-center justify-center gap-2">
                    <span class="material-symbols-outlined">check_circle</span>
                    Confirm Booking
                </button>
            </div>
        </div>
        
        <!-- Right Panel - Payment Breakdown -->
        <div class="w-96 bg-slate-50 border-l border-slate-200 flex flex-col">
            <div class="p-6 border-b bg-white">
                <h4 class="font-bold text-slate-800 text-lg flex items-center gap-2">
                    <span class="material-symbols-outlined text-purple-600">receipt_long</span>
                    Estimated Payment Details
                </h4>
            </div>
            
            <div id="paymentBreakdown" class="flex-1 overflow-y-auto p-6 space-y-4">
                <div class="text-center py-12 text-slate-400">
                    <span class="material-symbols-outlined text-5xl mb-2">shopping_cart</span>
                    <p class="text-sm">Select services to see payment details</p>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    // API Base
    const API_URL = '../api/api_appointments.php';
    
    // State
    let selectedDoctorId = null;
    let doctors = [];
    let services = [];

    // Init
    document.addEventListener('DOMContentLoaded', () => {
        fetchAppointments();
        fetchDoctors();
        fetchServices();
    });

    function fetchDoctors() {
        fetch(`${API_URL}?action=get_doctors`)
            .then(r => r.json())
            .then(d => {
                if(d.success) {
                    doctors = d.doctors;
                    renderDoctors();
                }
            });
    }

    function renderDoctors() {
        const container = document.getElementById('doctorList');
        container.innerHTML = doctors.map(doc => {
            const profilePic = doc.profile_picture ? `../${doc.profile_picture}` : '../assets/default_avatar.png';
            return `
            <div onclick="selectDoctor(${doc.user_id}, this)" class="cursor-pointer p-3 border border-slate-200 rounded-xl hover:border-purple-500 hover:bg-purple-50 transition-all flex items-center gap-3 doctor-card" data-id="${doc.user_id}">
                <div class="w-10 h-10 rounded-full bg-slate-200 bg-cover bg-center flex-shrink-0" style="background-image: url('${profilePic}')"></div>
                <div class="min-w-0 overflow-hidden">
                    <p class="font-bold text-xs truncate">${doc.full_name}</p>
                    <p class="text-[10px] text-slate-500 truncate">${doc.specialization || 'Dentist'}</p>
                </div>
            </div>
        `;
        }).join('');
    }

    function selectDoctor(id, el) {
        selectedDoctorId = id;
        document.querySelectorAll('.doctor-card').forEach(c => {
            c.classList.remove('border-purple-600', 'bg-purple-100', 'ring-2', 'ring-purple-200');
            c.classList.add('border-slate-200');
        });
        el.classList.remove('border-slate-200');
        el.classList.add('border-purple-600', 'bg-purple-100', 'ring-2', 'ring-purple-200');
        fetchSlots();
    }

    function fetchServices() {
        fetch(`${API_URL}?action=get_services`)
            .then(r => r.json())
            .then(d => {
                if(d.success) {
                    services = d.services;
                    renderServices();
                }
            });
    }

    function renderServices() {
        // Populate category filter
        const categories = [...new Set(services.map(s => s.category))].sort();
        const categoryFilter = document.getElementById('bookingCategoryFilter');
        if (categoryFilter) {
            categoryFilter.innerHTML = '<option value="">All Categories</option>' + 
                categories.map(cat => `<option value="${cat}">${cat}</option>`).join('');
        }
        
        // Render all services initially
        renderFilteredBookingServices(services);
    }
    
    function applyBookingFilters() {
        const searchTerm = document.getElementById('serviceSearch').value.toLowerCase();
        const categoryFilter = document.getElementById('bookingCategoryFilter').value;
        const installmentFilter = document.getElementById('bookingInstallmentFilter').value;
        
        let filtered = services;
        
        // Filter by search term
        if (searchTerm) {
            filtered = filtered.filter(s => 
                s.service_name.toLowerCase().includes(searchTerm) ||
                s.category.toLowerCase().includes(searchTerm)
            );
        }
        
        // Filter by category
        if (categoryFilter) {
            filtered = filtered.filter(s => s.category === categoryFilter);
        }
        
        // Filter by installment
        if (installmentFilter === 'available') {
            filtered = filtered.filter(s => s.is_installment_available == 1);
        } else if (installmentFilter === 'not_available') {
            filtered = filtered.filter(s => s.is_installment_available != 1);
        }
        
        renderFilteredBookingServices(filtered);
    }
    
    function renderFilteredBookingServices(filteredServices) {
        const container = document.getElementById('serviceList');
        
        if (filteredServices.length === 0) {
            container.innerHTML = `
                <div class="text-center py-8 text-slate-400">
                    <span class="material-symbols-outlined text-4xl mb-2">search_off</span>
                    <p class="text-sm">No services match your search</p>
                </div>
            `;
            return;
        }
        
        container.innerHTML = filteredServices.map(svc => {
            const hasInstallment = svc.is_installment_available == 1;
            const downPayment = parseFloat(svc.installment_down_payment || 0);
            const basePrice = parseFloat(svc.base_price);
            
            return `
            <label class="flex items-start gap-3 p-4 border border-slate-200 rounded-xl hover:border-purple-300 hover:bg-purple-50/50 transition-all cursor-pointer">
                <input type="checkbox" name="services" value="${svc.service_id}" class="mt-1 w-4 h-4 text-purple-600 border-gray-300 rounded focus:ring-purple-500">
                <div class="flex-1 min-w-0">
                    <div class="flex items-start justify-between gap-2 mb-2">
                        <p class="font-bold text-sm text-slate-800">${svc.service_name}</p>
                        <button type="button" onclick="event.preventDefault(); showServiceDetail(${svc.service_id})" class="text-purple-600 hover:text-purple-700">
                            <span class="material-symbols-outlined text-lg">info</span>
                        </button>
                    </div>
                    <div class="flex flex-wrap gap-1.5 text-[10px]">
                        <span class="px-2 py-0.5 bg-slate-100 text-slate-600 rounded-full">${svc.category}</span>
                        <span class="px-2 py-0.5 bg-green-100 text-green-700 rounded-full font-bold">₱${basePrice.toLocaleString()}</span>
                        ${svc.requires_checkup == 1 ? '<span class="px-2 py-0.5 bg-yellow-100 text-yellow-700 rounded-full flex items-center gap-1"><span class="material-symbols-outlined text-[10px]">medical_services</span> Check-up Req</span>' : ''}
                        ${svc.requires_xray == 1 ? '<span class="px-2 py-0.5 bg-blue-100 text-blue-700 rounded-full flex items-center gap-1"><span class="material-symbols-outlined text-[10px]">radiology</span> X-Ray Req</span>' : ''}
                        ${hasInstallment ? `<span class="px-2 py-0.5 bg-amber-100 text-amber-700 rounded-full font-bold flex items-center gap-1"><span class="material-symbols-outlined text-[10px]">payments</span> Installment Available</span>` : ''}
                    </div>
                    ${hasInstallment ? `
                        <div class="mt-2 p-2 bg-amber-50 border border-amber-200 rounded-lg text-xs">
                            <p class="font-bold text-amber-900 mb-1">Installment Option:</p>
                            <div class="flex justify-between text-amber-800">
                                <span>Down Payment:</span>
                                <span class="font-bold">₱${downPayment.toLocaleString()}</span>
                            </div>
                            <div class="flex justify-between text-amber-800">
                                <span>Remaining Balance:</span>
                                <span class="font-bold">₱${(basePrice - downPayment).toLocaleString()}</span>
                            </div>
                        </div>
                    ` : ''}
                </div>
            </label>
        `;
        }).join('');
        
        // Add event listeners to checkboxes to update payment breakdown
        setTimeout(() => {
            document.querySelectorAll('input[name="services"]').forEach(cb => {
                cb.addEventListener('change', updatePaymentBreakdown);
            });
            updatePaymentBreakdown();
        }, 100);
    }
    
    function updatePaymentBreakdown() {
        const selectedCheckboxes = document.querySelectorAll('input[name="services"]:checked');
        const selectedIds = Array.from(selectedCheckboxes).map(cb => parseInt(cb.value));
        const selectedServices = services.filter(s => selectedIds.includes(s.service_id));
        
        const breakdown = document.getElementById('paymentBreakdown');
        
        if (selectedServices.length === 0) {
            breakdown.innerHTML = `
                <div class="text-center py-12 text-slate-400">
                    <span class="material-symbols-outlined text-5xl mb-2">shopping_cart</span>
                    <p class="text-sm">Select services to see payment details</p>
                </div>
            `;
            return;
        }
        
        let totalAmount = 0;
        let totalDownPayment = 0;
        let hasInstallment = false;
        
        selectedServices.forEach(svc => {
            const price = parseFloat(svc.base_price);
            totalAmount += price;
            
            if (svc.is_installment_available == 1) {
                hasInstallment = true;
                totalDownPayment += parseFloat(svc.installment_down_payment || 0);
            } else {
                totalDownPayment += price;
            }
        });
        
        const remainingBalance = totalAmount - totalDownPayment;
        
        breakdown.innerHTML = `
            <!-- Selected Services -->
            <div class="bg-white rounded-xl p-4 border border-slate-200">
                <h5 class="font-bold text-slate-700 text-sm mb-3 flex items-center gap-2">
                    <span class="material-symbols-outlined text-base">tune</span>
                    Selected Services
                </h5>
                <div class="space-y-2">
                    ${selectedServices.map(svc => {
                        const price = parseFloat(svc.base_price);
                        const hasInst = svc.is_installment_available == 1;
                        const dp = parseFloat(svc.installment_down_payment || 0);
                        
                        return `
                            <div class="flex items-start justify-between text-xs py-2 border-b border-slate-100 last:border-0">
                                <div class="flex-1">
                                    <p class="font-bold text-slate-800">${svc.service_name}</p>
                                    ${hasInst ? `<p class="text-purple-600 text-[10px] mt-0.5">DP: ₱${dp.toLocaleString()}</p>` : `<p class="text-slate-500 text-[10px] mt-0.5">Full Payment</p>`}
                                </div>
                                <span class="font-bold text-green-700">₱${price.toLocaleString()}</span>
                            </div>
                        `;
                    }).join('')}
                </div>
            </div>
            
            <!-- Payment Summary -->
            <div class="bg-gradient-to-br from-purple-50 to-purple-100 rounded-xl p-5 border-2 border-purple-200">
                <div class="flex items-center justify-between mb-4">
                    <span class="text-xs font-bold text-purple-900 uppercase">Total Amount</span>
                    <span class="text-2xl font-black text-purple-900">₱${totalAmount.toLocaleString('en-PH', {minimumFractionDigits: 2})}</span>
                </div>
                
                ${hasInstallment ? `
                    <div class="flex items-center justify-end gap-2 mb-3">
                        <span class="text-xs text-purple-700">Installment Available?</span>
                        <span class="flex items-center gap-1 text-green-700 font-bold text-sm">
                            <span class="material-symbols-outlined text-base">check_circle</span>
                            Yes
                        </span>
                    </div>
                ` : ''}
                
                <div class="grid grid-cols-${hasInstallment ? '2' : '1'} gap-3 mt-4">
                    <div class="bg-white rounded-lg p-3 border border-purple-200">
                        <p class="text-[10px] text-purple-700 font-bold uppercase mb-1">Down Payment ${hasInstallment ? '(Min)' : ''}</p>
                        <p class="text-xl font-black text-purple-900">₱${totalDownPayment.toLocaleString('en-PH', {minimumFractionDigits: 2})}</p>
                    </div>
                    
                    ${hasInstallment ? `
                        <div class="bg-amber-50 rounded-lg p-3 border border-amber-300">
                            <p class="text-[10px] text-amber-800 font-bold uppercase mb-1">Remaining Balance</p>
                            <p class="text-xl font-black text-amber-900">₱${remainingBalance.toLocaleString('en-PH', {minimumFractionDigits: 2})}</p>
                        </div>
                    ` : ''}
                </div>
                
                ${hasInstallment ? `
                    <div class="mt-4 p-3 bg-white/50 rounded-lg border border-purple-200">
                        <p class="text-[10px] text-purple-800 flex items-start gap-1">
                            <span class="material-symbols-outlined text-sm">info</span>
                            <span>Actual payment terms will be finalized during payment processing. Balance can be paid in installments as agreed with the clinic.</span>
                        </p>
                    </div>
                ` : ''}
            </div>
        `;
    }

    function fetchSlots() {
        const date = document.getElementById('apptDate').value;
        if(!selectedDoctorId || !date) return;

        const container = document.getElementById('slotContainer');
        container.innerHTML = '<p class="col-span-4 text-center text-sm text-slate-400 py-4">Loading slots...</p>';

        fetch(`${API_URL}?action=get_slots&doctor_id=${selectedDoctorId}&date=${date}`)
            .then(r => r.json())
            .then(d => {
                if(d.success) {
                    renderSlots(d.slots);
                } else {
                    container.innerHTML = `<p class="col-span-4 text-center text-sm text-red-400 py-2">Error: ${d.message}</p>`;
                }
            })
            .catch(e => {
                container.innerHTML = '<p class="col-span-4 text-center text-sm text-red-400 py-2">Connection error fetching slots.</p>';
            });
    }

    function renderSlots(slots) {
        const container = document.getElementById('slotContainer');
        if(slots.length === 0) {
            container.innerHTML = '<p class="col-span-4 text-center text-sm text-red-400 py-2">No available slots for this date.</p>';
            return;
        }
        container.innerHTML = slots.map(time => `
            <div onclick="selectTime('${time}', this)" class="slot-card text-center text-xs font-bold py-2 border rounded-lg cursor-pointer hover:bg-purple-50 hover:border-purple-300 transition-all bg-white border-slate-200 text-slate-600">
                ${formatTime(time)}
            </div>
        `).join('');
    }

    function selectTime(time, el) {
        document.getElementById('selectedTime').value = time;
        document.querySelectorAll('.slot-card').forEach(c => {
            c.classList.remove('bg-purple-600', 'text-white', 'border-purple-600');
            c.classList.add('bg-white', 'text-slate-600', 'border-slate-200');
        });
        el.classList.remove('bg-white', 'text-slate-600', 'border-slate-200');
        el.classList.add('bg-purple-600', 'text-white', 'border-purple-600');
    }

    function submitBooking() {
        const date = document.getElementById('apptDate').value;
        const time = document.getElementById('selectedTime').value;
        const serviceEls = document.querySelectorAll('input[name="services"]:checked');
        const serviceIds = Array.from(serviceEls).map(el => el.value);

        if(!selectedDoctorId || !date || !time || serviceIds.length === 0) {
            Swal.fire('Incomplete', 'Please select a doctor, date, time, and at least one service.', 'warning');
            return;
        }

        const btn = document.getElementById('btnBook');
        const originalText = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = '<span class="material-symbols-outlined animate-spin">progress_activity</span> Booking...';

        fetch(`${API_URL}?action=create_appointment`, {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({
                doctor_id: selectedDoctorId,
                date: date,
                time: time,
                service_ids: serviceIds
            })
        })
        .then(r => r.json())
        .then(d => {
            btn.disabled = false;
            btn.innerHTML = originalText;

            if(d.success) {
                Swal.fire('Success', 'Appointment request submitted!', 'success').then(() => {
                    closeBookingModal();
                    fetchAppointments();
                    // Reset form
                    document.getElementById('selectedTime').value = '';
                    document.getElementById('apptDate').value = '';
                    renderServices(); // Clear checks
                });
            } else {
                if(d.is_prerequisite_error) {
                    Swal.fire({
                        title: 'Prerequisite Required',
                        text: d.message,
                        icon: 'info',
                        confirmButtonText: 'Understand'
                    });
                } else if (d.is_consent_error) {
                    Swal.fire({
                        title: 'Parent Consent Required',
                        text: d.message,
                        icon: 'warning',
                        showCancelButton: true,
                        confirmButtonText: 'Go to Dependents',
                        cancelButtonText: 'Close',
                        confirmButtonColor: '#6A1B9A'
                    }).then((result) => {
                        if (result.isConfirmed) {
                            window.location.href = 'client_dependencies.php';
                        }
                    });
                } else {
                    Swal.fire('Error', d.message, 'error');
                }
            }
        })
        .catch(e => {
            btn.disabled = false;
            btn.innerHTML = originalText;
            Swal.fire('Error', 'Server error occurred.', 'error');
        });
    }

    function fetchAppointments() {
        fetch(`${API_URL}?action=get_appointments`)
            .then(r => r.json())
            .then(d => {
                const upcoming = d.appointments.filter(a => ['Pending', 'Booked', 'Confirmed'].includes(a.status));
                const past = d.appointments.filter(a => !['Pending', 'Booked', 'Confirmed'].includes(a.status));
                
                renderUpcoming(upcoming);
                renderHistory(past);
            });
    }

    function renderUpcoming(list) {
        const container = document.getElementById('upcomingContainer');
        if(list.length === 0) {
            container.innerHTML = `
                <div class="col-span-full text-center py-10 bg-white rounded-2xl border border-dashed border-slate-200">
                    <span class="material-symbols-outlined text-4xl text-slate-300 mb-2">event_busy</span>
                    <p class="text-slate-500 font-medium">No upcoming appointments found.</p>
                </div>`;
            return;
        }
        
        container.innerHTML = list.map(a => `
            <div class="bg-white rounded-2xl p-6 border-2 border-purple-200 hover:border-purple-400 hover:shadow-xl transition-all relative">
                <div class="absolute top-4 right-4">
                    <span class="px-3 py-1 text-xs font-bold rounded-full ${
                        a.status === 'Pending' ? 'bg-yellow-100 text-yellow-700' :
                        a.status === 'Booked' ? 'bg-green-100 text-green-700' :
                        a.status === 'Confirmed' ? 'bg-blue-100 text-blue-700' :
                        'bg-slate-100 text-slate-700'
                    }">
                        ${a.status}
                    </span>
                </div>
                
                ${a.status === 'Pending' ? `
                    <div class="mb-3 p-3 bg-amber-50 border-l-4 border-amber-500 rounded-r-lg">
                        <div class="flex items-start gap-2">
                            <span class="material-symbols-outlined text-amber-600 text-xl">warning</span>
                            <div class="flex-1">
                                <p class="text-xs font-bold text-amber-800">Appointment Not Protected</p>
                                <p class="text-[10px] text-amber-700 mt-0.5">This slot may be taken by paid appointments. Complete payment to secure your booking.</p>
                            </div>
                        </div>
                    </div>
                ` : ''}
                
                <div class="mb-4">
                    <p class="text-sm text-slate-500 mb-1">${formatDate(a.appointment_date)}</p>
                    <h3 class="text-2xl font-black text-purple-900">${formatTime(a.time_slot)}</h3>
                    <p class="text-sm font-bold text-slate-600 mt-1 flex items-center gap-1">
                        <span class="material-symbols-outlined text-sm">person</span>
                        Dr. ${a.doctor_name}
                    </p>
                </div>

                <!-- Services Breakdown -->
                <div class="mt-3 p-3 bg-slate-50 rounded-lg border border-slate-200">
                    <p class="text-xs font-bold text-slate-700 mb-2 flex items-center gap-1">
                        <span class="material-symbols-outlined text-sm">medical_services</span>
                        Services Booked
                    </p>
                    <div class="text-xs text-slate-600">
                        ${a.services || 'No services listed'}
                    </div>
                </div>

                <div class="pt-4 border-t border-slate-200 flex justify-between items-center gap-2">
                    <div class="text-xs text-slate-500 max-w-[50%] truncate" title="${a.services}">
                        ${a.services}
                    </div>
                    <div class="flex gap-2">
                        <button onclick="editAppointment(${a.appointment_id})" class="px-3 py-1.5 bg-purple-100 hover:bg-purple-200 text-purple-700 text-xs font-bold rounded-lg transition-colors flex items-center gap-1">
                            <span class="material-symbols-outlined text-sm">edit</span>
                            Edit
                        </button>
                        <button onclick="cancelAppointment(${a.appointment_id})" class="px-3 py-1.5 bg-red-100 hover:bg-red-200 text-red-700 text-xs font-bold rounded-lg transition-colors">
                            Cancel
                        </button>
                    </div>
                </div>
            </div>
        `).join('');
    }

    function renderHistory(list) {
        const tbody = document.getElementById('historyTableBody');
        if(list.length === 0) {
            document.getElementById('historyEmptyState').classList.remove('hidden');
            tbody.innerHTML = '';
            return;
        }
        document.getElementById('historyEmptyState').classList.add('hidden');
        tbody.innerHTML = list.map(a => `
            <tr class="hover:bg-slate-50 transition-colors">
                <td class="px-6 py-4">
                    <p class="font-bold text-slate-800">${formatDate(a.appointment_date)}</p>
                    <p class="text-xs text-slate-500">${formatTime(a.time_slot)}</p>
                </td>
                <td class="px-6 py-4 text-slate-600">Dr. ${a.doctor_name}</td>
                <td class="px-6 py-4 text-sm max-w-xs truncate" title="${a.services}">${a.services}</td>
                <td class="px-6 py-4">
                    <span class="px-3 py-1 rounded-full text-xs font-bold ${getStatusClass(a.status)}">
                        ${a.status}
                    </span>
                </td>
            </tr>
        `).join('');
    }

    function editAppointment(id) {
        // Fetch appointment details
        fetch(`${API_URL}?action=get_appointments`)
            .then(r => r.json())
            .then(d => {
                const appt = d.appointments.find(a => a.appointment_id == id);
                if (!appt) {
                    Swal.fire('Error', 'Appointment not found', 'error');
                    return;
                }
                
                // Show edit modal with current values
                Swal.fire({
                    title: 'Edit Appointment',
                    html: `
                        <div class="text-left space-y-4">
                            <div>
                                <label class="block text-sm font-bold text-slate-700 mb-1">Appointment Date</label>
                                <input type="date" id="editDate" value="${appt.appointment_date}" 
                                    min="${new Date().toISOString().split('T')[0]}"
                                    class="w-full px-3 py-2 border-2 border-slate-300 rounded-lg focus:border-purple-500 focus:outline-none">
                            </div>
                            <div>
                                <label class="block text-sm font-bold text-slate-700 mb-1">Appointment Time</label>
                                <select id="editTime" class="w-full px-3 py-2 border-2 border-slate-300 rounded-lg focus:border-purple-500 focus:outline-none">
                                    <option value="">Loading available slots...</option>
                                </select>
                            </div>
                            <div class="p-3 bg-slate-100 rounded-lg">
                                <p class="text-xs font-bold text-slate-700 mb-1">Current Status:</p>
                                <p class="text-sm text-slate-600">${appt.status} <span class="text-xs italic">(cannot be changed)</span></p>
                            </div>
                        </div>
                    `,
                    showCancelButton: true,
                    confirmButtonText: 'Save Changes',
                    cancelButtonText: 'Cancel',
                    confirmButtonColor: '#6A1B9A',
                    width: '500px',
                    didOpen: () => {
                        // Load available time slots for the current date
                        const dateInput = document.getElementById('editDate');
                        const timeSelect = document.getElementById('editTime');
                        
                        const loadTimeSlots = (date) => {
                            timeSelect.innerHTML = '<option value="">Loading...</option>';
                            
                            // Use the exact same API call as booking modal
                            fetch(`${API_URL}?action=get_slots&doctor_id=${appt.doctor_id}&date=${date}`)
                                .then(r => r.json())
                                .then(d => {
                                    console.log('Slots response:', d); // Debug log
                                    if (d.success && d.slots && d.slots.length > 0) {
                                        timeSelect.innerHTML = '<option value="">Select a time</option>' +
                                            d.slots.map(slot => {
                                                // Format time inline (HH:MM to 12-hour format)
                                                const [hours, mins] = slot.split(':');
                                                const h = parseInt(hours);
                                                const ampm = h >= 12 ? 'PM' : 'AM';
                                                const displayHour = h % 12 || 12;
                                                const formattedTime = `${displayHour}:${mins} ${ampm}`;
                                                
                                                return `<option value="${slot}" ${slot === appt.time_slot ? 'selected' : ''}>${formattedTime}</option>`;
                                            }).join('');
                                    } else {
                                        timeSelect.innerHTML = '<option value="">No available slots</option>';
                                    }
                                })
                                .catch(e => {
                                    console.error('Error loading slots:', e);
                                    timeSelect.innerHTML = '<option value="">Error loading slots</option>';
                                });
                        };
                        
                        // Load slots for current date
                        loadTimeSlots(appt.appointment_date);
                        
                        // Reload slots when date changes
                        dateInput.addEventListener('change', (e) => {
                            loadTimeSlots(e.target.value);
                        });
                    },
                    preConfirm: () => {
                        const date = document.getElementById('editDate').value;
                        const time = document.getElementById('editTime').value;
                        
                        if (!date || !time) {
                            Swal.showValidationMessage('Please select both date and time');
                            return false;
                        }
                        
                        return { date, time };
                    }
                }).then((result) => {
                    if (result.isConfirmed) {
                        // Update appointment
                        fetch(`${API_URL}?action=update_appointment`, {
                            method: 'POST',
                            headers: {'Content-Type': 'application/json'},
                            body: JSON.stringify({
                                appointment_id: id,
                                date: result.value.date,
                                time: result.value.time
                            })
                        })
                        .then(r => r.json())
                        .then(d => {
                            if (d.success) {
                                Swal.fire('Updated!', 'Your appointment has been updated.', 'success');
                                fetchAppointments();
                            } else {
                                Swal.fire('Error', d.message, 'error');
                            }
                        });
                    }
                });
            });
    }
    
    function cancelAppointment(id) {
        Swal.fire({
            title: 'Cancel Appointment?',
            text: "Are you sure you want to cancel this appointment?",
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#d33',
            cancelButtonColor: '#3085d6',
            confirmButtonText: 'Yes, cancel it!'
        }).then((result) => {
            if (result.isConfirmed) {
                fetch(`${API_URL}?action=cancel_appointment`, {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({ appointment_id: id })
                })
                .then(r => r.json())
                .then(d => {
                    if(d.success) {
                        Swal.fire('Cancelled!', 'Your appointment has been cancelled.', 'success');
                        fetchAppointments();
                    } else {
                        Swal.fire('Error', d.message, 'error');
                    }
                });
            }
        });
    }

    // Utils
    function formatDate(dateStr) {
        const d = new Date(dateStr);
        return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
    }
    function formatTime(timeStr) {
        const [hours, mins] = timeStr.split(':');
        const h = parseInt(hours);
        const m = parseInt(mins);
        const ampm = h >= 12 ? 'PM' : 'AM';
        const h12 = h % 12 || 12;
        return `${h12}:${m.toString().padStart(2, '0')} ${ampm}`;
    }
    function getStatusClass(status) {
        switch(status) {
            case 'Completed': return 'bg-blue-50 text-blue-600';
            case 'Cancelled': return 'bg-red-50 text-red-600';
            case 'No-Show': return 'bg-gray-100 text-gray-500';
            default: return 'bg-slate-100 text-slate-600';
        }
    }

    // UI Logic
    const bookingModal = document.getElementById('bookingModal');

    function openBookingModal() {
        bookingModal.classList.remove('hidden');
        fetchDoctors();
        fetchServices();
    }

    function closeBookingModal() {
        bookingModal.classList.add('hidden');
    }
</script>
</body>
</html>
