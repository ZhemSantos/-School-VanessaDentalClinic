<?php
session_start();
require_once '../includes/security.php';
// Require receptionist role
requireRole('Receptionist');

// URL for the Check-in Page (Relative to this file, it's ../client/checkin.php)
$protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http";
$host = $_SERVER['HTTP_HOST'];
$path = rtrim(dirname(dirname($_SERVER['PHP_SELF'])), '/\\');
$checkin_url = "$protocol://$host$path/client/checkin.php";

$qr_url = "https://api.qrserver.com/v1/create-qr-code/?size=300x300&data=" . urlencode($checkin_url);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8"/>
    <meta content="width=device-width, initial-scale=1.0" name="viewport"/>
    <title>Patient Arrival Check-in QR</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@200..800&display=swap" rel="stylesheet"/>
</head>
<body class="bg-gray-100 font-[Manrope] min-h-screen flex flex-col items-center justify-center p-8">

    <div class="bg-white max-w-md w-full rounded-3xl shadow-2xl overflow-hidden p-8 text-center print:shadow-none print:max-w-none print:w-full">
        
        <div class="mb-6">
            <h1 class="text-3xl font-black text-purple-900 leading-tight">Welcome!</h1>
            <p class="text-gray-500 font-medium mt-2">Please scan to check in</p>
        </div>

        <div class="bg-purple-50 p-6 rounded-3xl inline-block mb-6">
            <img src="<?php echo $qr_url; ?>" alt="Check-in QR Code" class="w-64 h-64 mix-blend-multiply">
        </div>

        <div class="space-y-4">
            <p class="text-sm text-gray-400">or visit</p>
            <p class="font-mono font-bold text-xs bg-gray-100 py-2 px-4 rounded-lg break-all"><?php echo $checkin_url; ?></p>
        </div>

        <div class="mt-12 flex justify-center gap-4 print:hidden">
            <button onclick="window.print()" class="bg-purple-600 hover:bg-purple-700 text-white px-8 py-3 rounded-xl font-bold transition-all shadow-lg shadow-purple-200">
                Print QR Code
            </button>
            <a href="receptionist_dashboard.php" class="bg-gray-200 hover:bg-gray-300 text-gray-700 px-6 py-3 rounded-xl font-bold transition-all">
                Back
            </a>
        </div>

    </div>

    <div class="mt-8 text-center text-gray-400 text-xs print:hidden">
        <p>Tip: Print this page and place it at the reception desk.</p>
    </div>

</body>
</html>
