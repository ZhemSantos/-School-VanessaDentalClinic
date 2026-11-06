<?php
session_start();
require_once '../db.php';
require_once '../includes/security.php';

configureSecureSession();
requireRole('Admin');
updateUserActivity();

// --- Functions ---
function generateTimeDropdown($name, $value = '', $min_time = '00:00', $max_time = '23:45', $classes = '', $disabled = false, $required = false, $data_attr = '') {
    $options = [];
    $normalized_value = '';
    if (!empty($value)) {
        if (preg_match('/^(\d{1,2}):(\d{2})$/', $value, $matches)) {
            $hour = (int)$matches[1];
            $min = (int)$matches[2];
            $min = round($min / 15) * 15;
            if ($min >= 60) { $min = 0; $hour++; }
            $normalized_value = sprintf('%02d:%02d', $hour, $min);
        }
    }
    list($min_hour, $min_min) = explode(':', $min_time);
    list($max_hour, $max_min) = explode(':', $max_time);
    $min_total = (int)$min_hour * 60 + (int)$min_min;
    $max_total = (int)$max_hour * 60 + (int)$max_min;
    
    // Fix: If max is 00:00 (midnight) and min > 0, treat max as 24:00 (1440 mins)
    if ($max_total === 0 && $min_total > 0) {
        $max_total = 1440;
    }
    // Also handle overnight shifts (max < min) by adding 24h? 
    // For now, simpler fix for midnight end time is enough for user case.
    if ($max_total < $min_total) $max_total += 1440;
    
    for ($hour = 0; $hour < 24; $hour++) {
        for ($min = 0; $min < 60; $min += 15) {
            $time_total = $hour * 60 + $min;
            if ($time_total >= $min_total && $time_total <= $max_total) {
                $time_str = sprintf('%02d:%02d', $hour, $min);
                $time_display = date('g:i A', strtotime($time_str));
                $options[] = [ 'value' => $time_str, 'display' => $time_display, 'selected' => ($normalized_value === $time_str) ];
            }
        }
    }
    if ($normalized_value && !array_search($normalized_value, array_column($options, 'value'))) {
         $time_display = date('g:i A', strtotime($normalized_value));
         $options[] = [ 'value' => $normalized_value, 'display' => $time_display, 'selected' => true ];
         usort($options, function($a, $b) { return strcmp($a['value'], $b['value']); });
    }
    $disabled_attr = $disabled ? 'disabled' : '';
    $required_attr = $required ? 'required' : '';
    $data_attr_html = $data_attr ? ' ' . $data_attr : '';
    $html = '<select name="' . htmlspecialchars($name) . '" class="' . htmlspecialchars($classes) . '" ' . $disabled_attr . ' ' . $required_attr . $data_attr_html . '>';
    $html .= '<option value="">Select time...</option>';
    foreach ($options as $option) {
        $selected = $option['selected'] ? 'selected' : '';
        $html .= '<option value="' . htmlspecialchars($option['value']) . '" ' . $selected . '>' . htmlspecialchars($option['display']) . '</option>';
    }
    $html .= '</select>';
    return $html;
}

function getDefaultProfilePhoto($full_name) {
    $names = explode(' ', trim($full_name));
    $initials = '';
    if (count($names) >= 2) {
        $initials = strtoupper(substr($names[0], 0, 1) . substr($names[count($names) - 1], 0, 1));
    } else {
        $initials = strtoupper(substr($full_name, 0, 2));
    }
    $colors = [
        ['bg' => '#8a11d4', 'text' => '#ffffff'], ['bg' => '#6A1B9A', 'text' => '#ffffff'],
        ['bg' => '#FFD700', 'text' => '#151118'], ['bg' => '#10b981', 'text' => '#ffffff'],
        ['bg' => '#3b82f6', 'text' => '#ffffff'], 
    ];
    $colorIndex = abs(crc32($full_name)) % count($colors);
    $color = $colors[$colorIndex];
    return ['initials' => $initials, 'bg_color' => $color['bg'], 'text_color' => $color['text']];
}

$clinic_schedule_db = [];
try {
    $s_stmt = $pdo->query("SELECT * FROM tbl_clinic_schedule");
    while ($row = $s_stmt->fetch(PDO::FETCH_ASSOC)) {
        $clinic_schedule_db[strtolower($row['day_of_week'])] = $row;
    }
} catch (Exception $e) { error_log("Error: " . $e->getMessage()); }

function getClinicHours($day, $schedule_db) {
    $day = strtolower($day);
    if (isset($schedule_db[$day]) && $schedule_db[$day]['is_open']) {
        $start = date('H:i', strtotime($schedule_db[$day]['start_time']));
        $end = date('H:i', strtotime($schedule_db[$day]['end_time']));
        return ['start' => $start, 'end' => $end, 'active' => true];
    }
    return ['start' => '09:00', 'end' => '17:00', 'active' => false];
}

// --- Logic ---
$message = '';
$message_type = '';
$csrf_token = generateCSRFToken();
$edit_user_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
if ($edit_user_id <= 0) { header('Location: admin_schedule.php?error=invalid_user'); exit; }

// Fetch Data
try {
    $stmt = $pdo->prepare("SELECT user_id, full_name, role, profile_picture FROM tbl_user WHERE user_id = ? LIMIT 1");
    $stmt->execute([$edit_user_id]);
    $user_data = $stmt->fetch();
    if (!$user_data) { header('Location: admin_schedule.php?error=user_not_found'); exit; }
    
    $schedule_stmt = $pdo->prepare("SELECT day_of_week, start_time, end_time FROM tbl_doctor_schedule WHERE doctor_id = ?");
    $schedule_stmt->execute([$edit_user_id]);
    $existing_schedule = $schedule_stmt->fetchAll();
    
    $schedule_map = [];
    foreach ($existing_schedule as $sched) {
        $schedule_map[strtolower($sched['day_of_week'])] = [ 'start' => $sched['start_time'], 'end' => $sched['end_time'] ];
    }
} catch (PDOException $e) { die('DB Error: ' . $e->getMessage()); }

// Handle Post
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_schedule'])) {
    $submitted_token = $_POST['csrf_token'] ?? '';
    if (!verifyCSRFToken($submitted_token)) {
        $message = 'Invalid security token.'; $message_type = 'error';
    } else {
        try {
            $pdo->beginTransaction();
            $pdo->prepare("DELETE FROM tbl_doctor_schedule WHERE doctor_id = ?")->execute([$edit_user_id]);
            $days = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];
            foreach ($days as $day) {
                if (!empty($_POST[$day.'_active']) && !empty($_POST[$day.'_start']) && !empty($_POST[$day.'_end'])) {
                    $start = $_POST[$day.'_start']; $end = $_POST[$day.'_end'];
                    if ($start >= $end) throw new Exception("End time must be after start time for ".ucfirst($day).".");
                    
                    // Validate against Clinic Hours
                    $ch_check = getClinicHours($day, $clinic_schedule_db);
                    if ($ch_check['active']) {
                        if ($start < $ch_check['start'] || $end > $ch_check['end']) {
                             throw new Exception("Schedule for ".ucfirst($day)." must be within clinic hours (" . date('g:i A', strtotime($ch_check['start'])) . " - " . date('g:i A', strtotime($ch_check['end'])) . ").");
                        }
                    } else {
                        throw new Exception("Clinic is closed on ".ucfirst($day).". Cannot schedule.");
                    }

                    $pdo->prepare("INSERT INTO tbl_doctor_schedule (doctor_id, day_of_week, start_time, end_time) VALUES (?, ?, ?, ?)")->execute([$edit_user_id, ucfirst($day), $start, $end]);
                }
            }
            $pdo->commit();
            $message = 'Schedule updated successfully!'; $message_type = 'success';
            
            // Refresh
            $schedule_stmt->execute([$edit_user_id]);
            $existing_schedule = $schedule_stmt->fetchAll();
            $schedule_map = [];
            foreach ($existing_schedule as $sched) {
                $schedule_map[strtolower($sched['day_of_week'])] = [ 'start' => $sched['start_time'], 'end' => $sched['end_time'] ];
            }
        } catch (Exception $e) {
            $pdo->rollBack();
            $message = 'Update failed: ' . $e->getMessage(); $message_type = 'error';
        }
    }
    $csrf_token = generateCSRFToken();
}

$days = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];
$user_default_photo = getDefaultProfilePhoto($user_data['full_name']);
?>
<!DOCTYPE html>
<html class="light" lang="en">
<head>
    <meta charset="utf-8"/>
    <meta content="width=device-width, initial-scale=1.0" name="viewport"/>
    <title>Edit Schedule - Vanessa Nicolas Dental Clinic</title>
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@200..800&display=swap" rel="stylesheet"/>
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet"/>
    <script src="https://cdn.tailwindcss.com?plugins=forms,container-queries"></script>
    <script>
        tailwind.config = {
            darkMode: "class",
            theme: {
                extend: {
                    colors: {
                        "primary": "#8a11d4", "accent": "#FFD700",
                        "background-light": "#f7f6f8", "background-dark": "#1b1022",
                        "surface-light": "#ffffff", "surface-dark": "#2d1b36",
                        "text-main": "#151118", "text-sub": "#7a6189",
                    },
                    fontFamily: { "display": ["Manrope", "sans-serif"] },
                },
            },
        }
    </script>
    <style>
        .schedule-card.active { border-color: #8a11d4; background-color: rgba(138, 17, 212, 0.05); }
    </style>
</head>
<body class="bg-background-light dark:bg-background-dark text-text-main dark:text-white font-display overflow-hidden">
<div class="flex h-screen w-full">
    <!-- Sidebar -->
    <aside class="w-72 flex-shrink-0 flex flex-col bg-surface-light dark:bg-surface-dark border-r border-[#f3f0f4] dark:border-gray-800 transition-colors duration-200">
        <div class="flex flex-col h-full p-4">
             <a href="admin_current_user.php" class="flex gap-3 mb-8 items-center p-2 cursor-pointer hover:bg-[#f3f0f4] dark:hover:bg-gray-700/50 rounded-xl p-2 transition-colors">
                <?php
                if (isset($_SESSION['user']['profile_picture']) && file_exists('../'.$_SESSION['user']['profile_picture'])) {
                    echo '<div class="bg-center bg-no-repeat aspect-square bg-cover rounded-full size-12 border-2 border-primary" style="background-image: url(\'../'.$_SESSION['user']['profile_picture'].'\');"></div>';
                } else {
                     $d = getDefaultProfilePhoto($_SESSION['user']['full_name']??'Admin');
                    echo '<div class="rounded-full size-12 border-2 border-primary flex items-center justify-center text-white font-bold" style="background-color:'.$d['bg_color'].';color:'.$d['text_color'].'">'.$d['initials'].'</div>';
                }
                ?>
                <div class="flex flex-col">
                    <h1 class="text-text-main dark:text-white text-base font-bold"><?php echo htmlspecialchars($_SESSION['user']['full_name']??'Admin'); ?></h1>
                    <p class="text-primary text-xs font-semibold uppercase"><?php echo htmlspecialchars($_SESSION['user']['role']??'Admin'); ?></p>
                </div>
            </a>
            <nav class="flex flex-col gap-2 flex-1">
                <a class="flex items-center gap-3 px-4 py-3 rounded-xl text-text-main dark:text-gray-300 hover:bg-[#f3f0f4] dark:hover:bg-gray-700/50 transition-all" href="admin_dashboard.php"><span class="material-symbols-outlined">dashboard</span><p class="text-sm font-medium">Dashboard</p></a>
                <a class="flex items-center gap-3 px-4 py-3 rounded-xl text-text-main dark:text-gray-300 hover:bg-[#f3f0f4] dark:hover:bg-gray-700/50 transition-all" href="admin_user.php"><span class="material-symbols-outlined">group</span><p class="text-sm font-medium">Users</p></a>
                <a class="flex items-center gap-3 px-4 py-3 rounded-xl bg-primary/10 text-primary font-bold transition-all" href="admin_schedule.php"><span class="material-symbols-outlined fill-1">schedule</span><p class="text-sm font-medium">Schedules</p></a>
                <a class="flex items-center gap-3 px-4 py-3 rounded-xl text-text-main dark:text-gray-300 hover:bg-[#f3f0f4] dark:hover:bg-gray-700/50 transition-all" href="admin_service.php"><span class="material-symbols-outlined">medical_services</span><p class="text-sm font-medium">Services</p></a>
                <a class="flex items-center gap-3 px-4 py-3 rounded-xl text-text-main dark:text-gray-300 hover:bg-[#f3f0f4] dark:hover:bg-gray-700/50 transition-all" href="admin_reports.php"><span class="material-symbols-outlined">description</span><p class="text-sm font-medium">Reports</p></a>
                <a class="flex items-center gap-3 px-4 py-3 rounded-xl text-text-main dark:text-gray-300 hover:bg-[#f3f0f4] dark:hover:bg-gray-700/50 transition-all" href="admin_settings.php"><span class="material-symbols-outlined">settings</span><p class="text-sm font-medium">Settings</p></a>
            </nav>
            <div class="mt-auto pt-6 border-t border-gray-100 dark:border-gray-800">
                <a href="../logout.php" class="flex items-center gap-3 px-4 py-2 text-text-sub hover:text-red-500 transition-colors"><span class="material-symbols-outlined">logout</span><span class="text-sm font-medium">Logout</span></a>
            </div>
        </div>
    </aside>

    <main class="flex-1 flex flex-col h-full overflow-hidden bg-background-light dark:bg-background-dark relative">
        <header class="h-20 flex items-center justify-between px-8 bg-surface-light dark:bg-surface-dark border-b border-[#f3f0f4] dark:border-gray-800 flex-shrink-0 z-20">
            <div class="flex items-center gap-4">
                 <a href="admin_schedule.php" class="p-2 rounded-xl hover:bg-gray-100 dark:hover:bg-gray-800 transition-colors"><span class="material-symbols-outlined">arrow_back</span></a>
                <h2 class="text-text-main dark:text-white text-xl font-bold">Edit Schedule</h2>
            </div>
        </header>

        <div class="flex-1 overflow-y-auto p-8 scroll-smooth">
            <div class="max-w-[1200px] mx-auto flex flex-col gap-6">
                <!-- Message -->
                <?php if ($message): ?>
                <div class="p-4 rounded-xl <?php echo $message_type==='success'?'bg-green-50 text-green-700':'bg-red-50 text-red-700'; ?> border flex items-center gap-3">
                    <span class="material-symbols-outlined"><?php echo $message_type==='success'?'check_circle':'error'; ?></span>
                    <p class="text-sm font-medium"><?php echo htmlspecialchars($message); ?></p>
                </div>
                <?php endif; ?>

                <form method="POST" action="admin_edit_schedule.php?id=<?php echo $edit_user_id; ?>" class="grid grid-cols-1 lg:grid-cols-12 gap-8 items-start">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                    <input type="hidden" name="update_schedule" value="1">
                    
                    <!-- LEFT: Clinic Hours & User Info -->
                    <div class="lg:col-span-4 space-y-6">
                        <!-- User Card -->
                        <div class="bg-surface-light dark:bg-surface-dark rounded-2xl p-6 border border-gray-100 dark:border-gray-700 shadow-sm">
                            <div class="flex flex-col items-center gap-3">
                                 <?php if ($user_data['profile_picture'] && file_exists('../' . $user_data['profile_picture'])): ?>
                                <div class="w-20 h-20 rounded-full bg-cover bg-center ring-2 ring-primary" style="background-image: url('../<?php echo $user_data['profile_picture']; ?>');"></div>
                                <?php else: ?>
                                <div class="w-20 h-20 rounded-full flex items-center justify-center text-white font-bold ring-2 ring-primary text-xl" style="background-color: <?php echo $user_default_photo['bg_color']; ?>; color: <?php echo $user_default_photo['text_color']; ?>;"><?php echo $user_default_photo['initials']; ?></div>
                                <?php endif; ?>
                                <div class="text-center">
                                    <h3 class="text-lg font-bold text-text-main dark:text-white"><?php echo htmlspecialchars($user_data['full_name']); ?></h3>
                                    <p class="text-sm text-text-sub font-medium"><?php echo htmlspecialchars($user_data['role']); ?></p>
                                </div>
                            </div>
                        </div>

                        <!-- Clinic Hours -->
                        <div class="bg-surface-light dark:bg-surface-dark rounded-2xl p-6 border border-gray-100 dark:border-gray-700 shadow-sm">
                             <h4 class="text-sm font-bold text-primary uppercase tracking-wider mb-4 flex items-center gap-2 border-b border-gray-100 dark:border-gray-700 pb-2">
                                <span class="material-symbols-outlined text-lg">access_time</span> Clinic Hours Ref
                            </h4>
                            <div class="flex flex-col gap-3">
                                <?php foreach ($days as $d): 
                                    $h = getClinicHours($d, $clinic_schedule_db);
                                ?>
                                <div class="flex items-center justify-between text-sm">
                                    <span class="text-text-sub font-medium capitalize"><?php echo $d; ?></span>
                                    <?php if ($h['active']): ?>
                                        <span class="text-xs font-bold bg-green-50 text-green-700 px-2 py-1 rounded"><?php echo date('g:i A',strtotime($h['start'])).'-'.date('g:i A',strtotime($h['end'])); ?></span>
                                    <?php else: ?>
                                        <span class="text-xs font-bold bg-gray-100 text-gray-500 px-2 py-1 rounded">Closed</span>
                                    <?php endif; ?>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>

                    <!-- RIGHT: Schedule Grid -->
                    <div class="lg:col-span-8">
                        <div class="bg-surface-light dark:bg-surface-dark rounded-2xl p-6 border border-gray-100 dark:border-gray-700 shadow-sm">
                            <h3 class="text-lg font-bold text-text-main dark:text-white mb-1">Weekly Schedule</h3>
                            <p class="text-sm text-text-sub mb-6">Configure the shift timings for this staff member.</p>
                            
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <?php foreach ($days as $day): 
                                    $is_active = isset($schedule_map[$day]);
                                    $s_val = $is_active ? substr($schedule_map[$day]['start'], 0, 5) : '';
                                    $e_val = $is_active ? substr($schedule_map[$day]['end'], 0, 5) : '';
                                    $ch = getClinicHours($day, $clinic_schedule_db);
                                ?>
                                <div class="schedule-card p-4 rounded-xl border-2 transition-all <?php echo $is_active ? 'border-primary bg-primary/5' : 'border-gray-200 dark:border-gray-700 bg-gray-50'; ?>" data-day="<?php echo $day; ?>">
                                    <div class="flex items-center justify-between mb-3">
                                        <label class="flex items-center gap-2 cursor-pointer <?php echo !$ch['active'] ? 'opacity-50 pointer-events-none' : ''; ?>">
                                            <input type="checkbox" name="<?php echo $day; ?>_active" value="1" <?php echo ($is_active && $ch['active']) ? 'checked' : ''; ?> <?php echo !$ch['active'] ? 'disabled' : ''; ?> class="rounded border-gray-300 text-primary w-5 h-5 day-toggle" data-day="<?php echo $day; ?>">
                                            <span class="text-sm font-bold capitalize text-text-main dark:text-white"><?php echo $day; ?></span>
                                        </label>
                                        <!-- Actions -->
                                        <!-- Actions -->
                                        <div class="flex gap-1 action-buttons <?php echo $is_active ? '' : 'hidden'; ?>">
                                            <button type="button" class="p-1 hover:bg-primary/10 rounded text-primary text-xs font-bold copy-btn" data-day="<?php echo $day; ?>" title="Copy settings">Copy</button>
                                            <button type="button" class="p-1 hover:bg-accent/20 rounded text-accent text-xs font-bold apply-all-btn" data-day="<?php echo $day; ?>" title="Apply to all">All</button>
                                        </div>
                                    </div>
                                    <div class="flex items-center gap-2 <?php echo $is_active ? '' : 'opacity-50 pointer-events-none'; ?>" id="inputs_<?php echo $day; ?>">
                                        <?php 
                                            // Dynamic range based on Clinic Hours
                                            $min_time = $ch['active'] ? $ch['start'] : '00:00';
                                            $max_time = $ch['active'] ? $ch['end'] : '23:45';
                                            
                                            // Start Time Dropdown
                                            echo generateTimeDropdown($day.'_start', $s_val, $min_time, $max_time, 'flex-1 text-xs rounded-lg border-gray-200 input-start', !$is_active, false); 
                                        ?>
                                        <span class="text-text-sub">-</span>
                                        <?php 
                                            // End Time Dropdown
                                            echo generateTimeDropdown($day.'_end', $e_val, $min_time, $max_time, 'flex-1 text-xs rounded-lg border-gray-200 input-end', !$is_active, false); 
                                        ?>
                                    </div>
                                    <p class="text-[10px] text-text-sub mt-2">Clinic Hours: <?php echo $ch['active'] ? date('g:iA',strtotime($ch['start'])).'-'.date('g:iA',strtotime($ch['end'])) : 'Closed'; ?></p>
                                </div>
                                <?php endforeach; ?>
                            </div>

                            <div class="mt-8 flex gap-4">
                                <button type="submit" class="flex-1 px-6 py-3 rounded-xl bg-primary hover:bg-primary/90 text-white font-bold shadow-lg shadow-primary/30 transition-all">Save Changes</button>
                                <a href="admin_schedule.php" class="px-6 py-3 rounded-xl border border-gray-200 text-text-sub hover:bg-gray-50 font-bold transition-all">Cancel</a>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </main>
</div>

<!-- Copy Modal -->
<div id="copyModal" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-black/60 backdrop-blur-sm">
    <div class="bg-white dark:bg-surface-dark p-6 rounded-2xl w-96 shadow-xl transform scale-95 opacity-0 transition-all" id="copyModalContent">
        <h3 class="text-lg font-bold mb-4">Copy <span id="copySourceDay" class="capitalize text-primary"></span> to:</h3>
        <div id="copyTargets" class="grid grid-cols-2 gap-2 mb-6"></div>
        <div class="flex gap-2 justify-end">
            <button type="button" onclick="closeCopyModal()" class="px-4 py-2 text-sm font-bold text-gray-500 hover:bg-gray-100 rounded-lg">Cancel</button>
            <button type="button" id="confirmCopyBtn" class="px-4 py-2 text-sm font-bold bg-primary text-white rounded-lg">Copy</button>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Toggle Logic
    document.querySelectorAll('.day-toggle').forEach(cb => {
        cb.addEventListener('change', function() {
            const day = this.dataset.day;
            const card = this.closest('.schedule-card');
            const inputs = document.getElementById('inputs_' + day);
            const selects = inputs.querySelectorAll('select');
            
            if (this.checked) {
                card.classList.add('border-primary', 'bg-primary/5');
                card.classList.remove('border-gray-200', 'dark:border-gray-700', 'bg-gray-50');
                inputs.classList.remove('opacity-50', 'pointer-events-none');
                selects.forEach(s => s.disabled = false);
                
                // Show actions
                const acts = card.querySelector('.action-buttons');
                if (acts) acts.classList.remove('hidden');
            } else {
                card.classList.remove('border-primary', 'bg-primary/5');
                card.classList.add('border-gray-200', 'dark:border-gray-700', 'bg-gray-50');
                inputs.classList.add('opacity-50', 'pointer-events-none');
                selects.forEach(s => s.disabled = true);
                
                // Hide actions
                const acts = card.querySelector('.action-buttons');
                if (acts) acts.classList.add('hidden');
            }
        });
    });

    // Copy Logic
    let sourceDay = null;
    const modal = document.getElementById('copyModal');
    const modalContent = document.getElementById('copyModalContent');
    const targetsDiv = document.getElementById('copyTargets');

    document.querySelectorAll('.copy-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            sourceDay = this.dataset.day;
            document.getElementById('copySourceDay').textContent = sourceDay;
            targetsDiv.innerHTML = '';
            
            ['monday','tuesday','wednesday','thursday','friday','saturday','sunday'].forEach(d => {
                if (d !== sourceDay) {
                    targetsDiv.innerHTML += `<label class="flex items-center gap-2 p-2 border rounded hover:bg-gray-50 cursor-pointer"><input type="checkbox" class="copy-target" value="${d}"><span class="capitalize text-sm font-medium">${d}</span></label>`;
                }
            });
            
            modal.classList.remove('hidden');
            setTimeout(() => { modalContent.classList.remove('scale-95', 'opacity-0'); modalContent.classList.add('scale-100', 'opacity-100'); }, 10);
        });
    });

    window.closeCopyModal = function() {
        modalContent.classList.remove('scale-100', 'opacity-100');
        modalContent.classList.add('scale-95', 'opacity-0');
        setTimeout(() => modal.classList.add('hidden'), 200);
    };

    document.getElementById('confirmCopyBtn').addEventListener('click', function() {
        const targets = Array.from(document.querySelectorAll('.copy-target:checked')).map(cb => cb.value);
        if (targets.length && sourceDay) {
            const sStart = document.querySelector(`select[name="${sourceDay}_start"]`).value;
            const sEnd = document.querySelector(`select[name="${sourceDay}_end"]`).value;
            
            targets.forEach(t => {
                document.querySelector(`input[name="${t}_active"]`).checked = true;
                document.querySelector(`input[name="${t}_active"]`).dispatchEvent(new Event('change'));
                document.querySelector(`select[name="${t}_start"]`).value = sStart;
                document.querySelector(`select[name="${t}_end"]`).value = sEnd;
            });
        }
        closeCopyModal();
    });

    // Apply All Logic
    document.querySelectorAll('.apply-all-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            const src = this.dataset.day;
            if(confirm(`Apply ${src} schedule to ALL days?`)) {
                const sStart = document.querySelector(`select[name="${src}_start"]`).value;
                const sEnd = document.querySelector(`select[name="${src}_end"]`).value;
                ['monday','tuesday','wednesday','thursday','friday','saturday','sunday'].forEach(t => {
                    if (t !== src) {
                        document.querySelector(`input[name="${t}_active"]`).checked = true;
                        document.querySelector(`input[name="${t}_active"]`).dispatchEvent(new Event('change'));
                        document.querySelector(`select[name="${t}_start"]`).value = sStart;
                        document.querySelector(`select[name="${t}_end"]`).value = sEnd;
                    }
                });
            }
        });
    });
});
</script>
</body>
</html>
