<?php
// Theme Picker Component
// Include this in any page to show theme selection

$current_theme = $_SESSION['theme'] ?? 'default';
$themes = [
    'default' => ['name' => 'Default', 'color' => '#8a11d4', 'icon' => 'palette'],
    'purple' => ['name' => 'Purple', 'color' => '#6A1B9A', 'icon' => 'palette'],
    'gold' => ['name' => 'Gold', 'color' => '#FFD700', 'icon' => 'palette'],
];
?>
<div id="themePicker" class="fixed bottom-6 right-6 z-50">
    <button id="themePickerBtn" class="w-14 h-14 rounded-full bg-primary text-white shadow-lg hover:bg-primary/90 transition-all flex items-center justify-center" title="Change Theme">
        <span class="material-symbols-outlined text-2xl">palette</span>
    </button>
    <div id="themePickerMenu" style="display: none;" class="absolute bottom-16 right-0 w-48 bg-white dark:bg-gray-800 rounded-xl shadow-xl border border-gray-200 dark:border-gray-700 p-2">
        <div class="text-xs font-bold text-text-sub px-3 py-2 uppercase tracking-wider">Theme</div>
        <?php foreach ($themes as $theme_key => $theme): ?>
        <button onclick="changeTheme('<?php echo $theme_key; ?>')" class="w-full flex items-center gap-3 px-3 py-2 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors <?php echo $current_theme === $theme_key ? 'bg-primary/10 text-primary' : 'text-text-main dark:text-white'; ?>">
            <div class="w-4 h-4 rounded-full" style="background-color: <?php echo $theme['color']; ?>;"></div>
            <span class="text-sm font-medium"><?php echo $theme['name']; ?></span>
            <?php if ($current_theme === $theme_key): ?>
            <span class="material-symbols-outlined text-sm ml-auto">check</span>
            <?php endif; ?>
        </button>
        <?php endforeach; ?>
    </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const themePickerBtn = document.getElementById('themePickerBtn');
    const themePickerMenu = document.getElementById('themePickerMenu');
    
    if (themePickerBtn && themePickerMenu) {
        themePickerBtn.addEventListener('click', function(e) {
            e.stopPropagation();
            if (themePickerMenu.style.display === 'none' || themePickerMenu.style.display === '') {
                themePickerMenu.style.display = 'block';
            } else {
                themePickerMenu.style.display = 'none';
            }
        });
        
        document.addEventListener('click', function(e) {
            if (!themePickerBtn.contains(e.target) && !themePickerMenu.contains(e.target)) {
                themePickerMenu.style.display = 'none';
            }
        });
    }
});

function changeTheme(theme) {
    fetch('change_theme.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: 'theme=' + encodeURIComponent(theme)
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            location.reload();
        }
    })
    .catch(error => {
        console.error('Error changing theme:', error);
    });
}
</script>

