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
    <title>Medical Records - Vanessa Nicolas Dental Clinic</title>
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
            <a class="flex items-center gap-4 px-4 py-3 rounded-xl hover:bg-white/10 text-white/90 hover:text-white transition-all group" href="client_payment.php">
                <span class="material-symbols-outlined">payments</span>
                <span class="font-medium">Payments</span>
            </a>
            <a class="flex items-center gap-4 px-4 py-3 rounded-xl hover:bg-white/10 text-white/90 hover:text-white transition-all group" href="client_feedback.php">
                <span class="material-symbols-outlined">reviews</span>
                <span class="font-medium">Feedback</span>
            </a>
            <a class="flex items-center gap-4 px-4 py-3 rounded-xl bg-white text-royal-purple shadow-lg transition-all group" href="client_medical_records.php">
                <span class="material-symbols-outlined">medical_information</span>
                <span class="font-bold">Medical Records</span>
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
                <h2 class="text-3xl font-black text-[#151118] tracking-tight">Medical Records</h2>
                <p class="text-slate-500 font-medium flex items-center gap-2">
                    <span class="material-symbols-outlined text-lg">folder_open</span>
                    View and download your medical files.
                </p>
            </div>
        </header>

        <!-- Content Area -->
        <div class="flex-1 overflow-y-auto px-8 py-6 scrollbar-hide">
            <div id="filesContainer" class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <div class="animate-pulse bg-white h-40 rounded-2xl"></div>
                <div class="animate-pulse bg-white h-40 rounded-2xl"></div>
                <div class="animate-pulse bg-white h-40 rounded-2xl"></div>
            </div>
        </div>
    </main>
</div>

<!-- Preview Modal -->
<div id="previewModal" class="fixed inset-0 z-50 hidden">
    <div class="absolute inset-0 bg-slate-900/95 backdrop-blur-sm" onclick="closePreview()"></div>
    <div class="absolute top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 bg-white w-[90vw] h-[90vh] rounded-2xl shadow-2xl overflow-hidden flex flex-col">
        <div class="p-4 border-b flex justify-between items-center bg-gray-50">
            <h3 id="previewTitle" class="font-bold text-gray-800">File Preview</h3>
            <button onclick="closePreview()" class="px-4 py-2 bg-gray-200 text-gray-700 rounded-lg font-bold text-sm hover:bg-gray-300 transition-colors">Close</button>
        </div>
        <div id="previewContent" class="flex-1 overflow-auto p-6 bg-white">
            <!-- Preview content will be loaded here -->
        </div>
    </div>
</div>

<script>
    const API_URL = '../api/api_medical_records.php';

    document.addEventListener('DOMContentLoaded', loadFiles);

    function loadFiles() {
        fetch(`${API_URL}?action=list`)
            .then(r => r.json())
            .then(d => {
                if(d.success) renderFiles(d.files);
            });
    }

    function renderFiles(list) {
        const container = document.getElementById('filesContainer');
        if(!list || list.length === 0) {
            container.innerHTML = `
                <div class="col-span-full p-12 bg-white rounded-2xl border border-dashed border-slate-200 text-center">
                    <span class="material-symbols-outlined text-6xl text-slate-300 mb-3">folder_off</span>
                    <p class="text-slate-400 font-medium">No medical files uploaded yet.</p>
                </div>`;
            return;
        }
        
        // Group files by type
        const folders = {
            'xray': { name: 'X-Rays', icon: 'radiology', color: 'bg-blue-100 text-blue-600', files: [] },
            'photo': { name: 'Photos', icon: 'image', color: 'bg-pink-100 text-pink-600', files: [] },
            'html': { name: 'Consent Forms', icon: 'description', color: 'bg-purple-100 text-purple-600', files: [] },
            'document': { name: 'Documents', icon: 'article', color: 'bg-orange-100 text-orange-600', files: [] },
            'other': { name: 'Other Files', icon: 'folder', color: 'bg-slate-100 text-slate-600', files: [] }
        };
        
        // Categorize files
        list.forEach(file => {
            const type = (file.file_type || 'other').toLowerCase();
            if (folders[type]) {
                folders[type].files.push(file);
            } else {
                folders['other'].files.push(file);
            }
        });
        
        // Render folders
        container.innerHTML = Object.keys(folders).map(key => {
            const folder = folders[key];
            if (folder.files.length === 0) return '';
            
            return `
                <div class="col-span-full">
                    <!-- Folder Header -->
                    <button onclick="toggleFolder('${key}')" class="w-full flex items-center justify-between p-4 bg-white rounded-t-2xl border border-gray-200 hover:bg-gray-50 transition-colors group">
                        <div class="flex items-center gap-3">
                            <div class="w-12 h-12 ${folder.color} rounded-xl flex items-center justify-center">
                                <span class="material-symbols-outlined text-2xl">${folder.icon}</span>
                            </div>
                            <div class="text-left">
                                <h3 class="font-bold text-slate-800">${folder.name}</h3>
                                <p class="text-xs text-slate-500">${folder.files.length} file${folder.files.length > 1 ? 's' : ''}</p>
                            </div>
                        </div>
                        <span id="arrow-${key}" class="material-symbols-outlined text-slate-400 transition-transform">expand_more</span>
                    </button>
                    
                    <!-- Folder Content -->
                    <div id="folder-${key}" class="bg-gray-50 border-x border-b border-gray-200 rounded-b-2xl p-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        ${folder.files.map(file => `
                            <div class="bg-white p-5 rounded-xl border border-gray-100 shadow-sm hover:shadow-md transition-all group">
                                <div class="flex items-start justify-between mb-3">
                                    <div class="w-12 h-12 ${folder.color} rounded-lg flex items-center justify-center flex-shrink-0">
                                        <span class="material-symbols-outlined text-2xl">${folder.icon}</span>
                                    </div>
                                    <span class="text-[10px] px-2 py-1 bg-slate-100 text-slate-600 rounded-full font-bold uppercase">${file.file_type}</span>
                                </div>
                                <h4 class="font-bold text-slate-800 mb-1 text-sm truncate" title="${file.file_name}">${file.file_name}</h4>
                                <p class="text-xs text-slate-500 mb-3">${formatDate(file.uploaded_at)}</p>
                                <div class="flex gap-2">
                                    <button onclick="previewFile(${file.file_id}, '${file.file_name}', '${file.file_type}')" class="flex-1 py-2.5 bg-gradient-to-r from-blue-600 to-blue-700 hover:from-blue-700 hover:to-blue-800 text-white font-bold text-xs rounded-lg transition-all flex items-center justify-center gap-2 shadow-sm">
                                        <span class="material-symbols-outlined text-base">visibility</span>
                                        Preview
                                    </button>
                                    <a href="${API_URL}?action=download&id=${file.file_id}" target="_blank" class="flex-1 py-2.5 bg-gradient-to-r from-purple-600 to-purple-700 hover:from-purple-700 hover:to-purple-800 text-white font-bold text-xs rounded-lg transition-all flex items-center justify-center gap-2 shadow-sm">
                                        <span class="material-symbols-outlined text-base">download</span>
                                        Download
                                    </a>
                                </div>
                            </div>
                        `).join('')}
                    </div>
                </div>
            `;
        }).filter(Boolean).join('');
    }
    
    function toggleFolder(folderId) {
        const content = document.getElementById(`folder-${folderId}`);
        const arrow = document.getElementById(`arrow-${folderId}`);
        
        if (content.classList.contains('hidden')) {
            content.classList.remove('hidden');
            arrow.style.transform = 'rotate(0deg)';
        } else {
            content.classList.add('hidden');
            arrow.style.transform = 'rotate(-90deg)';
        }
    }

    function getFileIcon(type) {
        const icons = {
            'X-Ray': 'radiology',
            'Lab Result': 'science',
            'Prescription': 'medication',
            'Report': 'description',
            'Image': 'image',
            'Other': 'insert_drive_file'
        };
        return icons[type] || 'insert_drive_file';
    }

    function getFileColor(type) {
        const colors = {
            'X-Ray': 'bg-blue-100 text-blue-600',
            'Lab Result': 'bg-green-100 text-green-600',
            'Prescription': 'bg-purple-100 text-purple-600',
            'Report': 'bg-orange-100 text-orange-600',
            'Image': 'bg-pink-100 text-pink-600',
            'Other': 'bg-slate-100 text-slate-600'
        };
        return colors[type] || 'bg-slate-100 text-slate-600';
    }

    function formatDate(d) {
        return new Date(d).toLocaleDateString('en-US', {month:'short', day:'numeric', year:'numeric'});
    }
    
    function previewFile(fileId, fileName, fileType) {
        const modal = document.getElementById('previewModal');
        const title = document.getElementById('previewTitle');
        const content = document.getElementById('previewContent');
        
        title.textContent = fileName;
        content.innerHTML = '<div class="flex items-center justify-center h-full"><div class="animate-spin rounded-full h-12 w-12 border-b-2 border-purple-600"></div></div>';
        
        modal.classList.remove('hidden');
        
        // Build file URL
        const fileUrl = `${API_URL}?action=view&id=${fileId}`;
        
        // Determine preview type based on file type
        const type = fileType.toLowerCase();
        
        if (type === 'photo' || type === 'xray') {
            // Image preview
            content.innerHTML = `<img src="${fileUrl}" alt="${fileName}" class="max-w-full max-h-full mx-auto object-contain" />`;
        } else if (type === 'html') {
            // HTML/Consent form preview
            content.innerHTML = `<iframe src="${fileUrl}" class="w-full h-full border-0" style="min-height: 600px;"></iframe>`;
        } else {
            // Other files - show in iframe or link
            content.innerHTML = `
                <div class="text-center py-12">
                    <span class="material-symbols-outlined text-6xl text-slate-300 mb-4">description</span>
                    <p class="text-slate-600 mb-4">Preview not available for this file type.</p>
                    <a href="${fileUrl}" target="_blank" class="inline-flex items-center gap-2 px-6 py-3 bg-purple-600 text-white rounded-lg font-bold hover:bg-purple-700 transition-colors">
                        <span class="material-symbols-outlined">open_in_new</span>
                        Open in New Tab
                    </a>
                </div>
            `;
        }
    }
    
    function closePreview() {
        document.getElementById('previewModal').classList.add('hidden');
    }
</script>
</body>
</html>
